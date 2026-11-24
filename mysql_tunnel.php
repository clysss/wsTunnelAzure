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
// ============================================================================

// 1. Verify the URL security token
if (!hash_equals($WAKE_KEY, (string) ($_GET['key'] ?? ''))) {
    http_response_code(403);
    exit('Forbidden');
}

// 2. Keep running in background after releasing Azure's HTTP wake-up request
ignore_user_abort(true);
@set_time_limit(0);
@ini_set('default_socket_timeout', '5');
@ini_set('zlib.output_compression', 'Off');

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Content-Length: 3');
header('Connection: close');

echo "OK\n";
flush();

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

// 3. Prevent duplicate parallel control tunnels
$lockFp = fopen(sys_get_temp_dir() . '/ovh_mysql_tunnel.lock', 'c');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    exit;
}

register_shutdown_function(static function () use ($lockFp): void {
    @flock($lockFp, LOCK_UN);
    @fclose($lockFp);
});

/**
 * Ensures all bytes are written to a non-blocking stream.
 */
function fwriteAll($stream, string $data): bool
{
    $total = strlen($data);
    $written = 0;

    while ($written < $total) {
        $n = @fwrite($stream, $written === 0 ? $data : substr($data, $written));
        if ($n === false) {
            return false;
        }
        if ($n === 0) {
            $w = [$stream];
            $r = $e = null;
            if (@stream_select($r, $w, $e, 1, 0) === false) {
                return false;
            }
            continue;
        }
        $written += $n;
    }

    return true;
}

/**
 * Opens an authenticated RFC 6455 WebSocket connection to the Azure relay.
 */
function connectWebSocket(string $host, string $secret, ?string $sessionId = null)
{
    $fp = @stream_socket_client("tcp://{$host}:8080", $errno, $errstr, 5, STREAM_CLIENT_CONNECT);
    if (!$fp) {
        return false;
    }

    stream_set_timeout($fp, 5);

    $headers = [
        'GET / HTTP/1.1',
        "Host: {$host}:8080",
        'Upgrade: websocket',
        'Connection: Upgrade',
        'Sec-WebSocket-Key: ' . base64_encode(random_bytes(16)),
        'Sec-WebSocket-Version: 13',
        "X-API-KEY: {$secret}",
    ];

    if ($sessionId !== null) {
        $headers[] = "X-Session-Id: {$sessionId}";
    }

    if (!fwriteAll($fp, implode("\r\n", $headers) . "\r\n\r\n")) {
        fclose($fp);
        return false;
    }

    $response = '';
    while (($line = fgets($fp)) !== false) {
        $response .= $line;
        if (rtrim($line) === '') {
            break;
        }
    }

    if (strpos($response, ' 101 ') === false) {
        fclose($fp);
        return false;
    }

    stream_set_blocking($fp, false);
    return $fp;
}

/**
 * Encodes a masked client WebSocket frame using fast native string XOR.
 */
function wsEncode(string $payload, int $opcode = 0x2): string
{
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
    if ($len === 0) {
        return $header . $mask;
    }

    $maskPad = str_repeat($mask, intdiv($len + 3, 4));
    return $header . $mask . ($payload ^ substr($maskPad, 0, $len));
}

/**
 * Decodes the next complete unmasked server WebSocket frame from the buffer.
 */
function wsDecode(string &$buffer): ?array
{
    $bufLen = strlen($buffer);
    if ($bufLen < 2) {
        return null;
    }

    $opcode = ord($buffer[0]) & 0x0F;
    $len    = ord($buffer[1]) & 0x7F;
    $offset = 2;

    if ($len === 126) {
        if ($bufLen < 4) {
            return null;
        }
        $len = unpack('n', substr($buffer, 2, 2))[1];
        $offset = 4;
    } elseif ($len === 127) {
        if ($bufLen < 10) {
            return null;
        }
        $len = unpack('J', substr($buffer, 2, 8))[1];
        $offset = 10;
    }

    if ($bufLen < $offset + $len) {
        return null;
    }

    $payload = $len > 0 ? substr($buffer, $offset, $len) : '';
    $buffer  = substr($buffer, $offset + $len);

    return ['opcode' => $opcode, 'payload' => $payload];
}

