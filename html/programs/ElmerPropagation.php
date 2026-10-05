<?php
/** Station-aware HF propagation timing guidance for Ask Elmer. */
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
require_once $root . '/classes/MysqliDb.php';
ini_set('display_errors', '0');
ini_set('log_errors', '1');

$fetchJson = static function (string $url): ?array {
    $cache = sys_get_temp_dir() . '/elmer-propagation-' . hash('sha256', $url) . '.json';
    if (is_file($cache) && filemtime($cache) >= time() - 900) {
        $cached = json_decode((string) @file_get_contents($cache), true);
        if (is_array($cached)) return $cached;
    }
    $context = stream_context_create(['http' => [
        'timeout' => 15,
        'ignore_errors' => true,
        'header' => "User-Agent: RigPi-Elmer-Propagation/1.0\r\nAccept: application/json\r\n",
    ]]);
    $raw = @file_get_contents($url, false, $context);
    if ($raw === false || strlen($raw) > 1000000) return null;
    $data = json_decode($raw, true);
    if (!is_array($data)) return null;
    @file_put_contents($cache, json_encode($data), LOCK_EX);
    @chmod($cache, 0600);
    return $data;
};

$spotBands = [
    160 => '1.8MHz', 80 => '3.5MHz', 60 => '5MHz', 40 => '7MHz', 30 => '10MHz',
    20 => '14MHz', 17 => '18MHz', 15 => '21MHz', 12 => '24MHz', 10 => '28MHz',
];

$fetchDxSummit = static function (int $band) use ($spotBands): array {
    $cache = sys_get_temp_dir() . '/elmer-dxsummit-' . $band . 'm.csv';
    $now = time();
    $raw = null;
    $status = 'unavailable';
    $cacheAge = null;
    if (is_file($cache)) {
        $cacheAge = max(0, $now - (int) filemtime($cache));
        if ($cacheAge <= 600) {
            $raw = @file_get_contents($cache);
            $status = 'cached';
        }
    }
    if ($raw === null) {
        $url = 'http://www.dxsummit.fi/api/v1/spots?' . http_build_query([
            'include' => $spotBands[$band], 'limit' => 10000,
            'from_time' => $now - 86400, 'to_time' => $now, 'content_type' => 'csv',
        ]);
        $context = stream_context_create(['http' => [
            'timeout' => 15, 'ignore_errors' => true,
            'header' => "User-Agent: RigPi-Elmer-Propagation/1.0\r\nAccept: text/csv\r\n",
        ]]);
        $download = @file_get_contents($url, false, $context, 0, 2000001);
        if (is_string($download) && strlen($download) > 20 && strlen($download) <= 2000000 && str_starts_with($download, 'id,de_call,dx_call,')) {
            $raw = $download;
            $status = 'live';
            $cacheAge = 0;
            @file_put_contents($cache, $raw, LOCK_EX);
            @chmod($cache, 0600);
        } elseif (is_file($cache) && $cacheAge !== null && $cacheAge <= 172800) {
            $raw = @file_get_contents($cache);
            $status = 'stale_cache';
        }
    }
    return ['raw' => is_string($raw) ? $raw : null, 'status' => $status, 'cache_age' => $cacheAge];
};

// DX Summit longitudes are positive west and negative east.
$spotRegions = [
    'eastern_north_america' => [24, 55, 55, 95], 'europe' => [34, 72, -45, 25],
    'africa' => [-36, 38, -52, 20], 'asia' => [5, 78, -180, -25],
    'japan' => [24, 46, -154, -122], 'australia' => [-45, -10, -155, -110],
    'oceania' => [-50, 30, -180, 180], 'south_america' => [-58, 15, 30, 82],
    'caribbean' => [9, 28, 58, 90], 'north_america' => [15, 72, 50, 170],
];
$inSpotRegion = static function (float $lat, float $lon, string $region) use ($spotRegions): bool {
    if (!isset($spotRegions[$region])) return false;
    [$minLat, $maxLat, $minLon, $maxLon] = $spotRegions[$region];
    return $lat >= $minLat && $lat <= $maxLat && $lon >= $minLon && $lon <= $maxLon;
};

