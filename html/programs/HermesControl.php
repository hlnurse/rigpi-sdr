<?php
/** Session-authenticated Hermes panel bridge for the assigned local instance. */
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Use POST for Hermes controls.']);
    exit;
}
if (empty($_SESSION['myUsername'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Please sign in to RigPi.']);
    exit;
}
if (($_SERVER['HTTP_X_RIGPI_ACTION'] ?? '') !== 'hermes-control') {
    http_response_code(403);
    echo json_encode(['error' => 'The Hermes control header is missing.']);
    exit;
}
$fetchSite = strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
if ($fetchSite !== '' && $fetchSite !== 'same-origin') {
    http_response_code(403);
    echo json_encode(['error' => 'Hermes controls must originate from this RigPi.']);
    exit;
}
require '/var/www/html/programs/sqldata.php';
try {
    $request = json_decode(file_get_contents('php://input'), true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($request)) throw new InvalidArgumentException('Invalid Hermes request.');
    $action = strtolower(trim((string)($request['action'] ?? 'status')));
    if (!in_array($action, ['status', 'settings', 'stop'], true)) {
        throw new InvalidArgumentException('Invalid Hermes action.');
    }
    $username = (string)$_SESSION['myUsername'];
    $db = new mysqli('localhost', $sql_radio_username, $sql_radio_password, $sql_radio_database);
    if ($db->connect_errno) throw new RuntimeException('The RigPi database is unavailable.');
    $stmt = $db->prepare('SELECT Access_Level, DeadMan, SDRHost FROM Users WHERE Username=? LIMIT 1');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    if (!$user) throw new RuntimeException('The signed-in account was not found.');
    $accessLevel = (int)$user['Access_Level'];
    if ($action === 'settings' && $accessLevel !== 1) {
        http_response_code(403);
        throw new RuntimeException('Administrator access is required to change Hermes settings.');
    }
    if ($action === 'stop' && $accessLevel >= 4) {
        http_response_code(403);
        throw new RuntimeException('This account cannot control transmission.');
    }
    $sdrPath = (string)(parse_url((string)$user['SDRHost'], PHP_URL_PATH) ?? '');
    if (!preg_match('#/sdr([1-8])?/?$#', $sdrPath, $match)) {
        throw new RuntimeException('This account needs a local SDR assignment.');
    }
    $number = isset($match[1]) && $match[1] !== '' ? (int)$match[1] : 1;
    $assignedPort = 8000 + $number;
    $port = filter_var($request['instance_port'] ?? $assignedPort, FILTER_VALIDATE_INT);
    if ($port === false || $port < 8001 || $port > 8008) {
        throw new InvalidArgumentException('Invalid local SDR instance.');
    }
    if ($port !== $assignedPort && $accessLevel !== 1) {
        http_response_code(403);
        throw new RuntimeException('This account can control only its assigned SDR instance.');
    }
    $secretPath = '/etc/rigpi/hackrf_tx_secret';
    $secret = is_readable($secretPath) ? trim((string)file_get_contents($secretPath)) : '';
    if ($secret === '') throw new RuntimeException('The SDR transmit bridge is not provisioned.');
    $payload = ['action' => $action];
    if ($action === 'settings') $payload['settings'] = $request['settings'] ?? null;
    $newline = chr(13) . chr(10);
    $headers = 'Content-Type: application/json' . $newline .
        'X-RigPi-TX-Bridge: ' . $secret . $newline .
        'X-RigPi-Hermes-Admin: ' . ($accessLevel === 1 ? '1' : '0') . $newline;
    $context = stream_context_create(['http' => [
        'method' => 'POST', 'header' => $headers,
        'content' => json_encode($payload, JSON_THROW_ON_ERROR),
        'timeout' => 5, 'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents('http://127.0.0.1:' . $port . '/api/hermes/panel', false, $context);
    if (!is_string($raw) || $raw === '') throw new RuntimeException('The assigned SDR did not respond.');
    $status = 500;
    foreach (($http_response_header ?? []) as $header) {
        if (preg_match('#^HTTP/[^ ]+ +([0-9]+)#', $header, $match)) $status = (int)$match[1];
    }
    $reply = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($reply)) throw new RuntimeException('The SDR returned an invalid response.');
    $minutes = max(0, (int)$user['DeadMan']);
    $reply['can_configure'] = $accessLevel === 1;
    $reply['can_transmit'] = $accessLevel < 4;
    $reply['maximum_seconds'] = $minutes > 0 ? max(30, min(3600, $minutes * 60)) : 3600;
    http_response_code($status);
    echo json_encode($reply, JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    if (http_response_code() < 400) http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $error->getMessage()]);
}
