<?php
/** Authenticated Open-Meteo current conditions and short forecast for Ask Elmer. */
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
require_once $root . '/programs/GetCallbookFunc.php';
require_once $root . '/programs/GetUserFieldFunc.php';
require_once $root . '/classes/MysqliDb.php';
ini_set('display_errors', '0');
ini_set('log_errors', '1');

$fetchJson = static function ($url) {
    $context = stream_context_create(['http' => [
        'timeout' => 15,
        'ignore_errors' => true,
        'header' => "User-Agent: RigPi-Elmer-Weather/1.0\r\nAccept: application/json\r\n",
    ]]);
    $raw = @file_get_contents($url, false, $context);
    if ($raw === false || strlen($raw) > 1000000) return null;
    $data = json_decode($raw, true);
    return is_array($data) && empty($data['error']) ? $data : null;
};
$gridCenter = static function ($grid) {
    $grid = strtoupper(preg_replace('/[^A-R0-9X]/i', '', (string)$grid));
    if (strlen($grid) < 4 || !preg_match('/^[A-R]{2}\d{2}(?:[A-X]{2})?(?:\d{2})?$/', $grid)) return null;
    $lon = -180 + (ord($grid[0]) - 65) * 20 + ((int)$grid[2]) * 2;
    $lat = -90 + (ord($grid[1]) - 65) * 10 + ((int)$grid[3]);
    $lonSize = 2.0; $latSize = 1.0;
    if (strlen($grid) >= 6) {
        $lonSize /= 24; $latSize /= 24;
        $lon += (ord($grid[4]) - 65) * $lonSize;
        $lat += (ord($grid[5]) - 65) * $latSize;
    }
    if (strlen($grid) >= 8) {
        $lonSize /= 10; $latSize /= 10;
        $lon += ((int)$grid[6]) * $lonSize;
        $lat += ((int)$grid[7]) * $latSize;
    }
    return [$lat + $latSize / 2, $lon + $lonSize / 2];
};
$condition = static function ($code) {
    $map = [0=>'Clear sky',1=>'Mainly clear',2=>'Partly cloudy',3=>'Overcast',
        45=>'Fog',48=>'Freezing fog',51=>'Light drizzle',53=>'Drizzle',55=>'Heavy drizzle',
        56=>'Light freezing drizzle',57=>'Freezing drizzle',61=>'Light rain',63=>'Rain',65=>'Heavy rain',
        66=>'Light freezing rain',67=>'Freezing rain',71=>'Light snow',73=>'Snow',75=>'Heavy snow',
        77=>'Snow grains',80=>'Light rain showers',81=>'Rain showers',82=>'Heavy rain showers',
        85=>'Light snow showers',86=>'Heavy snow showers',95=>'Thunderstorm',
        96=>'Thunderstorm with hail',99=>'Severe thunderstorm with hail'];
    return $map[(int)$code] ?? 'Unknown conditions';
};
$hourlyRows = static function ($hourly, $limit) use ($condition) {
    $rows = [];
    $times = is_array($hourly['time'] ?? null) ? $hourly['time'] : [];
    for ($i = 0; $i < min($limit, count($times)); $i++) {
        $code = (int)($hourly['weather_code'][$i] ?? -1);
        $rows[] = [
            'time'=>(string)$times[$i], 'weather_code'=>$code, 'condition'=>$condition($code),
            'temperature'=>$hourly['temperature_2m'][$i] ?? null,
            'apparent_temperature'=>$hourly['apparent_temperature'][$i] ?? null,
            'precipitation_probability'=>$hourly['precipitation_probability'][$i] ?? null,
            'precipitation'=>$hourly['precipitation'][$i] ?? null,
            'pressure_msl'=>$hourly['pressure_msl'][$i] ?? null,
            'cloud_cover'=>$hourly['cloud_cover'][$i] ?? null,
            'visibility'=>$hourly['visibility'][$i] ?? null,
            'wind_speed'=>$hourly['wind_speed_10m'][$i] ?? null,
            'wind_direction'=>$hourly['wind_direction_10m'][$i] ?? null,
            'wind_gusts'=>$hourly['wind_gusts_10m'][$i] ?? null,
            'cape'=>$hourly['cape'][$i] ?? null,
        ];
    }
    return $rows;
};
$operatingSummary = static function ($rows, $historical = false) {
    $values = static function ($rows, $field) {
        return array_values(array_map('floatval', array_filter(
            array_column($rows, $field), static fn($value) => is_numeric($value))));
    };
    $maxOrNull = static fn($items) => count($items) ? max($items) : null;
    $minOrNull = static fn($items) => count($items) ? min($items) : null;
    $precip = $values($rows, 'precipitation');
    return [
        'period_start'=>(string)($rows[0]['time'] ?? ''),
        'period_end'=>(string)($rows[count($rows)-1]['time'] ?? ''),
        'temperature_min'=>$minOrNull($values($rows, 'temperature')),
        'temperature_max'=>$maxOrNull($values($rows, 'temperature')),
        'precipitation_total'=>count($precip) ? array_sum($precip) : null,
        'precipitation_probability_max'=>$maxOrNull($values($rows, 'precipitation_probability')),
        'wind_speed_max'=>$maxOrNull($values($rows, 'wind_speed')),
        'wind_gusts_max'=>$maxOrNull($values($rows, 'wind_gusts')),
        'visibility_min'=>$minOrNull($values($rows, 'visibility')),
        'pressure_min'=>$minOrNull($values($rows, 'pressure_msl')),
        'pressure_max'=>$maxOrNull($values($rows, 'pressure_msl')),
        'cape_max'=>$maxOrNull($values($rows, 'cape')),
        'thunderstorm_code_present'=>count(array_filter($rows, static fn($row) => (int)($row['weather_code'] ?? -1) >= 95)) > 0,
        'notice'=>$historical
            ? 'Historical values are gridded reanalysis, not readings from a specific local weather station.'
            : 'Model guidance only, not an official warning or an antenna safety certification. Check local alerts and your station equipment limits.',
    ];
};

