<?php
/** Manage RigPi native HTTPS through DuckDNS and Let's Encrypt. */
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
    || ($_SERVER['HTTP_X_RIGPI_ACTION'] ?? '') !== 'duckdns-service') {
    http_response_code(403);
    echo json_encode(['error' => 'The native HTTPS request is not authorized.']);
    exit;
}
$fetchSite = strtolower((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
if ($fetchSite !== '' && $fetchSite !== 'same-origin') {
    http_response_code(403);
    echo json_encode(['error' => 'Native HTTPS changes must originate from this RigPi.']);
    exit;
}

require $root . '/programs/sqldata.php';
require_once $root . '/classes/MysqliDb.php';
require_once $root . '/programs/GetUserFieldFunc.php';
$signedIn = (string) $_SESSION['myUsername'];
if ((int) getUserField($signedIn, 'Access_Level') !== 1) {
    http_response_code(403);
    echo json_encode(['error' => 'Only a RigPi administrator may configure native HTTPS.']);
    exit;
}

$request = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($request)) $request = [];
$action = strtolower(trim((string) ($request['action'] ?? 'status')));
$allowed = ['status', 'provision', 'set_token', 'update_ip', 'renew', 'enable', 'disable', 'remove'];
if (!in_array($action, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'The native HTTPS service request is invalid.']);
    exit;
}

function commandResult(array $command, string $input = ''): array
{
    $pipes = [];
    $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    if (!is_resource($process)) return [127, '', 'Unable to start the native HTTPS helper.'];
    if ($input !== '') fwrite($pipes[0], $input);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    return [proc_close($process), trim($stdout), trim($stderr)];
}

function httpsStatus(): array
{
    [$code, $output, $error] = commandResult(
        ['/usr/bin/sudo', '-n', '/usr/local/sbin/rigpi-duckdns-https', 'status']
    );
    if ($code !== 0) throw new RuntimeException($error ?: $output ?: 'Native HTTPS status is unavailable.');
    $values = [];
    foreach (preg_split('/\R/', $output) as $line) {
        if (preg_match('/^([^:]+):\s*(.*)$/', $line, $match)) {
            $values[strtolower(str_replace(' ', '_', trim($match[1])))] = trim($match[2]);
        }
    }
    $configured = ($values['configured'] ?? 'no') === 'yes';
    $enabled = ($values['https_enabled'] ?? 'no') === 'yes';
    $hostname = ($values['hostname'] ?? 'none') !== 'none' ? ($values['hostname'] ?? '') : '';
    return [
        'status' => $enabled ? 'connected' : ($configured ? 'configured' : 'disconnected'),
        'configured' => $configured,
        'enabled' => $enabled,
        'hostname' => $hostname,
        'remote_url' => $hostname !== '' ? 'https://' . $hostname . '/' : '',
        'dns_address' => $values['dns_address'] ?? '',
        'timer' => $values['ip_update_timer'] ?? 'inactive',
        'certificate_expires' => $values['certificate_expires'] ?? '',
    ];
}

function setDuckDNSRemoteUrls(string $hostname): void
{
    global $sql_radio_username, $sql_radio_password, $sql_radio_database;
    if (!preg_match('/^[a-z0-9][a-z0-9-]{0,62}\.duckdns\.org$/', $hostname)) {
        throw new RuntimeException('The DuckDNS hostname is invalid.');
    }
    $db = new MysqliDb('localhost', $sql_radio_username, $sql_radio_password, $sql_radio_database);
    $users = $db->get('Users', null, ['uID', 'SDRHostRemote']);
    foreach ($users as $user) {
        $id = (int) ($user['uID'] ?? 0);
        if ($id < 1) continue;
        $saved = trim((string) ($user['SDRHostRemote'] ?? ''));
        if ($saved !== '' && !preg_match('#^https://[a-z0-9-]+\.duckdns\.org/sdr\d+/?$#i', $saved)) continue;
        $db->where('uID', $id);
        if (!$db->update('Users', ['SDRHostRemote' => 'https://' . $hostname . '/sdr' . $id])) {
            throw new RuntimeException('RigPi could not update the accounts’ remote SDR addresses.');
        }
    }
}