function closeSession(array &$sessions, string $sid, bool $notifyWs = false): void
{
    if (!isset($sessions[$sid])) {
        return;
    }
    if ($notifyWs) {
        @fwrite($sessions[$sid]['ws'], wsEncode('', 0x8));
    }
    @fclose($sessions[$sid]['ws']);
    @fclose($sessions[$sid]['db']);
    unset($sessions[$sid]);
}

// --- MAIN EVENT LOOP ---
$controlWs = connectWebSocket($AZURE_HOST, $TUNNEL_SECRET);
if (!$controlWs) {
    exit;
}

$startTime     = time();
$controlBuffer = '';
$sessions      = [];

while (true) {
    $elapsed = time() - $startTime;
    if ($elapsed >= $HARD_LIMIT || ($elapsed >= $MAX_RUNTIME && empty($sessions))) {
        break;
    }

    $read = [$controlWs];
    foreach ($sessions as $s) {
        $read[] = $s['ws'];
        $read[] = $s['db'];
    }
    $write = $except = null;

    if (@stream_select($read, $write, $except, 0, 200000) === false) {
        break;
    }

    // 1. Control channel (incoming OPEN_DB triggers from Azure)
    if (in_array($controlWs, $read, true)) {
        $chunk = fread($controlWs, 8192);
        if ($chunk === '' || $chunk === false) {
            break;
        }
        $controlBuffer .= $chunk;

        while (($frame = wsDecode($controlBuffer)) !== null) {
            if ($frame['opcode'] === 0x8) {
                break 2;
            }
            if ($frame['opcode'] === 0x9) {
                fwriteAll($controlWs, wsEncode($frame['payload'], 0xA));
                continue;
            }

            $msg = json_decode($frame['payload'], true);
            if (($msg['action'] ?? '') === 'OPEN_DB' && !empty($msg['sessionId'])) {
                $sid    = (string) $msg['sessionId'];
                $dbSock = @stream_socket_client("tcp://{$MYSQL_HOST}:{$MYSQL_PORT}", $e1, $e2, 5);
                $dataWs = $dbSock ? connectWebSocket($AZURE_HOST, $TUNNEL_SECRET, $sid) : false;

                if ($dbSock && $dataWs) {
                    stream_set_blocking($dbSock, false);
                    $sessions[$sid] = ['ws' => $dataWs, 'db' => $dbSock, 'wsBuf' => ''];
                } else {
                    if ($dbSock) {
                        fclose($dbSock);
                    }
                    if ($dataWs) {
                        fclose($dataWs);
                    }
                }
            }
        }
    }

    // 2. Bidirectional binary relay for active MySQL sessions
    foreach (array_keys($sessions) as $sid) {
        if (!isset($sessions[$sid])) {
            continue;
        }

        // Azure -> OVH MySQL
        if (in_array($sessions[$sid]['ws'], $read, true)) {
            $chunk = fread($sessions[$sid]['ws'], 65536);
            if ($chunk === '' || $chunk === false) {
                closeSession($sessions, $sid);
                continue;
            }

            $sessions[$sid]['wsBuf'] .= $chunk;
            while (($frame = wsDecode($sessions[$sid]['wsBuf'])) !== null) {
                if ($frame['opcode'] === 0x8) {
                    closeSession($sessions, $sid);
                    continue 2;
                }
                if ($frame['opcode'] === 0x9) {
                    fwriteAll($sessions[$sid]['ws'], wsEncode($frame['payload'], 0xA));
                    continue;
                }
                if ($frame['payload'] !== '' && !fwriteAll($sessions[$sid]['db'], $frame['payload'])) {
                    closeSession($sessions, $sid, true);
                    continue 2;
                }
            }
        }

        // OVH MySQL -> Azure
        if (isset($sessions[$sid]) && in_array($sessions[$sid]['db'], $read, true)) {
            $dbData = fread($sessions[$sid]['db'], 65536);
            if ($dbData === '' || $dbData === false || !fwriteAll($sessions[$sid]['ws'], wsEncode($dbData, 0x2))) {
                closeSession($sessions, $sid, true);
            }
        }
    }
}

foreach (array_keys($sessions) as $sid) {
    closeSession($sessions, $sid, true);
}
@fwrite($controlWs, wsEncode('', 0x8));
@fclose($controlWs);