$summarizeSpots = static function (int $band, callable $targetMatch) use ($fetchDxSummit, $inSpotRegion): array {
    $feed = $fetchDxSummit($band);
    $base = [
        'spot_provider' => 'DX Summit', 'spot_reference' => 'https://www.dxsummit.fi/',
        'spot_status' => $feed['status'], 'spot_window_hours' => 24,
        'spot_cache_age_seconds' => $feed['cache_age'], 'spot_total_band_records' => 0,
        'spot_path_records' => 0, 'spot_east_to_target' => 0, 'spot_target_to_east' => 0,
        'spot_unique_spotters' => 0, 'spot_unique_stations' => 0,
        'spot_peak_start_utc' => null, 'spot_peak_end_utc' => null, 'spot_peak_records' => 0,
        'spot_limit_reached' => false, 'spot_hourly' => [],
        'spot_notice' => 'DX Summit observations are reports, not completed contacts or a guarantee. They are biased toward modes and operators that submit conventional cluster spots.',
    ];
    if (!is_string($feed['raw'])) return $base;
    $lines = preg_split('/\r\n|\n|\r/', trim($feed['raw']));
    if (!$lines || count($lines) < 2) return $base;
    $header = str_getcsv((string) array_shift($lines), ',', '"', '\\');
    $expected = ['id','de_call','dx_call','info','frequency','time','dx_country','de_latitude','de_longitude','dx_latitude','dx_longitude'];
    if ($header !== $expected) { $base['spot_status'] = 'unavailable'; return $base; }
    $now = time(); $cutoff = $now - 86400;
    $hourStart = (int) (floor($cutoff / 3600) * 3600);
    $buckets = [];
    for ($i = 0; $i < 25; $i++) $buckets[$hourStart + $i * 3600] = ['east_to_target' => 0, 'target_to_east' => 0];
    $seen = []; $spotters = []; $stations = []; $total = 0; $forward = 0; $reverse = 0;
    foreach ($lines as $line) {
        if ($line === '') continue;
        $row = str_getcsv($line, ',', '"', '\\');
        if (count($row) !== count($expected)) continue;
        $total++;
        [$id,$de,$dx,$info,$frequency,$stamp,$country,$deLat,$deLon,$dxLat,$dxLon] = $row;
        $de = strtoupper(trim($de)); $dx = strtoupper(trim($dx));
        if (!preg_match('/^[A-Z0-9\/]{3,16}$/', $de) || !preg_match('/^[A-Z0-9\/]{3,16}$/', $dx)) continue;
        if (!is_numeric($frequency) || !is_numeric($deLat) || !is_numeric($deLon) || !is_numeric($dxLat) || !is_numeric($dxLon)) continue;
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $stamp, new DateTimeZone('UTC'));
        $when = $parsed ? $parsed->getTimestamp() : false;
        if ($when === false || $when < $cutoff - 3600 || $when > $now + 300) continue;
        $deEast = $inSpotRegion((float) $deLat, (float) $deLon, 'eastern_north_america');
        $dxEast = $inSpotRegion((float) $dxLat, (float) $dxLon, 'eastern_north_america');
        $deTarget = $targetMatch((float) $deLat, (float) $deLon);
        $dxTarget = $targetMatch((float) $dxLat, (float) $dxLon);
        $direction = $deEast && $dxTarget ? 'east_to_target' : ($deTarget && $dxEast ? 'target_to_east' : '');
        if ($direction === '') continue;
        $hour = (int) (floor(max($cutoff, $when) / 3600) * 3600);
        if (!isset($buckets[$hour])) continue;
        $key = $direction . '|' . $hour . '|' . $de . '|' . $dx . '|' . round((float) $frequency, 1);
        if (isset($seen[$key])) continue;
        $seen[$key] = true; $buckets[$hour][$direction]++;
        $spotters[$de] = true; $stations[$dx] = true;
        if ($direction === 'east_to_target') $forward++; else $reverse++;
    }
    $hourly = [];
    foreach ($buckets as $hour => $counts) {
        $hourly[] = ['hour_utc' => gmdate('Y-m-d\TH:00:00\Z', $hour),
            'east_to_target' => $counts['east_to_target'], 'target_to_east' => $counts['target_to_east'],
            'total' => $counts['east_to_target'] + $counts['target_to_east']];
    }
    $peak = 0; $peakIndex = null;
    for ($i = 0; $i <= count($hourly) - 3; $i++) {
        $sum = $hourly[$i]['total'] + $hourly[$i + 1]['total'] + $hourly[$i + 2]['total'];
        if ($sum > $peak) { $peak = $sum; $peakIndex = $i; }
    }
    $base['spot_total_band_records'] = $total;
    $base['spot_path_records'] = $forward + $reverse;
    $base['spot_east_to_target'] = $forward; $base['spot_target_to_east'] = $reverse;
    $base['spot_unique_spotters'] = count($spotters); $base['spot_unique_stations'] = count($stations);
    $base['spot_peak_records'] = $peak; $base['spot_hourly'] = $hourly;
    $base['spot_limit_reached'] = $total >= 10000;
    if ($peakIndex !== null) {
        $peakStart = strtotime($hourly[$peakIndex]['hour_utc']);
        $base['spot_peak_start_utc'] = gmdate('Y-m-d\TH:i:s\Z', $peakStart);
        $base['spot_peak_end_utc'] = gmdate('Y-m-d\TH:i:s\Z', $peakStart + 10800);
    }
    return $base;
};

