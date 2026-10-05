<?php
/** Local cache plus U.S. Census batch geocoding for public FCC addresses. */

function elmerFccEnsureGeocodeTable(mysqli $db): void
{
    $sql = "CREATE TABLE IF NOT EXISTS ElmerFCCGeocode (
        fccid INT NOT NULL PRIMARY KEY,
        address_hash CHAR(64) NOT NULL,
        latitude DECIMAL(10,7) NULL,
        longitude DECIMAL(11,7) NULL,
        match_status VARCHAR(16) NOT NULL,
        match_type VARCHAR(40) NOT NULL DEFAULT '',
        matched_address VARCHAR(240) NOT NULL DEFAULT '',
        benchmark VARCHAR(40) NOT NULL DEFAULT 'Public_AR_Current',
        geocoded_at DATETIME NOT NULL,
        KEY address_hash (address_hash),
        KEY match_status (match_status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!$db->query($sql)) throw new RuntimeException('The FCC geocode cache could not be created.');
}

function elmerFccAddressHash(array $row): string
{
    $parts = [$row['address1'] ?? '', $row['city'] ?? '', $row['state'] ?? '',
        substr((string)($row['zip'] ?? ''), 0, 5)];
    $parts = array_map(static fn($value) => strtoupper(trim(preg_replace('/\s+/', ' ', (string)$value))), $parts);
    return hash('sha256', implode('|', $parts));
}

function elmerFccReadGeocodeCache(mysqli $db, array $ids): array
{
    if (!$ids) return [];
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $result = [];
    foreach (array_chunk($ids, 500) as $chunk) {
        $rows = $db->query('SELECT * FROM ElmerFCCGeocode WHERE fccid IN (' . implode(',', $chunk) . ')');
        if (!$rows) continue;
        while ($row = $rows->fetch_assoc()) $result[(int)$row['fccid']] = $row;
    }
    return $result;
}

function elmerFccCensusBatch(mysqli $db, array $records, int $maximum = 1000): array
{
    $records = array_slice($records, 0, $maximum);
    if (!$records) return ['attempted' => 0, 'matched' => 0, 'unmatched' => 0, 'error' => ''];
    if (!function_exists('curl_init')) {
        return ['attempted' => 0, 'matched' => 0, 'unmatched' => 0,
            'error' => 'PHP cURL is not installed.'];
    }
    $path = tempnam(sys_get_temp_dir(), 'elmer-fcc-');
    if ($path === false) throw new RuntimeException('A temporary geocoding file could not be created.');
    try {
        $handle = fopen($path, 'wb');
        if (!$handle) throw new RuntimeException('The temporary geocoding file could not be opened.');
        foreach ($records as $row) {
            fputcsv($handle, [(int)$row['fccid'], trim((string)$row['address1']),
                trim((string)$row['city']), trim((string)$row['state']),
                substr(trim((string)$row['zip']), 0, 5)], ',', '"', '', "\n");
        }
        fclose($handle);
        $curl = curl_init('https://geocoding.geo.census.gov/geocoder/locations/addressbatch');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => ['addressFile' => new CURLFile($path, 'text/csv', 'fcc-addresses.csv'),
                'benchmark' => 'Public_AR_Current'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_USERAGENT => 'RigPi-Elmer/0.19 (https://rigpi.net)',
        ]);
        $body = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);
        if ($body === false || $status < 200 || $status >= 300) {
            return ['attempted' => count($records), 'matched' => 0, 'unmatched' => 0,
                'error' => $curlError ?: 'Census geocoder returned HTTP ' . $status . '.'];
        }
        $byId = [];
        foreach ($records as $row) $byId[(int)$row['fccid']] = $row;
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, (string)$body);
        rewind($stream);
        $parsed = [];
        while (($fields = fgetcsv($stream, null, ',', '"', '')) !== false) {
            if (count($fields) < 3) continue;
            $id = (int)ltrim((string)$fields[0], "\xEF\xBB\xBF");
            if (!$id || !isset($byId[$id])) continue;
            $statusText = trim((string)$fields[2]);
            $coordinates = trim((string)($fields[5] ?? ''));
            $longitude = null;
            $latitude = null;
            if (strcasecmp($statusText, 'Match') === 0 && preg_match(
                    '/^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*$/',
                    $coordinates, $match)) {
                $longitude = (float)$match[1];
                $latitude = (float)$match[2];
            } else {
                $statusText = strcasecmp($statusText, 'Tie') === 0 ? 'Tie' : 'No_Match';
            }
            $parsed[$id] = ['status' => $latitude !== null ? 'Match' : $statusText,
                'latitude' => $latitude, 'longitude' => $longitude,
                'match_type' => trim((string)($fields[3] ?? '')),
                'matched_address' => trim((string)($fields[4] ?? ''))];
        }
        fclose($stream);
        $upsert = $db->prepare("INSERT INTO ElmerFCCGeocode
            (fccid,address_hash,latitude,longitude,match_status,match_type,matched_address,benchmark,geocoded_at)
            VALUES (?,?,?,?,?,?,?,'Public_AR_Current',UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE address_hash=VALUES(address_hash),latitude=VALUES(latitude),
            longitude=VALUES(longitude),match_status=VALUES(match_status),match_type=VALUES(match_type),
            matched_address=VALUES(matched_address),benchmark=VALUES(benchmark),geocoded_at=VALUES(geocoded_at)");
        $matched = 0;
        $unmatched = 0;
        foreach ($records as $row) {
            $id = (int)$row['fccid'];
            $item = $parsed[$id] ?? ['status' => 'No_Match', 'latitude' => null,
                'longitude' => null, 'match_type' => '', 'matched_address' => ''];
            $hash = elmerFccAddressHash($row);
            $latitude = $item['latitude'];
            $longitude = $item['longitude'];
            $matchStatus = $item['status'];
            $matchType = $item['match_type'];
            $matchedAddress = substr($item['matched_address'], 0, 240);
            $upsert->bind_param('isddsss', $id, $hash, $latitude, $longitude,
                $matchStatus, $matchType, $matchedAddress);
            $upsert->execute();
            if ($matchStatus === 'Match') $matched++; else $unmatched++;
        }
        return ['attempted' => count($records), 'matched' => $matched,
            'unmatched' => $unmatched, 'error' => ''];
    } finally {
        @unlink($path);
    }
}

function elmerFccDistanceMiles(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $earth = 3958.7613;
    $lat1 = deg2rad($lat1); $lat2 = deg2rad($lat2);
    $deltaLat = $lat2 - $lat1; $deltaLon = deg2rad($lon2 - $lon1);
    $a = sin($deltaLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($deltaLon / 2) ** 2;
    return $earth * 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));
}
