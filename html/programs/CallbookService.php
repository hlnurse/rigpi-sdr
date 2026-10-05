<?php
/** Configure and test a RigPi user's QRZ or HamQTH service. */
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
    || ($_SERVER['HTTP_X_RIGPI_ACTION'] ?? '') !== 'callbook-service') {
    http_response_code(403);
    echo json_encode(['error' => 'The callbook service request is not authorized.']);
    exit;
}
$fetchSite = strtolower((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
if ($fetchSite !== '' && $fetchSite !== 'same-origin') {
    http_response_code(403);
    echo json_encode(['error' => 'Callbook service changes must originate from this RigPi.']);
    exit;
}

require $root . '/programs/sqldata.php';
require_once $root . '/classes/MysqliDb.php';
require_once $root . '/programs/GetUserFieldFunc.php';
$request = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($request)) {
    http_response_code(400);
    echo json_encode(['error' => 'The request is invalid.']);
    exit;
}
$provider = strtolower(trim((string) ($request['provider'] ?? '')));
$action = strtolower(trim((string) ($request['action'] ?? 'status')));
$targetId = filter_var($request['user_id'] ?? 0, FILTER_VALIDATE_INT);
if (!in_array($provider, ['qrz', 'hamqth'], true)
    || !in_array($action, ['status', 'save', 'test', 'disconnect'], true)
    || !$targetId || $targetId < 1) {
    http_response_code(400);
    echo json_encode(['error' => 'The callbook service request is invalid.']);
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
    echo json_encode(['error' => 'You may configure only your own callbook services.']);
    exit;
}

$userField = $provider === 'qrz' ? 'qrzUser' : 'hamqthUser';
$passwordField = $provider === 'qrz' ? 'qrzPWD' : 'hamqthPWD';
$configured = trim((string) ($target[$userField] ?? '')) !== ''
    && trim((string) ($target[$passwordField] ?? '')) !== '';
if ($action === 'status') {
    echo json_encode(['status' => $configured ? 'configured' : 'disconnected']);
    exit;
}
if ($action === 'disconnect') {
    $values = [$userField => '', $passwordField => ''];
    if ($provider === 'qrz') $values['qrzKey'] = '';
    $db->where('uID', $targetId);
    if (!$db->update('Users', $values)) {
        http_response_code(500);
        echo json_encode(['error' => 'RigPi could not clear the callbook credentials.']);
        exit;
    }
    echo json_encode(['status' => 'disconnected']);
    exit;
}

$username = trim((string) ($request['username'] ?? ''));
$password = (string) ($request['password'] ?? '');
if ($username === '' || $password === '' || strlen($username) > 100 || strlen($password) > 200
    || preg_match('/[\x00-\x1F\x7F]/', $username . $password)) {
    http_response_code(400);
    echo json_encode(['error' => 'Enter a valid username and password.']);
    exit;
}
if ($action === 'save') {
    $values = [$userField => $username, $passwordField => $password];
    if ($provider === 'qrz') $values['qrzKey'] = '';
    $db->where('uID', $targetId);
    if (!$db->update('Users', $values)) {
        http_response_code(500);
        echo json_encode(['error' => 'RigPi could not save the callbook credentials.']);
        exit;
    }
    echo json_encode(['status' => 'configured']);
    exit;
}

$context = stream_context_create(['http' => ['timeout' => 15, 'ignore_errors' => true]]);
if ($provider === 'qrz') {
    $context = stream_context_create(['http' => [
        'timeout' => 15,
        'ignore_errors' => true,
        'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
        'method' => 'POST',
        'content' => http_build_query([
            'username' => $username, 'password' => $password, 'agent' => 'RigPi_Elmer_Settings'
        ]),
    ]]);
    $response = @file_get_contents('https://xmldata.qrz.com/xml/current', false, $context);
    $xml = $response !== false ? @simplexml_load_string($response) : false;
    $key = $xml !== false ? trim((string) ($xml->Session->Key ?? '')) : '';
    if ($key === '') {
        $error = $xml !== false ? trim((string) ($xml->Session->Error ?? '')) : '';
        http_response_code(502);
        echo json_encode(['error' => $error ?: 'QRZ did not accept the credentials or could not be reached.']);
        exit;
    }
    echo json_encode(['status' => 'connected', 'message' => 'QRZ XML authentication succeeded.']);
    exit;
}

$url = 'https://www.hamqth.com/xml.php?u=' . rawurlencode($username)
    . '&p=' . rawurlencode($password) . '&prg=RigPi';
$response = @file_get_contents($url, false, $context);
$sessionId = '';
if ($response !== false && preg_match('/<session_id>([^<]+)<\/session_id>/', $response, $match)) {
    $sessionId = trim($match[1]);
}
if ($sessionId === '') {
    http_response_code(502);
    echo json_encode(['error' => 'HamQTH did not accept the credentials or could not be reached.']);
    exit;
}
echo json_encode(['status' => 'connected', 'message' => 'HamQTH authentication succeeded.']);
