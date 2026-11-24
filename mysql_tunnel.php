<?php
declare(strict_types=1);
<?php
// ============================================================================
// DEPLOYMENT LOCATION:
// Upload this file to your OVH public web folder (or better to a subfolder) : 
// www/mysql_tunnel.php 
// and rename it.
// ============================================================================

// ============================================================================
// CONFIGURATION — Edit these values before uploading
// ============================================================================
$WAKE_KEY      = 'WAKE_KEY';                                                 // Must match WAKE_KEY on Azure
$AZURE_HOST    = 'mysql-tunnel-relay-xxxx.francecentral.azurecontainer.io';  // Azure ACI FQDN
$TUNNEL_SECRET = 'CHANGE_ME_TUNNEL_SECRET';                                  // Must match TUNNEL_SECRET on Azure
$MYSQL_HOST    = 'YOUR_DB.mysql.db';                                         // MySQL host provided by OVH

$MYSQL_PORT    = 3306;                                                       // MySQL port (default: 3306)
$MAX_RUNTIME   = 25;                                                         // Idle/base lifetime in seconds (OVH safe)
$HARD_LIMIT    = 50;                                                         // Absolute max lifetime if a query is active

// 1. Verify the URL security token
if (!hash_equals($WAKE_KEY, $_GET['key'] ?? '')) {
    http_response_code(403);
    exit('Forbidden');
}

// 2. Keep the script running after closing the HTTP connection with Azure
ignore_user_abort(true);
@set_time_limit(0);
@ini_set('default_socket_timeout', '5');
@ini_set('zlib.output_compression', 'Off');

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Connection: close');

echo "OK\n";
flush();

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

$startTime = time();

// 3. File lock to prevent multiple parallel tunnel instances
$lockFile = sys_get_temp_dir() . '/ovh_mysql_tunnel.lock';
$lockFp   = fopen($lockFile, 'c');
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    exit;
}

function connectWebSocket(string $host, string $secret, ?string $sessionId = null) {
    $fp = @stream_socket_client("tcp://{$host}:3306", $errno, $errstr, 10, STREAM_CLIENT_CONNECT);
    if (!$fp) return false;

    $key = base64_encode(random_bytes(16));
    $headers = [
        "GET / HTTP/1.1",
        "Host: {$host}:8080",
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
                    continue 2;
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
