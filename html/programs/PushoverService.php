<?php
/** Configure and test Pushover notifications for a RigPi user. */
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
if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || ($_SERVER['HTTP_X_RIGPI_ACTION'] ?? '') !== 'pushover-service') {
    http_response_code(403);
    echo json_encode(['error' => 'The Pushover service request is not authorized.']);
    exit;
}
$fetchSite = strtolower((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
if ($fetchSite !== '' && $fetchSite !== 'same-origin') {
    http_response_code(403);
    echo json_encode(['error' => 'Pushover changes must originate from this RigPi.']);
    exit;
}

require $root . '/programs/sqldata.php';
require_once $root . '/classes/MysqliDb.php';
require_once $root . '/programs/GetUserFieldFunc.php';
$request = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($request)) $request = [];
$action = strtolower(trim((string) ($request['action'] ?? 'status')));
$targetId = filter_var($request['user_id'] ?? 0, FILTER_VALIDATE_INT);
if (!in_array($action, ['status', 'save', 'test', 'disconnect'], true)
    || !$targetId || $targetId < 1) {
    http_response_code(400);
    echo json_encode(['error' => 'The Pushover service request is invalid.']);
    exit;
}

$db = new MysqliDb('localhost', $sql_radio_username, $sql_radio_password, $sql_radio_database);
$db->where('uID', $targetId);
$target = $db->getOne('Users');
if (!$target) {
    http_response_code(404);
    echo json_encode(['error' => 'The RigPi user was not found.']);
    exit;
}
$signedIn = (string) $_SESSION['myUsername'];
$signedInLevel = (int) getUserField($signedIn, 'Access_Level');
if ($signedInLevel !== 1 && strcasecmp((string) $target['Username'], $signedIn) !== 0) {
    http_response_code(403);
    echo json_encode(['error' => 'You may configure only your own Pushover notifications.']);
    exit;
}

$db->where('uID', 1);
$admin = $db->getOne('Users', ['PushoverToken']);
$adminToken = trim((string) ($admin['PushoverToken'] ?? ''));
$savedToken = trim((string) ($target['PushoverToken'] ?? ''));
$savedUser = trim((string) ($target['PushoverUser'] ?? ''));
$effectiveToken = $targetId === 1 ? $savedToken : $adminToken;
$configured = $effectiveToken !== '' && $savedUser !== '';

if ($action === 'status') {
    echo json_encode([
        'status' => $configured ? 'configured' : 'disconnected',
        'application_configured' => $effectiveToken !== '',
        'user_configured' => $savedUser !== '',
        'notify' => (string) ($target['PushoverNotify'] ?? 'none'),
        'delay' => (int) ($target['PushoverDelay'] ?? 60),
    ]);
    exit;
}
if ($action === 'disconnect') {
    $values = [
        'PushoverToken' => '',
        'PushoverUser' => '',
        'PushoverNotify' => 'none',
        'PushoverDelay' => 60,
    ];
    $db->where('uID', $targetId);
    if (!$db->update('Users', $values)) {
        http_response_code(500);
        echo json_encode(['error' => 'RigPi could not clear the Pushover configuration.']);
        exit;
    }
    echo json_encode(['status' => 'disconnected']);
    exit;
}

$token = trim((string) ($request['token'] ?? ''));
$user = trim((string) ($request['user'] ?? ''));
$notify = strtolower(trim((string) ($request['notify'] ?? 'none')));
$delay = filter_var($request['delay'] ?? 60, FILTER_VALIDATE_INT);
$allowedNotify = ['none', 'login', 'logout', 'both', 'spots', 'both+spots', 'login+spots', 'logout+spots'];
if ($targetId !== 1) $token = $adminToken;
if (!preg_match('/^[A-Za-z0-9]{20,80}$/', $token)
    || !preg_match('/^[A-Za-z0-9]{20,80}$/', $user)
    || !in_array($notify, $allowedNotify, true)
    || $delay === false || $delay < 0 || $delay > 86400) {
    http_response_code(400);
    echo json_encode(['error' => $targetId === 1
        ? 'Enter a valid Pushover application token and user key.'
        : 'Enter a valid Pushover user key. The administrator must configure the station application token first.']);
    exit;
}

if ($action === 'save') {
    $values = [
        'PushoverUser' => $user,
        'PushoverNotify' => $notify,
        'PushoverDelay' => $delay,
    ];
    if ($targetId === 1) $values['PushoverToken'] = $token;
    else $values['PushoverToken'] = '';
    $db->where('uID', $targetId);
    if (!$db->update('Users', $values)) {
        http_response_code(500);
        echo json_encode(['error' => 'RigPi could not save the Pushover configuration.']);
        exit;
    }
    echo json_encode(['status' => 'configured', 'message' => 'Pushover configuration saved.']);
    exit;
}

$ch = curl_init('https://api.pushover.net/1/messages.json');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'token' => $token,
        'user' => $user,
        'title' => 'RigPi Test',
        'message' => 'Pushover notification is working!',
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 12,
]);
$response = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);
if ($httpCode !== 200) {
    $body = json_decode((string) $response, true);
    $errors = isset($body['errors']) && is_array($body['errors']) ? implode(' ', $body['errors']) : '';
    http_response_code(502);
    echo json_encode(['error' => $errors ?: ($curlError ?: 'Pushover rejected the test notification.')]);
    exit;
}
echo json_encode(['status' => 'connected', 'message' => 'Test notification sent successfully.']);
