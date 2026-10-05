<?php
/** Manage the local Cloudflare Tunnel connector for RigPi. */
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
    || ($_SERVER['HTTP_X_RIGPI_ACTION'] ?? '') !== 'cloudflare-service') {
    http_response_code(403);
    echo json_encode(['error' => 'The Cloudflare service request is not authorized.']);
    exit;
}
$fetchSite = strtolower((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
if ($fetchSite !== '' && $fetchSite !== 'same-origin') {
    http_response_code(403);
    echo json_encode(['error' => 'Cloudflare changes must originate from this RigPi.']);
    exit;
}

require $root . '/programs/sqldata.php';
require_once $root . '/classes/MysqliDb.php';
require_once $root . '/programs/GetUserFieldFunc.php';
$signedIn = (string) $_SESSION['myUsername'];
if ((int) getUserField($signedIn, 'Access_Level') !== 1) {
    http_response_code(403);
    echo json_encode(['error' => 'Only a RigPi administrator may configure Cloudflare Tunnel.']);
    exit;
}
$request = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($request)) $request = [];
$action = strtolower(trim((string) ($request['action'] ?? 'status')));
if (!in_array($action, ['status', 'connect', 'restart', 'disconnect'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'The Cloudflare service request is invalid.']);
    exit;
}

function commandResult(array $command, string $input = ''): array
{
    $pipes = [];
    $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    if (!is_resource($process)) return [127, '', 'Unable to start the Cloudflare service helper.'];
    if ($input !== '') fwrite($pipes[0], $input);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    return [proc_close($process), trim($stdout), trim($stderr)];
}
function serviceActive(): bool
{
    [$code] = commandResult(['/usr/bin/systemctl', 'is-active', '--quiet', 'cloudflared.service']);
    return $code === 0;
}
function cloudflareStatus(string $root, string $username): array
{
    $installed = is_executable('/usr/local/bin/cloudflared') || is_executable('/usr/bin/cloudflared');
    $active = $installed && serviceActive();
    $version = '';
    if ($installed) {
        $binary = is_executable('/usr/local/bin/cloudflared') ? '/usr/local/bin/cloudflared' : '/usr/bin/cloudflared';
        [, $output] = commandResult([$binary, '--version']);
        if (preg_match('/version\s+([^\s]+)/i', $output, $match)) $version = $match[1];
    }
    require $root . '/programs/sqldata.php';
    $db = new MysqliDb('localhost', $sql_radio_username, $sql_radio_password, $sql_radio_database);
    $db->where('Username', $username);
    $user = $db->getOne('Users', ['SDRHostRemote']);
    return [
        'status' => $active ? 'connected' : 'disconnected',
        'installed' => $installed,
        'active' => $active,
        'version' => $version,
        'remote_url' => trim((string) ($user['SDRHostRemote'] ?? '')),
    ];
}

if ($action === 'status') {
    echo json_encode(cloudflareStatus($root, $signedIn));
    exit;
}
if ($action === 'connect') {
    $token = trim((string) ($request['token'] ?? ''));
    if (strlen($token) < 40 || strlen($token) > 4096
        || !preg_match('/^[A-Za-z0-9._~+\/=-]+$/', $token)) {
        http_response_code(400);
        echo json_encode(['error' => 'Paste only the Cloudflare connector token, not the entire installation command.']);
        exit;
    }
    [$code, , $error] = commandResult(
        ['/usr/bin/sudo', '-n', '/usr/local/sbin/rigpi-cloudflare-service', 'connect'],
        $token . "\n"
    );
    $token = '';
    if ($code !== 0) {
        http_response_code(500);
        echo json_encode(['error' => $error ?: 'RigPi could not start the Cloudflare connector.']);
        exit;
    }
    usleep(700000);
    $status = cloudflareStatus($root, $signedIn);
    $status['message'] = $status['active']
        ? 'Cloudflare Tunnel connected.'
        : 'The connector was installed but has not reached Cloudflare yet. Check again in a few seconds.';
    echo json_encode($status);
    exit;
}

[$code, , $error] = commandResult(
    ['/usr/bin/sudo', '-n', '/usr/local/sbin/rigpi-cloudflare-service', $action]
);
if ($code !== 0) {
    http_response_code(500);
    echo json_encode(['error' => $error ?: 'RigPi could not change the Cloudflare connector.']);
    exit;
}
if ($action === 'restart') usleep(700000);
$status = cloudflareStatus($root, $signedIn);
$status['message'] = $action === 'disconnect'
    ? 'Cloudflare Tunnel disconnected and its local token removed.'
    : ($status['active'] ? 'Cloudflare Tunnel restarted.' : 'The connector is still starting.');
echo json_encode($status);