function clearDuckDNSRemoteUrls(string $hostname): void
{
    global $sql_radio_username, $sql_radio_password, $sql_radio_database;
    if ($hostname === '') return;
    $prefix = 'https://' . $hostname . '/sdr';
    $db = new MysqliDb('localhost', $sql_radio_username, $sql_radio_password, $sql_radio_database);
    $users = $db->get('Users', null, ['uID', 'SDRHostRemote']);
    foreach ($users as $user) {
        $saved = trim((string) ($user['SDRHostRemote'] ?? ''));
        if (strpos($saved, $prefix) !== 0) continue;
        $db->where('uID', (int) $user['uID']);
        if (!$db->update('Users', ['SDRHostRemote' => ''])) {
            throw new RuntimeException('RigPi could not clear an account’s DuckDNS SDR address.');
        }
    }
}

if ($action === 'status') {
    try { echo json_encode(httpsStatus()); }
    catch (RuntimeException $error) {
        http_response_code(500);
        echo json_encode(['error' => $error->getMessage()]);
    }
    exit;
}

$previousStatus = null;
if ($action === 'remove') {
    try { $previousStatus = httpsStatus(); } catch (RuntimeException $ignored) {}
}
$commandAction = str_replace('_', '-', $action);
$command = ['/usr/bin/sudo', '-n', '/usr/local/sbin/rigpi-duckdns-https', $commandAction];
$input = '';
if ($action === 'provision') {
    set_time_limit(300);
    $subdomain = strtolower(trim((string) ($request['subdomain'] ?? '')));
    $subdomain = preg_replace('/\.duckdns\.org$/i', '', $subdomain);
    $email = trim((string) ($request['email'] ?? ''));
    $token = trim((string) ($request['token'] ?? ''));
    if (!preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $subdomain)) {
        http_response_code(400);
        echo json_encode(['error' => 'Enter the DuckDNS name without .duckdns.org.']);
        exit;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['error' => 'Enter a valid certificate email address.']);
        exit;
    }
    if (!preg_match('/^[A-Za-z0-9_-]{20,128}$/', $token)) {
        http_response_code(400);
        echo json_encode(['error' => 'Paste the DuckDNS account token.']);
        exit;
    }
    $command[] = $subdomain;
    $command[] = $email;
    $input = $token . "\n";
    $token = '';
} elseif ($action === 'set_token') {
    $token = trim((string) ($request['token'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9_-]{20,128}$/', $token)) {
        http_response_code(400);
        echo json_encode(['error' => 'Paste the new DuckDNS account token.']);
        exit;
    }
    $input = $token . "\n";
    $token = '';
} elseif ($action === 'renew') {
    set_time_limit(300);
}

[$code, $output, $error] = commandResult($command, $input);
$input = '';
if ($code !== 0) {
    http_response_code(500);
    echo json_encode(['error' => $error ?: $output ?: 'RigPi could not change native HTTPS.']);
    exit;
}
try {
    $status = httpsStatus();
    if (($action === 'provision' || $action === 'enable') && $status['enabled'] && $status['hostname'] !== '') {
        setDuckDNSRemoteUrls($status['hostname']);
    } elseif ($action === 'remove' && is_array($previousStatus)) {
        clearDuckDNSRemoteUrls((string) ($previousStatus['hostname'] ?? ''));
    }
    $messages = [
        'provision' => 'Native HTTPS is ready. Forward external TCP port 443 to this RigPi.',
        'set_token' => 'The DuckDNS token was updated and verified.',
        'update_ip' => 'The DuckDNS address was refreshed.',
        'renew' => 'The HTTPS certificate was renewed.',
        'enable' => 'Native HTTPS was enabled.',
        'disable' => 'Native HTTPS was disabled; its certificate and settings were retained.',
        'remove' => 'Native HTTPS credentials and certificate were removed from this RigPi.',
    ];
    $status['message'] = $messages[$action] ?? $output;
    echo json_encode($status);
} catch (RuntimeException $statusError) {
    echo json_encode(['status' => 'unknown', 'message' => $output ?: 'The change completed.', 'warning' => $statusError->getMessage()]);
}
