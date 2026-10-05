<?php
/** Configure and test the station-wide RigPi SMTP service. */
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
    || ($_SERVER['HTTP_X_RIGPI_ACTION'] ?? '') !== 'email-service') {
    http_response_code(403);
    echo json_encode(['error' => 'The email service request is not authorized.']);
    exit;
}
$fetchSite = strtolower((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
if ($fetchSite !== '' && $fetchSite !== 'same-origin') {
    http_response_code(403);
    echo json_encode(['error' => 'Email changes must originate from this RigPi.']);
    exit;
}

require $root . '/programs/sqldata.php';
require_once $root . '/classes/MysqliDb.php';
require_once $root . '/programs/GetUserFieldFunc.php';
require_once $root . '/programs/RigPiMailer.php';
$request = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($request)) $request = [];
$action = strtolower(trim((string) ($request['action'] ?? 'status')));
$targetId = filter_var($request['user_id'] ?? 0, FILTER_VALIDATE_INT);
if (!in_array($action, ['status', 'save', 'test', 'disconnect'], true)
    || !$targetId || $targetId < 1) {
    http_response_code(400);
    echo json_encode(['error' => 'The email service request is invalid.']);
    exit;
}

$db = new MysqliDb('localhost', $sql_radio_username, $sql_radio_password, $sql_radio_database);
$db->where('uID', $targetId);
$target = $db->getOne('Users', ['uID', 'Username', 'My_Email']);
if (!$target) {
    http_response_code(404);
    echo json_encode(['error' => 'The RigPi user was not found.']);
    exit;
}
$signedIn = (string) $_SESSION['myUsername'];
$signedInLevel = (int) getUserField($signedIn, 'Access_Level');
if ($signedInLevel !== 1) {
    http_response_code(403);
    echo json_encode(['error' => 'Only a RigPi administrator may configure outgoing email.']);
    exit;
}

try {
    $saved = rigpiLoadEmailSettings($db);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['error' => 'RigPi could not initialize the email settings.']);
    exit;
}
$configured = trim((string) ($saved['SMTPHost'] ?? '')) !== ''
    && trim((string) ($saved['FromEmail'] ?? '')) !== '';
if ($action === 'status') {
    echo json_encode([
        'status' => $configured ? 'configured' : 'disconnected',
        'provider' => (string) ($saved['Provider'] ?? 'custom'),
        'host' => (string) ($saved['SMTPHost'] ?? ''),
        'port' => (int) ($saved['SMTPPort'] ?? 587),
        'encryption' => (string) ($saved['SMTPEncryption'] ?? 'starttls'),
        'username' => (string) ($saved['SMTPUsername'] ?? ''),
        'password_configured' => (string) ($saved['SMTPPassword'] ?? '') !== '',
        'from_email' => (string) ($saved['FromEmail'] ?? ''),
        'from_name' => (string) ($saved['FromName'] ?? 'RigPi'),
        'recipient' => (string) ($target['My_Email'] ?? ''),
    ]);
    exit;
}
if ($action === 'disconnect') {
    $db->where('SettingID', 1);
    $db->delete('EmailSettings');
    echo json_encode(['status' => 'disconnected']);
    exit;
}

$provider = strtolower(trim((string) ($request['provider'] ?? 'custom')));
$host = trim((string) ($request['host'] ?? ''));
$port = filter_var($request['port'] ?? 0, FILTER_VALIDATE_INT);
$encryption = strtolower(trim((string) ($request['encryption'] ?? 'starttls')));
$username = trim((string) ($request['username'] ?? ''));
$password = (string) ($request['password'] ?? '');
$fromEmail = trim((string) ($request['from_email'] ?? ''));
$fromName = trim((string) ($request['from_name'] ?? 'RigPi')) ?: 'RigPi';
if ($password === '') $password = (string) ($saved['SMTPPassword'] ?? '');
$providers = ['custom', 'gmail', 'outlook', 'yahoo'];
if (!in_array($provider, $providers, true)
    || !preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]{0,252}$/', $host)
    || $port === false || $port < 1 || $port > 65535
    || !in_array($encryption, ['none', 'starttls', 'tls'], true)
    || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)
    || preg_match('/[\r\n]/', $username . $password . $fromName)
    || (($username === '') !== ($password === ''))) {
    http_response_code(400);
    echo json_encode(['error' => 'Enter valid SMTP server, security, sender, and authentication settings.']);
    exit;
}
$settings = [
    'SettingID' => 1,
    'Provider' => $provider,
    'SMTPHost' => $host,
    'SMTPPort' => $port,
    'SMTPEncryption' => $encryption,
    'SMTPUsername' => $username,
    'SMTPPassword' => $password,
    'FromEmail' => $fromEmail,
    'FromName' => $fromName,
];
if ($action === 'save') {
    if ($saved) {
        $db->where('SettingID', 1);
        $ok = $db->update('EmailSettings', $settings);
    } else {
        $ok = (bool) $db->insert('EmailSettings', $settings);
    }
    if (!$ok) {
        http_response_code(500);
        echo json_encode(['error' => 'RigPi could not save the email configuration.']);
        exit;
    }
    echo json_encode(['status' => 'configured', 'message' => 'Email configuration saved.']);
    exit;
}

try {
    rigpiSendEmail(
        $settings,
        trim((string) ($target['My_Email'] ?? '')),
        'RigPi Test Email',
        'This is a test email from RigPi for user ' . (string) $target['Username'] . ".\n\nYour SMTP email service is working."
    );
    echo json_encode(['status' => 'connected', 'message' => 'Test email sent successfully.']);
} catch (Throwable $error) {
    http_response_code(502);
    echo json_encode(['error' => $error->getMessage()]);
}

