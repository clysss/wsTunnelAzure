<?php
declare(strict_types=1);

// ============================================================================
// USER CONFIGURATION (edit these values to match your Azure & OVH setup)
// ============================================================================
$WAKE_KEY      = 'your-wake-secret-key';
$TUNNEL_SECRET = 'your-long-tunnel-secret-key';
$AZURE_HOST    = 'mysql-tunnel-relay.xxxx.francecentral.azurecontainerapps.io';

// Optional routing mode:
//   '' (empty)        => Dynamic mode: extracts `dbname` from PDO DSN and
//                        routes automatically to `{$dbname}.mysql.db`.
//   'myhost.mysql.db' => Fixed mode: transparent TCP bridge to this exact host.
$FIXED_DB_HOST = '';

// ============================================================================
// INTERNAL RUNTIME SETTINGS (do not modify unless changing ACA template)
// ============================================================================
$MYSQL_PORT  = 3306;
$MAX_RUNTIME = 290; // Total worker lifetime in seconds (must stay < ACA cooldownPeriod of 300s)

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

// 4. MySQL Protocol Helpers (used only when $FIXED_DB_HOST === '')
function buildMysqlPacket(int $seqId, string $payload): string {
    $len = strlen($payload);
    return chr($len & 0xff)
        . chr(($len >> 8) & 0xff)
        . chr(($len >> 16) & 0xff)
        . chr($seqId & 0xff)
        . $payload;
}

function buildSyntheticGreeting(): string {
    $salt1    = random_bytes(8);
    $salt2    = random_bytes(12);
    $caps     = 0x0008a20d;
    $capsLow  = pack('v', $caps & 0xffff);
    $capsHigh = pack('v', ($caps >> 16) & 0xffff);

    $payload  = "\x0a8.0.36-ovh-relay\0";
    $payload .= pack('V', 1);
    $payload .= $salt1 . "\x00";
    $payload .= $capsLow . "\xff";
    $payload .= pack('v', 0x0002);
    $payload .= $capsHigh . chr(21) . str_repeat("\x00", 10);
    $payload .= $salt2 . "\x00mysql_native_password\0";

    return buildMysqlPacket(0, $payload);
}

function parseClientHandshakeResponse(string $packet): ?array {
    if (strlen($packet) < 36) return null;
    $payload = substr($packet, 4);
    $caps    = unpack('V', substr($payload, 0, 4))[1];
    $pos     = 32;

    $userEnd = strpos($payload, "\0", $pos);
    if ($userEnd === false) return null;
    $pos = $userEnd + 1;

    if ($caps & 0x00200000 || $caps & 0x00008000) {
        if ($pos >= strlen($payload)) return null;
        $pos += 1 + ord($payload[$pos]);
    } else {
        $authEnd = strpos($payload, "\0", $pos);
        $pos = ($authEnd === false) ? strlen($payload) : $authEnd + 1;
    }

    $dbname = '';
    if (($caps & 0x00000008) && $pos < strlen($payload)) {
        $dbEnd = strpos($payload, "\0", $pos);
        if ($dbEnd !== false) {
            $dbname = substr($payload, $pos, $dbEnd - $pos);
        }
    }

    return ['dbname' => $dbname, 'raw' => $payload];
}

function parseServerGreeting(string $packet): ?array {
    if (strlen($packet) < 36) return null;
    $payload = substr($packet, 4);
    if (ord($payload[0]) !== 0x0a) return null;

    $verEnd = strpos($payload, "\0", 1);
    if ($verEnd === false) return null;
    $pos = $verEnd + 1 + 4;

    $salt1 = substr($payload, $pos, 8);
    $pos  += 9;

    $capsLow = unpack('v', substr($payload, $pos, 2))[1];
    $pos    += 5;

    $capsHigh = unpack('v', substr($payload, $pos, 2))[1];
    $caps     = $capsLow | ($capsHigh << 16);
    $pos     += 2;

    $authLen = ($caps & 0x00080000) ? ord($payload[$pos]) : 0;
    $pos    += 11;

    $salt2Len = max(13, $authLen - 8);
    $salt2    = substr($payload, $pos, $salt2Len);
    $pos     += $salt2Len;

    $plugin = 'mysql_native_password';
    if ($caps & 0x00080000) {
        $plugEnd = strpos($payload, "\0", $pos);
        if ($plugEnd !== false) {
            $plugin = substr($payload, $pos, $plugEnd - $pos);
        }
    }

    return ['plugin' => $plugin, 'salt' => $salt1 . $salt2];
}

