<?php
/** Authenticated bridge between the RigPi browser and local HackRF TX. */
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$root = '/var/www/html';
if (empty($_SESSION['myUsername'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Please sign in to RigPi.']);
    exit;
}
if (($_SERVER['HTTP_X_RIGPI_ACTION'] ?? '') !== 'hackrf-tx') {
    http_response_code(403);
    echo json_encode(['error' => 'The HackRF transmit safety header is missing.']);
    exit;
}
$fetchSite = strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
if ($fetchSite !== '' && $fetchSite !== 'same-origin') {
    http_response_code(403);
    echo json_encode(['error' => 'HackRF transmit must originate from this RigPi.']);
    exit;
}

require $root . '/programs/sqldata.php';

try {
    $request = json_decode(file_get_contents('php://input'), true, 16, JSON_THROW_ON_ERROR);
    $username = (string)$_SESSION['myUsername'];
    $db = new mysqli('localhost', $sql_radio_username, $sql_radio_password, $sql_radio_database);
    if ($db->connect_errno) throw new RuntimeException('The RigPi database is unavailable.');
    $stmt = $db->prepare('SELECT Access_Level, DeadMan, SDRHost FROM Users WHERE Username=? LIMIT 1');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    if (!$user) throw new RuntimeException('The signed-in RigPi account was not found.');
    $accessLevel = (int)$user['Access_Level'];
    if ($accessLevel >= 4) {
        http_response_code(403);
        throw new RuntimeException('This RigPi account is not permitted to transmit.');
    }

    $sdrPath = (string)(parse_url((string)$user['SDRHost'], PHP_URL_PATH) ?? '');
    if (!preg_match('#/sdr([1-8])?/?$#', $sdrPath, $match)) {
        throw new RuntimeException('This account does not have a valid local SDR assignment.');
    }
    $sdrNumber = isset($match[1]) && $match[1] !== '' ? (int)$match[1] : 1;

    $deadManMinutes = max(0, (int)$user['DeadMan']);
    // A zero RigPi Deadman value historically means unlimited. HackRF keeps a
    // non-bypassable one-hour ceiling in addition to its browser heartbeat.
    $maximumSeconds = $deadManMinutes > 0
        ? max(30, min(3600, $deadManMinutes * 60)) : 3600;
    $secretPath = '/etc/rigpi/hackrf_tx_secret';
    $secret = is_readable($secretPath) ? trim((string)file_get_contents($secretPath)) : '';
    if ($secret === '') throw new RuntimeException('HackRF transmit has not been provisioned.');

    $action = strtolower(trim((string)($request['action'] ?? 'status')));
    $endpoint = $action === 'offer' ? 'offer' : 'control';
    if (!in_array($action, ['offer', 'stop', 'heartbeat', 'status'], true)) {
        throw new InvalidArgumentException('The HackRF transmit action is invalid.');
    }
    $payload = $request;
    $payload['maximum_seconds'] = $maximumSeconds;
    if ($action !== 'offer') $payload = ['action' => $action];
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $port = 8000 + $sdrNumber;
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\n" .
                    "X-RigPi-TX-Bridge: {$secret}\r\n",
        'content' => $json,
        'timeout' => $action === 'offer' ? 25 : 3,
        'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents("http://127.0.0.1:{$port}/api/hackrf_tx/{$endpoint}", false, $context);
    if (!is_string($raw) || $raw === '') throw new RuntimeException('The HackRF transmit service did not respond.');
    $status = 500;
    foreach (($http_response_header ?? []) as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $match)) $status = (int)$match[1];
    }
    http_response_code($status);
    echo $raw;
} catch (Throwable $error) {
    if (http_response_code() < 400) http_response_code(400);
    echo json_encode(['error' => $error->getMessage()]);
}
