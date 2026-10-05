<?php
/** Authenticated country profile and local map geometry for Ask Elmer. */

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (empty($_SESSION['myUsername'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Please sign in to RigPi.']);
    exit;
}

ini_set('display_errors', '0');
ini_set('log_errors', '1');

try {
    $request = json_decode(file_get_contents('php://input'), true, 8, JSON_THROW_ON_ERROR);
    $country = trim((string)($request['country'] ?? ''));
    $call = strtoupper(trim((string)($request['call'] ?? '')));
    if ($country === '' || strlen($country) > 120 || preg_match('/[<>\x00-\x1f]/', $country)) {
        throw new InvalidArgumentException('A valid callbook country is required.');
    }
    if ($call !== '' && !preg_match('/^(?:[A-Z0-9]{1,3}\/)?[A-Z0-9]{1,3}\d[A-Z]{1,4}(?:\/[A-Z0-9]{1,4})?$/', $call)) {
        throw new InvalidArgumentException('The station callsign is invalid.');
    }

    $latitude = $request['latitude'] ?? null;
    $longitude = $request['longitude'] ?? null;
    $hasStation = is_numeric($latitude) && is_numeric($longitude) &&
        (float)$latitude >= -90 && (float)$latitude <= 90 &&
        (float)$longitude >= -180 && (float)$longitude <= 180;
    $basis = trim((string)($request['coordinate_basis'] ?? 'callbook coordinates'));
    if (strlen($basis) > 80) $basis = 'callbook coordinates';

    $cacheDir = '/var/cache/rigpi-elmer/worldfactbook';
    if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0750, true) && !is_dir($cacheDir)) {
        throw new RuntimeException('The country-information cache is unavailable.');
    }

    $fetchJson = static function (string $url, string $cacheFile, int $ttl) use ($cacheDir): array {
        $path = $cacheDir . '/' . $cacheFile;
        $raw = '';
        if (is_file($path) && time() - (int)filemtime($path) <= $ttl) {
            $raw = (string)file_get_contents($path);
        } else {
            $context = stream_context_create(['http' => [
                'method' => 'GET',
                'timeout' => 10,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\n" .
                    "User-Agent: RigPi-Elmer/5 (+https://rigpi.net)\r\n",
            ]]);
            $download = @file_get_contents($url, false, $context);
            if (is_string($download) && strlen($download) > 1 && strlen($download) <= 2000000) {
                $raw = $download;
                $temp = $path . '.tmp-' . bin2hex(random_bytes(4));
                if (@file_put_contents($temp, $raw, LOCK_EX) !== false) {
                    @chmod($temp, 0640);
                    @rename($temp, $path);
                }
                @unlink($temp);
            } elseif (is_file($path)) {
                $raw = (string)file_get_contents($path);
            }
        }
        $data = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new RuntimeException('Country information is unavailable.');
        return $data;
    };

    $normalize = static function ($value): string {
        $value = html_entity_decode(trim((string)$value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $value = is_string($ascii) ? $ascii : $value;
        return strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', ' ', $value) ?? ''));
    };
    $needle = $normalize($country);
    $aliases = [
        'usa' => 'united states', 'us' => 'united states',
        'england' => 'united kingdom', 'great britain' => 'united kingdom',
        'republic of korea' => 'south korea', 'korea south' => 'south korea',
        'russian federation' => 'russia', 'czech republic' => 'czechia',
    ];
    $needle = $aliases[$needle] ?? $needle;

    $countries = $fetchJson(
        'https://worldfactbook.io/api/v1/countries/',
        'countries.json',
        7 * 86400
    );
    $match = null;
    foreach ($countries as $item) {
        if (!is_array($item)) continue;
        $names = [
            $item['name'] ?? '', $item['officialName'] ?? '', $item['slug'] ?? '',
            $item['iso2'] ?? '', $item['iso3'] ?? '',
        ];
        foreach ($names as $name) {
            if ($normalize($name) === $needle) {
                $match = $item;
                break 2;
            }
        }
    }
    if (!$match || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', (string)($match['slug'] ?? ''))) {
        http_response_code(404);
        echo json_encode(['error' => 'No World Factbook profile matches ' . $country . '.']);
        exit;
    }

    $slug = (string)$match['slug'];
    $data = $fetchJson(
        'https://worldfactbook.io/api/v1/countries/' . rawurlencode($slug) . '/',
        'country-' . $slug . '.json',
        7 * 86400
    );
    $cleanText = static function ($value, int $limit = 1200): string {
        if (is_array($value)) $value = $value['text'] ?? '';
        $text = html_entity_decode(strip_tags((string)$value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        return function_exists('mb_substr') ? mb_substr($text, 0, $limit) : substr($text, 0, $limit);
    };
    $sectionText = static function (array $section, string $name, int $limit = 1200) use ($cleanText): string {
        return $cleanText($section[$name] ?? '', $limit);
    };
    $parseCoordinatePair = static function ($value) use ($cleanText): ?array {
        $text = $cleanText(is_array($value) ? ($value['text'] ?? '') : $value, 80);
        if (!preg_match('/^(\d{1,2})(?:\s+(\d{1,2})(?:\s+(\d{1,2}(?:\.\d+)?))?)?\s*([NS])\s*,\s*(\d{1,3})(?:\s+(\d{1,2})(?:\s+(\d{1,2}(?:\.\d+)?))?)?\s*([EW])$/i', $text, $match)) {
            return null;
        }
        $latitude = (float)$match[1] + (float)($match[2] ?? 0) / 60 + (float)($match[3] ?? 0) / 3600;
        $longitude = (float)$match[5] + (float)($match[6] ?? 0) / 60 + (float)($match[7] ?? 0) / 3600;
        if (strtoupper($match[4]) === 'S') $latitude *= -1;
        if (strtoupper($match[8]) === 'W') $longitude *= -1;
        return $latitude >= -90 && $latitude <= 90 && $longitude >= -180 && $longitude <= 180
            ? [$latitude, $longitude] : null;
    };

    $geography = is_array($data['geography'] ?? null) ? $data['geography'] : [];
    $government = is_array($data['government'] ?? null) ? $data['government'] : [];
    $people = is_array($data['peopleAndSociety'] ?? null) ? $data['peopleAndSociety'] : [];
    $languages = '';
    if (is_array($people['Languages'] ?? null)) {
        $languages = $cleanText($people['Languages']['Languages'] ?? $people['Languages'], 800);
    }
    $population = is_numeric($data['population'] ?? null) ? (int)$data['population'] : null;
    $area = is_numeric($data['area'] ?? null) ? (float)$data['area'] : null;
    $profile = [
        'schema_version' => 1,
        'country' => $cleanText($data['name'] ?? $country, 120),
        'official_name' => $cleanText($data['officialName'] ?? '', 180),
        'slug' => $slug,
        'flag' => $cleanText($data['flag'] ?? '', 16),
        'iso2' => strtoupper($cleanText($data['iso2'] ?? '', 2)),
        'iso3' => strtoupper($cleanText($data['iso3'] ?? '', 3)),
        'region' => $cleanText($data['region'] ?? '', 80),
        'subregion' => $cleanText($data['subregion'] ?? '', 100),
        'capital' => $cleanText($data['capital'] ?? '', 120),
        'population' => $population,
        'area_km2' => $area,
        'introduction' => $cleanText($data['introduction'] ?? '', 1800),
        'location' => $sectionText($geography, 'Location'),
        'climate' => $sectionText($geography, 'Climate'),
        'terrain' => $sectionText($geography, 'Terrain'),
        'languages' => $languages,
        'government_type' => $sectionText($government, 'Government type'),
        'data_updated_at' => $cleanText($data['dataUpdatedAt'] ?? '', 40),
        'retrieved_at' => gmdate('c'),
        'provider' => 'WorldFactbook.io',
        'reference' => 'https://worldfactbook.io/countries/' . rawurlencode($slug) . '/',
        'notice' => 'Country facts are aggregated from public-domain and open-license sources; dates vary by indicator.',
    ];
    if ($hasStation) {
        $profile['station_marker_available'] = true;
        $profile['station_marker_basis'] = $cleanText($basis, 80);
        $capitalSection = is_array($government['Capital'] ?? null) ? $government['Capital'] : [];
        $capitalCoordinates = $parseCoordinatePair($capitalSection['geographic coordinates'] ?? null);
        if ($capitalCoordinates !== null) {
            [$capitalLatitude, $capitalLongitude] = $capitalCoordinates;
            $lat1 = deg2rad((float)$latitude);
            $lat2 = deg2rad($capitalLatitude);
            $deltaLatitude = deg2rad($capitalLatitude - (float)$latitude);
            $deltaLongitude = deg2rad($capitalLongitude - (float)$longitude);
            $haversine = sin($deltaLatitude / 2) ** 2 +
                cos($lat1) * cos($lat2) * sin($deltaLongitude / 2) ** 2;
            $distanceKm = 6371.0088 * 2 * atan2(sqrt($haversine), sqrt(max(0, 1 - $haversine)));
            $profile['capital_distance_km'] = round($distanceKm, 1);
            $profile['capital_distance_miles'] = round($distanceKm * 0.621371192, 1);
            $profile['capital_distance_basis'] = 'Approximate great-circle distance from the local callbook station marker to the WorldFactbook capital coordinates.';
        }
    }
    $profile = array_filter($profile, static fn($value) => $value !== '' && $value !== null);

    $map = null;
    $naturalEarthPath = '/var/www/html/assets/maps/ne_110m_admin_0_countries.geojson';
    if (is_file($naturalEarthPath)) {
        $naturalEarth = json_decode((string)file_get_contents($naturalEarthPath), true, 512, JSON_THROW_ON_ERROR);
        $iso2 = strtoupper((string)($profile['iso2'] ?? ''));
        $iso3 = strtoupper((string)($profile['iso3'] ?? ''));
        $countryName = $normalize($profile['country'] ?? $country);
        foreach (($naturalEarth['features'] ?? []) as $feature) {
            if (!is_array($feature) || !is_array($feature['properties'] ?? null) || !is_array($feature['geometry'] ?? null)) continue;
            $properties = $feature['properties'];
            $names = [$properties['ADMIN'] ?? '', $properties['NAME'] ?? '', $properties['NAME_LONG'] ?? ''];
            $featureIso2 = strtoupper((string)($properties['ISO_A2'] ?? ''));
            $featureIso3 = strtoupper((string)($properties['ISO_A3'] ?? ($properties['ADM0_A3'] ?? '')));
            if (($iso2 !== '' && $featureIso2 === $iso2) || ($iso3 !== '' && $featureIso3 === $iso3) ||
                in_array($countryName, array_map($normalize, $names), true)) {
                $map = [
                    'kind' => 'country_station',
                    'country' => (string)($profile['country'] ?? $country),
                    'call' => $call,
                    'geometry' => $feature['geometry'],
                    'map_provider' => 'Natural Earth',
                    'map_reference' => 'https://www.naturalearthdata.com/',
                ];
                if ($hasStation) {
                    $map['station'] = [
                        'latitude' => round((float)$latitude, 7),
                        'longitude' => round((float)$longitude, 7),
                        'basis' => $basis,
                    ];
                }
                break;
            }
        }
    }

    echo json_encode(['profile' => $profile, 'map' => $map], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (JsonException | InvalidArgumentException $error) {
    http_response_code(400);
    echo json_encode(['error' => $error->getMessage()]);
} catch (Throwable $error) {
    error_log('Elmer country: ' . $error->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'RigPi could not retrieve country information.']);
}
