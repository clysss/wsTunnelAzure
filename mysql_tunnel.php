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

// Debug toggle:
//   false => Production mode (no disk I/O, no log file created).
//   true  => Writes hex packet traces to `tunnel_debug.log` in this directory.
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
// 4. MYSQL PROTOCOL HELPERS (used only in Dynamic Mode when $FIXED_DB_HOST === '')
// ============================================================================

/**
 * Wraps a raw MySQL payload in the standard 4-byte MySQL packet header:
 * [3-byte little-endian payload length][1-byte sequence ID][payload].
 */
function buildMysqlPacket(int $seqId, string $payload): string {
    $len = strlen($payload);
    return chr($len & 0xff)
        . chr(($len >> 8) & 0xff)
        . chr(($len >> 16) & 0xff)
        . chr($seqId & 0xff)
        . $payload;
}

/**
 * Builds a synthetic MySQL Protocol v10 Initial Handshake packet (seqId = 0).
 *
 * Why this is needed in dynamic mode:
 * In the MySQL protocol, the server speaks first before the client reveals which
 * database (`dbname`) it wants to connect to. Because OVH hosts each database on
 * its own hostname (`{$dbname}.mysql.db`), we cannot open the OVH socket yet.
 * We therefore pretend to be a MySQL server long enough for PDO to send its
 * HandshakeResponse containing `dbname`.
 *
 * Important binary detail:
 * MySQL splits the 20-byte cryptographic salt (scramble) into two fields:
 * - `salt1`: 8 bytes
 * - `salt2`: 12 bytes + 1 trailing NUL byte = 13 bytes
 * Total `auth_plugin_data_len` is 8 + 13 = 21 bytes (`chr(21)`), which must match
 * the exact byte layout expected by PHP's `mysqlnd` driver.
 */
function buildSyntheticGreeting(): string {
    $salt1    = random_bytes(8);
    $salt2    = random_bytes(12); // 12 random bytes + "\0" = 13 bytes -> total 21 bytes with $salt1
    // Capability flags: LONG_PASSWORD | FOUND_ROWS | LONG_FLAG | CONNECT_WITH_DB |
    // PROTOCOL_41 | TRANSACTIONS | SECURE_CONNECTION | PLUGIN_AUTH (0x0008a20f)
    $caps     = 0x0008a20f;
    $capsLow  = pack('v', $caps & 0xffff);
    $capsHigh = pack('v', ($caps >> 16) & 0xffff);

    $payload  = "\x0a8.0.36-ovh-relay\0";             // Protocol v10 + banner
    $payload .= pack('V', 1);                         // Connection ID
    $payload .= $salt1 . "\x00";                      // First 8 bytes of salt + filler
    $payload .= $capsLow . "\x21";                    // Lower capability flags + utf8_general_ci (0x21)
    $payload .= pack('v', 0x0002);                    // Server status: AUTOCOMMIT
    $payload .= $capsHigh . chr(21) . str_repeat("\x00", 10); // Upper caps + salt length (21) + 10 reserved zeros
    $payload .= $salt2 . "\x00mysql_native_password\0"; // Remaining 12 salt bytes + NUL + default auth plugin

    return buildMysqlPacket(0, $payload);
}

/**
 * Parses PDO's HandshakeResponse41 packet (seqId = 1) to extract `dbname` and
 * record the exact byte offset/length of the password hash inside the packet.
 *
 * Keeping the raw payload and the exact byte offset allows us later to overwrite
 * ONLY the 20-byte password hash in-place without altering any MySQL 8.4 capability
 * flags or connection attributes that PDO negotiated.
 */
