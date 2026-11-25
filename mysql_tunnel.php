<?php
declare(strict_types=1);

// ============================================================================
// USER CONFIGURATION (edit these values to match your Azure & OVH setup)
// ============================================================================
$WAKE_KEY      = 'your-wake-secret-key';
$TUNNEL_SECRET = 'your-long-tunnel-secret-key';
$AZURE_HOST    = 'mysql-tunnel-relay.xxxx.francecentral.azurecontainerapps.io';

// MySQL hostname to which all client connections are bridged.
$FIXED_DB_HOST = '';

// Debug toggle:
//   false => Production mode (no disk I/O, no log file created).
//   true  => Writes connection events to `tunnel_debug.log` in this directory.
//            Remember to restart the Azure revision (or wait 290s) when toggling.
$DEBUG         = false;

// ============================================================================
// INTERNAL RUNTIME SETTINGS (do not modify unless changing ACA template)
// ============================================================================
$MYSQL_PORT  = 3306;
$MAX_RUNTIME = 290; // Total worker lifetime in seconds (must stay < ACA cooldownPeriod of 300s)

// 1. Verify the URL security token so unauthorized callers cannot wake the worker
if (!hash_equals($WAKE_KEY, $_GET['key'] ?? '')) {
    http_response_code(403);
    exit('Forbidden');
}

if (trim($FIXED_DB_HOST) === '') {
    http_response_code(500);
    exit('MySQL host is not configured');
}

// 2. Detach from the HTTP request so PHP keeps running after Azure receives "OK"
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

// Flush the HTTP response immediately on PHP-FPM / OVH shared hosting
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

$startTime = time();

/**
 * Writes timestamped diagnostic messages to `tunnel_debug.log` when `$DEBUG` is enabled.
 */
function tunnelLog(string $msg): void {
    global $DEBUG;
    if (!$DEBUG) {
        return;
    }
    @file_put_contents(__DIR__ . '/tunnel_debug.log', date('H:i:s') . " {$msg}\n", FILE_APPEND);
}

// 3. Acquire an exclusive non-blocking file lock so only one worker runs at a time
$lockFile = sys_get_temp_dir() . '/ovh_mysql_tunnel.lock';
$lockFp   = fopen($lockFile, 'c');
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    exit;
}

tunnelLog("WORKER_START pid=" . getmypid());

/**
 * Opens a raw TCP connection to the Azure Container App on port 3306 and upgrades
 * it to an RFC 6455 WebSocket stream.
 *
 * - When `$sessionId` is null, this socket acts as the Control Channel (receives OPEN_DB commands).
 * - When `$sessionId` is provided, this socket carries raw MySQL binary frames for one PDO session.
 *
 * @return resource|false Non-blocking socket stream on success, false on failure.
 */
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

    // Read HTTP response headers until the blank line
    $response = '';
    while (($line = fgets($fp)) !== false) {
        $response .= $line;
        if (rtrim($line) === '') break;
    }

    // Verify HTTP 101 Switching Protocols
    if (strpos($response, '101') === false) {
        fclose($fp);
        return false;
    }

    stream_set_blocking($fp, false);
    return $fp;
}

/**
 * Encodes a payload into a masked client-to-server WebSocket frame (RFC 6455).
 * Note: RFC 6455 requires all client-to-server frames to be XOR-masked with a 4-byte key.
 *
 * @param string $payload Raw binary or JSON data.
 * @param int    $opcode  0x2 for binary MySQL packets, 0x8 for close, 0xA for pong.
 */
function wsEncode(string $payload, int $opcode = 0x2): string {
    $len = strlen($payload);
    $header = chr(0x80 | $opcode); // FIN bit set + opcode

    if ($len <= 125) {
        $header .= chr(0x80 | $len); // MASK bit set + 7-bit length
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

/**
 * Attempts to extract and decode one complete server-to-client WebSocket frame
 * from the rolling receive buffer. Consumes the frame bytes from `$buffer` when complete.
 *
 * @return array{opcode: int, payload: string}|null Frame data, or null if more bytes are needed.
 */
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

// ============================================================================
// 4. MAIN MULTIPLEXED EVENT LOOP
// ============================================================================

$controlWs = connectWebSocket($AZURE_HOST, $TUNNEL_SECRET);
if (!$controlWs) {
    tunnelLog("CTRL_WS_FAIL");
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

    // ------------------------------------------------------------------------
    // A. Control Channel: handles incoming session requests from Azure Relay
    // ------------------------------------------------------------------------
    if (in_array($controlWs, $read, true)) {
        $chunk = fread($controlWs, 8192);
        if ($chunk === '' || $chunk === false) break;
        $controlBuffer .= $chunk;

        while (($frame = wsDecode($controlBuffer)) !== null) {
            // Reply to WebSocket Ping (0x9) with Pong (0xA) to keep the tunnel alive
            if ($frame['opcode'] === 0x9) {
                fwrite($controlWs, wsEncode($frame['payload'], 0xA));
                continue;
            }
            $msg = json_decode($frame['payload'], true);
            if (($msg['action'] ?? '') === 'OPEN_DB' && !empty($msg['sessionId'])) {
                $sid = $msg['sessionId'];
                $dataWs = connectWebSocket($AZURE_HOST, $TUNNEL_SECRET, $sid);
                if (!$dataWs) continue;

                $dbSock = @stream_socket_client("tcp://{$FIXED_DB_HOST}:{$MYSQL_PORT}", $e1, $e2, 5);
                if ($dbSock) {
                    stream_set_blocking($dbSock, false);
                    $sessions[$sid] = [
                        'ws'    => $dataWs,
                        'db'    => $dbSock,
                        'wsBuf' => '',
                    ];
                } else {
                    tunnelLog("[$sid] DB_CONN_FAIL {$e1} {$e2}");
                    fclose($dataWs);
                }
            }
        }
    }

    // ------------------------------------------------------------------------
    // B. Data Sessions: bidirectional relay between Azure WebSockets and OVH MySQL
    // ------------------------------------------------------------------------
    foreach ($sessions as $sid => &$s) {
        // B1. Data arriving from PDO client (via Azure WebSocket)
        if (in_array($s['ws'], $read, true)) {
            $chunk = fread($s['ws'], 65536);
            if ($chunk === '' || $chunk === false) {
                tunnelLog("[$sid] WS_CLOSED");
                fclose($s['ws']);
                fclose($s['db']);
                unset($sessions[$sid]);
                continue;
            }
            $s['wsBuf'] .= $chunk;
            while (($frame = wsDecode($s['wsBuf'])) !== null) {
                if ($frame['opcode'] === 0x8) { // WebSocket Close frame
                    tunnelLog("[$sid] WS_OP8");
                    fclose($s['ws']);
                    fclose($s['db']);
                    unset($sessions[$sid]);
                    continue 2;
                }
                if ($frame['payload'] === '') continue;

                fwrite($s['db'], $frame['payload']);
            }
        }

        // B2. Data arriving from OVH MySQL server
        if (in_array($s['db'], $read, true)) {
            $dbData = fread($s['db'], 65536);
            if ($dbData === '' || $dbData === false) {
                tunnelLog("[$sid] DB_CLOSED");
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

tunnelLog("WORKER_END pid=" . getmypid());

// Clean up all open sockets and release the file lock before exiting
foreach ($sessions as $s) {
    @fclose($s['ws']);
    @fclose($s['db']);
}
@fclose($controlWs);
flock($lockFp, LOCK_UN);
fclose($lockFp);
