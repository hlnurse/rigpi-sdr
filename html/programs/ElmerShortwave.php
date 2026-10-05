<?php
/** Authenticated local queries of RigPi's ILGRadio schedule with EiBi fallback. */
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$ilgCache = '/home/pi/Elmer/cache/ilgradio/schedules.csv';
$ilgMetadata = '/home/pi/Elmer/cache/ilgradio/metadata.json';
$eibiCache = '/home/pi/Elmer/cache/eibi/schedules.csv';
$eibiMetadata = '/home/pi/Elmer/cache/eibi/metadata.json';
if (empty($_SESSION['myUsername'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Please sign in to RigPi.']);
    exit;
}
ini_set('display_errors', '0');
ini_set('log_errors', '1');

function elmerSwDayActive(string $days, int $day): bool {
    if ($days === '') return true;
    if ($days === 'MF' || str_starts_with($days, 'MF-')) return $day >= 1 && $day <= 5;
    $names = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];
    $indexes = array_flip($names);
    $active = [];
    preg_match_all('/(Mo|Tu|We|Th|Fr|Sa|Su)(?:-(Mo|Tu|We|Th|Fr|Sa|Su))?/', $days, $ranges, PREG_SET_ORDER);
    foreach ($ranges as $range) {
        $first = $indexes[$range[1]];
        $last = !empty($range[2]) ? $indexes[$range[2]] : $first;
        for ($value = $first;; $value = ($value + 1) % 7) {
            $active[$value] = true;
            if ($value === $last) break;
        }
    }
    if (!$active && preg_match('/^[1-7]+$/', $days)) {
        foreach (str_split($days) as $value) $active[((int)$value) % 7] = true;
    }
    return !$active || isset($active[$day]);
}

function elmerSwDateActive(array $row, DateTimeImmutable $now): bool {
    $startText = (string)($row['start_date'] ?? '');
    $stopText = (string)($row['stop_date'] ?? '');
    if ($startText === '' && $stopText === '') return true;
    if (strlen($startText) === 6 || strlen($stopText) === 6) {
        $today = $now->format('Ymd');
        foreach ([[$startText, false], [$stopText, true]] as [$value, $isStop]) {
            if ($value === '') continue;
            $date = DateTimeImmutable::createFromFormat('!dmY', $value, new DateTimeZone('UTC'));
            if (!$date) continue;
            $key = $date->format('Ymd');
            if ((!$isStop && $today < $key) || ($isStop && $today > $key)) return false;
        }
        return true;
    }
    $value = static function (string $text): ?int {
        if (!preg_match('/^(\d{2})(\d{2})$/', $text, $match)) return null;
        if (!checkdate((int)$match[2], (int)$match[1], 2000)) return null;
        return ((int)$match[2] * 100) + (int)$match[1];
    };
    $start = $value($startText); $stop = $value($stopText);
    $today = ((int)$now->format('n') * 100) + (int)$now->format('j');
    if ($start !== null && $stop !== null)
        return $start <= $stop ? $today >= $start && $today <= $stop : $today >= $start || $today <= $stop;
    return $start !== null ? $today >= $start : ($stop === null || $today <= $stop);
}

function elmerSwActive(array $row, DateTimeImmutable $now): bool {
    if (!preg_match('/^(\d{2})(\d{2})-(\d{2})(\d{2})$/', (string)($row['time_utc'] ?? ''), $match))
        return false;
    $start = ((int)$match[1] * 60) + (int)$match[2];
    $stop = ((int)$match[3] * 60) + (int)$match[4];
    if ($start > 1440 || $stop > 1440) return false;
    $minute = ((int)$now->format('G') * 60) + (int)$now->format('i');
    $day = (int)$now->format('w');
    if ($start <= $stop) {
        $inTime = ($start === 0 && $stop === 1440) || ($minute >= $start && $minute < $stop);
    } else {
        $inTime = $minute >= $start || $minute < $stop;
        if ($minute < $stop) $day = ($day + 6) % 7;
    }
    if (!$inTime) return false;
    return elmerSwDayActive((string)($row['days'] ?? ''), $day) && elmerSwDateActive($row, $now);
}

function elmerSwStationKey(string $name): string {
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
    $name = strtolower($ascii === false ? $name : $ascii);
    // Schedule providers abbreviate Radio as R. (for example R.Marti).
    $name = preg_replace('/^r[.\s]+/', 'radio ', trim($name));
    return preg_replace('/[^a-z0-9]+/', '', $name);
}

function elmerSwRead(string $path, string $source, DateTimeImmutable $now, bool $activeNow,
                     string $language, string $station, array &$activeFrequencies): array {
    if (!is_readable($path)) return [];
    $handle = fopen($path, 'rb');
    if (!$handle) throw new RuntimeException('The shortwave schedule cache could not be opened.');
    $headers = fgetcsv($handle, 0, ',', '"', '');
    if (!is_array($headers)) throw new RuntimeException('The shortwave schedule cache is invalid.');
    $matches = [];
    while (($values = fgetcsv($handle, 0, ',', '"', '')) !== false) {
        if (count($values) !== count($headers)) continue;
        $row = array_combine($headers, $values);
        $frequency = (int)($row['frequency_hz'] ?? 0);
        if ($frequency < 100000 || $frequency > 30000000) continue;
        $active = !$activeNow || elmerSwActive($row, $now);
        if (!$active) continue;
        if ($source === 'ILGRadio') $activeFrequencies[$frequency] = true;
        $rowLanguage = trim((string)($row['language_name'] ?? $row['language_code'] ?? ''));
        $rowStation = trim((string)($row['station'] ?? ''));
        if ($language !== '' && stripos($rowLanguage, $language) === false) continue;
        if ($station !== '' && (elmerSwStationKey($station) === '' || strpos(elmerSwStationKey($rowStation), elmerSwStationKey($station)) === false)) continue;
        if ($rowStation === '') continue;
        $matches[] = [
            'source' => $source, 'frequency_hz' => $frequency,
            'station' => $rowStation, 'language' => $rowLanguage,
            'time_utc' => (string)($row['time_utc'] ?? ''),
            'days' => (string)($row['days'] ?? ''),
            'country' => (string)($row['country_code'] ?? ''),
            'target' => (string)($row['target_name'] ?? $row['target_code'] ?? ''),
            'transmitter' => (string)($row['location'] ?? $row['transmitter'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'monitored' => (string)($row['monitored'] ?? ''),
            'mode' => (string)($row['mode'] ?? 'AM'),
            'power_kw' => (string)($row['power_kw'] ?? ''),
        ];
    }
    fclose($handle);
    return $matches;
}

try {
    $request = json_decode(file_get_contents('php://input'), true, 12, JSON_THROW_ON_ERROR);
    if (!is_array($request)) throw new InvalidArgumentException('The shortwave search is invalid.');
    $language = trim((string)($request['language'] ?? ''));
    $station = trim((string)($request['station'] ?? ''));
    $activeNow = !array_key_exists('active_now', $request) || (bool)$request['active_now'];
    $limit = min(50, max(1, (int)($request['limit'] ?? 50)));
    if (($language === '' && $station === '') || strlen($language) > 40 || strlen($station) > 80 ||
        !preg_match("/^[A-Za-z0-9 ()\/',.+-]*$/", $language) ||
        !preg_match("/^[A-Za-z0-9 ()\/',.&+-]*$/", $station))
        throw new InvalidArgumentException('Please provide a valid language or station name.');
    if (!is_readable($ilgCache) && !is_readable($eibiCache))
        throw new RuntimeException('No shortwave schedule database has been installed.');

    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $activeIlg = [];
    $ilgMatches = elmerSwRead($ilgCache, 'ILGRadio', $now, $activeNow, $language, $station, $activeIlg);
    $unused = [];
    $eibiMatches = elmerSwRead($eibiCache, 'EiBi', $now, $activeNow, $language, $station, $unused);
    $matches = $ilgMatches;
    foreach ($eibiMatches as $row) {
        if (!isset($activeIlg[$row['frequency_hz']])) $matches[] = $row;
    }
    usort($matches, static fn($left, $right) =>
        ($left['frequency_hz'] <=> $right['frequency_hz']) ?:
        strcmp($left['station'], $right['station']) ?: strcmp($left['time_utc'], $right['time_utc']));
    $metadata = is_readable($ilgMetadata)
        ? (json_decode((string)file_get_contents($ilgMetadata), true) ?: []) : [];
    $eibi = is_readable($eibiMetadata)
        ? (json_decode((string)file_get_contents($eibiMetadata), true) ?: []) : [];
    $provider = $ilgMatches ? 'ILGRadio with EiBi fallback' : 'EiBi';
    $payload = [
        'provider' => $provider,
        'reference' => $ilgMatches ? 'https://www.ilgradio.com/' : 'https://www.eibispace.de/',
        'retrieved_at' => $now->format(DateTimeInterface::ATOM),
        'database_date' => (string)($metadata['database_date'] ?? $eibi['downloaded_at'] ?? ''),
        'language_filter' => $language, 'station_filter' => $station,
        'active_now' => $activeNow,
        'cache_records' => (int)(($metadata['cached'] ?? 0) + ($eibi['cached'] ?? 0)),
        'total_matches' => count($matches),
        'returned' => min($limit, count($matches)),
        'notice' => 'Schedule entries indicate planned broadcasts; reception is not guaranteed.',
        'results' => array_slice($matches, 0, $limit),
    ];
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $error) {
    http_response_code(400); echo json_encode(['error' => $error->getMessage()]);
} catch (Throwable $error) {
    error_log('Elmer shortwave: ' . $error->getMessage());
    http_response_code(500); echo json_encode(['error' => 'RigPi could not search the shortwave schedule.']);
}