function parseClientHandshakeResponse(string $packet): ?array {
    if (strlen($packet) < 36) return null;
    $payload = substr($packet, 4); // Strip 4-byte MySQL packet header
    $caps    = unpack('V', substr($payload, 0, 4))[1];
    $pos     = 32; // Skip fixed 32-byte header (caps[4] + max_packet[4] + charset[1] + reserved[23])

    // 1. Read NUL-terminated username
    $userEnd = strpos($payload, "\0", $pos);
    if ($userEnd === false) return null;
    $pos = $userEnd + 1;

    // 2. Locate the password hash field (length-prefixed when SECURE_CONNECTION or PLUGIN_AUTH_LENENC is set)
    $authOffset = $pos;
    $authLen    = 0;
    if (($caps & 0x00200000) || ($caps & 0x00008000)) {
        if ($pos >= strlen($payload)) return null;
        $authLen    = ord($payload[$pos]);
        $authOffset = $pos + 1;
        $pos        = $authOffset + $authLen;
    } else {
        $authEnd = strpos($payload, "\0", $pos);
        $pos     = ($authEnd === false) ? strlen($payload) : $authEnd + 1;
    }

    // 3. Read NUL-terminated database name if CLIENT_CONNECT_WITH_DB (0x00000008) is set
    $dbname = '';
    if (($caps & 0x00000008) && $pos < strlen($payload)) {
        $dbEnd = strpos($payload, "\0", $pos);
        if ($dbEnd !== false) {
            $dbname = substr($payload, $pos, $dbEnd - $pos);
        }
    }

    return [
        'rawPayload' => $payload,
        'authOffset' => $authOffset,
        'authLen'    => $authLen,
        'dbname'     => $dbname,
    ];
}

/**
 * Rebuilds PDO's HandshakeResponse41 packet (seqId = 1) for the real OVH MySQL server
 * by patching ONLY the 20-byte password hash in-place.
 *
 * Why patch in-place instead of rebuilding flags from scratch:
 * PDO initially hashed the user's password against our synthetic salt. Once we connect
 * to OVH and send an AuthSwitchRequest (`0xFE`) with OVH's real salt, PDO replies with
 * the password re-hashed using OVH's real salt. Substituting those 20 bytes directly
 * into PDO's original packet preserves every flag and attribute MySQL 8.4 expects.
 */
function rebuildClientHandshakeResponse(array $parsed, string $authBytes): string {
    $payload = substr($parsed['rawPayload'], 0, $parsed['authOffset'])
             . $authBytes
             . substr($parsed['rawPayload'], $parsed['authOffset'] + $parsed['authLen']);

    return buildMysqlPacket(1, $payload);
}

/**
 * Parses the real OVH MySQL server's Initial Handshake packet (seqId = 0) to extract
 * its authentication plugin name and its real 20-byte cryptographic salt (`salt1` + `salt2`).
 */
function parseServerGreeting(string $packet): ?array {
    if (strlen($packet) < 36) return null;
    $payload = substr($packet, 4);
    if (ord($payload[0]) !== 0x0a) return null; // Must be Protocol v10

    // Skip NUL-terminated server version string + 4-byte connection ID
    $verEnd = strpos($payload, "\0", 1);
    if ($verEnd === false) return null;
    $pos = $verEnd + 1 + 4;

    // Read first 8 bytes of salt + 1-byte filler
    $salt1 = substr($payload, $pos, 8);
    $pos  += 9;

    // Read lower 16 bits of capability flags + skip charset (1B) and status flags (2B)
    $capsLow = unpack('v', substr($payload, $pos, 2))[1];
    $pos    += 5;

    // Read upper 16 bits of capability flags
    $capsHigh = unpack('v', substr($payload, $pos, 2))[1];
    $caps     = $capsLow | ($capsHigh << 16);
    $pos     += 2;

    // Read total auth_plugin_data_len (if CLIENT_PLUGIN_AUTH 0x00080000 is set) + skip 10 reserved bytes
    $authLen = ($caps & 0x00080000) ? ord($payload[$pos]) : 0;
    $pos    += 11;

    // Extract the remaining 12 bytes of salt2 (excluding the trailing NUL byte so salt = 20 bytes exact)
    $salt2 = substr($payload, $pos, 12);
    $pos  += max(13, $authLen - 8);

    // Read NUL-terminated auth plugin name (e.g., `mysql_native_password`)
    $plugin = 'mysql_native_password';
    if ($caps & 0x00080000) {
        $plugEnd = strpos($payload, "\0", $pos);
        if ($plugEnd !== false) {
            $plugin = substr($payload, $pos, $plugEnd - $pos);
        }
    }

    return ['plugin' => $plugin, 'salt' => $salt1 . $salt2];
}

