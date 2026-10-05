<?php
/** Privacy-limited previous-QSO summary for the signed-in RigPi account. */
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$root = '/var/www/html';
if (empty($_SESSION['myUsername'])) {
    http_response_code(401); echo json_encode(['error'=>'Please sign in to RigPi.']); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' ||
    ($_SERVER['HTTP_X_ELMER_ACTION'] ?? '') !== 'logbook-history') {
    http_response_code(403); echo json_encode(['error'=>'The logbook-history request is invalid.']); exit;
}
$fetchSite = strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
if ($fetchSite !== '' && $fetchSite !== 'same-origin') {
    http_response_code(403); echo json_encode(['error'=>'Logbook history must be requested from this RigPi.']); exit;
}
require_once $root . '/programs/sqldata.php';
require_once $root . '/programs/GetUserFieldFunc.php';
ini_set('display_errors', '0'); ini_set('log_errors', '1');

try {
    $request = json_decode(file_get_contents('php://input') ?: '{}', true, 8, JSON_THROW_ON_ERROR);
    $call = strtoupper(trim((string)($request['call'] ?? '')));
    if (!preg_match('/^(?:[A-Z0-9]{1,3}\/)?[A-Z0-9]{1,3}\d[A-Z]{1,4}(?:\/[A-Z0-9]{1,4})?$/', $call)) {
        throw new InvalidArgumentException('Please provide one valid callsign for the logbook search.');
    }
    $username = (string)$_SESSION['myUsername'];
    $myCall = strtoupper(trim((string)($_SESSION['myCall'] ?? '')));
    $user = (int)getUserField($username, 'uID');
    if ($user < 1) throw new RuntimeException('The signed-in RigPi account was not found.');
    session_write_close();

    $db = new mysqli('localhost', $sql_radio_username, $sql_radio_password, $sql_radio_database);
    if ($db->connect_errno) throw new RuntimeException('RigPi could not open the logbook.');
    $db->set_charset('utf8mb4');
    $stmt = $db->prepare(
        'SELECT u.SelectedRadio,m.LogName FROM Users u ' .
        'LEFT JOIN MySettings m ON m.Radio=u.SelectedRadio WHERE u.Username=? LIMIT 1');
    $stmt->bind_param('s', $username); $stmt->execute();
    $settings = $stmt->get_result()->fetch_assoc() ?: []; $stmt->close();
    $logName = trim((string)($settings['LogName'] ?? ''));

    $where = 'UPPER(TRIM(Callsign))=?';
    $types = 's'; $values = [$call];
    if ($logName !== '' && strcasecmp($logName, 'ALL Logs') !== 0) {
        $where .= ' AND Logname=?'; $types .= 's'; $values[] = $logName;
    } elseif ($myCall !== '') {
        $where .= ' AND (UPPER(TRIM(MyCall))=? OR UPPER(TRIM(StationCall))=?)';
        $types .= 'ss'; $values[] = $myCall; $values[] = $myCall;
    }
    $bind = static function ($stmt, $types, &$values) {
        $arguments = [$types];
        foreach ($values as &$value) $arguments[] = &$value;
        call_user_func_array([$stmt, 'bind_param'], $arguments);
    };
    $stmt = $db->prepare('SELECT COUNT(*) contacts FROM Logbook WHERE ' . $where);
    $bind($stmt, $types, $values); $stmt->execute();
    $contacts = (int)(($stmt->get_result()->fetch_assoc()['contacts'] ?? 0)); $stmt->close();
    $latest = [];
    if ($contacts > 0) {
        $stmt = $db->prepare(
            'SELECT Time_Start,Time_Start_Plain,Band,Mode,SubMode,Tx_Frequency,Logname ' .
            'FROM Logbook WHERE ' . $where .
            " ORDER BY CAST(NULLIF(Time_Start,'') AS UNSIGNED) DESC,MobileID DESC LIMIT 1");
        $bind($stmt, $types, $values); $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: []; $stmt->close();
        $timestamp = (int)($row['Time_Start'] ?? 0);
        if ($timestamp <= 0 && trim((string)($row['Time_Start_Plain'] ?? '')) !== '') {
            $timestamp = (int)strtotime((string)$row['Time_Start_Plain'] . ' UTC');
        }
        $mode = strtoupper(trim((string)($row['SubMode'] ?? '')));
        if ($mode === '') $mode = strtoupper(trim((string)($row['Mode'] ?? '')));
        $latest = [
            'date'=>$timestamp > 0 ? gmdate('n/j/y', $timestamp) : '',
            'date_iso'=>$timestamp > 0 ? gmdate('Y-m-d', $timestamp) : '',
            'band'=>strtolower(trim((string)($row['Band'] ?? ''))),
            'mode'=>$mode,
        ];
    }
    echo json_encode([
        'call'=>$call,'contacts'=>$contacts,'worked_before'=>$contacts > 0,
        'latest'=>$latest,'log_scope'=>$logName !== '' ? $logName : 'signed-in operator',
        'provider'=>'RigPi local logbook','retrieved_at'=>gmdate('c'),
        'notice'=>'Only the matching contact count and latest date, band, and mode were supplied to Elmer.',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (JsonException | InvalidArgumentException $error) {
    http_response_code(400); echo json_encode(['error'=>$error->getMessage()]);
} catch (Throwable $error) {
    error_log('Elmer logbook history: ' . $error->getMessage());
    http_response_code(500); echo json_encode(['error'=>'RigPi could not search the signed-in logbook.']);
}
