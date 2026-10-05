<?php
/** Authenticated local searches of Elmer's cached HearHam repeater directory. */
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$root = '/var/www/html';
$cacheFile = '/home/pi/Elmer/cache/hearham/repeaters.csv';
$metadataFile = '/home/pi/Elmer/cache/hearham/metadata.json';
if (empty($_SESSION['myUsername'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Please sign in to RigPi.']);
    exit;
}
require_once $root . '/programs/sqldata.php';
require_once $root . '/programs/GetUserFieldFunc.php';
ini_set('display_errors', '0');
ini_set('log_errors', '1');

function elmerRepeaterDistance(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $lat1 = deg2rad($lat1); $lat2 = deg2rad($lat2);
    $dlat = $lat2 - $lat1; $dlon = deg2rad($lon2 - $lon1);
    $a = sin($dlat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dlon / 2) ** 2;
    return 3958.7613 * 2 * asin(min(1.0, sqrt($a)));
}

function elmerRepeaterBandRange(string $band): ?array {
    return match ($band) {
        '10m' => [28000000, 29700000], '6m' => [50000000, 54000000],
        '2m' => [144000000, 148000000], '1.25m' => [222000000, 225000000],
        '70cm' => [420000000, 450000000], '33cm' => [902000000, 928000000],
        '23cm' => [1240000000, 1300000000], default => null,
    };
}

function elmerRepeaterModeMatches(string $recordMode, string $wanted): bool {
    if ($wanted === '') return true;
    $parts = preg_split('/[\/,+ ]+/', strtoupper(trim($recordMode)), -1, PREG_SPLIT_NO_EMPTY);
    if ($wanted === 'YSF') return in_array('YSF', $parts, true) || in_array('FUSION', $parts, true);
    if ($wanted === 'D-STAR') return in_array('D-STAR', $parts, true) || in_array('DSTAR', $parts, true);
    return in_array($wanted, $parts, true);
}

try {
    $request = json_decode(file_get_contents('php://input'), true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($request)) throw new InvalidArgumentException('The repeater search is invalid.');
    $center = strtolower(trim((string)($request['center'] ?? 'user')));
    $postal = trim((string)($request['postal_code'] ?? ''));
    $centerCity = trim((string)($request['center_city'] ?? ''));
    $centerState = strtoupper(trim((string)($request['center_state'] ?? '')));
    $radius = (float)($request['radius_miles'] ?? 25);
    $call = strtoupper(trim((string)($request['call'] ?? '')));
    $mode = strtoupper(trim((string)($request['mode'] ?? '')));
    $band = strtolower(trim((string)($request['band'] ?? '')));
    $limit = min(50, max(1, (int)($request['limit'] ?? 25)));
    $offset = min(10000, max(0, (int)($request['offset'] ?? 0)));
    $allowedModes = ['', 'FM', 'DMR', 'D-STAR', 'YSF', 'P25', 'NXDN', 'M17'];
    $allowedBands = ['', '10m', '6m', '2m', '1.25m', '70cm', '33cm', '23cm'];
    if ($call !== '' && !preg_match('/^(?:[A-Z0-9]{1,3}\/)?[A-Z0-9]{1,3}\d[A-Z]{1,4}(?:\/[A-Z0-9]{1,4})?$/', $call))
        throw new InvalidArgumentException('The repeater callsign is invalid.');
    if (!in_array($center, ['user', 'postal', 'city'], true))
        throw new InvalidArgumentException('The repeater search center is invalid.');
    if ($center === 'postal' && !preg_match('/^\d{5}$/', $postal))
        throw new InvalidArgumentException('Please use a five-digit US ZIP code.');
    if ($center === 'city' && ($centerCity === '' || strlen($centerCity) > 60 ||
        !preg_match("/^[A-Za-z .'-]+$/", $centerCity) || !preg_match('/^[A-Z]{2}$/', $centerState)))
        throw new InvalidArgumentException('Please use a city and two-letter state abbreviation.');
    if ($radius <= 0 || $radius > 250)
        throw new InvalidArgumentException('The repeater radius must be between 0 and 250 miles.');
    if (!in_array($mode, $allowedModes, true) || !in_array($band, $allowedBands, true))
        throw new InvalidArgumentException('The repeater mode or band is invalid.');
    if (!is_readable($cacheFile)) throw new RuntimeException('The HearHam repeater cache has not been installed.');

    $username = (string)$_SESSION['myUsername'];
    $uid = (int)getUserField($username, 'uID');
    if ($uid < 1) throw new RuntimeException('The signed-in RigPi account was not found.');
    $db = new mysqli('localhost', $sql_radio_username, $sql_radio_password, $sql_radio_database);
    if ($db->connect_errno) throw new RuntimeException('The RigPi database is unavailable.');
    $db->set_charset('utf8mb4');
    if ($center === 'user') {
        $stmt = $db->prepare('SELECT My_Latitude,My_Longitude FROM Users WHERE uID=? LIMIT 1');
        $stmt->bind_param('i', $uid); $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $centerLat = (float)($row['My_Latitude'] ?? 0); $centerLon = (float)($row['My_Longitude'] ?? 0);
        $centerLabel = 'signed-in RigPi account';
        if (($centerLat == 0.0 && $centerLon == 0.0) || $centerLat < -90 || $centerLat > 90 ||
            $centerLon < -180 || $centerLon > 180)
            throw new InvalidArgumentException('Set your latitude and longitude in RigPi User Settings before searching near you.');
    } elseif ($center === 'postal') {
        $stmt = $db->prepare('SELECT LATITUDE,LONGITUDE FROM ZipCode WHERE ZIP_CODE=? LIMIT 1');
        $stmt->bind_param('s', $postal); $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) throw new InvalidArgumentException('That ZIP code is not in the onboard ZIP database.');
        $centerLat = (float)$row['LATITUDE']; $centerLon = (float)$row['LONGITUDE'];
        $centerLabel = 'ZIP ' . $postal . ' centroid';
    } else {
        $stmt = $db->prepare('SELECT AVG(CAST(z.LATITUDE AS DECIMAL(11,7))) AS latitude,'
            . 'AVG(CAST(z.LONGITUDE AS DECIMAL(11,7))) AS longitude,COUNT(*) AS records '
            . 'FROM fcc_amateur.hd hd JOIN fcc_amateur.en en ON en.fccid=hd.fccid '
            . 'JOIN ZipCode z ON z.ZIP_CODE=LEFT(en.zip,5) '
            . "WHERE hd.status='A' AND UPPER(en.city)=UPPER(?) AND UPPER(en.state)=?");
        $stmt->bind_param('ss', $centerCity, $centerState); $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row || (int)$row['records'] < 1)
            throw new InvalidArgumentException('That city and state were not found in the onboard FCC database.');
        $centerLat = (float)$row['latitude']; $centerLon = (float)$row['longitude'];
        $centerLabel = ucwords(strtolower($centerCity)) . ', ' . $centerState;
    }
    $db->close();

    $bandRange = elmerRepeaterBandRange($band);
    $handle = fopen($cacheFile, 'rb');
    if (!$handle) throw new RuntimeException('The HearHam repeater cache could not be opened.');
    $headers = fgetcsv($handle, 0, ',', '"', '');
    if (!is_array($headers)) throw new RuntimeException('The HearHam repeater cache is invalid.');
    $matches = [];
    while (($values = fgetcsv($handle, 0, ',', '"', '')) !== false) {
        if (count($values) !== count($headers)) continue;
        $row = array_combine($headers, $values);
        if ($call !== '' && strtoupper(trim((string)$row['callsign'])) !== $call) continue;
        $frequency = (int)$row['frequency_hz'];
        if ($bandRange && ($frequency < $bandRange[0] || $frequency > $bandRange[1])) continue;
        if (!elmerRepeaterModeMatches((string)$row['mode'], $mode)) continue;
        $lat = (float)$row['latitude']; $lon = (float)$row['longitude'];
        $distance = elmerRepeaterDistance($centerLat, $centerLon, $lat, $lon);
        if ($call === '' && $distance > $radius) continue;
        $matches[] = [
            'id' => (string)$row['id'], 'call' => (string)$row['callsign'],
            'frequency_hz' => $frequency, 'offset_hz' => (int)$row['offset_hz'],
            'mode' => (string)$row['mode'], 'encode' => (string)$row['encode'],
            'decode' => (string)$row['decode'], 'city' => (string)$row['city'],
            'group' => (string)$row['group'], 'internet_node' => (string)$row['internet_node'],
            'power' => (string)$row['power'], 'restriction' => (string)$row['restriction'],
            'latitude' => $lat, 'longitude' => $lon, 'distance_miles' => round($distance, 1),
        ];
    }
    fclose($handle);
    usort($matches, static fn($a, $b) => ($a['distance_miles'] <=> $b['distance_miles']) ?:
        strcmp($a['call'], $b['call']) ?: ($a['frequency_hz'] <=> $b['frequency_hz']));
    $metadata = [];
    if (is_readable($metadataFile)) {
        $metadata = json_decode((string)file_get_contents($metadataFile), true) ?: [];
    }
    $returned = array_slice($matches, $offset, $limit);
    $points = array_map(static fn($row) => [
        'call' => $row['call'], 'frequency_hz' => $row['frequency_hz'], 'mode' => $row['mode'],
        'city' => $row['city'], 'distance_miles' => $row['distance_miles'],
        'latitude' => $row['latitude'], 'longitude' => $row['longitude'],
    ], $returned);
    $payload = [
        'provider' => 'HearHam', 'reference' => 'https://hearham.com/repeaters',
        'retrieved_at' => (string)($metadata['downloaded_at'] ?? gmdate('c', filemtime($cacheFile))),
        'cache_records' => (int)($metadata['cached'] ?? 0),
        'individual_verification_dates_available' => false,
        'verification_notice' => 'HearHam does not provide individual record verification dates in this export.',
        'center' => $centerLabel, 'radius_miles' => $call !== '' ? 0 : $radius,
        'call_filter' => $call, 'band' => $band, 'mode' => $mode,
        'total_matches' => count($matches), 'returned' => count($returned), 'results' => $returned,
        'map' => ['center_latitude' => $centerLat, 'center_longitude' => $centerLon,
            'center_label' => $centerLabel, 'radius_miles' => $call !== '' ? 0 : $radius, 'points' => $points],
    ];
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $error) {
    http_response_code(400); echo json_encode(['error' => $error->getMessage()]);
} catch (Throwable $error) {
    error_log('Elmer repeaters: ' . $error->getMessage());
    http_response_code(500); echo json_encode(['error' => 'RigPi could not search the repeater directory.']);
}
