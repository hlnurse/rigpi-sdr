<?php
/** Bridge to the local Elmer pairing client.
 *
 * Any signed-in account may read connection status. Only an administrator may
 * start, pair, or disconnect the station service.
 */
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
require_once $root . '/programs/GetUserFieldFunc.php';
$accessLevel = (int) getUserField($_SESSION['myUsername'], 'Access_Level');
if (($_SERVER['HTTP_X_ELMER_ACTION'] ?? '') !== 'service-config') {
    http_response_code(403);
    echo json_encode(['error' => 'The Elmer service request is missing its safety header.']);
    exit;
}
$fetchSite = strtolower((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
if ($fetchSite !== '' && $fetchSite !== 'same-origin') {
    http_response_code(403);
    echo json_encode(['error' => 'Elmer service changes must originate from this RigPi.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST is required.']);
    exit;
}
$raw = file_get_contents('php://input');
$request = json_decode($raw ?: '{}', true);
if (!is_array($request)) {
    http_response_code(400);
    echo json_encode(['error' => 'The request is invalid.']);
    exit;
}
$action = (string) ($request['action'] ?? 'status');
if (!in_array($action, ['status', 'start', 'disconnect'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'The requested action is not supported.']);
    exit;
}
if ($action !== 'status' && $accessLevel !== 1) {
    http_response_code(403);
    echo json_encode(['error' => 'RigPi administrator access is required.']);
    exit;
}
$command = ['/usr/bin/sudo', '-n', '/usr/local/sbin/elmer-service-web', $action];
if ($action === 'start') {
    $stationName = trim((string) ($request['station_name'] ?? ''));
    $callsign = strtoupper(trim((string) ($request['callsign'] ?? '')));
    $contactEmail = strtolower(trim((string) ($request['contact_email'] ?? '')));
    $serviceAlerts = !empty($request['service_alerts']) ? '1' : '0';
    $productUpdates = !empty($request['product_updates']) ? '1' : '0';
    if ($stationName === '' || strlen($stationName) > 100 || preg_match('/[\x00-\x1F\x7F]/', $stationName)) {
        http_response_code(400);
        echo json_encode(['error' => 'Enter a valid station name.']);
        exit;
    }
    if (!preg_match('/^[A-Z0-9]{1,3}[A-Z0-9\/]{1,9}$/', $callsign)) {
        http_response_code(400); echo json_encode(['error' => 'Enter a valid amateur-radio callsign.']); exit;
    }
    if (!filter_var($contactEmail, FILTER_VALIDATE_EMAIL) || strlen($contactEmail) > 254) {
        http_response_code(400); echo json_encode(['error' => 'Enter a valid email address.']); exit;
    }
    $command[] = $stationName;
    $command[] = $callsign;
    $command[] = $contactEmail;
    $command[] = $serviceAlerts;
    $command[] = $productUpdates;
}
$pipes = [];
$process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) {
    http_response_code(500);
    echo json_encode(['error' => 'RigPi could not open the Elmer service manager.']);
    exit;
}
$output = stream_get_contents($pipes[1]);
$errors = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($process);
$result = json_decode($output, true);
if (!is_array($result)) {
    http_response_code(500);
    echo json_encode(['error' => trim($errors) ?: 'The Elmer service manager returned an invalid response.']);
    exit;
}
if ($exitCode !== 0 && ($result['status'] ?? '') !== 'disconnected') {
    http_response_code(502);
}
echo json_encode($result, JSON_UNESCAPED_SLASHES);
