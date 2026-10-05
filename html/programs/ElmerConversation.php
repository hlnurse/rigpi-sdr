<?php
/** On-demand, privacy-limited QSO conversation ideas for the last callbook lookup. */
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$root = '/var/www/html';
if (empty($_SESSION['myUsername'])) {
    http_response_code(401); echo json_encode(['error'=>'Please sign in to RigPi.']); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' ||
    ($_SERVER['HTTP_X_ELMER_ACTION'] ?? '') !== 'qso-ideas') {
    http_response_code(403); echo json_encode(['error'=>'The QSO Ideas request is invalid.']); exit;
}
$fetchSite = strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
if ($fetchSite !== '' && $fetchSite !== 'same-origin') {
    http_response_code(403); echo json_encode(['error'=>'QSO Ideas must be requested from this RigPi.']); exit;
}
require_once $root . '/programs/sqldata.php';
require_once $root . '/programs/GetUserFieldFunc.php';
ini_set('display_errors', '0'); ini_set('log_errors', '1');

try {
    $request = json_decode(file_get_contents('php://input') ?: '{}', true, 8, JSON_THROW_ON_ERROR);
    $username = (string)$_SESSION['myUsername'];
    $myCall = strtoupper(trim((string)($_SESSION['myCall'] ?? '')));
    $user = (int)getUserField($username, 'uID');
    if ($user < 1) throw new RuntimeException('The signed-in RigPi account was not found.');
    $call = strtoupper(trim((string)($request['call'] ?? '')));
    $db = new mysqli('localhost', $sql_radio_username, $sql_radio_password, $sql_radio_database);
    if ($db->connect_errno) throw new RuntimeException('RigPi could not open the callbook.');
    $db->set_charset('utf8mb4');
    if ($call === '') {
        $stmt = $db->prepare('SELECT Callsign FROM Callbook WHERE User=? LIMIT 1');
        $stmt->bind_param('i', $user); $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
        $call = strtoupper(trim((string)($row['Callsign'] ?? '')));
    }
    if (!preg_match('/^(?:[A-Z0-9]{1,3}\/)?[A-Z0-9]{1,3}\d[A-Z]{1,4}(?:\/[A-Z0-9]{1,4})?$/', $call)) {
        throw new InvalidArgumentException('Look up the other station’s callsign first, then choose QSO Ideas.');
    }

    // Keep log history local.  Only the most recent date/band/mode and count
    // are turned into conversation ideas; no complete QSO record leaves RigPi.
    $stmt = $db->prepare(
        'SELECT u.SelectedRadio,m.LogName FROM Users u ' .
        'LEFT JOIN MySettings m ON m.Radio=u.SelectedRadio WHERE u.Username=? LIMIT 1');
    $stmt->bind_param('s', $username); $stmt->execute();
    $logSettings = $stmt->get_result()->fetch_assoc() ?: []; $stmt->close();
    $logName = trim((string)($logSettings['LogName'] ?? ''));
    $historyWhere = 'UPPER(TRIM(Callsign))=?';
    $historyTypes = 's';
    $historyValues = [$call];
    if ($logName !== '' && strcasecmp($logName, 'ALL Logs') !== 0) {
        $historyWhere .= ' AND Logname=?';
        $historyTypes .= 's'; $historyValues[] = $logName;
    } elseif ($myCall !== '') {
        $historyWhere .= ' AND (UPPER(TRIM(MyCall))=? OR UPPER(TRIM(StationCall))=?)';
        $historyTypes .= 'ss'; $historyValues[] = $myCall; $historyValues[] = $myCall;
    }
    $bindHistory = static function ($stmt, $types, &$values) {
        $arguments = [$types];
        foreach ($values as &$value) $arguments[] = &$value;
        call_user_func_array([$stmt, 'bind_param'], $arguments);
    };
    $stmt = $db->prepare('SELECT COUNT(*) AS contacts FROM Logbook WHERE ' . $historyWhere);
    $bindHistory($stmt, $historyTypes, $historyValues); $stmt->execute();
    $historyCount = (int)(($stmt->get_result()->fetch_assoc()['contacts'] ?? 0)); $stmt->close();
    $lastQso = [];
    if ($historyCount > 0) {
        $stmt = $db->prepare(
            'SELECT Time_Start,Time_Start_Plain,Band,Mode,SubMode,Tx_Frequency,Logname ' .
            'FROM Logbook WHERE ' . $historyWhere .
            " ORDER BY CAST(NULLIF(Time_Start,'') AS UNSIGNED) DESC,MobileID DESC LIMIT 1");
        $bindHistory($stmt, $historyTypes, $historyValues); $stmt->execute();
        $lastQso = $stmt->get_result()->fetch_assoc() ?: []; $stmt->close();
    }

    $sessionId = session_id(); session_write_close();
    $localJson = static function ($path, $payload) use ($sessionId) {
        $context = stream_context_create(['http'=>[
            'method'=>'POST','timeout'=>20,'ignore_errors'=>true,
            'header'=>"Content-Type: application/json\r\nCookie: PHPSESSID=" . $sessionId . "\r\n",
            'content'=>json_encode($payload),
        ]]);
        $raw = @file_get_contents('http://127.0.0.1' . $path, false, $context);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($data) && empty($data['error']) ? $data : [];
    };
    $book = $localJson('/programs/ElmerCallbook.php', [
        'call'=>$call, 'include'=>['biography','details'], 'provider'=>'auto']);
    if (!$book) throw new RuntimeException($call . ' could not be refreshed from the RigPi callbook.');
    $weather = $localJson('/programs/ElmerWeather.php', [
        'target'=>'call','call'=>$call,'mode'=>'forecast','forecast_days'=>2,
        'hourly_hours'=>0,'antenna_safety'=>false,'historical_date'=>'']);

    $ideas = [];
    $add = static function (&$items, $text, $source) {
        foreach ($items as $item) if ($item['text'] === $text) return;
        $items[] = ['text'=>$text, 'source'=>$source];
    };
    $bio = trim((string)($book['biography'] ?? ''));
    $provider = trim((string)($book['provider'] ?? 'callbook'));
    $distanceMiles = is_numeric($book['distance_miles'] ?? null) ? (float)$book['distance_miles'] : null;
    $isNearby = $distanceMiles !== null && $distanceMiles <= 5;
    $name = trim((string)($book['name'] ?? ''));
    $club = trim((string)($book['club'] ?? ''));
    $clubText = trim($name . ' ' . $club . ' ' . $bio);
    $isClub = $club !== '' || preg_match('/\b(?:club|association|society|radio\s+group|amateur\s+radio\s+club|radio\s+club|school|university|college)\b/i', $clubText);
    $isContestCall = (bool)preg_match('/\b(?:contest|contesting|contest\s+call|contest\s+club|multi[- ]?op)\b/i', $clubText);
    $isLocalClub = $isClub && $distanceMiles !== null && $distanceMiles <= 25;
    if ($historyCount > 0 && $lastQso) {
        $timestamp = (int)($lastQso['Time_Start'] ?? 0);
        if ($timestamp <= 0 && trim((string)($lastQso['Time_Start_Plain'] ?? '')) !== '') {
            $timestamp = (int)strtotime((string)$lastQso['Time_Start_Plain'] . ' UTC');
        }
        $date = $timestamp > 0 ? gmdate('n/j/y', $timestamp) : '';
        $band = strtolower(trim((string)($lastQso['Band'] ?? '')));
        $mode = strtoupper(trim((string)($lastQso['SubMode'] ?? '')));
        if ($mode === '') $mode = strtoupper(trim((string)($lastQso['Mode'] ?? '')));
        $details = trim(implode(' ', array_filter([$band, $mode])));
        if ($date !== '') {
            $when = $details !== '' ? "on {$details} on {$date}" : "on {$date}";
            if ($historyCount === 1) {
                $add($ideas, "We last worked {$when}. Good to see you again!", 'RigPi logbook');
            } else {
                $add($ideas, "We’ve worked {$historyCount} times; our most recent QSO was {$when}. Good to see you again!", 'RigPi logbook');
            }
        }
    }
    $isLandmark = false;
    if ($call === 'W1AW') {
        $isLandmark = true;
        $add($ideas, 'Are you a guest operator at W1AW? What is your home callsign?', 'W1AW station profile');
        $add($ideas, 'Is this your first time operating from W1AW?', 'W1AW station profile');
        $add($ideas, 'Which operating position are you using at W1AW today?', 'W1AW station profile');
        $add($ideas, 'What is it like operating from the ARRL headquarters station?', 'W1AW station profile');
        $add($ideas, 'Are you visiting ARRL headquarters, or do you operate W1AW regularly?', 'W1AW station profile');
        $add($ideas, 'What has been the most memorable contact from W1AW today?', 'W1AW station profile');
    }
    if ($isContestCall && !$isLandmark) {
        $add($ideas, 'What contests does the club operate in?', $provider . ' contest profile');
        $add($ideas, "Is {$call} used mainly for contests, or is it active between events too?", $provider . ' contest profile');
        $add($ideas, 'Does the club usually enter single-operator or multi-operator categories?', $provider . ' contest profile');
        $add($ideas, 'Which contest brings out the largest group of operators?', $provider . ' contest profile');
        $add($ideas, 'How does the club assign operators and bands during a contest?', $provider . ' contest profile');
        $add($ideas, 'What has been the club’s most memorable contest result?', $provider . ' contest profile');
        $add($ideas, 'Does the club focus more on domestic contests or DX contests?', $provider . ' contest profile');
    }
    if ($isClub && !$isLandmark) {
        if ($isLocalClub) {
            $clubName = $name !== '' ? $name : $call;
            $add($ideas, "I see {$call} is a local club call. Are you a member of {$clubName}?", $provider . ' local-club context');
            $add($ideas, "How long have you been involved with {$call}?", $provider . ' local-club context');
            $add($ideas, "Which {$call} club activities do you take part in?", $provider . ' local-club context');
            $add($ideas, "Does {$call} have a regular meeting, net, or repeater?", $provider . ' local-club context');
            $add($ideas, "What is {$call} doing to bring new people into amateur radio?", $provider . ' local-club context');
        } else {
            $add($ideas, "I see {$call} is a club call. Are you connected with an organization or school?", $provider . ' callbook');
            $add($ideas, 'What kinds of activities keep your club busiest?', $provider . ' callbook');
            $add($ideas, 'How many active operators does the club have?', $provider . ' callbook');
            $add($ideas, 'Does the club have a regular meeting, net, or repeater?', $provider . ' callbook');
            $add($ideas, 'What is the club doing to bring new people into amateur radio?', $provider . ' callbook');
        }
    }
    if ($bio !== '') {
        foreach (['German Shepherd'=>'German Shepherd','golden retriever'=>'golden retriever',
                  'Labrador'=>'Labrador','my dog'=>'dog','our dog'=>'dog','my cat'=>'cat','our cat'=>'cat'] as $needle=>$label) {
            if (stripos($bio, $needle) !== false) {
                $add($ideas, "I see your profile mentions a {$label}. What is their name?", $provider . ' biography'); break;
            }
        }
        foreach (['Collins','Drake','Heathkit','Hallicrafters','Hammarlund'] as $brand) {
            if (stripos($bio, $brand) !== false) {
                $add($ideas, "I see you use classic {$brand} equipment. How long have you had it?", $provider . ' biography'); break;
            }
        }
        foreach (['Elecraft','Icom','Yaesu','Kenwood','FlexRadio','Flex Radio','Apache Labs','Ten-Tec'] as $brand) {
            if (stripos($bio, $brand) !== false) {
                $add($ideas, "I see your profile mentions {$brand} equipment. Which radio is your favorite?", $provider . ' biography'); break;
            }
        }
        foreach (['CW','FT8','QRP','DXing','satellite','contest'] as $interest) {
            if (preg_match('/\b' . preg_quote($interest, '/') . '\b/i', $bio)) {
                $add($ideas, "I see you enjoy {$interest}. What first drew you to it?", $provider . ' biography'); break;
            }
        }
    }
    $tomorrow = $weather['daily'][1] ?? null;
    if (is_array($tomorrow) && !$isNearby && !$isLocalClub) {
        $chance = is_numeric($tomorrow['precipitation_probability'] ?? null) ? (float)$tomorrow['precipitation_probability'] : 0;
        $amount = is_numeric($tomorrow['precipitation'] ?? null) ? (float)$tomorrow['precipitation'] : 0;
        if ($chance >= 40 || $amount > 0) {
            $add($ideas, 'Looks like rain may reach your QTH tomorrow. Do you get much rain there this time of year?', 'Open-Meteo forecast');
        }
    }
    $city = trim((string)($book['city'] ?? ''));
    $state = trim((string)($book['state'] ?? ''));
    if ($city !== '' && !$isNearby && !$isLocalClub) {
        $place = $city . ($state !== '' ? ', ' . $state : '');
        $add($ideas, "I see you’re in {$place}. What is the ham-radio community like there?", $provider . ' callbook');
    } elseif ($city !== '' && $isNearby && !$isClub) {
        $add($ideas, 'I see you’re nearby. Which local club, net, or repeater are you most active with?', $provider . ' nearby-station context');
    }
    if (!$isClub && !$isLandmark) {
        $previousCall = trim((string)($book['previous_call'] ?? ''));
        if ($previousCall !== '') {
            $add($ideas, "I see you previously held {$previousCall}. What prompted the callsign change?", $provider . ' callbook');
        }
        $add($ideas, 'What radio and antenna are you using today?', 'general QSO idea');
        $add($ideas, 'How long have you been active in amateur radio?', 'general QSO idea');
        $add($ideas, 'Which band or mode do you enjoy most?', 'general QSO idea');
        $add($ideas, 'Have you made any recent changes to your station?', 'general QSO idea');
        $add($ideas, 'What amateur-radio project are you working on now?', 'general QSO idea');
        $add($ideas, 'What was your most memorable contact?', 'general QSO idea');
    }
    $add($ideas, 'What part of amateur radio are you enjoying most lately?', 'general QSO idea');
    echo json_encode(['call'=>$call,'name'=>$name,'is_club'=>(bool)$isClub,
        'is_contest_call'=>(bool)$isContestCall,'is_nearby'=>(bool)$isNearby,
        'is_local_club'=>(bool)$isLocalClub,'is_landmark'=>(bool)$isLandmark,
        'log_history'=>['contacts'=>$historyCount,'last_date'=>$date ?? '',
            'last_band'=>$band ?? '','last_mode'=>$mode ?? ''],'ideas'=>$ideas],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (JsonException | InvalidArgumentException $error) {
    http_response_code(400); echo json_encode(['error'=>$error->getMessage()]);
} catch (Throwable $error) {
    error_log('Elmer QSO Ideas: ' . $error->getMessage());
    http_response_code(500); echo json_encode(['error'=>'RigPi could not prepare QSO conversation ideas.']);
}
