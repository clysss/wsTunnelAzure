<?php
// mysql_tunnel.php — Place inside www/ on your OVH hosting

// 1. Verify the URL security token
$WAKE_KEY = 'YOUR_URL_SECRET_KEY';
if (!hash_equals($WAKE_KEY, $_GET['key'] ?? '')) {
    http_response_code(403);
    exit('Forbidden');
}

// 2. Keep the script running after closing the HTTP connection with Azure
ignore_user_abort(true);
@set_time_limit(0);
@ini_set('default_socket_timeout', 5);

// 3. Immediately return "200 OK" to Azure to release the HTTP wake-up call
header('Content-Type: text/plain');
header('Connection: close');
header('Content-Length: 2');
echo 'OK';
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    flush();
}

// --- CONFIGURATION ---
$AZURE_HOST    = 'mysql-tunnel-relay.xxxx.francecentral.azurecontainerapps.io'; // Your Azure FQDN
$TUNNEL_SECRET = 'YOUR_TUNNEL_SECRET'; // Must match tunnelSecret in Azure

$MYSQL_HOST    = 'YOUR_DB.mysql.db'; // MySQL host provided by OVH
$MYSQL_PORT    = 3306;

// Stay under OVH web server execution limit (usually 30s)
$MAX_RUNTIME   = 25;
$startTime     = time();

// File lock to prevent multiple parallel tunnel instances
$lockFile = sys_get_temp_dir() . '/ovh_mysql_tunnel.lock';
$lockFp   = fopen($lockFile, 'c');
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    exit; // Another instance is already active
}

function connectWebSocket(string $host, string $secret, ?string $sessionId = null) {
    $context = stream_context_create([
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
            'SNI_enabled'      => true,
            'peer_name'        => $host
        ]
    ]);

    $fp = @stream_socket_client("ssl://{$host}:443", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $context);
    if (!$fp) return false;

    $key = base64_encode(random_bytes(16));
    $headers = [
        "GET / HTTP/1.1",
        "Host: {$host}",
        "Upgrade: websocket",
        "Connection: Upgrade",
        "Sec-WebSocket-Key: {$key}",
        "Sec-WebSocket-Version: 13",
        "X-API-KEY: {$secret}"
    ];

    if ($sessionId !== null) {
        $headers[] = "X-Session-Id: {$sessionId}";
    }

    fwrite($fp, implode("\r\n", $headers) . "\r\n\r\n");

    $response = '';
    while (($line = fgets($fp)) !== false) {
        $response .= $line;
        if (rtrim($line) === '') break;
    }

    if (strpos($response, '101') === false) {
        fclose($fp);
        return false;
    }

    stream_set_blocking($fp, false);
    return $fp;
}

function wsEncode(string $payload, int $opcode = 0x2): string {
    $len = strlen($payload);
    $header = chr(0x80 | $opcode);

    if ($len <= 125) {
        $header .= chr(0x80 | $len);
    } elseif ($len <= 65535) {
        $header .= chr(0x80 | 126) . pack('n', $len);
    } else {
        $header .= chr(0x80 | 127) . pack('J', $len);
    }

    $mask = random_bytes(4);
    $masked = '';
    for ($i = 0; $i < $len; $i++) {
        $masked .= $payload[$i] ^ $mask[$i % 4];
    }

    return $header . $mask . $masked;
}

function wsDecode(string &$buffer): ?array {
    if (strlen($buffer) < 2) return null;

    $firstByte  = ord($buffer[0]);
    $secondByte = ord($buffer[1]);
    $opcode     = $firstByte & 0x0F;
    $len        = $secondByte & 0x7F;
    $offset     = 2;

    if ($len === 126) {
        if (strlen($buffer) < 4) return null;
        $len = unpack('n', substr($buffer, 2, 2))[1];
        $offset = 4;
    } elseif ($len === 127) {
        if (strlen($buffer) < 10) return null;
        $len = unpack('J', substr($buffer, 2, 8))[1];
        $offset = 10;
    }

    if (strlen($buffer) < $offset + $len) return null;

    $payload = substr($buffer, $offset, $len);
    $buffer  = substr($buffer, $offset + $len);

    return ['opcode' => $opcode, 'payload' => $payload];
}

// --- MAIN EVENT LOOP ---
$controlWs = connectWebSocket($AZURE_HOST, $TUNNEL_SECRET);
if (!$controlWs) {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    exit;
}

$controlBuffer = '';
$sessions = [];

while ((time() - $startTime) < $MAX_RUNTIME) {
    $read = [$controlWs];
    foreach ($sessions as $s) {
        $read[] = $s['ws'];
        $read[] = $s['db'];
    }
    $write = null;
    $except = null;

    if (@stream_select($read, $write, $except, 0, 200000) === false) break;

    // 1. Control channel (incoming PDO requests from Azure)
    if (in_array($controlWs, $read, true)) {
        $chunk = fread($controlWs, 8192);
        if ($chunk === '' || $chunk === false) break;
        $controlBuffer .= $chunk;

        while (($frame = wsDecode($controlBuffer)) !== null) {
            if ($frame['opcode'] === 0x9) {
                fwrite($controlWs, wsEncode($frame['payload'], 0xA));
                continue;
            }
            $msg = json_decode($frame['payload'], true);
            if (($msg['action'] ?? '') === 'OPEN_DB' && !empty($msg['sessionId'])) {
                $sid = $msg['sessionId'];
                $dbSock = @stream_socket_client("tcp://{$MYSQL_HOST}:{$MYSQL_PORT}", $e1, $e2, 5);
                $dataWs = connectWebSocket($AZURE_HOST, $TUNNEL_SECRET, $sid);

                if ($dbSock && $dataWs) {
                    stream_set_blocking($dbSock, false);
                    $sessions[$sid] = ['ws' => $dataWs, 'db' => $dbSock, 'wsBuf' => ''];
                } else {
                    if ($dbSock) fclose($dbSock);
                    if ($dataWs) fclose($dataWs);
                }
            }
        }
    }

    // 2. Bidirectional relay for active MySQL sessions
    foreach ($sessions as $sid => &$s) {
        if (in_array($s['ws'], $read, true)) {
            $chunk = fread($s['ws'], 65536);
            if ($chunk === '' || $chunk === false) {
                fclose($s['ws']);
                fclose($s['db']);
                unset($sessions[$sid]);
                continue;
            }
            $s['wsBuf'] .= $chunk;
            while (($frame = wsDecode($s['wsBuf'])) !== null) {
                if ($frame['opcode'] === 0x8) {
                    fclose($s['ws']);
                    fclose($s['db']);
                    unset($sessions[$sid]);
                    break 2;
                }
                if ($frame['payload'] !== '') {
                    fwrite($s['db'], $frame['payload']);
                }
            }
        }

        if (in_array($s['db'], $read, true)) {
            $dbData = fread($s['db'], 65536);
            if ($dbData === '' || $dbData === false) {
                fwrite($s['ws'], wsEncode('', 0x8));
                fclose($s['ws']);
                fclose($s['db']);
                unset($sessions[$sid]);
                continue;
            }
            fwrite($s['ws'], wsEncode($dbData, 0x2));
        }
    }
    unset($s);
}

foreach ($sessions as $s) {
    @fclose($s['ws']);
    @fclose($s['db']);
}
@fclose($controlWs);
flock($lockFp, LOCK_UN);
fclose($lockFp);