// --- MAIN EVENT LOOP ---
$isDynamicMode = trim((string) $FIXED_DB_HOST) === '';

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
        if ($s['db'] !== null) {
            $read[] = $s['db'];
        }
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
                $dataWs = connectWebSocket($AZURE_HOST, $TUNNEL_SECRET, $sid);
                if (!$dataWs) continue;

                if ($isDynamicMode) {
                    fwrite($dataWs, wsEncode(buildSyntheticGreeting(), 0x2));
                    $sessions[$sid] = [
                        'ws'          => $dataWs,
                        'db'          => null,
                        'wsBuf'       => '',
                        'stage'       => 'WAIT_CLIENT_HELLO',
                        'clientHello' => null,
                        'pendingAuth' => '',
                    ];
                } else {
                    $dbSock = @stream_socket_client("tcp://{$FIXED_DB_HOST}:{$MYSQL_PORT}", $e1, $e2, 5);
                    if ($dbSock) {
                        stream_set_blocking($dbSock, false);
                        $sessions[$sid] = [
                            'ws'          => $dataWs,
                            'db'          => $dbSock,
                            'wsBuf'       => '',
                            'stage'       => 'BRIDGE',
                            'clientHello' => null,
                            'pendingAuth' => '',
                        ];
                    } else {
                        fclose($dataWs);
                    }
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
                if ($s['db'] !== null) fclose($s['db']);
                unset($sessions[$sid]);
                continue;
            }
            $s['wsBuf'] .= $chunk;
            while (($frame = wsDecode($s['wsBuf'])) !== null) {
                if ($frame['opcode'] === 0x8) {
                    fclose($s['ws']);
                    if ($s['db'] !== null) fclose($s['db']);
                    unset($sessions[$sid]);
                    continue 2;
                }
                if ($frame['payload'] === '') continue;

                if ($s['stage'] === 'WAIT_CLIENT_HELLO') {
                    $parsed = parseClientHandshakeResponse($frame['payload']);
                    if (!$parsed || $parsed['dbname'] === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $parsed['dbname'])) {
                        $err = buildMysqlPacket(2, "\xff\x15\x04#28000Missing or invalid dbname in PDO DSN");
                        fwrite($s['ws'], wsEncode($err, 0x2));
                        fwrite($s['ws'], wsEncode('', 0x8));
                        fclose($s['ws']);
                        unset($sessions[$sid]);
                        continue 2;
                    }

                    $targetHost = $parsed['dbname'] . '.mysql.db';
                    $dbSock = @stream_socket_client("tcp://{$targetHost}:{$MYSQL_PORT}", $e1, $e2, 5);
                    if (!$dbSock) {
                        $err = buildMysqlPacket(2, "\xff\xd2\x07#HY000Cannot connect to {$targetHost}");
                        fwrite($s['ws'], wsEncode($err, 0x2));
                        fwrite($s['ws'], wsEncode('', 0x8));
                        fclose($s['ws']);
                        unset($sessions[$sid]);
                        continue 2;
                    }

                    stream_set_blocking($dbSock, false);
                    $s['db']          = $dbSock;
                    $s['clientHello'] = $parsed;
                    $s['stage']       = 'WAIT_SERVER_GREETING';
                } elseif ($s['stage'] === 'WAIT_SERVER_AUTH_PROMPT') {
                    $s['pendingAuth'] .= $frame['payload'];
                } elseif ($s['stage'] === 'BRIDGE' && $s['db'] !== null) {
                    fwrite($s['db'], $frame['payload']);
                }
            }
        }

        if ($s['db'] !== null && in_array($s['db'], $read, true)) {
            $dbData = fread($s['db'], 65536);
            if ($dbData === '' || $dbData === false) {
                fwrite($s['ws'], wsEncode('', 0x8));
                fclose($s['ws']);
                fclose($s['db']);
                unset($sessions[$sid]);
                continue;
            }

            if ($s['stage'] === 'WAIT_SERVER_GREETING') {
                $greeting = parseServerGreeting($dbData);
                if (!$greeting) {
                    fwrite($s['ws'], wsEncode('', 0x8));
                    fclose($s['ws']);
                    fclose($s['db']);
                    unset($sessions[$sid]);
                    continue;
                }

                fwrite($s['db'], buildMysqlPacket(1, $s['clientHello']['raw']));

                $switchPayload = "\xfe" . $greeting['plugin'] . "\0" . $greeting['salt'];
                fwrite($s['ws'], wsEncode(buildMysqlPacket(2, $switchPayload), 0x2));
                $s['stage'] = 'WAIT_SERVER_AUTH_PROMPT';
            } elseif ($s['stage'] === 'WAIT_SERVER_AUTH_PROMPT') {
                if (ord($dbData[4] ?? "\x00") === 0xff) {
                    fwrite($s['ws'], wsEncode($dbData, 0x2));
                    $s['stage'] = 'BRIDGE';
                } else {
                    if ($s['pendingAuth'] !== '') {
                        fwrite($s['db'], $s['pendingAuth']);
                        $s['pendingAuth'] = '';
                    }
                    $s['stage'] = 'BRIDGE';
                }
            } elseif ($s['stage'] === 'BRIDGE') {
                fwrite($s['ws'], wsEncode($dbData, 0x2));
            }
        }
    }
    unset($s);
}

foreach ($sessions as $s) {
    @fclose($s['ws']);
    if ($s['db'] !== null) @fclose($s['db']);
}
@fclose($controlWs);
flock($lockFp, LOCK_UN);
fclose($lockFp);
