<?php
/** Small, authenticated, read-only PTT sample for Elmer's audible state cues. */
function elmerFastPttReadback(int $port): ?bool {
    $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $error, $message, 0.08, STREAM_CLIENT_CONNECT);
    if ($socket === false) return null;
    stream_set_timeout($socket, 0, 120000);
    try {
        // Lowercase t reads PTT. This endpoint never sends a transmit command.
        if (@fwrite($socket, "t\n") !== 2) return null;
        $line = @fgets($socket, 64);
        $value = is_string($line) ? trim($line) : '';
        return $value === '1' ? true : ($value === '0' ? false : null);
    } finally { fclose($socket); }
}

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if (empty($_SESSION['myUsername'])) {
    http_response_code(401); echo json_encode(['error' => 'Please sign in to RigPi.']); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_SERVER['HTTP_X_ELMER_ACTION'] ?? '') !== 'rig-control') {
    http_response_code(403); echo json_encode(['error' => 'The RigPi status request is invalid.']); exit;
}
$fetchSite = strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
if ($fetchSite !== '' && $fetchSite !== 'same-origin') {
    http_response_code(403); echo json_encode(['error' => 'Status must be read from this RigPi.']); exit;
}
$username = (string)$_SESSION['myUsername'];
$radio = (int)($_SESSION['myRadio'] ?? 0);
session_write_close();
ini_set('display_errors', '0'); ini_set('log_errors', '1');
try {
    $request = json_decode(file_get_contents('php://input') ?: '{}', true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($request) || !is_int($request['radio'] ?? null) || $request['radio'] !== $radio || $radio < 1 || $radio > 4) {
        throw new InvalidArgumentException('Select that radio in RigPi before reading its PTT state.');
    }
    require '/var/www/html/programs/sqldata.php';
    $db = new mysqli('localhost', $sql_radio_username, $sql_radio_password, $sql_radio_database);
    if ($db->connect_errno) throw new RuntimeException('The RigPi database is unavailable.');
    $stmt = $db->prepare('SELECT Access_Level FROM Users WHERE Username=? LIMIT 1');
    $stmt->bind_param('s', $username); $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    if (!$user || !in_array((int)$user['Access_Level'], [1,2,3,4], true)) {
        http_response_code(403); echo json_encode(['error' => 'Your account cannot read radio status.']); exit;
    }
    $stmt->close(); $db->close();
    echo json_encode(['radio' => $radio, 'transmitting' => elmerFastPttReadback(4530 + 2 * $radio)]);
} catch (InvalidArgumentException|JsonException $error) {
    http_response_code(400); echo json_encode(['error' => 'The radio status request is invalid.']);
} catch (Throwable $error) {
    http_response_code(503); echo json_encode(['error' => 'Radio PTT readback is unavailable.']);
}
