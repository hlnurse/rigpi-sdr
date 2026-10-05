<?php
/** Authenticated, privacy-limited searches of RigPi's onboard FCC database. */
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
require_once $root . '/programs/sqldata.php';
require_once $root . '/programs/GetUserFieldFunc.php';
require_once $root . '/programs/ElmerFCCGeocode.php';
ini_set('display_errors', '0');
ini_set('log_errors', '1');

try {
    $request = json_decode(file_get_contents('php://input'), true, 8, JSON_THROW_ON_ERROR);
    if (!empty($request['expiration_search'])) {
        require $root . '/programs/ElmerFCCExpiration.php';
        exit;
    }
    $postal = trim((string)($request['postal_code'] ?? ''));
    $call = strtoupper(trim((string)($request['call'] ?? '')));
    $street = trim((string)($request['street'] ?? ''));
    $city = trim((string)($request['city'] ?? ''));
    $state = strtoupper(trim((string)($request['state'] ?? '')));
    $centerCity = trim((string)($request['center_city'] ?? ''));
    $centerState = strtoupper(trim((string)($request['center_state'] ?? '')));
    $centerCall = strtoupper(trim((string)($request['center_call'] ?? '')));
    $requestedCenterLat = array_key_exists('center_latitude', $request) ? (float)$request['center_latitude'] : null;
    $requestedCenterLon = array_key_exists('center_longitude', $request) ? (float)$request['center_longitude'] : null;
    $requestedCenterBasis = trim((string)($request['center_basis'] ?? ''));
    $callPrefix = strtoupper(trim((string)($request['call_prefix'] ?? '')));
    $callSuffix = strtoupper(trim((string)($request['call_suffix'] ?? '')));
    $lastName = trim((string)($request['last_name'] ?? ''));
    $classCode = strtoupper(trim((string)($request['license_class_code'] ?? '')));
    $clubsOnly = !empty($request['clubs_only']);
    $aggregate = strtolower(trim((string)($request['aggregate'] ?? '')));
    $groupBy = strtolower(trim((string)($request['group_by'] ?? '')));
    $regionType = strtolower(trim((string)($request['region_type'] ?? '')));
    $county = trim((string)($request['county'] ?? ''));
    $country = strtoupper(trim((string)($request['country'] ?? '')));
    $regionalCount = $aggregate === 'count';
    if ($regionalCount && $classCode === 'GROUP') {
        $groupBy = 'license_class';
        $classCode = '';
    }
    $center = (string)($request['center'] ?? ($postal !== '' && $call === '' ? 'postal' : 'user'));
    $radius = isset($request['radius_miles']) ? (float)$request['radius_miles'] : null;
    $limit = min(50, max(1, (int)($request['limit'] ?? 25)));
    $offset = min(100000, max(0, (int)($request['offset'] ?? 0)));
    $sort = strtolower(trim((string)($request['sort'] ?? 'distance')));
    if (!in_array($sort, ['distance', 'name', 'call'], true)) $sort = 'distance';
    if ($postal !== '' && !preg_match('/^\d{5}$/', $postal)) {
        throw new InvalidArgumentException('Please use a five-digit US ZIP code.');
    }
    if ($call !== '' && (strlen($call) > 24 ||
        !preg_match('/^(?:[A-Z0-9]{1,3}\/)?[A-Z0-9]{1,3}\d[A-Z]{1,4}(?:\/[A-Z0-9]{1,4})?$/', $call))) {
        throw new InvalidArgumentException('Please use one valid amateur-radio callsign.');
    }
    if ($centerCall !== '' && (strlen($centerCall) > 24 ||
        !preg_match('/^(?:[A-Z0-9]{1,3}\/)?[A-Z0-9]{1,3}\d[A-Z]{1,4}(?:\/[A-Z0-9]{1,4})?$/', $centerCall))) {
        throw new InvalidArgumentException('Please use one valid amateur-radio callsign as the search center.');
    }
    if (($requestedCenterLat === null) !== ($requestedCenterLon === null) ||
        ($requestedCenterLat !== null && ($requestedCenterLat < -90 || $requestedCenterLat > 90 ||
            $requestedCenterLon < -180 || $requestedCenterLon > 180))) {
        throw new InvalidArgumentException('The fallback search-center coordinates are invalid.');
    }
    if ($street !== '' && (strlen($street) > 80 || !preg_match("/^[A-Za-z0-9 .'-]+$/", $street))) {
        throw new InvalidArgumentException('The FCC street search is invalid.');
    }
    if ($city !== '' && (strlen($city) > 60 || !preg_match("/^[A-Za-z .'-]+$/", $city))) {
        throw new InvalidArgumentException('The FCC city search is invalid.');
    }
    if ($state !== '' && !preg_match('/^[A-Z]{2}$/', $state)) {
        throw new InvalidArgumentException('Please use a two-letter state abbreviation.');
    }
    if ($centerCity !== '' && (strlen($centerCity) > 60 || !preg_match("/^[A-Za-z .'-]+$/", $centerCity))) {
        throw new InvalidArgumentException('The FCC search-center city is invalid.');
    }
    if ($centerState !== '' && !preg_match('/^[A-Z]{2}$/', $centerState)) {
        throw new InvalidArgumentException('Please use a two-letter state abbreviation for the search center.');
    }
    if ($callPrefix !== '' && !preg_match('/^[A-Z0-9]{2,8}$/', $callPrefix)) {
        throw new InvalidArgumentException('A callsign prefix must contain 2 to 8 letters or digits.');
    }
    if ($callSuffix !== '' && !preg_match('/^[A-Z0-9]{2,8}$/', $callSuffix)) {
        throw new InvalidArgumentException('A callsign suffix must contain 2 to 8 letters or digits.');
    }
    if ($lastName !== '' && (strlen($lastName) > 40 || !preg_match("/^[A-Za-z][A-Za-z'-]{0,39}$/", $lastName))) {
        throw new InvalidArgumentException('The FCC last-name search is invalid.');
    }
    if ($classCode !== '' && !in_array($classCode, ['N', 'T', 'P', 'G', 'A', 'E'], true)) {
        throw new InvalidArgumentException('The FCC license class code is invalid.');
    }
    if ($regionalCount && !in_array($regionType, ['city', 'county', 'state', 'country'], true)) {
        throw new InvalidArgumentException('The FCC count region is invalid.');
    }
    if ($regionalCount && !in_array($groupBy, ['', 'license_class'], true)) {
        throw new InvalidArgumentException('The FCC regional grouping is invalid.');
    }
    if ($county !== '' && (strlen($county) > 60 || !preg_match("/^[A-Za-z .'-]+$/", $county))) {
        throw new InvalidArgumentException('The FCC county name is invalid.');
    }
    if (!$regionalCount && (!in_array($center, ['user', 'postal', 'city', 'call'], true) ||
        ($center === 'postal' && $postal === '') ||
        ($center === 'city' && ($centerCity === '' || $centerState === '')) ||
        ($center === 'call' && $centerCall === ''))) {
        throw new InvalidArgumentException('The FCC search center is invalid.');
    }
    if ($radius !== null && ($radius <= 0 || $radius > 250)) {
        throw new InvalidArgumentException('The search radius must be between 0 and 250 miles.');
    }
    if (!$regionalCount && $postal === '' && $radius === null && $call === '' && $street === '' &&
        $callPrefix === '' && $callSuffix === '' && $lastName === '') {
        throw new InvalidArgumentException('Please include a callsign, name, callsign pattern, ZIP code, street, or search radius.');
    }
    $username = (string)$_SESSION['myUsername'];
    $userCall = strtoupper(trim((string)($_SESSION['myCall'] ?? '')));
    $cacheKey = hash('sha256', json_encode([
        'version' => 9,
        'username' => $username,
        'postal_code' => $postal,
        'call' => $call,
        'street' => strtoupper($street),
        'city' => strtoupper($city),
        'state' => $state,
        'center_city' => strtoupper($centerCity),
        'center_state' => $centerState,
        'center_call' => $centerCall,
        'center_latitude' => $requestedCenterLat,
        'center_longitude' => $requestedCenterLon,
        'call_prefix' => $callPrefix,
        'call_suffix' => $callSuffix,
        'last_name' => strtoupper($lastName),
        'license_class_code' => $classCode,
        'center' => $center,
        'radius_miles' => $radius,
        'clubs_only' => $clubsOnly,
    ], JSON_UNESCAPED_SLASHES));
    $searchCache = $_SESSION['elmer_fcc_club_cache'] ?? null;
    if (is_array($searchCache) &&
        hash_equals((string)($searchCache['key'] ?? ''), $cacheKey) &&
        (int)($searchCache['expires_at'] ?? 0) >= time() &&
        is_array($searchCache['payload'] ?? null) &&
        is_array($searchCache['all_results'] ?? null)) {
        $payload = $searchCache['payload'];
        $allResults = $searchCache['all_results'];
        usort($allResults, static function ($a, $b) use ($sort, $userCall) {
            $aOwn = $userCall !== '' && strtoupper((string)$a['call']) === $userCall;
            $bOwn = $userCall !== '' && strtoupper((string)$b['call']) === $userCall;
            if ($aOwn !== $bOwn) return $aOwn ? -1 : 1;
            if ($sort === 'name') return strcasecmp((string)$a['name'], (string)$b['name']) ?:
                strcmp((string)$a['call'], (string)$b['call']);
            if ($sort === 'call') return strcmp((string)$a['call'], (string)$b['call']);
            return ($a['distance_miles'] <=> $b['distance_miles']) ?: strcmp((string)$a['call'], (string)$b['call']);
        });
        $payload['results'] = array_slice($allResults, $offset, $limit);
        $payload['returned'] = count($payload['results']);
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    $uid = (int)getUserField($username, 'uID');
    if ($uid < 1) throw new RuntimeException('The signed-in RigPi account was not found.');
    $db = new mysqli('localhost', $sql_radio_username, $sql_radio_password, $sql_radio_database);
    if ($db->connect_errno) throw new RuntimeException('The RigPi database is unavailable.');
    $db->set_charset('utf8mb4');

    if ($regionalCount) {
        $count = 0;
        $regionLabel = '';
        $countBasis = '';
        $classLabels = ['N' => 'Novice', 'T' => 'Technician', 'P' => 'Technician Plus',
            'G' => 'General', 'A' => 'Advanced', 'E' => 'Amateur Extra'];
        $classLabel = $classCode !== '' ? ($classLabels[$classCode] ?? $classCode) : '';
        $classJoin = $classCode !== '' ? ' JOIN fcc_amateur.am am ON am.fccid=hd.fccid ' : ' ';
        $classCondition = $classCode !== '' ? " AND am.class='" . $classCode . "'" : '';
        if ($regionType === 'state') {
            if (!preg_match('/^[A-Z]{2}$/', $state)) {
                throw new InvalidArgumentException('Please use a two-letter state abbreviation.');
            }
            $stmt = $db->prepare('SELECT COUNT(DISTINCT hd.fccid) AS total '
                . 'FROM fcc_amateur.hd hd' . $classJoin
                . 'JOIN fcc_amateur.en en ON en.fccid=hd.fccid '
                . 'WHERE hd.status=\'A\' AND UPPER(en.state)=?' . $classCondition);
            $stmt->bind_param('s', $state);
            $stmt->execute();
            $count = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
            $stateStmt = $db->prepare('SELECT State FROM States WHERE Abbr=? LIMIT 1');
            $stateStmt->bind_param('s', $state);
            $stateStmt->execute();
            $stateName = (string)($stateStmt->get_result()->fetch_assoc()['State'] ?? $state);
            $regionLabel = $stateName . ' (' . $state . ')';
            $countBasis = 'Exact active FCC amateur-license records whose FCC mailing-state field matches the requested state.';
        } elseif ($regionType === 'city') {
            if ($city === '' || !preg_match('/^[A-Z]{2}$/', $state)) {
                throw new InvalidArgumentException('Please include a city and two-letter state abbreviation.');
            }
            $stmt = $db->prepare('SELECT COUNT(DISTINCT hd.fccid) AS total '
                . 'FROM fcc_amateur.hd hd' . $classJoin
                . 'JOIN fcc_amateur.en en ON en.fccid=hd.fccid '
                . 'WHERE hd.status=\'A\' AND UPPER(en.city)=UPPER(?) AND UPPER(en.state)=?'
                . $classCondition);
            $stmt->bind_param('ss', $city, $state);
            $stmt->execute();
            $count = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
            $regionLabel = ucwords(strtolower($city)) . ', ' . $state;
            $countBasis = 'Exact active FCC amateur-license records whose FCC mailing-city and mailing-state fields match the requested region.';
        } elseif ($regionType === 'county') {
            if ($county === '' || !preg_match('/^[A-Z]{2}$/', $state)) {
                throw new InvalidArgumentException('Please include a county and two-letter state abbreviation.');
            }
            $stmt = $db->prepare('SELECT COUNT(DISTINCT hd.fccid) AS total '
                . 'FROM fcc_amateur.hd hd' . $classJoin
                . 'JOIN fcc_amateur.en en ON en.fccid=hd.fccid '
                . 'WHERE hd.status=\'A\' AND LEFT(en.zip,5) IN ('
                . 'SELECT z.ZIP_CODE FROM ZipCode z JOIN Counties c '
                . 'ON FLOOR(c.FIPS/1000)=CAST(z.STATE AS UNSIGNED) '
                . 'AND MOD(c.FIPS,1000)=CAST(z.COUNTY AS UNSIGNED) '
                . 'WHERE c.ST=? AND UPPER(c.County)=UPPER(?))' . $classCondition);
            $stmt->bind_param('ss', $state, $county);
            $stmt->execute();
            $count = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
            $regionLabel = ucwords(strtolower($county)) . ' County, ' . $state;
            $countBasis = 'Active FCC amateur-license records grouped by FCC mailing ZIP, then mapped from ZIP to county. ZIP-to-county assignment is approximate where a ZIP crosses county boundaries.';
        } else {
            if (!in_array($country, ['US', 'USA', 'UNITED STATES', 'UNITED STATES OF AMERICA'], true)) {
                throw new InvalidArgumentException('The FCC database provides a United States license total, not worldwide amateur-radio counts.');
            }
            $stmt = $db->prepare('SELECT COUNT(DISTINCT hd.fccid) AS total FROM fcc_amateur.hd hd'
                . $classJoin . 'WHERE hd.status=\'A\'' . $classCondition);
            $stmt->execute();
            $count = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
            $regionLabel = 'United States';
            $countBasis = 'All active records in the FCC amateur-license database; this is a US licensing total, not a worldwide callbook count.';
        }
        if ($groupBy === 'license_class') {
            if ($regionType === 'state') {
                $classStmt = $db->prepare('SELECT am.class,COUNT(DISTINCT hd.fccid) AS total '
                    . 'FROM fcc_amateur.hd hd JOIN fcc_amateur.am am ON am.fccid=hd.fccid '
                    . 'JOIN fcc_amateur.en en ON en.fccid=hd.fccid '
                    . 'WHERE hd.status=\'A\' AND UPPER(en.state)=? GROUP BY am.class');
                $classStmt->bind_param('s', $state);
            } elseif ($regionType === 'city') {
                $classStmt = $db->prepare('SELECT am.class,COUNT(DISTINCT hd.fccid) AS total '
                    . 'FROM fcc_amateur.hd hd JOIN fcc_amateur.am am ON am.fccid=hd.fccid '
                    . 'JOIN fcc_amateur.en en ON en.fccid=hd.fccid '
                    . 'WHERE hd.status=\'A\' AND UPPER(en.city)=UPPER(?) AND UPPER(en.state)=? '
                    . 'GROUP BY am.class');
                $classStmt->bind_param('ss', $city, $state);
            } elseif ($regionType === 'county') {
                $classStmt = $db->prepare('SELECT am.class,COUNT(DISTINCT hd.fccid) AS total '
                    . 'FROM fcc_amateur.hd hd JOIN fcc_amateur.am am ON am.fccid=hd.fccid '
                    . 'JOIN fcc_amateur.en en ON en.fccid=hd.fccid '
                    . 'WHERE hd.status=\'A\' AND LEFT(en.zip,5) IN ('
                    . 'SELECT z.ZIP_CODE FROM ZipCode z JOIN Counties c '
                    . 'ON FLOOR(c.FIPS/1000)=CAST(z.STATE AS UNSIGNED) '
                    . 'AND MOD(c.FIPS,1000)=CAST(z.COUNTY AS UNSIGNED) '
                    . 'WHERE c.ST=? AND UPPER(c.County)=UPPER(?)) GROUP BY am.class');
                $classStmt->bind_param('ss', $state, $county);
            } else {
                $classStmt = $db->prepare('SELECT am.class,COUNT(DISTINCT hd.fccid) AS total '
                    . 'FROM fcc_amateur.hd hd JOIN fcc_amateur.am am ON am.fccid=hd.fccid '
                    . 'WHERE hd.status=\'A\' GROUP BY am.class');
            }
            $classStmt->execute();
            $classRows = $classStmt->get_result();
            $grouped = [];
            while ($row = $classRows->fetch_assoc()) {
                $code = strtoupper(trim((string)($row['class'] ?? '')));
                if (isset($classLabels[$code])) $grouped[$code] = (int)$row['total'];
            }
            $parts = [];
            $count = 0;
            foreach (['E', 'A', 'G', 'P', 'T', 'N'] as $code) {
                $value = (int)($grouped[$code] ?? 0);
                $count += $value;
                $parts[] = $classLabels[$code] . ' ' . $value;
            }
            $regionLabel .= ' — by license class';
            $countBasis .= ' Breakdown: ' . implode('; ', $parts) . '.';
        } elseif ($classLabel !== '') {
            $regionLabel .= ' — ' . $classLabel;
            $countBasis .= ' The count is limited to FCC license class ' . $classCode
                . ' (' . $classLabel . ').';
        }
        echo json_encode([
            'provider' => 'RigPi onboard FCC database',
            'retrieved_at' => gmdate('c'),
            'query_type' => 'regional_count',
            'region_type' => $regionType,
            'region_label' => $regionLabel,
            'count_basis' => $countBasis,
            'active_only' => true,
            'total_matches' => $count,
            'returned' => 0,
            'radius_applied' => false,
            'distance_basis' => 'No distance search was used for this regional count.',
            'notice' => $groupBy === 'license_class'
                ? 'The total includes active individual FCC amateur licenses with a recognized class; club and other unclassified records are excluded.'
                : ($classCode !== ''
                ? 'The count is limited to active FCC ' . $classLabel . ' license records.'
                : 'Counts are active FCC amateur-license records and may include club and other non-individual license records.'),
            'results' => [],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    $centerCoordinateNotice = '';

    if ($lastName !== '' && $radius === null) {
        $centerLat = 0.0;
        $centerLon = 0.0;
        $centerLabel = 'active United States FCC amateur-license records';
    } elseif ($center === 'user') {
        $stmt = $db->prepare('SELECT My_Latitude, My_Longitude, MyZIP FROM Users WHERE uID=? LIMIT 1');
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $centerRow = $stmt->get_result()->fetch_assoc();
        $centerLat = (float)($centerRow['My_Latitude'] ?? 0);
        $centerLon = (float)($centerRow['My_Longitude'] ?? 0);
        $centerLabel = 'signed-in RigPi account';
        if ($centerLat < -90 || $centerLat > 90 || $centerLon < -180 || $centerLon > 180 ||
            ($centerLat == 0.0 && $centerLon == 0.0)) {
            throw new InvalidArgumentException('Set your latitude and longitude in RigPi User Settings before searching near you.');
        }
    } elseif ($center === 'postal') {
        elmerFccEnsureGeocodeTable($db);
        $stmt = $db->prepare('SELECT COUNT(*) AS samples,AVG(g.latitude) AS LATITUDE,AVG(g.longitude) AS LONGITUDE '
            . 'FROM ElmerFCCGeocode g JOIN fcc_amateur.en en ON en.fccid=g.fccid '
            . 'WHERE LEFT(en.zip,5)=? AND g.match_status=\'Match\' AND g.latitude IS NOT NULL AND g.longitude IS NOT NULL');
        $stmt->bind_param('s', $postal);
        $stmt->execute();
        $centerRow = $stmt->get_result()->fetch_assoc();
        if ((int)($centerRow['samples'] ?? 0) >= 3) {
            $centerLat = (float)$centerRow['LATITUDE'];
            $centerLon = (float)$centerRow['LONGITUDE'];
            $centerLabel = 'ZIP ' . $postal . ' FCC-address centroid';
        } else {
            $stmt = $db->prepare('SELECT LATITUDE, LONGITUDE FROM ZipCode WHERE ZIP_CODE=? LIMIT 1');
            $stmt->bind_param('s', $postal);
            $stmt->execute();
            $centerRow = $stmt->get_result()->fetch_assoc();
            if (!$centerRow) throw new InvalidArgumentException('That ZIP code is not in the onboard ZIP database.');
            $centerLat = (float)$centerRow['LATITUDE'];
            $centerLon = (float)$centerRow['LONGITUDE'];
            $centerLabel = 'ZIP ' . $postal . ' database centroid';
        }
    } elseif ($center === 'city') {
        elmerFccEnsureGeocodeTable($db);
        $stmt = $db->prepare('SELECT LEFT(en.zip,5) AS primary_zip,COUNT(*) AS licensees,'
            . 'AVG(CAST(z.LATITUDE AS DECIMAL(11,7))) AS LATITUDE,'
            . 'AVG(CAST(z.LONGITUDE AS DECIMAL(11,7))) AS LONGITUDE '
            . 'FROM fcc_amateur.hd hd JOIN fcc_amateur.en en ON en.fccid=hd.fccid '
            . 'JOIN ZipCode z ON z.ZIP_CODE=LEFT(en.zip,5) '
            . 'WHERE hd.status=\'A\' AND UPPER(en.city)=UPPER(?) AND UPPER(en.state)=? '
            . 'GROUP BY LEFT(en.zip,5) ORDER BY licensees DESC LIMIT 1');
        $stmt->bind_param('ss', $centerCity, $centerState);
        $stmt->execute();
        $cityRow = $stmt->get_result()->fetch_assoc();
        if (!$cityRow) throw new InvalidArgumentException('That city and state were not found in the onboard FCC database.');
        $primaryZip = (string)$cityRow['primary_zip'];
        $centerLat = (float)$cityRow['LATITUDE'];
        $centerLon = (float)$cityRow['LONGITUDE'];
        $stmt = $db->prepare('SELECT COUNT(*) AS samples,AVG(g.latitude) AS LATITUDE,AVG(g.longitude) AS LONGITUDE '
            . 'FROM ElmerFCCGeocode g JOIN fcc_amateur.en en ON en.fccid=g.fccid '
            . 'WHERE UPPER(en.city)=UPPER(?) AND UPPER(en.state)=? AND g.match_status=\'Match\' '
            . 'AND g.latitude IS NOT NULL AND g.longitude IS NOT NULL');
        $stmt->bind_param('ss', $centerCity, $centerState);
        $stmt->execute();
        $geocodedCity = $stmt->get_result()->fetch_assoc();
        if ((int)($geocodedCity['samples'] ?? 0) >= 3) {
            $centerLat = (float)$geocodedCity['LATITUDE'];
            $centerLon = (float)$geocodedCity['LONGITUDE'];
        }
        $centerLabel = ucwords(strtolower($centerCity)) . ', ' . $centerState
            . ' (ZIP ' . $primaryZip . ' area)';
    } else {
        elmerFccEnsureGeocodeTable($db);
        $stmt = $db->prepare('SELECT hd.fccid,hd.callsign,en.address1,en.city,en.state,en.zip '
            . 'FROM fcc_amateur.hd hd JOIN fcc_amateur.en en ON en.fccid=hd.fccid '
            . 'WHERE hd.status=\'A\' AND hd.callsign=? LIMIT 1');
        $stmt->bind_param('s', $centerCall);
        $stmt->execute();
        $centerRow = $stmt->get_result()->fetch_assoc();
        if (!$centerRow) throw new InvalidArgumentException('That callsign was not found in the onboard FCC database.');
        $centerCache = elmerFccReadGeocodeCache($db, [(int)$centerRow['fccid']]);
        $centerPoint = $centerCache[(int)$centerRow['fccid']] ?? null;
        if (!$centerPoint || !hash_equals((string)$centerPoint['address_hash'], elmerFccAddressHash($centerRow)) ||
            $centerPoint['match_status'] !== 'Match' || $centerPoint['latitude'] === null || $centerPoint['longitude'] === null) {
            elmerFccCensusBatch($db, [$centerRow], 1);
            $centerCache = elmerFccReadGeocodeCache($db, [(int)$centerRow['fccid']]);
            $centerPoint = $centerCache[(int)$centerRow['fccid']] ?? null;
        }
        if ($centerPoint && $centerPoint['match_status'] === 'Match' &&
            $centerPoint['latitude'] !== null && $centerPoint['longitude'] !== null) {
            $centerLat = (float)$centerPoint['latitude'];
            $centerLon = (float)$centerPoint['longitude'];
            $centerLabel = $centerCall . ' FCC mailing address';
        } elseif ($requestedCenterLat !== null && $requestedCenterLon !== null) {
            $centerLat = $requestedCenterLat;
            $centerLon = $requestedCenterLon;
            $basis = $requestedCenterBasis !== '' ? $requestedCenterBasis : 'callbook coordinates';
            $centerLabel = $centerCall . ' ' . $basis;
            $centerCoordinateNotice = 'The FCC mailing address could not be geocoded; the search center uses ' . $basis . '.';
        } else {
            $centerZip = substr(trim((string)$centerRow['zip']), 0, 5);
            $stmt = $db->prepare('SELECT LATITUDE,LONGITUDE FROM ZipCode WHERE ZIP_CODE=? LIMIT 1');
            $stmt->bind_param('s', $centerZip);
            $stmt->execute();
            $zipPoint = $stmt->get_result()->fetch_assoc();
            if (!$zipPoint) throw new InvalidArgumentException('The FCC address for that callsign could not be located.');
            $centerLat = (float)$zipPoint['LATITUDE'];
            $centerLon = (float)$zipPoint['LONGITUDE'];
            $centerLabel = $centerCall . ' FCC mailing ZIP ' . $centerZip . ' centroid';
        }
    }

    $baseSelect = 'SELECT hd.fccid,hd.callsign,en.full_name,en.first,en.middle,en.last,en.address1,en.city,en.state,en.zip,'
        . 'am.class AS license_class_code,am.former_call '
        . 'FROM fcc_amateur.hd hd JOIN fcc_amateur.en en ON en.fccid=hd.fccid '
        . 'LEFT JOIN fcc_amateur.am am ON am.fccid=hd.fccid ';
    $clubCondition = $clubsOnly
        ? " AND (UPPER(en.full_name) LIKE '%CLUB%' OR UPPER(en.full_name) LIKE '%GROUP%' OR UPPER(en.full_name) LIKE '%ASSOCIATION%')"
        : '';
    $classCondition = $classCode !== '' ? " AND am.class='" . $classCode . "'" : '';
    $streetCondition = $street !== '' ? " AND UPPER(en.address1) LIKE '%" . strtoupper($street) . "%'" : '';
    $cityCondition = $city !== '' ? " AND UPPER(en.city)='" . strtoupper($city) . "'" : '';
    $stateCondition = $state !== '' ? " AND UPPER(en.state)='" . $state . "'" : '';
    $locationCondition = $streetCondition . $cityCondition . $stateCondition;
    $callPatternCondition = ($callPrefix !== '' ? " AND UPPER(hd.callsign) LIKE '" . $callPrefix . "%'" : '') .
        ($callSuffix !== '' ? " AND UPPER(hd.callsign) LIKE '%" . $callSuffix . "'" : '');
    if ($call !== '') {
        $stmt = $db->prepare($baseSelect . 'WHERE hd.status=\'A\' AND hd.callsign=?' . $clubCondition . $classCondition . $locationCondition . ' LIMIT 1');
        $stmt->bind_param('s', $call);
    } elseif ($street !== '') {
        $stmt = $db->prepare($baseSelect . 'WHERE hd.status=\'A\'' . $clubCondition . $classCondition .
            $locationCondition . ' ORDER BY hd.callsign LIMIT 2000');
    } elseif ($callPrefix !== '' || $callSuffix !== '') {
        $stmt = $db->prepare($baseSelect . 'WHERE hd.status=\'A\'' . $clubCondition . $classCondition .
            $callPatternCondition . $locationCondition . ' ORDER BY hd.callsign LIMIT 10000');
    } elseif ($lastName !== '') {
        $stmt = $db->prepare($baseSelect . 'WHERE hd.status=\'A\' AND UPPER(en.last)=UPPER(?)' .
            $clubCondition . $classCondition . $locationCondition . ' ORDER BY en.last,en.first,hd.callsign LIMIT 10000');
        $stmt->bind_param('s', $lastName);
    } elseif ($postal !== '' && $radius === null) {
        $stmt = $db->prepare($baseSelect . 'WHERE hd.status=\'A\' AND LEFT(en.zip,5)=?' . $clubCondition . $classCondition . $locationCondition . ' ORDER BY hd.callsign');
        $stmt->bind_param('s', $postal);
    } else {
        // ZIP centroids are used only to choose a generous local candidate set;
        // final inclusion is based on the geocoded FCC street address.
        $prefilter = min(275.0, (float)$radius + 15.0);
        $latDelta = $prefilter / 69.0;
        $lonDelta = $prefilter / max(10.0, 69.0 * cos(deg2rad($centerLat)));
        $south = $centerLat - $latDelta;
        $north = $centerLat + $latDelta;
        $west = $centerLon - $lonDelta;
        $east = $centerLon + $lonDelta;
        $distanceSql = '(3958.7613 * ACOS(LEAST(1, GREATEST(-1, '
            . 'SIN(RADIANS(?))*SIN(RADIANS(CAST(z.LATITUDE AS DECIMAL(11,7))))+'
            . 'COS(RADIANS(?))*COS(RADIANS(CAST(z.LATITUDE AS DECIMAL(11,7))))*'
            . 'COS(RADIANS(CAST(z.LONGITUDE AS DECIMAL(11,7)))-RADIANS(?))))))';
        $candidateLimit = ($classCode !== '' || $center === 'city') ? 10000 : 2000;
        $nearbyCondition = 'CAST(z.LATITUDE AS DECIMAL(11,7)) BETWEEN ? AND ?'
            . ' AND CAST(z.LONGITUDE AS DECIMAL(11,7)) BETWEEN ? AND ?'
            . ' AND ' . $distanceSql . '<=?';
        $zipStmt = $db->prepare('SELECT z.ZIP_CODE FROM ZipCode z WHERE ' . $nearbyCondition . ' LIMIT 2000');
        $zipStmt->bind_param('dddddddd', $south, $north, $west, $east,
            $centerLat, $centerLat, $centerLon, $prefilter);
        $zipStmt->execute();
        $zipRows = $zipStmt->get_result();
        $nearbyZips = [];
        while ($zipRow = $zipRows->fetch_assoc()) {
            $zip = trim((string)$zipRow['ZIP_CODE']);
            if (preg_match('/^\d{5}$/', $zip)) $nearbyZips[$zip] = true;
        }
        if ($postal !== '') $nearbyZips[$postal] = true;
        if (!$nearbyZips) {
            $stmt = $db->prepare($baseSelect . 'WHERE 1=0');
        } else {
            $zipConditions = array_map(static fn($zip) => "en.zip LIKE '" . $zip . "%'",
                array_keys($nearbyZips));
            $sql = $baseSelect . 'WHERE hd.status=\'A\'' . $clubCondition . $classCondition
                . ' AND (' . implode(' OR ', $zipConditions) . ') ORDER BY hd.callsign LIMIT ' . $candidateLimit;
            $stmt = $db->prepare($sql);
        }
    }
    $stmt->execute();
    $rows = $stmt->get_result();
    $candidates = [];
    while ($row = $rows->fetch_assoc()) {
        if ($centerCall !== '' && strtoupper(trim((string)$row['callsign'])) === $centerCall) continue;
        $candidates[] = $row;
    }

    $geocode = ['attempted' => 0, 'matched' => 0, 'unmatched' => 0, 'error' => ''];
    $cache = [];
    $deferred = 0;
    $needsGeocoding = $radius !== null || $call !== '';
    $useZipCentroids = $clubsOnly || $classCode !== '' || $center === 'city';
    if ($needsGeocoding && $candidates && !$useZipCentroids) {
        elmerFccEnsureGeocodeTable($db);
        $cache = elmerFccReadGeocodeCache($db, array_column($candidates, 'fccid'));
        $needed = [];
        foreach ($candidates as $row) {
            $id = (int)$row['fccid'];
            if (trim((string)$row['address1']) === '') continue;
            if (!isset($cache[$id]) || !hash_equals((string)$cache[$id]['address_hash'], elmerFccAddressHash($row))) {
                $needed[] = $row;
            }
        }
        $deferred = max(0, count($needed) - 1000);
        $geocode = elmerFccCensusBatch($db, $needed, 1000);
        $cache = elmerFccReadGeocodeCache($db, array_column($candidates, 'fccid'));
    }

    $evaluated = [];
    $mapPoints = [];
    $geocodedMatches = 0;
    $zipCentroidMatches = 0;
    $geocodeUnmatched = 0;
    $zipCenterCache = [];
    $zipCenterStmt = $useZipCentroids ? $db->prepare('SELECT LATITUDE,LONGITUDE FROM ZipCode WHERE ZIP_CODE=? LIMIT 1') : null;
    $classNames = ['T' => 'Technician', 'P' => 'Technician Plus', 'G' => 'General',
        'E' => 'Amateur Extra', 'A' => 'Advanced', 'N' => 'Novice'];
    $formatCandidate = static function (array $row, float $distance) use ($classNames, $clubsOnly, $street): array {
        $personName = trim(ucwords(strtolower(trim(($row['first'] ?? '') . ' ' . ($row['middle'] ?? '') . ' ' . ($row['last'] ?? '')))));
        $name = $clubsOnly ? trim((string)($row['full_name'] ?? '')) : $personName;
        $code = strtoupper(trim((string)($row['license_class_code'] ?? '')));
        $classLabel = $code === '' ? '' : (($classNames[$code] ?? $code) . ' (' . $code . ')');
        return [
            'call' => strtoupper(trim((string)$row['callsign'])),
            'name' => $name,
            'address_line' => $street !== '' ? trim((string)($row['address1'] ?? '')) : '',
            'city' => ucwords(strtolower(trim((string)$row['city']))),
            'state' => strtoupper(trim((string)$row['state'])),
            'postal_code' => substr(trim((string)$row['zip']), 0, 5),
            'license_class' => $classLabel,
            'former_call' => strtoupper(trim((string)($row['former_call'] ?? ''))),
            'distance_miles' => round($distance, 1),
        ];
    };
    foreach ($candidates as $row) {
        $distance = 0.0;
        if ($needsGeocoding) {
            $cached = $cache[(int)$row['fccid']] ?? null;
            $pointLat = null;
            $pointLon = null;
            if ($cached && hash_equals((string)$cached['address_hash'], elmerFccAddressHash($row)) &&
                $cached['match_status'] === 'Match' && $cached['latitude'] !== null && $cached['longitude'] !== null) {
                $geocodedMatches++;
                $pointLat = (float)$cached['latitude'];
                $pointLon = (float)$cached['longitude'];
            } elseif ($useZipCentroids && isset($row['zip_latitude'], $row['zip_longitude'])) {
                $zipCentroidMatches++;
                $pointLat = (float)$row['zip_latitude'];
                $pointLon = (float)$row['zip_longitude'];
            } elseif ($useZipCentroids && $zipCenterStmt) {
                $rowZip = substr(trim((string)$row['zip']), 0, 5);
                if (!array_key_exists($rowZip, $zipCenterCache)) {
                    $zipCenterStmt->bind_param('s', $rowZip);
                    $zipCenterStmt->execute();
                    $zipCenterCache[$rowZip] = $zipCenterStmt->get_result()->fetch_assoc() ?: null;
                }
                $zipPoint = $zipCenterCache[$rowZip];
                if ($zipPoint) {
                    $zipCentroidMatches++;
                    $pointLat = (float)$zipPoint['LATITUDE'];
                    $pointLon = (float)$zipPoint['LONGITUDE'];
                }
            }
            if ($pointLat === null || $pointLon === null) {
                $geocodeUnmatched++;
                continue;
            }
            $distance = elmerFccDistanceMiles($centerLat, $centerLon, $pointLat, $pointLon);
            if ($radius !== null && $distance > (float)$radius) continue;
            $mapClassCode = strtoupper(trim((string)($row['license_class_code'] ?? '')));
            $mapClassLabel = $mapClassCode === '' ? '' :
                (($classNames[$mapClassCode] ?? $mapClassCode) . ' (' . $mapClassCode . ')');
            $mapPoints[] = [
                'call' => strtoupper(trim((string)$row['callsign'])),
                'name' => $clubsOnly ? trim((string)($row['full_name'] ?? '')) :
                    trim(ucwords(strtolower(trim(($row['first'] ?? '') . ' ' . ($row['middle'] ?? '') . ' ' . ($row['last'] ?? ''))))),
                'license_class' => $mapClassLabel,
                'latitude' => round($pointLat, 7),
                'longitude' => round($pointLon, 7),
                'distance_miles' => round($distance, 1),
            ];
        }
        $evaluated[] = $formatCandidate($row, $distance);
    }
    usort($evaluated, static function ($a, $b) use ($sort, $userCall) {
        $aOwn = $userCall !== '' && strtoupper((string)$a['call']) === $userCall;
        $bOwn = $userCall !== '' && strtoupper((string)$b['call']) === $userCall;
        if ($aOwn !== $bOwn) return $aOwn ? -1 : 1;
        if ($sort === 'name') return strcasecmp((string)$a['name'], (string)$b['name']) ?:
            strcmp((string)$a['call'], (string)$b['call']);
        if ($sort === 'call') return strcmp((string)$a['call'], (string)$b['call']);
        return ($a['distance_miles'] <=> $b['distance_miles']) ?: strcmp((string)$a['call'], (string)$b['call']);
    });
    $total = count($evaluated);
    $results = array_slice($evaluated, $offset, $limit);
    $radiusApplied = $radius === null || $geocodedMatches > 0 || $zipCentroidMatches > 0;
    $noticeParts = [];
    if ($clubsOnly) $noticeParts[] = 'Results are limited to active FCC license names containing club, group, or association.';
    if ($classCode !== '') $noticeParts[] = 'Results are limited to FCC license class ' . $classCode . '.';
    if ($callPrefix !== '') $noticeParts[] = 'Results are limited to active callsigns beginning with ' . $callPrefix . '.';
    if ($callSuffix !== '') $noticeParts[] = 'Results are limited to active callsigns ending with ' . $callSuffix . '.';
    if ($lastName !== '') $noticeParts[] = 'Results are limited to active FCC amateur-license records with the exact last name ' . strtoupper($lastName) . '.';
    if ($zipCentroidMatches > 0) $noticeParts[] = $zipCentroidMatches . ' records were located by FCC mailing ZIP centroid.';
    if ($geocode['error'] !== '') $noticeParts[] = 'Census geocoding was unavailable: ' . $geocode['error'];
    if ($geocodeUnmatched > 0) $noticeParts[] = $geocodeUnmatched . ' candidate addresses could not be located and were not included in radius results.';
    if ($deferred > 0) $noticeParts[] = $deferred . ' candidate addresses were deferred to a later search.';
    if ($centerCoordinateNotice !== '') $noticeParts[] = $centerCoordinateNotice;
    if ($radius !== null && !$radiusApplied) $noticeParts[] = 'The requested person-level radius could not be applied.';
    if ($radius !== null && !$radiusApplied && $postal !== '') {
        $zipStmt = $db->prepare('SELECT LATITUDE,LONGITUDE FROM ZipCode WHERE ZIP_CODE=? LIMIT 1');
        $zipStmt->bind_param('s', $postal);
        $zipStmt->execute();
        $zipPoint = $zipStmt->get_result()->fetch_assoc();
        $fallbackDistance = $zipPoint ? elmerFccDistanceMiles($centerLat, $centerLon,
            (float)$zipPoint['LATITUDE'], (float)$zipPoint['LONGITUDE']) : 0.0;
        $evaluated = array_map(static fn($row) => $formatCandidate($row, $fallbackDistance), $candidates);
        $total = count($evaluated);
        $results = array_slice($evaluated, $offset, $limit);
        $noticeParts[] = 'Active licensees in the ZIP are shown as a fallback and are not claimed to be within the requested radius.';
    }
    $notice = substr(implode(' ', $noticeParts), 0, 240);
    $payload = [
        'provider' => 'RigPi onboard FCC database',
        'retrieved_at' => gmdate('c'),
        'postal_code_filter' => $postal,
        'radius_miles' => $radius,
        'radius_applied' => $radiusApplied,
        'center' => $centerLabel,
        'distance_basis' => !$needsGeocoding ? ($lastName !== '' ? 'Exact FCC last-name filter; no geographic radius requested' : ($street !== '' ? 'FCC mailing street/city/state filter; no radius requested' :
            (($callPrefix !== '' || $callSuffix !== '') ? 'FCC callsign pattern filter; no radius requested' : 'ZIP filter only; no person-level radius requested'))) :
            ($zipCentroidMatches > 0 ?
                'FCC mailing ZIP centroids are used for this broad geographic search. Locations and distances are approximate.' :
                'FCC mailing addresses geocoded by the U.S. Census and measured locally; coordinates are interpolated, not guaranteed rooftop locations'),
        'notice' => $notice,
        'geocoder' => !$needsGeocoding ? '' : 'U.S. Census Public_AR_Current',
        'geocode_attempted' => (int)$geocode['attempted'],
        'geocoded_matches' => $geocodedMatches,
        'geocode_unmatched' => $geocodeUnmatched,
        'geocode_deferred' => $deferred,
        'total_matches' => $total,
        'returned' => count($results),
        'results' => $results,
    ];
    if ($needsGeocoding && $mapPoints) {
        $payload['map'] = [
            'center_latitude' => round($centerLat, 7),
            'center_longitude' => round($centerLon, 7),
            'center_label' => $centerLabel,
            'radius_miles' => $radius,
            'points' => array_slice($mapPoints, 0, 200),
        ];
    }
    $cachedPayload = $payload;
    unset($cachedPayload['results'], $cachedPayload['returned']);
    $_SESSION['elmer_fcc_club_cache'] = [
        'key' => $cacheKey,
        'expires_at' => time() + 300,
        'payload' => $cachedPayload,
        'all_results' => $evaluated,
    ];
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (JsonException | InvalidArgumentException $error) {
    http_response_code(400);
    echo json_encode(['error' => $error->getMessage()]);
} catch (Throwable $error) {
    error_log('Elmer FCC search: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'RigPi could not complete the FCC search.']);
}