// ============================================================================
// 5. MAIN MULTIPLEXED EVENT LOOP
// ============================================================================
$isDynamicMode = trim((string) $FIXED_DB_HOST) === '';

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
        if ($s['db'] !== null) {
            $read[] = $s['db'];
        }
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

                if ($isDynamicMode) {
                    // Dynamic handshake state machine:
                    // 1. WAIT_CLIENT_HELLO        -> Send fake greeting (seq=0), wait for PDO's HandshakeResponse (seq=1)
                    // 2. WAIT_SERVER_GREETING     -> Connect to {$dbname}.mysql.db, read real OVH greeting (seq=0)
                    // 3. WAIT_CLIENT_SWITCH_REPLY -> Send AuthSwitchRequest (seq=2) with OVH salt, wait for PDO's reply (seq=3)
                    // 4. WAIT_SERVER_AUTH_OK      -> Send patched HandshakeResponse (seq=1) to OVH, rewrite OVH's OK (seq=2 -> seq=4)
                    // 5. BRIDGE                   -> Transparent bidirectional TCP <-> WebSocket relay
                    fwrite($dataWs, wsEncode(buildSyntheticGreeting(), 0x2));
                    $sessions[$sid] = [
                        'ws'          => $dataWs,
                        'db'          => null,
                        'wsBuf'       => '',
                        'stage'       => 'WAIT_CLIENT_HELLO',
                        'clientHello' => null,
                    ];
                } else {
                    // Fixed host mode: connect directly to OVH and bridge all bytes untouched
                    $dbSock = @stream_socket_client("tcp://{$FIXED_DB_HOST}:{$MYSQL_PORT}", $e1, $e2, 5);
                    if ($dbSock) {
                        stream_set_blocking($dbSock, false);
                        $sessions[$sid] = [
                            'ws'          => $dataWs,
                            'db'          => $dbSock,
                            'wsBuf'       => '',
                            'stage'       => 'BRIDGE',
                            'clientHello' => null,
                        ];
                    } else {
                        fclose($dataWs);
                    }
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
                tunnelLog("[$sid] WS_CLOSED stage={$s['stage']}");
                fclose($s['ws']);
                if ($s['db'] !== null) fclose($s['db']);
                unset($sessions[$sid]);
                continue;
            }
            $s['wsBuf'] .= $chunk;
            while (($frame = wsDecode($s['wsBuf'])) !== null) {
                if ($frame['opcode'] === 0x8) { // WebSocket Close frame
                    tunnelLog("[$sid] WS_OP8 stage={$s['stage']}");
                    fclose($s['ws']);
                    if ($s['db'] !== null) fclose($s['db']);
                    unset($sessions[$sid]);
                    continue 2;
                }
                if ($frame['payload'] === '') continue;

                if ($s['stage'] === 'WAIT_CLIENT_HELLO') {
                    tunnelLog("[$sid] CLIENT_HELLO len=" . strlen($frame['payload']) . " hex=" . bin2hex(substr($frame['payload'], 0, 48)));
                    $parsed = parseClientHandshakeResponse($frame['payload']);

                    // Validate dbname strictly to prevent SSRF / arbitrary host connections
                    if (!$parsed || $parsed['dbname'] === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $parsed['dbname'])) {
                        tunnelLog("[$sid] PARSE_FAIL dbname=" . ($parsed['dbname'] ?? 'NULL'));
                        $err = buildMysqlPacket(2, "\xff\x15\x04#28000Missing or invalid dbname in PDO DSN");
                        fwrite($s['ws'], wsEncode($err, 0x2));
                        fwrite($s['ws'], wsEncode('', 0x8));
                        fclose($s['ws']);
                        unset($sessions[$sid]);
                        continue 2;
                    }

                    $targetHost = $parsed['dbname'] . '.mysql.db';
                    tunnelLog("[$sid] CONNECTING {$targetHost}");
                    $dbSock = @stream_socket_client("tcp://{$targetHost}:{$MYSQL_PORT}", $e1, $e2, 5);
                    if (!$dbSock) {
                        tunnelLog("[$sid] DB_CONN_FAIL {$e1} {$e2}");
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

                } elseif ($s['stage'] === 'WAIT_CLIENT_SWITCH_REPLY') {
                    // PDO replied with AuthSwitchResponse (seq=3) containing the 20-byte hash
                    // computed against OVH's real salt. Patch it into the original HandshakeResponse
                    // (seq=1) and send it to OVH now.
                    tunnelLog("[$sid] CLIENT_SWITCH_REPLY len=" . strlen($frame['payload']) . " hex=" . bin2hex($frame['payload']));
                    $realAuthBytes = substr($frame['payload'], 4);
                    $handshakePkt  = rebuildClientHandshakeResponse($s['clientHello'], $realAuthBytes);
                    fwrite($s['db'], $handshakePkt);
                    $s['stage'] = 'WAIT_SERVER_AUTH_OK';

                } elseif ($s['stage'] === 'BRIDGE' && $s['db'] !== null) {
                    fwrite($s['db'], $frame['payload']);
                }
            }
        }

        // B2. Data arriving from OVH MySQL server
        if ($s['db'] !== null && in_array($s['db'], $read, true)) {
            $dbData = fread($s['db'], 65536);
            if ($dbData === '' || $dbData === false) {
                tunnelLog("[$sid] DB_CLOSED stage={$s['stage']}");
                fwrite($s['ws'], wsEncode('', 0x8));
                fclose($s['ws']);
                fclose($s['db']);
                unset($sessions[$sid]);
                continue;
            }

            if ($s['stage'] === 'WAIT_SERVER_GREETING') {
                $greeting = parseServerGreeting($dbData);
                tunnelLog("[$sid] SERVER_GREETING plugin=" . ($greeting['plugin'] ?? 'FAIL') . " hex=" . bin2hex(substr($dbData, 0, 64)));
                if (!$greeting) {
                    fwrite($s['ws'], wsEncode('', 0x8));
                    fclose($s['ws']);
                    fclose($s['db']);
                    unset($sessions[$sid]);
                    continue;
                }

                // Send MySQL AuthSwitchRequest (0xFE, seq=2) to PDO with OVH's real plugin and salt
                $switchPayload = "\xfe" . $greeting['plugin'] . "\0" . $greeting['salt'];
                fwrite($s['ws'], wsEncode(buildMysqlPacket(2, $switchPayload), 0x2));
                $s['stage'] = 'WAIT_CLIENT_SWITCH_REPLY';

            } elseif ($s['stage'] === 'WAIT_SERVER_AUTH_OK') {
                // OVH sees a standard 2-packet handshake (seq=0 greeting -> seq=1 client hello)
                // and therefore tags its OK/ERR response with seq=2.
                // PDO, however, went through AuthSwitch (seq=2 switch -> seq=3 reply) and expects
                // the final OK/ERR packet to have seq=4. Rewrite byte 3 (seqId) from 0x02 to 0x04.
                tunnelLog("[$sid] SERVER_AUTH_OK len=" . strlen($dbData) . " hex=" . bin2hex($dbData));
                if (strlen($dbData) >= 4) {
                    $dbData[3] = "\x04";
                }
                fwrite($s['ws'], wsEncode($dbData, 0x2));
                $s['stage'] = 'BRIDGE';

            } elseif ($s['stage'] === 'BRIDGE') {
                fwrite($s['ws'], wsEncode($dbData, 0x2));
            }
        }
    }
    unset($s);
}

tunnelLog("WORKER_END pid=" . getmypid());

// Clean up all open sockets and release the file lock before exiting
foreach ($sessions as $s) {
    @fclose($s['ws']);
    if ($s['db'] !== null) @fclose($s['db']);
}
@fclose($controlWs);
flock($lockFp, LOCK_UN);
fclose($lockFp);