$targets = [
    'europe' => ['Europe', 50.1, 8.7],
    'africa' => ['Africa', 9.1, 18.3],
    'asia' => ['Asia', 35.7, 139.7],
    'japan' => ['Japan', 35.7, 139.7],
    'australia' => ['Australia', -33.9, 151.2],
    'oceania' => ['Oceania', -25.3, 133.8],
    'south_america' => ['South America', -15.8, -47.9],
    'caribbean' => ['Caribbean', 18.2, -66.5],
    'north_america' => ['North America', 39.8, -98.6],
];

$geocodeTarget = static function (string $query): ?array {
    $cache = sys_get_temp_dir() . '/elmer-target-' . hash('sha256', strtolower($query)) . '.json';
    $raw = null;
    if (is_file($cache) && filemtime($cache) >= time() - 2592000) $raw = @file_get_contents($cache);
    if ($raw === null) {
        $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
            'q' => $query, 'format' => 'jsonv2', 'limit' => 3, 'addressdetails' => 1,
        ]);
        $context = stream_context_create(['http' => [
            'timeout' => 15, 'ignore_errors' => true,
            'header' => "User-Agent: RigPi-Elmer/1.0 (https://rigpi.net)\r\nAccept: application/json\r\nAccept-Language: en\r\n",
        ]]);
        $download = @file_get_contents($url, false, $context);
        if (is_string($download) && strlen($download) <= 250000) {
            $decoded = json_decode($download, true);
            if (is_array($decoded) && count($decoded)) {
                $raw = $download;
                @file_put_contents($cache, $raw, LOCK_EX); @chmod($cache, 0600);
            }
        }
        if ($raw === null && is_file($cache) && filemtime($cache) >= time() - 31536000) $raw = @file_get_contents($cache);
    }
    $rows = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($rows) || !isset($rows[0]) || !is_array($rows[0])) return null;
    $row = $rows[0];
    $lat = filter_var($row['lat'] ?? null, FILTER_VALIDATE_FLOAT);
    $lon = filter_var($row['lon'] ?? null, FILTER_VALIDATE_FLOAT);
    $bounds = $row['boundingbox'] ?? null;
    if ($lat === false || $lon === false || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180
        || !is_array($bounds) || count($bounds) !== 4) return null;
    $bounds = array_map(static fn($value) => filter_var($value, FILTER_VALIDATE_FLOAT), $bounds);
    if (in_array(false, $bounds, true)) return null;
    [$south, $north, $west, $east] = array_map('floatval', $bounds);
    if ($south < -90 || $north > 90 || $south > $north || $west < -180 || $east > 180 || $west > $east) return null;
    $label = trim((string) ($row['display_name'] ?? $query));
    if ($label === '' || strlen($label) > 200) return null;
    return ['label' => $label, 'latitude' => (float) $lat, 'longitude' => (float) $lon,
        'bounds' => [$south, $north, $west, $east], 'type' => strtolower(trim((string) ($row['type'] ?? ''))),
        'reference' => 'https://www.openstreetmap.org/search?' . http_build_query(['query' => $query])];
};