try {
    $request = json_decode(file_get_contents('php://input'), true, 8, JSON_THROW_ON_ERROR);
    $target = strtolower(trim((string)($request['target'] ?? 'user')));
    $call = strtoupper(trim((string)($request['call'] ?? '')));
    $place = trim((string)($request['location'] ?? ''));
    $days = max(1, min(7, (int)($request['forecast_days'] ?? 3)));
    $mode = strtolower(trim((string)($request['mode'] ?? 'forecast')));
    $hourlyHours = max(0, min(48, (int)($request['hourly_hours'] ?? 0)));
    $antennaSafety = !empty($request['antenna_safety']);
    $historicalDate = trim((string)($request['historical_date'] ?? ''));
    if (!in_array($mode, ['forecast', 'historical'], true)) {
        throw new InvalidArgumentException('The weather query mode is invalid.');
    }
    if ($mode === 'historical') {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $historicalDate, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) ||
            $date->format('Y-m-d') !== $historicalDate || $historicalDate < '1940-01-01' ||
            $historicalDate >= gmdate('Y-m-d')) {
            throw new InvalidArgumentException('Please use a completed date from 1940 through yesterday for historical weather.');
        }
        $hourlyHours = 24;
    } else {
        $historicalDate = '';
        if ($antennaSafety) $hourlyHours = max(24, $hourlyHours);
    }
    if (!in_array($target, ['user', 'call', 'place'], true) || strlen($place) > 160) {
        throw new InvalidArgumentException('The weather location is invalid.');
    }
    if ($target === 'call' && (!preg_match('/^(?:[A-Z0-9]{1,3}\/)?[A-Z0-9]{1,3}\d[A-Z]{1,4}(?:\/[A-Z0-9]{1,4})?$/', $call) || strlen($call) > 24)) {
        throw new InvalidArgumentException('The weather callsign is invalid.');
    }
    if ($target === 'place' && strlen($place) < 2) {
        throw new InvalidArgumentException('Please provide a city or place for the weather lookup.');
    }

    $username = (string)$_SESSION['myUsername'];
    $user = (int)getUserField($username, 'uID');
    if ($user < 1) throw new RuntimeException('The signed-in RigPi account was not found.');
    $db = new MysqliDb('localhost', $sql_radio_username, $sql_radio_password, $sql_radio_database);
    $db->where('uID', $user);
    $account = $db->getOne('Users');
    if (!$account) throw new RuntimeException('The signed-in RigPi account was not found.');

    $latitude = null; $longitude = null; $countryCode = '';
    $label = ''; $basis = '';
    if ($target === 'call') {
        $hasQrz = trim((string)($account['qrzPWD'] ?? '')) !== '';
        $hasHamqth = trim((string)($account['hamqthUser'] ?? '')) !== '' && trim((string)($account['hamqthPWD'] ?? '')) !== '';
        getCallbookFunc($call, ($hasQrz || $hasHamqth) ? 'QRZData' : 'FCCData', $user);
        $db->where('User', $user);
        $row = $db->getOne('Callbook');
        if (!$row || strtoupper(trim((string)($row['Callsign'] ?? ''))) !== $call) {
            throw new RuntimeException($call . ' was not found by the RigPi callbook.');
        }
        $latitude = filter_var($row['His_Latitude'] ?? null, FILTER_VALIDATE_FLOAT);
        $longitude = filter_var($row['His_Longitude'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($latitude === false || $longitude === false) {
            $center = $gridCenter($row['His_Grid'] ?? '');
            if ($center) [$latitude, $longitude] = $center;
        }
        $parts = array_filter([$row['His_City'] ?? '', $row['His_State'] ?? '', $row['His_Country'] ?? '']);
        $label = $call . (count($parts) ? ' — ' . implode(', ', $parts) : '');
        $basis = 'Callbook station coordinates or Maidenhead grid center';
        $country = strtoupper(trim((string)($row['His_Country'] ?? '')));
        if ($country === 'US' || str_contains($country, 'UNITED STATES')) $countryCode = 'US';
    } elseif ($target === 'user') {
        $latitude = filter_var($account['My_Latitude'] ?? null, FILTER_VALIDATE_FLOAT);
        $longitude = filter_var($account['My_Longitude'] ?? null, FILTER_VALIDATE_FLOAT);
        $parts = array_filter([$account['MyCity'] ?? '', $account['MyState'] ?? '', $account['MyCountry'] ?? '']);
        $label = count($parts) ? implode(', ', $parts) : 'Your RigPi station';
        $basis = 'Signed-in RigPi account location';
        $country = strtoupper(trim((string)($account['MyCountry'] ?? '')));
        if ($country === 'US' || str_contains($country, 'UNITED STATES')) $countryCode = 'US';
        if ($latitude === false || $longitude === false) {
            $place = trim(implode(', ', array_filter([$account['MyZIP'] ?? '', $account['MyCity'] ?? '', $account['MyState'] ?? '', $account['MyCountry'] ?? ''])));
            if ($place === '') {
                throw new RuntimeException('Add a ZIP/postal code or city and state to your RigPi account, or include a location in the weather question.');
            }
            $target = 'place';
        }
    }
    if ($target === 'place') {
        $geoUrl = 'https://geocoding-api.open-meteo.com/v1/search?' . http_build_query([
            'name'=>$place, 'count'=>1, 'language'=>'en', 'format'=>'json']);
        $geo = $fetchJson($geoUrl);
        $found = $geo['results'][0] ?? null;
        if (!is_array($found)) throw new RuntimeException('Open-Meteo could not resolve that weather location.');
        $latitude = $found['latitude'] ?? null; $longitude = $found['longitude'] ?? null;
        $countryCode = strtoupper(trim((string)($found['country_code'] ?? '')));
        $label = implode(', ', array_values(array_unique(array_filter([
            $found['name'] ?? '', $found['admin1'] ?? '', $found['country'] ?? '']))));
        $basis = 'Open-Meteo geocoding search';
    }
    if (!is_numeric($latitude) || !is_numeric($longitude) || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
        throw new RuntimeException('No usable coordinates were found for that weather location.');
    }

    $imperial = $countryCode === 'US';
    $baseUnits = [
        'temperature_unit'=>$imperial ? '°F' : '°C',
        'wind_speed_unit'=>$imperial ? 'mph' : 'km/h',
        'precipitation_unit'=>$imperial ? 'in' : 'mm',
        'visibility_unit'=>'m', 'pressure_unit'=>'hPa', 'cape_unit'=>'J/kg',
    ];
    if ($mode === 'historical') {
        $archiveParams = [
            'latitude'=>round((float)$latitude, 5), 'longitude'=>round((float)$longitude, 5),
            'start_date'=>$historicalDate, 'end_date'=>$historicalDate, 'timezone'=>'auto',
            'hourly'=>'temperature_2m,apparent_temperature,precipitation,weather_code,pressure_msl,cloud_cover,visibility,wind_speed_10m,wind_direction_10m,wind_gusts_10m',
            'temperature_unit'=>$imperial ? 'fahrenheit' : 'celsius',
            'wind_speed_unit'=>$imperial ? 'mph' : 'kmh',
            'precipitation_unit'=>$imperial ? 'inch' : 'mm',
        ];
        $archiveUrl = 'https://archive-api.open-meteo.com/v1/archive?' . http_build_query($archiveParams);
        $cacheFile = sys_get_temp_dir() . '/elmer-weather-' . hash('sha256', $archiveUrl) . '.json';
        $archive = is_file($cacheFile) && filemtime($cacheFile) >= time() - 86400
            ? json_decode((string)@file_get_contents($cacheFile), true) : null;
        if (!is_array($archive)) {
            $archive = $fetchJson($archiveUrl);
            if (!is_array($archive)) throw new RuntimeException('Open-Meteo historical weather data is unavailable right now.');
            @file_put_contents($cacheFile, json_encode($archive), LOCK_EX); @chmod($cacheFile, 0600);
        }
        $rows = $hourlyRows($archive['hourly'] ?? [], 24);
        if (!count($rows)) throw new RuntimeException('No historical weather was returned for that date and location.');
        echo json_encode(array_merge([
            'provider'=>'Open-Meteo', 'retrieved_at'=>gmdate('c'), 'data_kind'=>'historical_reanalysis',
            'reference'=>'https://open-meteo.com/en/docs/historical-weather-api',
            'attribution'=>'Weather data by Open-Meteo.com', 'location'=>$label,
            'location_basis'=>$basis, 'target'=>$target, 'call'=>$call,
            'timezone'=>(string)($archive['timezone'] ?? ''), 'historical_date'=>$historicalDate,
            'hourly'=>$rows, 'operating_summary'=>$operatingSummary($rows, true),
        ], $baseUnits), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    $params = [
        'latitude'=>round((float)$latitude, 5), 'longitude'=>round((float)$longitude, 5),
        'current'=>'temperature_2m,relative_humidity_2m,apparent_temperature,precipitation,weather_code,pressure_msl,cloud_cover,visibility,wind_speed_10m,wind_direction_10m,wind_gusts_10m,is_day',
        'daily'=>'weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max,precipitation_sum,sunrise,sunset,wind_speed_10m_max,wind_gusts_10m_max',
        'timezone'=>'auto', 'forecast_days'=>$days,
        'temperature_unit'=>$imperial ? 'fahrenheit' : 'celsius',
        'wind_speed_unit'=>$imperial ? 'mph' : 'kmh',
        'precipitation_unit'=>$imperial ? 'inch' : 'mm',
    ];
    if ($hourlyHours > 0) {
        $params['hourly'] = 'temperature_2m,apparent_temperature,precipitation_probability,precipitation,weather_code,pressure_msl,cloud_cover,visibility,wind_speed_10m,wind_direction_10m,wind_gusts_10m,cape';
        $params['forecast_hours'] = $hourlyHours;
    }
    $forecastUrl = 'https://api.open-meteo.com/v1/forecast?' . http_build_query($params);
    $cacheKey = hash('sha256', $forecastUrl);
    $cacheFile = sys_get_temp_dir() . '/elmer-weather-' . $cacheKey . '.json';
    $forecast = null;
    if (is_file($cacheFile) && filemtime($cacheFile) >= time() - 600) {
        $forecast = json_decode((string)@file_get_contents($cacheFile), true);
    }
    if (!is_array($forecast)) {
        $forecast = $fetchJson($forecastUrl);
        if (!is_array($forecast)) throw new RuntimeException('Open-Meteo weather data is unavailable right now.');
        @file_put_contents($cacheFile, json_encode($forecast), LOCK_EX);
        @chmod($cacheFile, 0600);
    }
    $current = $forecast['current'] ?? [];
    $daily = $forecast['daily'] ?? [];
    $rows = $hourlyHours > 0 ? $hourlyRows($forecast['hourly'] ?? [], $hourlyHours) : [];
    $dailyRows = [];
    for ($i = 0; $i < min($days, count($daily['time'] ?? [])); $i++) {
        $code = (int)($daily['weather_code'][$i] ?? -1);
        $dateValue = (string)($daily['time'][$i] ?? '');
        $dateObject = DateTimeImmutable::createFromFormat('!Y-m-d', $dateValue, new DateTimeZone('UTC'));
        $dailyRows[] = [
            'date'=>$dateValue, 'weekday'=>$dateObject ? $dateObject->format('l') : '',
            'weather_code'=>$code,
            'condition'=>$condition($code),
            'temperature_max'=>$daily['temperature_2m_max'][$i] ?? null,
            'temperature_min'=>$daily['temperature_2m_min'][$i] ?? null,
            'precipitation_probability'=>$daily['precipitation_probability_max'][$i] ?? null,
            'precipitation'=>$daily['precipitation_sum'][$i] ?? null,
            'wind_speed_max'=>$daily['wind_speed_10m_max'][$i] ?? null,
            'wind_gusts_max'=>$daily['wind_gusts_10m_max'][$i] ?? null,
            'sunrise'=>(string)($daily['sunrise'][$i] ?? ''), 'sunset'=>(string)($daily['sunset'][$i] ?? ''),
        ];
    }
    $code = (int)($current['weather_code'] ?? -1);
    $response = [
        'provider'=>'Open-Meteo', 'retrieved_at'=>gmdate('c'),
        'data_kind'=>'forecast',
        'reference'=>'https://open-meteo.com/', 'attribution'=>'Weather data by Open-Meteo.com',
        'location'=>$label, 'location_basis'=>$basis, 'target'=>$target,
        'call'=>$call, 'timezone'=>(string)($forecast['timezone'] ?? ''),
        'current'=>[
            'time'=>(string)($current['time'] ?? ''), 'weather_code'=>$code,
            'condition'=>$condition($code), 'temperature'=>$current['temperature_2m'] ?? null,
            'apparent_temperature'=>$current['apparent_temperature'] ?? null,
            'relative_humidity'=>$current['relative_humidity_2m'] ?? null,
            'precipitation'=>$current['precipitation'] ?? null,
            'pressure_msl'=>$current['pressure_msl'] ?? null,
            'cloud_cover'=>$current['cloud_cover'] ?? null,
            'visibility'=>$current['visibility'] ?? null,
            'wind_speed'=>$current['wind_speed_10m'] ?? null,
            'wind_direction'=>$current['wind_direction_10m'] ?? null,
            'wind_gusts'=>$current['wind_gusts_10m'] ?? null,
            'is_day'=>(int)($current['is_day'] ?? 0),
        ],
        'daily'=>$dailyRows,
    ];
    if (count($rows)) {
        $response['hourly'] = $rows;
        $response['operating_summary'] = $operatingSummary($rows, false);
    }
    echo json_encode(array_merge($response, $baseUnits), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (JsonException | InvalidArgumentException $error) {
    http_response_code(400);
    echo json_encode(['error'=>$error->getMessage()]);
} catch (Throwable $error) {
    error_log('Elmer weather: ' . $error->getMessage());
    http_response_code(502);
    echo json_encode(['error'=>$error->getMessage()]);
}
