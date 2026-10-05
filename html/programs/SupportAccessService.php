<?php
/** Temporarily authorize an administrator-supplied SSH support key. */
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$root = '/var/www/html';
if (empty($_SESSION['myUsername'])) {
    http_response_code(401); echo json_encode(['error' => 'Please sign in to RigPi.']); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || ($_SERVER['HTTP_X_RIGPI_ACTION'] ?? '') !== 'support-access') {
    http_response_code(403); echo json_encode(['error' => 'The support-access request is not authorized.']); exit;
}
$fetchSite = strtolower((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
if ($fetchSite !== '' && $fetchSite !== 'same-origin') {
    http_response_code(403); echo json_encode(['error' => 'Support access must be changed from this RigPi.']); exit;
}
require_once $root . '/programs/GetUserFieldFunc.php';
if ((int) getUserField((string) $_SESSION['myUsername'], 'Access_Level') !== 1) {
    http_response_code(403); echo json_encode(['error' => 'Only a RigPi administrator may enable support access.']); exit;
}
$request = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($request)) $request = [];
$action = strtolower(trim((string) ($request['action'] ?? 'status')));
if (!in_array($action, ['status', 'enable', 'revoke'], true)) {
    http_response_code(400); echo json_encode(['error' => 'The support-access request is invalid.']); exit;
}
$duration = (int) ($request['hours'] ?? 4);
if ($action === 'enable' && !in_array($duration, [1, 4, 24, 72], true)) {
    http_response_code(400); echo json_encode(['error' => 'Choose a valid support window.']); exit;
}
$connectionMode = strtolower(trim((string) ($request['connection_mode'] ?? 'quick')));
if ($action === 'enable' && !in_array($connectionMode, ['quick', 'existing'], true)) {
    http_response_code(400); echo json_encode(['error' => 'Choose a valid remote-support connection.']); exit;
}
$key = trim((string) ($request['public_key'] ?? ''));
if ($action === 'enable' && (strlen($key) < 40 || strlen($key) > 1200
    || !preg_match('/^ssh-ed25519\s+[A-Za-z0-9+\/=]+(?:\s+.*)?$/', $key))) {
    http_response_code(400); echo json_encode(['error' => 'Paste a valid Ed25519 SSH public key.']); exit;
}

$command = ['/usr/bin/sudo', '-n', '/usr/local/sbin/rigpi-support-access', $action];
if ($action === 'enable') {
    $command[] = (string) $duration;
    $command[] = $connectionMode;
}
$pipes = [];
$process = proc_open($command, [['pipe','r'], ['pipe','w'], ['pipe','w']], $pipes);
if (!is_resource($process)) {
    http_response_code(500); echo json_encode(['error' => 'Unable to start the support-access helper.']); exit;
}
if ($action === 'enable') fwrite($pipes[0], $key . "\n");
fclose($pipes[0]);
$output = trim(stream_get_contents($pipes[1])); fclose($pipes[1]);
$error = trim(stream_get_contents($pipes[2])); fclose($pipes[2]);
$code = proc_close($process);
$key = '';
if ($code !== 0) {
    http_response_code(500); echo json_encode(['error' => $error ?: 'RigPi could not change support access.']); exit;
}
$status = json_decode($output ?: '{}', true);
if (!is_array($status)) $status = [];
$status['message'] = $action === 'enable'
    ? 'Temporary remote support access is enabled.'
    : ($action === 'revoke' ? 'Remote support access was revoked.' : '');
echo json_encode($status);