$geocodedSpotMatcher = static function (array $target): callable {
    [$south, $north, $west, $east] = $target['bounds'];
    $latSpan = $north - $south; $lonSpan = $east - $west;
    if ($latSpan <= 45 && $lonSpan <= 120) {
        $pad = ($latSpan < 2 && $lonSpan < 2) ? 1.5 : 0.35;
        return static function (float $lat, float $westPositiveLon) use ($south, $north, $west, $east, $pad): bool {
            $lon = -$westPositiveLon;
            return $lat >= max(-90, $south - $pad) && $lat <= min(90, $north + $pad)
                && $lon >= $west - $pad && $lon <= $east + $pad;
        };
    }
    $centerLat = $target['latitude']; $centerLon = $target['longitude'];
    $radiusKm = in_array($target['type'], ['administrative','country','continent'], true) ? 2200.0 : 350.0;
    return static function (float $lat, float $westPositiveLon) use ($centerLat, $centerLon, $radiusKm): bool {
        $lon = -$westPositiveLon;
        $lat1 = deg2rad($centerLat); $lat2 = deg2rad($lat);
        $dLat = $lat2 - $lat1; $dLon = deg2rad($lon - $centerLon);
        $a = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLon / 2) ** 2;
        return 6371.0 * 2 * atan2(sqrt($a), sqrt(max(0.0, 1.0 - $a))) <= $radiusKm;
    };
};

$solar = static function (float $lat, float $lon, string $date) use ($fetchJson): ?array {
    $url = 'https://api.open-meteo.com/v1/forecast?' . http_build_query([
        'latitude' => round($lat, 5), 'longitude' => round($lon, 5),
        'daily' => 'sunrise,sunset', 'timezone' => 'auto',
        'start_date' => $date, 'end_date' => $date, 'timeformat' => 'unixtime',
    ]);
    $data = $fetchJson($url);
    $sunrise = $data['daily']['sunrise'][0] ?? null;
    $sunset = $data['daily']['sunset'][0] ?? null;
    $timezone = (string) ($data['timezone'] ?? '');
    if (!is_numeric($sunrise) || !is_numeric($sunset) || $timezone === '') return null;
    try { new DateTimeZone($timezone); } catch (Throwable $error) { return null; }
    return ['sunrise' => (int) $sunrise, 'sunset' => (int) $sunset, 'timezone' => $timezone];
};

