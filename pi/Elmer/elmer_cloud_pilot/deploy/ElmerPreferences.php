<?php
/** Per-user Elmer preferences and privacy-limited local conversation starters. */
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$root = '/var/www/html';
if (empty($_SESSION['myUsername'])) {
    http_response_code(401); echo json_encode(['error'=>'Please sign in to RigPi.']); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' ||
    ($_SERVER['HTTP_X_ELMER_ACTION'] ?? '') !== 'preferences') {
    http_response_code(403); echo json_encode(['error'=>'The Elmer preference request is invalid.']); exit;
}
$fetchSite = strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
if ($fetchSite !== '' && $fetchSite !== 'same-origin') {
    http_response_code(403); echo json_encode(['error'=>'Elmer preferences must be managed from this RigPi.']); exit;
}
require_once $root . '/programs/sqldata.php';
require_once $root . '/programs/GetUserFieldFunc.php';
ini_set('display_errors', '0'); ini_set('log_errors', '1');

try {
    $request = json_decode(file_get_contents('php://input') ?: '{}', true, 8, JSON_THROW_ON_ERROR);
    $action = strtolower(trim((string)($request['action'] ?? 'status')));
    if (!in_array($action, ['status','set','starter'], true)) {
        throw new InvalidArgumentException('The requested Elmer preference action is invalid.');
    }
    $username = (string)$_SESSION['myUsername'];
    $user = (int)getUserField($username, 'uID');
    $call = strtoupper(trim((string)($_SESSION['myCall'] ?? '')));
    if ($user < 1) throw new RuntimeException('The signed-in RigPi account was not found.');
    $db = new mysqli('localhost', $sql_radio_username, $sql_radio_password, $sql_radio_database);
    if ($db->connect_errno) throw new RuntimeException('RigPi could not open Elmer preferences.');
    $db->set_charset('utf8mb4');
    if (!$db->query("CREATE TABLE IF NOT EXISTS ElmerPreferences (
        uID INT NOT NULL PRIMARY KEY,
        conversation_starters TINYINT(1) NOT NULL DEFAULT 0,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4")) {
        throw new RuntimeException('RigPi could not initialize Elmer preferences.');
    }
    $stmt = $db->prepare('SELECT conversation_starters FROM ElmerPreferences WHERE uID=?');
    $stmt->bind_param('i', $user); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    $enabled = !empty($row['conversation_starters']);
    if ($action === 'set') {
        $enabled = !empty($request['conversation_starters']);
        $value = $enabled ? 1 : 0;
        $stmt = $db->prepare('INSERT INTO ElmerPreferences (uID,conversation_starters) VALUES (?,?) ON DUPLICATE KEY UPDATE conversation_starters=VALUES(conversation_starters)');
        $stmt->bind_param('ii', $user, $value); $stmt->execute(); $stmt->close();
        echo json_encode(['conversation_starters'=>$enabled]); exit;
    }
    if ($action === 'status' || !$enabled || !preg_match('/^(?:[A-Z0-9]{1,3}\/)?[A-Z0-9]{1,3}\d[A-Z]{1,4}(?:\/[A-Z0-9]{1,4})?$/', $call)) {
        echo json_encode(['conversation_starters'=>$enabled,'starter'=>'']); exit;
    }

    // Release the PHP session lock before authenticated loopback connector calls.
    $sessionId = session_id(); session_write_close();
    $localJson = static function ($path, $payload) use ($sessionId) {
        $context = stream_context_create(['http'=>[
            'method'=>'POST','timeout'=>18,'ignore_errors'=>true,
            'header'=>"Content-Type: application/json\r\nCookie: PHPSESSID=" . $sessionId . "\r\n",
            'content'=>json_encode($payload),
        ]]);
        $raw = @file_get_contents('http://127.0.0.1' . $path, false, $context);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($data) && empty($data['error']) ? $data : [];
    };
    $book = $localJson('/programs/ElmerCallbook.php', [
        'call'=>$call, 'include'=>['biography'], 'provider'=>'auto']);
    $weather = $localJson('/programs/ElmerWeather.php', [
        'target'=>'user','mode'=>'forecast','forecast_days'=>2,'hourly_hours'=>0,
        'antenna_safety'=>false,'historical_date'=>'']);
    $candidates = [];
    $bio = trim((string)($book['biography'] ?? ''));
    $provider = trim((string)($book['provider'] ?? 'callbook'));
    if ($bio !== '') {
        $petPatterns = [
            'German Shepherd'=>'German Shepherd', 'golden retriever'=>'golden retriever',
            'Labrador'=>'Labrador', 'my dog'=>'dog', 'our dog'=>'dog', 'my cat'=>'cat', 'our cat'=>'cat'];
        foreach ($petPatterns as $needle=>$label) {
            if (stripos($bio, $needle) !== false) {
                $candidates[] = ['text'=>"Your {$provider} biography mentions a {$label}. What is their name?",'source'=>$provider . ' biography'];
                break;
            }
        }
        $classic = ['Collins','Drake','Heathkit','Hallicrafters','Hammarlund'];
        $modern = ['Elecraft','Icom','Yaesu','Kenwood','FlexRadio','Flex Radio','Apache Labs','Ten-Tec'];
        foreach ($classic as $brand) {
            if (stripos($bio, $brand) !== false) {
                $candidates[] = ['text'=>"Your {$provider} biography mentions classic {$brand} equipment. How long have you had it?",'source'=>$provider . ' biography'];
                break;
            }
        }
        foreach ($modern as $brand) {
            if (stripos($bio, $brand) !== false) {
                $candidates[] = ['text'=>"Your {$provider} biography mentions {$brand} equipment. Which radio is your favorite?",'source'=>$provider . ' biography'];
                break;
            }
        }
        foreach (['CW','FT8','QRP','DXing','satellite','contest'] as $interest) {
            if (preg_match('/\b' . preg_quote($interest, '/') . '\b/i', $bio)) {
                $candidates[] = ['text'=>"Your {$provider} biography mentions {$interest}. What first drew you to it?",'source'=>$provider . ' biography'];
                break;
            }
        }
        if (stripos($bio, 'RigPi') !== false) {
            $candidates[] = ['text'=>"Your {$provider} biography mentions RigPi Station Server. What inspired you to create it?",'source'=>$provider . ' biography'];
        } elseif (stripos($bio, 'CommCat') !== false) {
            $candidates[] = ['text'=>"Your {$provider} biography mentions CommCat. How did that project get started?",'source'=>$provider . ' biography'];
        }
        if (!$candidates) {
            $candidates[] = ['text'=>"Your {$provider} biography tells quite a station story. What part of amateur radio has kept you interested the longest?",'source'=>$provider . ' biography'];
        }
    }
    $tomorrow = $weather['daily'][1] ?? null;
    if (is_array($tomorrow)) {
        $chance = is_numeric($tomorrow['precipitation_probability'] ?? null) ? (float)$tomorrow['precipitation_probability'] : 0;
        $amount = is_numeric($tomorrow['precipitation'] ?? null) ? (float)$tomorrow['precipitation'] : 0;
        if ($chance >= 40 || $amount > 0) {
            $candidates[] = ['text'=>'It looks like precipitation may reach your QTH tomorrow. Is that typical there this time of year?','source'=>'Open-Meteo forecast'];
        }
    }
    if (!$candidates) $candidates[] = [
        'text'=>'What part of amateur radio are you enjoying most lately?',
        'source'=>'RigPi conversation starter'];
    $choice = $candidates[random_int(0, count($candidates)-1)];
    echo json_encode(['conversation_starters'=>true,'starter'=>$choice['text'],
        'source'=>$choice['source']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (JsonException | InvalidArgumentException $error) {
    http_response_code(400); echo json_encode(['error'=>$error->getMessage()]);
} catch (Throwable $error) {
    error_log('Elmer preferences: ' . $error->getMessage());
    http_response_code(500); echo json_encode(['error'=>'RigPi could not manage Elmer conversation starters.']);
}
