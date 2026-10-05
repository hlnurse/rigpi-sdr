<?php
/** Authenticated, privacy-limited live callbook data for Ask Elmer. */
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

try {
    $request = json_decode(file_get_contents('php://input'), true, 8, JSON_THROW_ON_ERROR);
    $call = strtoupper(trim((string)($request['call'] ?? '')));
    if (strlen($call) > 24 || !preg_match('/^(?:[A-Z0-9]{1,3}\/)?[A-Z0-9]{1,3}\d[A-Z]{1,4}(?:\/[A-Z0-9]{1,4})?$/', $call) || preg_match('/^\d+(?:HZ|KHZ|MHZ|GHZ)$/', $call)) {
        throw new InvalidArgumentException('Please enter one valid amateur-radio callsign.');
    }
    $include = $request['include'] ?? [];
    $provider = strtolower(trim((string)($request['provider'] ?? 'auto')));
    if (!in_array($provider, ['auto', 'fcc'], true)) {
        throw new InvalidArgumentException('The requested callbook provider is invalid.');
    }
    if (!is_array($include)) {
        throw new InvalidArgumentException('The requested callbook details are invalid.');
    }
    $include = array_values(array_unique(array_map('strval', $include)));
    if (array_diff($include, ['address', 'biography', 'image', 'email', 'website', 'qsl', 'details'])) {
        throw new InvalidArgumentException('The requested callbook details are invalid.');
    }
    $username = (string)$_SESSION['myUsername'];
    $user = (int)getUserField($username, 'uID');
    if ($user < 1) {
        throw new RuntimeException('The signed-in RigPi account was not found.');
    }
    $qrzPassword = trim((string)getUserField($username, 'qrzPWD'));
    $hamqthUser = trim((string)getUserField($username, 'hamqthUser'));
    $hamqthPassword = trim((string)getUserField($username, 'hamqthPWD'));
    $hasExternalCallbook = $qrzPassword !== '' || ($hamqthUser !== '' && $hamqthPassword !== '');
    $requestedProvider = $provider === 'fcc' || !$hasExternalCallbook ? 'FCCData' : 'QRZData';

    $db = new MysqliDb('localhost', $sql_radio_username, $sql_radio_password, $sql_radio_database);
    $db->where('User', $user);
    $existing = $db->getOne('Callbook');
    $existingSource = strtoupper(trim((string)($existing['Note'] ?? '')));
    if ($provider === 'auto' && $hasExternalCallbook && $existing &&
        strtoupper(trim((string)($existing['Callsign'] ?? ''))) === $call &&
        strpos($existingSource, 'FCC') !== false) {
        // A previous FCC-only result must not hide a newly configured external callbook.
        clearCallbookRow($user, $db);
    }
    getCallbookFunc($call, $requestedProvider, $user);

    $db->where('User', $user);
    $row = $db->getOne('Callbook');
    if (!$row || strtoupper(trim((string)($row['Callsign'] ?? ''))) !== $call) {
        http_response_code(404);
        echo json_encode(['error' => $call . ' was not found by the RigPi callbook.']);
        exit;
    }

    $bioIsMissing = static function ($value) {
        $value = trim((string)$value);
        return $value === '' || stripos($value, '<QRZDatabase') !== false ||
            stripos($value, '<Session>') !== false ||
            stripos($value, 'Invalid session key') !== false ||
            stripos($value, 'Session Timeout') !== false;
    };
    $needsBio = in_array('biography', $include, true) ||
        (in_array('image', $include, true) && trim((string)($row['ImageURL'] ?? '')) === '');
    $initialSource = strtoupper(trim((string)($row['Note'] ?? '')));
    if ($qrzPassword !== '' && (strpos($initialSource, 'QRZ') !== false || $requestedProvider === 'QRZData') && $needsBio &&
        $bioIsMissing($row['His_Bio'] ?? '')) {
        $db->where('uID', $user);
        $qrzAccount = $db->getOne('Users');
        $key = trim((string)($qrzAccount['qrzKey'] ?? ''));
        $qrzUser = trim((string)($qrzAccount['qrzUser'] ?? ''));
        if ($key !== '') {
            getBio($key, $call, $user, $qrzUser, $db);
            $db->where('User', $user);
            $row = $db->getOne('Callbook');
        }
        if ($bioIsMissing($row['His_Bio'] ?? '')) {
            $key = trim((string)getKey($qrzUser, $qrzPassword, $db, $user));
            if ($key !== '') {
                $db->where('uID', $user);
                $db->update('Users', ['qrzKey' => $key]);
                getBio($key, $call, $user, $qrzUser, $db);
                $db->where('User', $user);
                $row = $db->getOne('Callbook');
            }
        }
    }

    $source = strtoupper(trim((string)($row['Note'] ?? '')));
    // Older RigPi importers often leave a generic Note even after a successful
    // QRZ lookup. The provider requested for this lookup is more authoritative
    // than that legacy note; an explicit HamQTH note still takes precedence.
    $isHamqth = strpos($source, 'HAMQTH') !== false;
    $isQrz = !$isHamqth && (strpos($source, 'QRZ') !== false ||
        ($requestedProvider === 'QRZData' && $qrzPassword !== ''));
    $isExternal = $isQrz || $isHamqth;
    $text = static function ($value) { return trim((string)$value); };
    $qrzRecord = null;
    $hamqthRecord = [];
    $hamqthBio = '';
    $fccDate = [];
    $extended = array_intersect($include, ['email', 'website', 'qsl', 'details']);
    if (in_array('details', $include, true)) {
        $dateRows = $db->rawQuery(
            "SELECT status,grant_date,effective_date,expiration_date,cancellation_date,last_action_date " .
            "FROM fcc_amateur.elmer_license_dates WHERE callsign=? " .
            "ORDER BY (status='A') DESC,expiration_date DESC LIMIT 1",
            [$call]
        );
        if (is_array($dateRows) && $dateRows) $fccDate = $dateRows[0];
    }
    if ($isQrz && $extended) {
        $db->where('uID', $user);
        $qrzAccount = $db->getOne('Users');
        $key = trim((string)($qrzAccount['qrzKey'] ?? ''));
        $qrzUser = trim((string)($qrzAccount['qrzUser'] ?? ''));
        $fetchRecord = static function ($sessionKey, $lookupCall) {
            if ($sessionKey === '') return [null, 'Missing session key'];
            $context = stream_context_create(['http' => [
                'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                'method' => 'POST',
                'content' => http_build_query(['s' => $sessionKey, 'callsign' => $lookupCall]),
                'timeout' => 10,
            ]]);
            $raw = @file_get_contents('https://xmldata.qrz.com/xml/current', false, $context);
            if ($raw === false) return [null, 'QRZ lookup failed'];
            $xml = @simplexml_load_string($raw);
            if ($xml === false) return [null, 'Invalid QRZ response'];
            $error = trim((string)($xml->Session->Error ?? ''));
            return [isset($xml->Callsign) ? $xml->Callsign : null, $error];
        };
        [$qrzRecord, $qrzError] = $fetchRecord($key, $call);
        if ($qrzRecord === null && $qrzError !== '' && $qrzUser !== '') {
            $key = trim((string)getKey($qrzUser, $qrzPassword, $db, $user));
            if ($key !== '') {
                $db->where('uID', $user);
                $db->update('Users', ['qrzKey' => $key]);
                [$qrzRecord] = $fetchRecord($key, $call);
            }
        }
    }
    if ($isHamqth && ($extended || array_intersect($include, ['biography', 'image']))) {
        $context = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
        $authUrl = 'https://www.hamqth.com/xml.php?u=' . rawurlencode($hamqthUser) .
            '&p=' . rawurlencode($hamqthPassword) . '&prg=RigPi_Elmer';
        $authXml = @file_get_contents($authUrl, false, $context);
        $sessionId = '';
        if ($authXml !== false && preg_match('#<session_id>([^<]+)</session_id>#i', $authXml, $match)) {
            $sessionId = trim(html_entity_decode($match[1], ENT_QUOTES | ENT_XML1, 'UTF-8'));
        }
        $xmlTag = static function ($xml, $field) {
            if (!is_string($xml) || $xml === '') return '';
            $quoted = preg_quote($field, '#');
            if (!preg_match('#<' . $quoted . '(?:\\s[^>]*)?>(.*?)</' . $quoted . '>#is', $xml, $match)) return '';
            $value = preg_replace('#^<!\\[CDATA\\[(.*)\\]\\]>$#is', '$1', trim($match[1])) ?? '';
            return trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_XML1, 'UTF-8'));
        };
        if ($sessionId !== '') {
            $lookupUrl = 'https://www.hamqth.com/xml.php?id=' . rawurlencode($sessionId) .
                '&callsign=' . rawurlencode($call) . '&prg=RigPi_Elmer';
            $lookupXml = @file_get_contents($lookupUrl, false, $context);
            foreach (['email', 'web', 'qsl_via', 'lotw', 'eqsl', 'qsl', 'nick', 'iota',
                      'utc_offset', 'picture', 'us_county'] as $field) {
                $hamqthRecord[$field] = $xmlTag($lookupXml, $field);
            }
            if (in_array('biography', $include, true)) {
                $bioUrl = 'https://www.hamqth.com/xml_bio.php?id=' . rawurlencode($sessionId) .
                    '&callsign=' . rawurlencode($call) . '&strip_html=1';
                $hamqthBio = $xmlTag(@file_get_contents($bioUrl, false, $context), 'bio');
            }
        }
    }
    $xmlText = static function ($record, $field) {
        return $record !== null && isset($record->{$field}) ? trim((string)$record->{$field}) : '';
    };
    $result = [
        'call' => $call,
        'name' => $text($row['His_Name'] ?? ''),
        'city' => $text($row['His_City'] ?? ''),
        'state' => $text($row['His_State'] ?? ''),
        'country' => $text($row['His_Country'] ?? ''),
        'grid' => $text($row['His_Grid'] ?? ''),
        'entity' => $text($row['His_Entity'] ?? ''),
        'dxcc' => $text($row['DXCC'] ?? ''),
        'cq_zone' => $text($row['CQZone'] ?? ''),
        'itu_zone' => $text($row['ITUZone'] ?? ''),
        'wpx_prefix' => $text($row['WPX_Prefix'] ?? ''),
        'license_class' => $text($row['LicenseClass'] ?? ''),
        'distance_miles' => $text($row['His_Distance_Mi'] ?? ''),
        'distance_km' => $text($row['His_Distance_KM'] ?? ''),
        'bearing_degrees' => $text($row['Beam_Heading'] ?? ''),
        'provider' => $isQrz ? 'QRZ XML' : ($isHamqth ? 'HamQTH XML' : 'RigPi onboard FCC data'),
        'retrieved_at' => gmdate('c'),
    ];
    if (!$isExternal) {
        $db->where('callsign', $call);
        $amateur = $db->getOne('fcc_amateur.am');
        if ($amateur) {
            $classCode = strtoupper(trim((string)($amateur['class'] ?? '')));
            $classNames = ['T' => 'Technician', 'G' => 'General', 'E' => 'Amateur Extra',
                'A' => 'Advanced', 'N' => 'Novice'];
            $result['license_class'] = $classNames[$classCode] ?? $classCode;
            $result['previous_call'] = strtoupper(trim((string)($amateur['former_call'] ?? '')));
        }
    }
    if ($isQrz) {
        $result['reference'] = 'https://www.qrz.com/db/' . rawurlencode($call);
    } elseif ($isHamqth) {
        $result['reference'] = 'https://www.hamqth.com/' . rawurlencode($call);
    }
    if (in_array('address', $include, true)) {
        $result['address_line'] = $text($row['His_Street'] ?? '');
        $result['postal_code'] = $text($row['His_Zip'] ?? '');
        $result['county'] = $text($row['His_County'] ?? '');
    }
    if ($isExternal && in_array('email', $include, true)) {
        $email = $isQrz ? $xmlText($qrzRecord, 'email') : ($hamqthRecord['email'] ?? '');
        $email = $email ?: $text($row['His_Email'] ?? '');
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $result['email'] = $email;
        }
    }
    if ($isExternal && in_array('website', $include, true)) {
        $website = $isQrz ? $xmlText($qrzRecord, 'url') : ($hamqthRecord['web'] ?? '');
        $website = $website ?: $text($row['His_URL'] ?? '');
        if ($website !== '' && filter_var($website, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $website)) {
            $result['website'] = $website;
        }
    }
    $yesNo = static function ($value) {
        $value = strtolower(trim((string)$value));
        if (in_array($value, ['1', 'y', 'yes'], true)) return 'yes';
        if (in_array($value, ['0', 'n', 'no'], true)) return 'no';
        return $value === '' ? 'unknown' : $value;
    };
    if ($isExternal && in_array('qsl', $include, true)) {
        $result['qsl_manager'] = ($isQrz ? $xmlText($qrzRecord, 'qslmgr') : ($hamqthRecord['qsl_via'] ?? '')) ?: $text($row['QSLMgr'] ?? '');
        $result['lotw'] = $yesNo(($isQrz ? $xmlText($qrzRecord, 'lotw') : ($hamqthRecord['lotw'] ?? '')) ?: ($row['LoTW'] ?? ''));
        $result['eqsl'] = $yesNo(($isQrz ? $xmlText($qrzRecord, 'eqsl') : ($hamqthRecord['eqsl'] ?? '')) ?: ($row['eQSL'] ?? ''));
        $result['paper_qsl'] = $yesNo(($isQrz ? $xmlText($qrzRecord, 'mqsl') : ($hamqthRecord['qsl'] ?? '')) ?: ($row['mQSL'] ?? ''));
    }
    if (in_array('details', $include, true)) {
        $result['aliases'] = ($isQrz ? $xmlText($qrzRecord, 'aliases') : '') ?: $text($row['Aliases'] ?? '');
        $result['previous_call'] = ($isQrz ? $xmlText($qrzRecord, 'p_call') : '') ?: ($result['previous_call'] ?? '');
        $result['nickname'] = $isQrz ? $xmlText($qrzRecord, 'nickname') : ($hamqthRecord['nick'] ?? '');
        $result['formatted_name'] = $isQrz ? $xmlText($qrzRecord, 'name_fmt') : $text($row['His_Name'] ?? '');
        $result['license_effective'] = ($isQrz ? $xmlText($qrzRecord, 'efdate') : '') ?:
            ($text($fccDate['effective_date'] ?? '') ?: $text($fccDate['grant_date'] ?? ''));
        $result['license_expires'] = ($isQrz ? $xmlText($qrzRecord, 'expdate') : '') ?:
            $text($fccDate['expiration_date'] ?? '');
        $result['iota'] = ($isQrz ? $xmlText($qrzRecord, 'iota') : ($hamqthRecord['iota'] ?? '')) ?: $text($row['IOTA'] ?? '');
        $result['time_zone'] = $isQrz ? ($xmlText($qrzRecord, 'TimeZone') ?: $text($row['TimeZone'] ?? '')) : '';
        $result['gmt_offset'] = $isQrz ? ($xmlText($qrzRecord, 'GMTOffset') ?: $text($row['GMTOffset'] ?? '')) : ($hamqthRecord['utc_offset'] ?? '');
        $result['daylight_saving'] = $isQrz ? $yesNo($xmlText($qrzRecord, 'DST') ?: ($row['DST'] ?? '')) : 'unknown';
        $result['club'] = $text($row['Club'] ?? '');
        $latitude = $isQrz ? $xmlText($qrzRecord, 'lat') : '';
        $longitude = $isQrz ? $xmlText($qrzRecord, 'lon') : '';
        $latitude = $latitude !== '' ? $latitude : $text($row['His_Latitude'] ?? '');
        $longitude = $longitude !== '' ? $longitude : $text($row['His_Longitude'] ?? '');
        if (is_numeric($latitude) && is_numeric($longitude) &&
            (float)$latitude >= -90 && (float)$latitude <= 90 &&
            (float)$longitude >= -180 && (float)$longitude <= 180) {
            // Local-only routing hints. Ask Elmer removes these before sending
            // callbook facts to the hosted answer service.
            $result['_center_latitude'] = round((float)$latitude, 7);
            $result['_center_longitude'] = round((float)$longitude, 7);
            $result['_center_basis'] = $isQrz ? 'QRZ XML coordinates' : 'callbook coordinates';
        }
    }
    if ($isExternal && in_array('image', $include, true)) {
        $image = $isHamqth ? ($hamqthRecord['picture'] ?? '') : $text($row['ImageURL'] ?? '');
        $image = $image ?: $text($row['ImageURL'] ?? '');
        $image = preg_replace('#^http://#i', 'https://', $image) ?? '';
        if ($image === '') {
            $bioHtml = html_entity_decode((string)($row['His_Bio'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (preg_match('#(?:https?:)?//(?:cdn-bio|cdn-xml|files|static)\.qrz\.com/[^\s"\'<>]+#i', $bioHtml, $match)) {
                $image = str_starts_with($match[0], '//') ? 'https:' . $match[0] : $match[0];
                $image = preg_replace('#^http://#i', 'https://', $image) ?? '';
            }
        }
        if (preg_match('#^https://(?:cdn-bio|cdn-xml|files|static)\.qrz\.com/#i', $image) ||
            preg_match('#^https://(?:www\.)?hamqth\.com/#i', $image)) {
            $result['image_url'] = $image;
        }
    }
    if ($isExternal && in_array('biography', $include, true)) {
        $bio = $isHamqth ? $hamqthBio : (string)($row['His_Bio'] ?? '');
        $bio = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $bio) ?? '';
        $equipmentHtml = preg_replace('#<(?:br\s*/?|/p|/div|/li|/h[1-6])\s*>#i', "\n", $bio) ?? $bio;
        // Strip markup before decoding entities. QRZ biographies can contain an
        // encoded "<<--" sequence that otherwise becomes a malformed HTML tag
        // and causes strip_tags() to discard everything that follows it.
        $equipmentText = html_entity_decode(strip_tags($equipmentHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $equipmentLines = preg_split('/\R+/u', $equipmentText) ?: [];
        $equipmentLines = array_values(array_filter(array_map(static function ($line) {
            return trim(preg_replace('/\s+/u', ' ', (string)$line) ?? '');
        }, $equipmentLines), static function ($line) {
            return $line !== '' && preg_match('/\b(?:rigs?|radios?|transceivers?|receivers?|equipment|setup|shack|antennas?|amplifiers?|amps?|tuners?|elecraft|icom|yaesu|kenwood|ten[ -]?tec|flexradio)\b/i', $line);
        }));
        $equipment = trim(implode(' ', array_slice($equipmentLines, 0, 8)));
        if ($equipment !== '') {
            $equipment = function_exists('mb_substr') ? mb_substr($equipment, 0, 1800) : substr($equipment, 0, 1800);
        }
        $bio = html_entity_decode(strip_tags($bio), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $bio = trim(preg_replace('/\s+/u', ' ', $bio) ?? '');
        if ($bio !== '') {
            if ($equipment !== '') {
                $bio = "Station equipment excerpt: {$equipment}\n\nBiography: {$bio}";
            }
            $result['biography'] = function_exists('mb_substr') ? mb_substr($bio, 0, 6000) : substr($bio, 0, 6000);
        }
    }
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (JsonException | InvalidArgumentException $error) {
    http_response_code(400);
    echo json_encode(['error' => $error->getMessage()]);
} catch (Throwable $error) {
    error_log('Elmer callbook: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'RigPi could not complete the callbook lookup.']);
}