try {
    $request = json_decode(file_get_contents('php://input'), true, 8, JSON_THROW_ON_ERROR);
    $band = (int) ($request['band_meters'] ?? 0);
    if (!in_array($band, [0, 160, 80, 60, 40, 30, 20, 17, 15, 12, 10], true)) {
        throw new InvalidArgumentException('Please specify a supported HF amateur band.');
    }
    $targetKey = strtolower(trim((string) ($request['target_region'] ?? '')));
    $targetQuery = trim((string) ($request['target_query'] ?? ''));
    if (isset($targets[$targetKey])) {
        [$targetLabel, $targetLat, $targetLon] = $targets[$targetKey];
        $targetQuery = $targetLabel;
        $targetBasis = 'Built-in regional planning point';
        $targetResolution = 'built_in_region';
        $targetReference = 'https://www.openstreetmap.org/';
        $targetMatch = static fn(float $lat, float $lon): bool => $inSpotRegion($lat, $lon, $targetKey);
    } else {
        if (strlen($targetQuery) < 2 || strlen($targetQuery) > 100 || preg_match('/[\x00-\x1F\x7F]/', $targetQuery)) {
            throw new InvalidArgumentException('Please specify a valid destination place or region.');
        }
        $resolvedTarget = $geocodeTarget($targetQuery);
        if (!$resolvedTarget) throw new RuntimeException('RigPi could not resolve that destination. Try a city, state, country, or named region.');
        $targetLabel = $resolvedTarget['label']; $targetLat = $resolvedTarget['latitude']; $targetLon = $resolvedTarget['longitude'];
        $targetBasis = 'OpenStreetMap Nominatim geocoding'; $targetResolution = 'geocoded_place';
        $targetReference = $resolvedTarget['reference']; $targetMatch = $geocodedSpotMatcher($resolvedTarget);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/i', '_', strtolower($targetQuery)), '_');
        $targetKey = $slug !== '' ? substr($slug, 0, 64) : 'place_' . substr(hash('sha256', $targetQuery), 0, 12);
    }
    $dayOffset = max(0, min(6, (int) ($request['day_offset'] ?? 0)));

    $userId = (int) getUserField((string) $_SESSION['myUsername'], 'uID');
    $db = new MysqliDb('localhost', $sql_radio_username, $sql_radio_password, $sql_radio_database);
    $db->where('uID', $userId);
    $account = $db->getOne('Users');
    if (!$account) throw new RuntimeException('The signed-in RigPi account was not found.');
    $bandBasis = 'Band specified in the question';
    if ($band === 0) {
        $radio = (int) ($account['SelectedRadio'] ?? 0);
        if ($radio < 1) throw new RuntimeException('Specify a band, or select and connect a radio so RigPi can use its current band.');
        $rigctldPort = (int) ($account['rigctldPort'] ?? 4532) + $radio - 1;
        $errno = 0; $error = '';
        $socket = @fsockopen('127.0.0.1', $rigctldPort, $errno, $error, 0.75);
        if (!is_resource($socket)) throw new RuntimeException('Specify a band, or connect the selected radio so RigPi can read its current band.');
        stream_set_timeout($socket, 0, 750000);
        @fwrite($socket, "f\n");
        $reply = trim((string) @fgets($socket));
        @fclose($socket);
        $frequency = preg_match('/^[0-9]{4,12}$/', $reply) ? (int) $reply : 0;
        $ranges = [
            160 => [1800000, 2000000], 80 => [3500000, 4000000], 60 => [5330000, 5407000],
            40 => [7000000, 7300000], 30 => [10100000, 10150000], 20 => [14000000, 14350000],
            17 => [18068000, 18168000], 15 => [21000000, 21450000], 12 => [24890000, 24990000],
            10 => [28000000, 29700000],
        ];
        foreach ($ranges as $candidate => [$low, $high]) {
            if ($frequency >= $low && $frequency <= $high) { $band = $candidate; break; }
        }
        if ($band === 0) throw new RuntimeException('Specify an HF band; the selected radio is not reporting a supported HF amateur-band frequency.');
        $bandBasis = 'Current band of selected Radio ' . $radio;
    }
    $lat = filter_var($account['My_Latitude'] ?? null, FILTER_VALIDATE_FLOAT);
    $lon = filter_var($account['My_Longitude'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($lat === false || $lon === false || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
        throw new RuntimeException('Add valid station coordinates to this RigPi account for propagation planning.');
    }
    $stationParts = array_values(array_filter([
        trim((string) ($account['MyCity'] ?? '')), trim((string) ($account['MyState'] ?? '')),
        trim((string) ($account['MyCountry'] ?? '')),
    ]));
    $stationLabel = count($stationParts) ? implode(', ', $stationParts) : 'Signed-in RigPi station';
    $todaySolar = $solar((float) $lat, (float) $lon, gmdate('Y-m-d'));
    if (!$todaySolar) throw new RuntimeException('Station timezone data is unavailable right now.');
    $stationZone = new DateTimeZone($todaySolar['timezone']);
    $stationNow = new DateTimeImmutable('now', $stationZone);
    $date = $stationNow->modify('+' . $dayOffset . ' day')->format('Y-m-d');
    $stationSun = $date === $stationNow->format('Y-m-d') ? $todaySolar : $solar((float) $lat, (float) $lon, $date);
    $targetSun = $solar((float) $targetLat, (float) $targetLon, $date);
    if (!$stationSun || !$targetSun) throw new RuntimeException('Solar-path timing data is unavailable right now.');

    if ($band <= 20) {
        $edgeHours = $band <= 12 ? 2.0 : ($band <= 15 ? 1.5 : 1.0);
        $start = $stationSun['sunrise'] + (int) round($edgeHours * 3600);
        $end = $targetSun['sunset'] - (int) round($edgeHours * 3600);
        $basis = 'Daylight-path planning window: after sunrise at the signed-in station and before sunset in the target region.';
    } elseif ($band <= 40) {
        $start = $targetSun['sunset'] - 3600;
        $end = $stationSun['sunset'] + 7200;
        $basis = 'Lower-HF transition window centered on afternoon/evening darkness arriving along the path.';
    } else {
        $start = $stationSun['sunset'] + 3600;
        $end = $stationSun['sunset'] + 6 * 3600;
        $basis = 'Low-band nighttime guidance beginning after local sunset at the signed-in station.';
    }
    if ($end <= $start) {
        $start = $stationSun['sunrise'] + 3600;
        $end = $start + 4 * 3600;
        $basis = 'Fallback daylight-path guidance because the endpoint daylight intervals do not overlap cleanly.';
    }
    // Operating guidance should not imply minute-by-minute prediction accuracy.
    $start = (int) (round($start / 1800) * 1800);
    $end = (int) (round($end / 1800) * 1800);

    $utc = new DateTimeZone('UTC');
    $startUtc = (new DateTimeImmutable('@' . $start))->setTimezone($utc);
    $endUtc = (new DateTimeImmutable('@' . $end))->setTimezone($utc);
    $startLocal = $startUtc->setTimezone($stationZone);
    $endLocal = $endUtc->setTimezone($stationZone);

    $f107 = null; $ap = null; $issued = '';
    $forecast = $fetchJson('https://services.swpc.noaa.gov/json/45-day-forecast.json');
    if (is_array($forecast)) {
        $issued = (string) ($forecast['issued'] ?? '');
        foreach (($forecast['data'] ?? []) as $row) {
            if (substr((string) ($row['time'] ?? ''), 0, 10) !== $date) continue;
            if (($row['metric'] ?? '') === 'f107' && is_numeric($row['value'] ?? null)) $f107 = (float) $row['value'];
            if (($row['metric'] ?? '') === 'ap' && is_numeric($row['value'] ?? null)) $ap = (float) $row['value'];
        }
    }
    $kpMax = null;
    $kp = $fetchJson('https://services.swpc.noaa.gov/products/noaa-planetary-k-index-forecast.json');
    if (is_array($kp)) {
        foreach ($kp as $row) {
            $stamp = (string) ($row['time_tag'] ?? '');
            if (substr($stamp, 0, 10) !== $date || !is_numeric($row['kp'] ?? null)) continue;
            $value = (float) $row['kp'];
            $kpMax = $kpMax === null ? $value : max($kpMax, $value);
        }
    }

    $spots = $summarizeSpots($band, $targetMatch);
    $result = [
        'schema_version' => 1, 'query_mode' => 'propagation_guidance',
        'band_meters' => $band, 'band_basis' => $bandBasis,
        'target_region' => $targetKey, 'target_label' => $targetLabel, 'target_query' => $targetQuery,
        'target_basis' => $targetBasis, 'target_resolution' => $targetResolution,
        'target_latitude' => round((float) $targetLat, 5), 'target_longitude' => round((float) $targetLon, 5),
        'target_reference' => $targetReference,
        'date' => $date, 'station_label' => $stationLabel,
        'station_basis' => 'Signed-in RigPi account coordinates',
        'station_latitude' => round((float) $lat, 5), 'station_longitude' => round((float) $lon, 5),
        'station_timezone' => $stationSun['timezone'],
        'window_start_local' => $startLocal->format('Y-m-d\TH:i:sP'),
        'window_end_local' => $endLocal->format('Y-m-d\TH:i:sP'),
        'window_start_utc' => $startUtc->format('Y-m-d\TH:i:s\Z'),
        'window_end_utc' => $endUtc->format('Y-m-d\TH:i:s\Z'),
        'window_basis' => $basis, 'forecast_kind' => 'planning_guidance',
        'f107' => $f107, 'ap' => $ap, 'kp_max' => $kpMax,
        'space_weather_provider' => 'NOAA Space Weather Prediction Center',
        'space_weather_issued_at' => $issued, 'retrieved_at' => gmdate('c'),
        'reference' => 'https://open-meteo.com/en/docs',
        'space_weather_reference' => 'https://www.swpc.noaa.gov/products/45-day-ap-and-f107cm-flux-forecast',
        'notice' => 'This is station-aware daylight-path guidance informed by forecast solar and geomagnetic indices, not a circuit-specific propagation prediction or guarantee. Check the band near the suggested window.',
    ];
    echo json_encode(array_merge($result, $spots), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (JsonException | InvalidArgumentException $error) {
    http_response_code(400); echo json_encode(['error' => $error->getMessage()]);
} catch (Throwable $error) {
    error_log('Elmer propagation: ' . $error->getMessage());
    http_response_code(502); echo json_encode(['error' => $error->getMessage()]);
}
