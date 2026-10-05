<?php
/** Authenticated search for recently expired and soon-expiring short calls. */
$includedByFccSearch = isset($request) && is_array($request);
if (!$includedByFccSearch) {
    session_start();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    if (empty($_SESSION['myUsername'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Please sign in to RigPi.']);
        exit;
    }
    $root = '/var/www/html';
    require_once $root . '/programs/sqldata.php';
}
ini_set('display_errors', '0');
ini_set('log_errors', '1');

try {
    if (!$includedByFccSearch) {
        $request = json_decode(file_get_contents('php://input'), true, 8, JSON_THROW_ON_ERROR);
    }
    $state = strtoupper(trim((string)($request['state'] ?? '')));
    $callPrefix = strtoupper(trim((string)($request['call_prefix'] ?? '')));
    $format = strtolower(trim((string)($request['call_format'] ?? '1x2')));
    $pastDays = min(3650, max(0, (int)($request['expired_within_days'] ??
        ((int)($request['expired_within_months'] ?? 24) * 30))));
    $futureDays = min(1825, max(1, (int)($request['expiring_within_days'] ??
        ((int)($request['expiring_within_months'] ?? 12) * 30))));
    $includeExpired = array_key_exists('include_expired', $request) ? !empty($request['include_expired']) : true;
    $includeExpiring = array_key_exists('include_expiring', $request) ? !empty($request['include_expiring']) : true;
    if (!$includeExpired && !$includeExpiring) {
        throw new InvalidArgumentException('Select expired calls, expiring calls, or both.');
    }
    $limit = min(50, max(1, (int)($request['limit'] ?? 25)));
    $offset = min(100000, max(0, (int)($request['offset'] ?? 0)));
    if ($state !== '' && !preg_match('/^[A-Z]{2}$/', $state)) {
        throw new InvalidArgumentException('Please use a two-letter state abbreviation.');
    }
    if ($callPrefix !== '' && !preg_match('/^[AKNW][A-Z]?[0-9]$/', $callPrefix)) {
        throw new InvalidArgumentException('The callsign prefix is invalid.');
    }
    if ($state === '' && $callPrefix === '') {
        throw new InvalidArgumentException('Please include a state or callsign prefix.');
    }
    $patterns = [
        '1x2' => '^[KNW][0-9][A-Z]{2}$',
        '2x1' => '^[AKNW][A-Z][0-9][A-Z]$',
        'short' => '^([KNW][0-9][A-Z]{2}|[AKNW][A-Z][0-9][A-Z])$',
        'two_letter_suffix' => '^[A-Z]{1,2}[0-9][A-Z]{2}$',
    ];
    if (!isset($patterns[$format])) {
        throw new InvalidArgumentException('The FCC short-callsign format is invalid.');
    }

    $db = new mysqli('localhost', $sql_radio_username, $sql_radio_password, $sql_radio_database);
    if ($db->connect_errno) throw new RuntimeException('The RigPi database is unavailable.');
    $db->set_charset('utf8mb4');
    $available = $db->query("SHOW TABLES FROM fcc_amateur LIKE 'elmer_short_calls'");
    if (!$available || $available->num_rows === 0) {
        throw new RuntimeException('FCC expiration dates have not been indexed. Run the FCC database update in System Settings.');
    }

    $scope = $state !== '' && $callPrefix !== '' ? "sc.state=? AND sc.callsign LIKE CONCAT(?, '%')"
        : ($state !== '' ? 'sc.state=?' : "sc.callsign LIKE CONCAT(?, '%')");
    $where = $scope . " AND sc.callsign REGEXP ? AND ("
        . "(?=1 AND sc.status='A' AND sc.expiration_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)) OR "
        . "(?=1 AND sc.expiration_date BETWEEN DATE_SUB(CURDATE(), INTERVAL ? DAY) AND DATE_SUB(CURDATE(), INTERVAL 1 DAY) "
        . "AND NOT EXISTS (SELECT 1 FROM fcc_amateur.elmer_short_calls current_call FORCE INDEX (idx_elmer_short_call_status) "
        . "WHERE current_call.callsign=sc.callsign AND current_call.status='A' AND current_call.expiration_date>=CURDATE()))"
        . ")";
    $sql = 'SELECT sc.callsign,sc.status,sc.expiration_date,sc.first,sc.middle,sc.last,sc.city,sc.state,sc.zip,sc.license_class AS class,COUNT(*) OVER() AS total_count '
        . 'FROM fcc_amateur.elmer_short_calls sc WHERE ' . $where
        . " ORDER BY CASE WHEN sc.expiration_date>=CURDATE() THEN 0 ELSE 1 END, "
        . "CASE WHEN sc.expiration_date>=CURDATE() THEN sc.expiration_date END ASC, sc.expiration_date DESC, sc.callsign ASC LIMIT ? OFFSET ?";
    $stmt = $db->prepare($sql);
    $includeExpiringValue = $includeExpiring ? 1 : 0;
    $includeExpiredValue = $includeExpired ? 1 : 0;
    if ($state !== '' && $callPrefix !== '') {
        $stmt->bind_param('sssiiiiii', $state, $callPrefix, $patterns[$format], $includeExpiringValue,
            $futureDays, $includeExpiredValue, $pastDays, $limit, $offset);
    } elseif ($state !== '') {
        $stmt->bind_param('ssiiiiii', $state, $patterns[$format], $includeExpiringValue, $futureDays,
            $includeExpiredValue, $pastDays, $limit, $offset);
    } else {
        $stmt->bind_param('ssiiiiii', $callPrefix, $patterns[$format], $includeExpiringValue, $futureDays,
            $includeExpiredValue, $pastDays, $limit, $offset);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $total = $rows ? (int)$rows[0]['total_count'] : 0;
    $statusNames = ['A' => 'Active', 'E' => 'Expired', 'C' => 'Cancelled', 'T' => 'Terminated'];
    $classNames = ['T' => 'Technician', 'P' => 'Technician Plus', 'G' => 'General',
        'E' => 'Amateur Extra', 'A' => 'Advanced', 'N' => 'Novice'];
    $results = [];
    $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
    foreach ($rows as $row) {
        $date = new DateTimeImmutable((string)$row['expiration_date'], new DateTimeZone('UTC'));
        $days = (int)$today->diff($date)->format('%r%a');
        $name = trim(ucwords(strtolower(trim(($row['first'] ?? '') . ' ' . ($row['middle'] ?? '') . ' ' . ($row['last'] ?? '')))));
        $code = strtoupper(trim((string)($row['class'] ?? '')));
        $status = strtoupper(trim((string)$row['status']));
        $results[] = [
            'call' => strtoupper(trim((string)$row['callsign'])),
            'name' => $name,
            'city' => ucwords(strtolower(trim((string)$row['city']))),
            'state' => strtoupper(trim((string)$row['state'])),
            'postal_code' => substr(trim((string)$row['zip']), 0, 5),
            'license_class' => $code === '' ? '' : (($classNames[$code] ?? $code) . ' (' . $code . ')'),
            'license_status' => $days < 0 && $status === 'A' ? 'Expired — renewal grace period' : ($statusNames[$status] ?? $status),
            'expiration_date' => $date->format('Y-m-d'),
            'days_until_expiration' => $days,
            'distance_miles' => 0.0,
        ];
    }

    $formatLabels = ['1x2' => '1×2 callsigns', '2x1' => '2×1 callsigns',
        'short' => '1×2 and 2×1 callsigns', 'two_letter_suffix' => 'callsigns with two-letter suffixes'];
    $regionLabel = implode(' / ', array_filter([$callPrefix === '' ? '' : $callPrefix . ' callsigns',
        $state === '' ? '' : $state]));
    $scopeDescription = $callPrefix !== '' && $state === ''
        ? 'the callsign prefix ' . $callPrefix
        : ($callPrefix !== '' ? 'the callsign prefix ' . $callPrefix . ' and FCC mailing state ' . $state
            : 'the FCC mailing state ' . $state);
    echo json_encode([
        'provider' => 'RigPi onboard FCC database',
        'retrieved_at' => gmdate('c'),
        'query_type' => 'license_expiration',
        'region_type' => $callPrefix !== '' && $state === '' ? 'callsign_prefix' : 'state',
        'region_label' => $regionLabel,
        'center' => $scopeDescription,
        'distance_basis' => 'No distance search was used; results use ' . $scopeDescription . '.',
        'notice' => 'Two-letter calls means ' . $formatLabels[$format] . '. ' .
            ($includeExpired ? 'Expired results cover the previous ' . $pastDays . ' days. ' : '') .
            ($includeExpiring ? 'Soon-expiring results cover the next ' . $futureDays . ' days. ' : '') .
            'An expired call is not necessarily available for reassignment.',
        'active_only' => false,
        'total_matches' => $total,
        'returned' => count($results),
        'results' => $results,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (JsonException | InvalidArgumentException $error) {
    http_response_code(400);
    echo json_encode(['error' => $error->getMessage()]);
} catch (Throwable $error) {
    error_log('Elmer FCC expiration search: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $error->getMessage()]);
}
