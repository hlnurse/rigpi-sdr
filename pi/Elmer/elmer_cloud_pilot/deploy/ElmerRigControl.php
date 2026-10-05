<?php
/** Authenticated, receive-only Ask Elmer radio actions. */
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
if (($_SERVER['HTTP_X_ELMER_ACTION'] ?? '') !== 'rig-control') {
    http_response_code(403);
    echo json_encode(['error' => 'The RigPi action request is missing its safety header.']);
    exit;
}
$fetchSite = strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
if ($fetchSite !== '' && $fetchSite !== 'same-origin') {
    http_response_code(403);
    echo json_encode(['error' => 'RigPi actions must originate from this RigPi.']);
    exit;
}

require $root . '/programs/sqldata.php';
require_once $root . '/programs/ElmerRigActions.php';

class ElmerSafetyInterlockException extends RuntimeException {}
class ElmerCalibrationException extends RuntimeException {}

function elmerRigCommand(int $port, string $command, array $arguments = []): array {
    if (!in_array($command, ['f', 'm', 't', 'l', 'F', 'M', 'L'], true)) {
        throw new RuntimeException('The radio command is not allowlisted.');
    }
    if (in_array($command, ['l', 'L'], true)) {
        $level = strtoupper(trim((string)($arguments[0] ?? '')));
        $allowedLevels = $command === 'l' ? ['AF', 'RF', 'SQL', 'STRENGTH'] : ['AF', 'RF', 'SQL'];
        if (!in_array($level, $allowedLevels, true)) {
            throw new RuntimeException('The receiver level is not allowlisted.');
        }
        if ($command === 'l' && count($arguments) !== 1) {
            throw new RuntimeException('The receiver level read is invalid.');
        }
        if ($command === 'L') {
            $value = $arguments[1] ?? null;
            if (count($arguments) !== 2 || !is_numeric($value)
                || (float)$value < 0.0 || (float)$value > 1.0) {
                throw new RuntimeException('The receiver level value is invalid.');
            }
        }
    }
    $socket = @fsockopen('127.0.0.1', $port, $socketError, $socketMessage, 0.5);
    if ($socket === false) {
        throw new RuntimeException('The selected radio control is not connected. Connect the radio in RigPi and try again.');
    }
    fclose($socket);
    $parts = ['/usr/bin/timeout', '6', '/usr/bin/rigctl', '-m', '2', '-r',
              '127.0.0.1:' . $port, $command];
    foreach ($arguments as $argument) $parts[] = (string)$argument;
    $shell = implode(' ', array_map('escapeshellarg', $parts)) . ' 2>&1';
    $output = [];
    $status = 0;
    exec($shell, $output, $status);
    $text = trim(implode("\n", $output));
    if ($status !== 0 || preg_match('/RPRT\s+-\d+/', $text)) {
        throw new RuntimeException('The selected radio rejected the receive-control command.');
    }
    return $output;
}

function elmerRigFrequency(int $port): int {
    foreach (array_reverse(elmerRigCommand($port, 'f')) as $line) {
        if (preg_match('/^\s*(\d{3,11})(?:\.\d+)?\s*$/', $line, $match)) return (int)$match[1];
    }
    throw new RuntimeException('RigPi could not read the radio frequency.');
}

function elmerRigModeState(int $port): array {
    $mode = '';
    $bandwidth = null;
    foreach (elmerRigCommand($port, 'm') as $line) {
        $value = strtoupper(trim($line));
        if ($mode === '' && preg_match('/^[A-Z][A-Z0-9-]{0,11}$/', $value)
            && !str_starts_with($value, 'RPRT')) {
            $mode = $value;
            continue;
        }
        if ($mode !== '' && preg_match('/^-?\d+$/', $value)) {
            $bandwidth = (int)$value;
            break;
        }
    }
    if ($mode === '') throw new RuntimeException('RigPi could not read the radio mode.');
    return ['mode' => $mode,
            'bandwidth_hz' => $bandwidth !== null && $bandwidth > 0 ? $bandwidth : null];
}

function elmerReceiverState(int $port): array {
    return ['frequency_hz' => elmerRigFrequency($port)] + elmerRigModeState($port);
}

function elmerLevelName(mixed $value): string {
    $level = strtoupper(trim((string)$value));
    if (!in_array($level, ['AF', 'RF', 'SQL'], true)) {
        throw new InvalidArgumentException('The requested receiver level is not allowlisted.');
    }
    return $level;
}

function elmerLevelPercent(mixed $value): int {
    if (is_bool($value) || !is_numeric($value)) {
        throw new InvalidArgumentException('The requested receiver level percentage is invalid.');
    }
    $percent = (int)round((float)$value);
    if ($percent < 0 || $percent > 100) {
        throw new InvalidArgumentException('The requested receiver level must be between 0 and 100 percent.');
    }
    return $percent;
}

function elmerRigLevel(int $port, string $level): float {
    $level = elmerLevelName($level);
    foreach (array_reverse(elmerRigCommand($port, 'l', [$level])) as $line) {
        $value = trim($line);
        if (is_numeric($value)) {
            $number = (float)$value;
            if ($number >= 0.0 && $number <= 1.0) return $number;
        }
    }
    throw new RuntimeException("RigPi could not read the {$level} receiver level.");
}

function elmerApplyReceiverLevel(int $port, string $level, int $percent,
                                 ?float &$lastActual = null): float {
    $level = elmerLevelName($level);
    $percent = elmerLevelPercent($percent);
    $target = $percent / 100.0;
    elmerAssertReceive($port);
    elmerRigCommand($port, 'L', [$level, number_format($target, 6, '.', '')]);
    // This radio/backend acknowledges RF gain before the new value is ready
    // to read. Waiting here prevents the following sweep step from overtaking
    // the preceding write.
    if ($level === 'RF') usleep(1100000);
    for ($attempt = 0; $attempt < 5; $attempt++) {
        usleep(200000);
        elmerAssertReceive($port);
        $actual = elmerRigLevel($port, $level);
        $lastActual = $actual;
        // Many radios quantize normalized Hamlib levels to 8-bit steps.
        if (abs($actual - $target) <= 0.015) return $actual;
    }
    throw new RuntimeException("The radio did not confirm the requested {$level} receiver level.");
}

function elmerSetRfGainForSweep(int $port, int $requestedPercent,
                                ?float &$lastActual = null): array {
    $requestedPercent = elmerLevelPercent($requestedPercent);
    elmerAssertReceive($port);
    elmerRigCommand($port, 'L', ['RF', number_format($requestedPercent / 100.0, 6, '.', '')]);
    usleep(1100000);
    elmerAssertReceive($port);
    $actual = elmerRigLevel($port, 'RF');
    $lastActual = $actual;
    return [
        'requested_percent' => $requestedPercent,
        'actual_percent' => (int)round($actual * 100),
        'actual_value' => $actual,
    ];
}

function elmerRigStrength(int $port): int {
    foreach (array_reverse(elmerRigCommand($port, 'l', ['STRENGTH'])) as $line) {
        $value = trim($line);
        if (preg_match('/^-?\d+$/', $value)) {
            $strength = (int)$value;
            if ($strength >= -80 && $strength <= 100) return $strength;
        }
    }
    throw new RuntimeException('RigPi could not read the S-meter through Hamlib.');
}

function elmerMedian(array $values): float {
    if (!$values) throw new InvalidArgumentException('No receiver samples were provided.');
    sort($values, SORT_NUMERIC);
    $count = count($values);
    $middle = intdiv($count, 2);
    return $count % 2 ? (float)$values[$middle]
        : ((float)$values[$middle - 1] + (float)$values[$middle]) / 2.0;
}

function elmerStrengthSamples(int $port, int $count): array {
    $count = max(1, min(7, $count));
    $samples = [];
    for ($index = 0; $index < $count; $index++) {
        elmerAssertReceive($port);
        $samples[] = elmerRigStrength($port);
        if ($index + 1 < $count) usleep(120000);
    }
    return $samples;
}

function elmerAutoRfNoiseFloor(int $port, ?float &$lastLevel = null): array {
    // Hamlib's ideal scale uses S0=-54 dB and 6 dB per S-unit. This radio's
    // meter rises as RF gain is increased, but needs about one second to
    // reflect each level change. Start at minimum gain and find the first
    // stable half-S-unit rise above that baseline.
    elmerApplyReceiverLevel($port, 'RF', 0, $lastLevel);
    usleep(250000);
    $baselineSamples = elmerStrengthSamples($port, 5);
    $baselineSpread = max($baselineSamples) - min($baselineSamples);
    if ($baselineSpread > 3) {
        throw new ElmerCalibrationException('The S-meter is changing too much. Choose a quiet frequency and try again.');
    }
    $baseline = elmerMedian($baselineSamples);
    $threshold = $baseline + 3.0;
    $previousGain = 0;
    $bracketGain = null;
    for ($gain = 10; $gain <= 100; $gain += 10) {
        $gainResult = elmerSetRfGainForSweep($port, $gain, $lastLevel);
        $actualGain = (int)$gainResult['actual_percent'];
        usleep(250000);
        $strength = elmerRigStrength($port);
        if ($strength >= $threshold) {
            $bracketGain = $actualGain;
            break;
        }
        if ($actualGain <= $previousGain) {
            throw new ElmerCalibrationException('The radio reached its RF-gain ceiling before the S-meter moved enough to identify the noise floor. The original RF gain was restored.');
        }
        $previousGain = $actualGain;
    }
    if ($bracketGain === null) {
        throw new ElmerCalibrationException('This radio reported the same S-meter value across the RF-gain range, so Elmer could not locate the noise floor. The original RF gain was restored.');
    }
    $selectedGain = $bracketGain;
    for ($gain = $previousGain + 2; $gain < $bracketGain; $gain += 2) {
        $gainResult = elmerSetRfGainForSweep($port, $gain, $lastLevel);
        $actualGain = (int)$gainResult['actual_percent'];
        usleep(250000);
        if (elmerRigStrength($port) >= $threshold) {
            $selectedGain = $actualGain;
            break;
        }
    }
    elmerApplyReceiverLevel($port, 'RF', $selectedGain, $lastLevel);
    usleep(250000);
    $finalSamples = elmerStrengthSamples($port, 5);
    $finalSpread = max($finalSamples) - min($finalSamples);
    $finalStrength = elmerMedian($finalSamples);
    if ($finalSpread > 3 || $finalStrength < $threshold) {
        throw new ElmerCalibrationException('A stable noise-floor threshold could not be confirmed. Choose a quiet frequency and try again. The original RF gain was restored.');
    }
    return [
        'level_name' => 'RF', 'level_percent' => $selectedGain,
        'level_value' => (float)$lastLevel,
        'baseline_strength_db' => $baseline,
        'verified_strength_db' => $finalStrength,
        'strength_samples_db' => $finalSamples,
    ];
}

function elmerRigPttActive(int $port): bool {
    foreach (array_reverse(elmerRigCommand($port, 't')) as $line) {
        $value = trim($line);
        if ($value === '0') return false;
        if ($value === '1') return true;
    }
    throw new RuntimeException('RigPi could not verify the radio PTT state.');
}

function elmerAssertReceive(int $port): void {
    if (elmerRigPttActive($port)) {
        throw new ElmerSafetyInterlockException(
            'The radio entered transmit. Elmer stopped without sending another control command.'
        );
    }
}

function elmerSelectedRadio(mysqli $db, int $radio): array {
    if ($radio < 1 || $radio > 4) {
        throw new InvalidArgumentException('Select Radio 1–4 in RigPi before asking Elmer to control it.');
    }
    $stmt = $db->prepare('SELECT IsAlive,PTTIn,PTTOut,RadioName
        FROM RadioInterface LEFT JOIN MySettings USING (Radio) WHERE Radio=? LIMIT 1');
    $stmt->bind_param('i', $radio);
    $stmt->execute();
    $state = $stmt->get_result()->fetch_assoc();
    if (!$state || (int)$state['IsAlive'] !== 1) throw new RuntimeException('The selected radio is not connected.');
    if ((int)$state['PTTIn'] === 1 || (int)$state['PTTOut'] === 1) {
        throw new ElmerSafetyInterlockException(
            'RigPi will not change station state while the selected radio is transmitting. Return to receive and try again.'
        );
    }
    return $state;
}

function elmerCwState(mysqli $db, int $radio, bool $forUpdate = false): array {
    $suffix = $forUpdate ? ' FOR UPDATE' : '';
    $stmt = $db->prepare('SELECT RI.CWOutCk,RI.CWBusy,RI.PTTIn,RI.PTTOut,
        K.WKSpeed FROM RadioInterface RI JOIN Keyer K USING (Radio)
        WHERE RI.Radio=? LIMIT 1' . $suffix);
    $stmt->bind_param('i', $radio);
    $stmt->execute();
    $state = $stmt->get_result()->fetch_assoc();
    if (!$state) throw new RuntimeException('RigPi could not read the selected keyer state.');
    return [
        'cw_hold' => (int)$state['CWOutCk'] === 1,
        'cw_busy' => (int)$state['CWBusy'] === 1,
        'ptt_active' => (int)$state['PTTIn'] === 1 || (int)$state['PTTOut'] === 1,
        'cw_speed_wpm' => (int)$state['WKSpeed'],
    ];
}

function elmerCwText(mixed $value): string {
    $text = strtoupper(trim(preg_replace('/\s+/', ' ', (string)$value) ?? ''));
    if ($text === '' || strlen($text) > 240) {
        throw new InvalidArgumentException('CW buffer text must contain 1–240 characters.');
    }
    // Exclude control characters and RigPi's special buffer-control characters
    // (!, line feed, < and >). Elmer may stage ordinary copy only.
    if (!preg_match("/^[A-Z0-9 .,?\/'=+():@-]+$/", $text)) {
        throw new InvalidArgumentException('CW buffer text contains a character Elmer is not permitted to stage.');
    }
    return $text;
}

function elmerAssertCwIdle(array $state): void {
    if (!empty($state['ptt_active']) || !empty($state['cw_busy'])) {
        throw new ElmerSafetyInterlockException(
            'RigPi will not change keyer preparation while the radio is transmitting or the keyer is busy.'
        );
    }
}

function elmerResolveMacro(mysqli $db, int $radio, array $request, array $current): array {
    $context = ($request['macro_context'] ?? '') === 'keyer' ? 'keyer' : 'tuner';
    $bankField = $context === 'keyer' ? 'MacroBankKeyer' : 'MacroBankTuner';
    $requestedBank = $request['macro_bank'] ?? null;
    $selector = trim((string)($request['macro_selector'] ?? ''));
    // Accept both natural forms, "from bank 2" and "from macro bank 2",
    // even when a voice/planning client leaves the suffix in the selector.
    if (($requestedBank === null || $requestedBank === '') &&
        preg_match('/^(.+?)\s+(?:from|in)\s+(?:macro\s+)?bank\s+([1-4])$/i', $selector, $bankMatch)) {
        $selector = trim($bankMatch[1]);
        $requestedBank = (int)$bankMatch[2];
    }
    if ($requestedBank !== null && $requestedBank !== '') {
        $bank = elmerIntegerParameter($requestedBank, 'macro bank', 1, 4);
    } else {
        $result = $db->query("SELECT {$bankField} AS active_bank FROM RadioInterface WHERE Radio=" . (int)$radio . ' LIMIT 1');
        $row = $result ? $result->fetch_assoc() : null;
        $bank = elmerIntegerParameter($row['active_bank'] ?? null, 'active macro bank', 1, 4);
    }
    if ($selector === '' || strlen($selector) > 100) {
        throw new InvalidArgumentException('Specify an exact macro label or a macro slot from 1 through 32.');
    }
    $field = 'Macros' . $bank;
    $stmt = $db->prepare("SELECT RI.{$field} AS macros,MS.DX
        FROM RadioInterface RI JOIN MySettings MS USING (Radio)
        WHERE RI.Radio=? LIMIT 1");
    $stmt->bind_param('i', $radio);
    $stmt->execute();
    $settings = $stmt->get_result()->fetch_assoc();
    if (!$settings) throw new RuntimeException('RigPi could not read the selected macro bank.');
    $entries = [];
    foreach (explode('~', rawurldecode((string)$settings['macros'])) as $index => $encodedEntry) {
        $parts = explode('|', $encodedEntry, 2);
        $entries[] = [
            'slot' => $index + 1,
            'label' => trim((string)($parts[0] ?? '')),
            'command' => trim((string)($parts[1] ?? '')),
        ];
    }
    $selected = null;
    if (preg_match('/^(?:#|button\s*)?(\d{1,2})$/i', $selector, $match)) {
        $slot = elmerIntegerParameter($match[1], 'macro slot', 1, 32);
        $selected = $entries[$slot - 1] ?? null;
    } else {
        foreach ($entries as $entry) {
            if (strcasecmp($entry['label'], $selector) === 0) {
                $selected = $entry;
                break;
            }
        }
    }
    if (!$selected || $selected['label'] === '' || $selected['command'] === '') {
        throw new InvalidArgumentException("No enabled macro exactly matching '{$selector}' was found in bank {$bank}.");
    }
    $source = $selected['command'];
    $expanded = str_replace(
        ["'*", "'X"],
        [(string)($_SESSION['myCall'] ?? ''), (string)($settings['DX'] ?? '')],
        $source
    );
    if (preg_match('/^F\d+:(.+)$/s', $expanded, $alias)) $expanded = trim($alias[1]);
    if (str_starts_with($expanded, '$')) {
        $cw = substr($expanded, 1);
        if (str_contains($cw, '<') || str_contains($cw, '>')) {
            throw new InvalidArgumentException('That CW macro contains keyer control tokens and is not approved for Elmer.');
        }
        return [
            'macro_kind' => 'cw_stage', 'macro_bank' => $bank,
            'macro_slot' => (int)$selected['slot'], 'macro_label' => $selected['label'],
            'macro_command' => $source, 'macro_expansion' => $expanded,
            'cw_text' => elmerCwText($cw),
        ];
    }
    if (str_contains($expanded, '{') || str_contains($expanded, '}') ||
        preg_match('/(?:!PTT(?:ON|OFF)|!T\/R|!TUNE|\*T(?:\s|$)|\\set_ptt|\bPTT\b)/i', $expanded)) {
        throw new InvalidArgumentException('That macro is transmit-capable, latched, or otherwise outside Elmer’s approved macro set.');
    }
    if (!preg_match_all('/\*([FM])\s+([^*]+)/i', $expanded, $matches, PREG_SET_ORDER) ||
        trim(implode('', array_column($matches, 0))) !== trim($expanded)) {
        throw new InvalidArgumentException('That macro command family is not yet approved for Elmer.');
    }
    $target = [
        'frequency_hz' => (int)$current['frequency_hz'],
        'mode' => (string)$current['mode'],
        'bandwidth_hz' => $current['bandwidth_hz'],
    ];
    foreach ($matches as $part) {
        $code = strtoupper($part[1]);
        $arguments = trim($part[2]);
        if ($code === 'F') {
            if (!preg_match('/^\d{3,11}$/', $arguments)) {
                throw new InvalidArgumentException('The macro frequency is invalid.');
            }
            $target['frequency_hz'] = elmerIntegerParameter($arguments, 'macro frequency', 1000, 6000000000);
        } else {
            if (!preg_match('/^([A-Z][A-Z0-9-]{0,11})(?:\s+(-?\d+))?$/i', $arguments, $modeMatch)) {
                throw new InvalidArgumentException('The macro mode or bandwidth is invalid.');
            }
            $target['mode'] = elmerModeParameter($modeMatch[1]);
            if (isset($modeMatch[2]) && (int)$modeMatch[2] > 0) {
                $target['bandwidth_hz'] = elmerIntegerParameter($modeMatch[2], 'macro bandwidth', 50, 1000000);
            }
        }
    }
    return [
        'macro_kind' => 'receiver', 'macro_bank' => $bank,
        'macro_slot' => (int)$selected['slot'], 'macro_label' => $selected['label'],
        'macro_command' => $source, 'macro_expansion' => $expanded,
    ] + $target;
}

function elmerResolveMacroRequest(mysqli $db, int $radio, array $request, array $current): array {
    $requested = $request['macro_sequence'] ?? null;
    if (!is_array($requested) || !$requested) {
        return elmerResolveMacro($db, $radio, $request, $current);
    }
    if (count($requested) > 4) {
        throw new InvalidArgumentException('Elmer permits at most four macros in one compound request.');
    }
    $resolved = [];
    $planned = [
        'frequency_hz' => (int)$current['frequency_hz'],
        'mode' => (string)$current['mode'],
        'bandwidth_hz' => $current['bandwidth_hz'],
    ];
    $cw = null;
    foreach (array_values($requested) as $index => $item) {
        if (!is_array($item)) throw new InvalidArgumentException('The compound macro request is invalid.');
        $selector = trim((string)($item['selector'] ?? ''));
        $bank = $item['bank'] ?? null;
        $partRequest = [
            'macro_selector' => $selector,
            'macro_bank' => ((int)$bank >= 1 && (int)$bank <= 4) ? (int)$bank : null,
            'macro_context' => $request['macro_context'] ?? 'tuner',
        ];
        $part = elmerResolveMacro($db, $radio, $partRequest, $planned);
        if (($part['macro_kind'] ?? '') === 'cw_stage') {
            if ($cw !== null) {
                throw new InvalidArgumentException('A compound request may contain only one CW macro.');
            }
            if ($index !== count($requested) - 1) {
                throw new InvalidArgumentException('The CW macro must be the final step in a compound request.');
            }
            $cw = $part;
        } else {
            if ($cw !== null) {
                throw new InvalidArgumentException('Receiver macros cannot follow a CW macro.');
            }
            $planned['frequency_hz'] = (int)$part['frequency_hz'];
            $planned['mode'] = (string)$part['mode'];
            $planned['bandwidth_hz'] = $part['bandwidth_hz'];
        }
        $resolved[] = $part;
    }
    if (count($resolved) === 1) return $resolved[0];
    $receiverCount = count(array_filter($resolved,
        fn(array $part): bool => ($part['macro_kind'] ?? '') === 'receiver'));
    $kind = $cw !== null
        ? ($receiverCount > 0 ? 'receiver_then_cw' : 'cw_stage')
        : 'receiver';
    $combined = [
        'macro_kind' => $kind,
        'macro_count' => count($resolved),
        'macro_sequence' => $resolved,
        'macro_bank' => 0,
        'macro_slot' => 0,
        'macro_label' => implode(' → ', array_column($resolved, 'macro_label')),
        'macro_command' => implode(' ; ', array_column($resolved, 'macro_command')),
        'macro_expansion' => implode(' → ', array_column($resolved, 'macro_expansion')),
        'frequency_hz' => $planned['frequency_hz'],
        'mode' => $planned['mode'],
        'bandwidth_hz' => $planned['bandwidth_hz'],
    ];
    if ($cw !== null) $combined['cw_text'] = $cw['cw_text'];
    return $combined;
}

function elmerIntegerParameter(mixed $value, string $label, int $minimum, int $maximum): int {
    if (is_bool($value) || !is_numeric($value) || (float)(int)$value !== (float)$value) {
        throw new InvalidArgumentException("The requested {$label} is invalid.");
    }
    $number = (int)$value;
    if ($number < $minimum || $number > $maximum) {
        throw new InvalidArgumentException("The requested {$label} is outside the supported safety range.");
    }
    return $number;
}

function elmerModeParameter(mixed $value): string {
    $mode = strtoupper(trim((string)$value));
    $allowed = ['AM','AMS','CW','CWR','DSB','ECSSLSB','ECSSUSB','FAX','FM','LSB',
                'PKTFM','PKTLSB','PKTUSB','RTTY','RTTYR','SAH','SAL','SAM','USB','WFM'];
    if (!in_array($mode, $allowed, true)) {
        throw new InvalidArgumentException('The requested receive mode is not allowlisted.');
    }
    return $mode;
}

function elmerRequestedAction(mysqli $db, string $username, int $radio, string $name,
                              array $request, array $current): array {
    $action = elmerActionDefinition($name);
    if ($name === 'macro.run') {
        $macro = elmerResolveMacroRequest($db, $radio, $request, $current);
        return elmerActionWithParameters($action, $macro,
            'Run macro ' . $macro['macro_label']);
    }
    if ($name === 'keyer.speed') {
        $speed = elmerIntegerParameter($request['cw_speed_wpm'] ?? null, 'CW speed', 5, 60);
        return elmerActionWithParameters($action, ['cw_speed_wpm' => $speed],
            'Set CW speed to ' . $speed . ' WPM');
    }
    if ($name === 'keyer.stage_text') {
        $text = elmerCwText($request['cw_text'] ?? null);
        return elmerActionWithParameters($action, ['cw_text' => $text],
            'Stage CW text on Hold');
    }
    if ($name === 'receiver.auto_rf_noise') {
        return elmerActionWithParameters($action,
            ['level_name' => 'RF', 'auto_noise_floor' => true],
            'Set RF gain to the noise floor');
    }
    if ($name === 'receiver.level') {
        $level = elmerLevelName($request['level_name'] ?? null);
        $percent = elmerLevelPercent($request['level_percent'] ?? null);
        $labels = ['AF' => 'volume', 'RF' => 'RF gain', 'SQL' => 'squelch'];
        return elmerActionWithParameters($action,
            ['level_name' => $level, 'level_percent' => $percent],
            'Set receiver ' . $labels[$level] . ' to ' . $percent . '%');
    }
    if ($name === 'tune_wwv_10mhz') {
        return elmerActionWithParameters($action,
            ['frequency_hz' => 10000000, 'mode' => 'AM', 'bandwidth_hz' => null]);
    }
    if ($name === 'receiver.restore') {
        $target = elmerLatestRestorableState($db, $username, $radio);
        $target['frequency_hz'] = elmerIntegerParameter($target['frequency_hz'], 'frequency', 1000, 6000000000);
        $target['mode'] = elmerModeParameter($target['mode']);
        $target['bandwidth_hz'] = isset($target['bandwidth_hz']) && (int)$target['bandwidth_hz'] > 0
            ? elmerIntegerParameter($target['bandwidth_hz'], 'bandwidth', 50, 1000000) : null;
        return elmerActionWithParameters($action, $target, 'Restore previous receiver setting');
    }
    if ($name !== 'receiver.tune') throw new InvalidArgumentException('That receive action is not supported.');
    $hasFrequency = array_key_exists('frequency_hz', $request)
        && $request['frequency_hz'] !== null && $request['frequency_hz'] !== '';
    $frequency = $hasFrequency
        ? elmerIntegerParameter($request['frequency_hz'], 'frequency', 1000, 6000000000)
        : (int)$current['frequency_hz'];
    $hasMode = array_key_exists('mode', $request) && trim((string)$request['mode']) !== '';
    $mode = $hasMode ? elmerModeParameter($request['mode']) : (string)$current['mode'];
    $bandwidth = null;
    if (array_key_exists('bandwidth_hz', $request) && $request['bandwidth_hz'] !== null
        && $request['bandwidth_hz'] !== '') {
        $bandwidth = elmerIntegerParameter($request['bandwidth_hz'], 'bandwidth', 50, 1000000);
    }
    if ($hasFrequency) {
        $label = 'Tune to ' . number_format($frequency / 1000000, 6) . ' MHz ' . $mode;
        if ($bandwidth !== null) $label .= ', ' . number_format($bandwidth) . ' Hz bandwidth';
    } elseif ($hasMode) {
        $label = 'Set receiver mode to ' . $mode;
        if ($bandwidth !== null) $label .= ', ' . number_format($bandwidth) . ' Hz bandwidth';
    } elseif ($bandwidth !== null) {
        $label = 'Set receiver bandwidth to ' . number_format($bandwidth) . ' Hz';
    } else {
        throw new InvalidArgumentException('No receiver setting was requested.');
    }
    return elmerActionWithParameters($action,
        ['frequency_hz' => $frequency, 'mode' => $mode, 'bandwidth_hz' => $bandwidth], $label);
}

function elmerStateMatches(array $actual, array $expected): bool {
    // Some radios quantize a requested dial frequency by a few hertz. Keep the
    // tolerance narrow enough to catch a real state change while accepting it.
    if (abs((int)$actual['frequency_hz'] - (int)$expected['frequency_hz']) > 25) return false;
    if (strtoupper((string)$actual['mode']) !== strtoupper((string)$expected['mode'])) return false;
    if (isset($expected['bandwidth_hz']) && (int)$expected['bandwidth_hz'] > 0) {
        $actualWidth = (int)($actual['bandwidth_hz'] ?? 0);
        $tolerance = max(10, (int)round((int)$expected['bandwidth_hz'] * 0.05));
        if ($actualWidth <= 0 || abs($actualWidth - (int)$expected['bandwidth_hz']) > $tolerance) return false;
    }
    return true;
}

function elmerApplyReceiverState(int $port, array $target, ?array &$lastActual = null): array {
    $width = isset($target['bandwidth_hz']) && (int)$target['bandwidth_hz'] > 0
        ? (int)$target['bandwidth_hz'] : -1;
    $before = elmerReceiverState($port);
    elmerAssertReceive($port);
    if (strtoupper((string)$before['mode']) !== strtoupper((string)$target['mode']) || $width > 0) {
        elmerRigCommand($port, 'M', [(string)$target['mode'], (string)$width]);
        // Several radios acknowledge a mode change before the backend has
        // finished applying it. A frequency command sent in that interval can
        // be lost, leaving the old dial frequency in the new mode.
        usleep(400000);
        elmerAssertReceive($port);
    }
    $actual = [];
    // rigctld can acknowledge a frequency write before a busy radio/backend
    // accepts it. Retry the same receive-only F command, with PTT checks before
    // every write and during verification.
    for ($setAttempt = 0; $setAttempt < 3; $setAttempt++) {
        elmerAssertReceive($port);
        elmerRigCommand($port, 'F', [(string)$target['frequency_hz']]);
        for ($verifyAttempt = 0; $verifyAttempt < 4; $verifyAttempt++) {
            usleep(250000);
            elmerAssertReceive($port);
            $actual = elmerReceiverState($port);
            $lastActual = $actual;
            if (elmerStateMatches($actual, $target)) return $actual;
        }
    }
    throw new RuntimeException('The radio did not confirm the requested receiver setting.');
}

function elmerConfirmationText(string $radioName, array $current, array $target, string $actionName): string {
    if ($actionName === 'macro.run') {
        $identity = 'Bank ' . (int)$target['macro_bank'] . ', slot ' . (int)$target['macro_slot'] .
            ': ' . (string)$target['macro_label'];
        if ((int)($target['macro_count'] ?? 1) > 1) {
            $identity = (int)$target['macro_count'] . ' macros: ' . (string)$target['macro_label'];
        }
        $preview = (string)$target['macro_expansion'];
        if (($target['macro_kind'] ?? '') === 'cw_stage') {
            return "Stage approved CW macro {$identity} on {$radioName}?\n\nExpansion: {$preview}\n\n" .
                'Elmer will force Hold on and stage the text. It cannot transmit or release Hold.';
        }
        $from = number_format((int)$current['frequency_hz'] / 1000000, 6) . ' MHz ' . $current['mode'];
        $to = number_format((int)$target['frequency_hz'] / 1000000, 6) . ' MHz ' . $target['mode'];
        if (!empty($target['bandwidth_hz'])) $to .= ', ' . number_format((int)$target['bandwidth_hz']) . ' Hz';
        if (($target['macro_kind'] ?? '') === 'receiver_then_cw') {
            return "Run approved compound macros {$identity} on {$radioName}?\n\nSequence: {$preview}\n{$from} → {$to}\n" .
                'Then stage CW with Hold ON: ' . (string)$target['cw_text'] . "\n\n" .
                'Elmer will verify the receiver first, then replace the held CW buffer. It cannot transmit or release Hold.';
        }
        return "Run approved receiver macro {$identity} on {$radioName}?\n\nExpansion: {$preview}\n{$from} → {$to}\n\n" .
            'Elmer will execute only the parsed frequency/mode/bandwidth settings and verify them. It cannot transmit.';
    }
    if ($actionName === 'keyer.speed') {
        return 'Set ' . $radioName . ' CW speed from ' . (int)$current['cw_speed_wpm'] .
            ' to ' . (int)$target['cw_speed_wpm'] . " WPM?\n\n" .
            'This changes keyer speed only. It cannot transmit.';
    }
    if ($actionName === 'keyer.stage_text') {
        return "Put the following text in {$radioName}'s CW buffer with Hold forced ON?\n\n" .
            (string)$target['cw_text'] . "\n\n" .
            'Elmer cannot release Hold or transmit. Review the text in the RigPi Keyer and release Hold manually when ready.';
    }
    if ($actionName === 'receiver.auto_rf_noise') {
        $from = (int)round((float)$current['level_value'] * 100);
        return "Automatically adjust {$radioName} RF gain from {$from}% until background noise just moves the S-meter?\n\n" .
            'Use a quiet frequency. Elmer will abort and restore the original RF gain if readings are unstable or any check fails. This cannot transmit.';
    }
    if ($actionName === 'receiver.level') {
        $level = (string)$target['level_name'];
        $labels = ['AF' => 'volume', 'RF' => 'RF gain', 'SQL' => 'squelch'];
        $from = (int)round((float)$current['level_value'] * 100);
        $to = (int)$target['level_percent'];
        return 'Change ' . $radioName . ' ' . $labels[$level] . " from {$from}% to {$to}%?\n\n" .
            'This changes a receive level only. It cannot transmit.';
    }
    $verb = $actionName === 'receiver.restore' ? 'Restore' : 'Tune';
    $from = number_format((int)$current['frequency_hz'] / 1000000, 6) . ' MHz ' . $current['mode'];
    $to = number_format((int)$target['frequency_hz'] / 1000000, 6) . ' MHz ' . $target['mode'];
    if (!empty($target['bandwidth_hz'])) $to .= ', ' . number_format((int)$target['bandwidth_hz']) . ' Hz bandwidth';
    return "{$verb} {$radioName} from {$from} to {$to}?\n\n" .
        'This changes receive frequency, mode, and optionally bandwidth only. It cannot transmit.';
}

try {
    $request = json_decode(file_get_contents('php://input'), true, 8, JSON_THROW_ON_ERROR);
    $phase = (string)($request['phase'] ?? '');
    $username = (string)$_SESSION['myUsername'];
    $sessionRadio = (int)($_SESSION['myRadio'] ?? 0);
    $db = new mysqli('localhost', $sql_radio_username, $sql_radio_password, $sql_radio_database);
    if ($db->connect_errno) throw new RuntimeException('The RigPi database is unavailable.');
    $db->set_charset('utf8mb4');

    $stmt = $db->prepare('SELECT Access_Level FROM Users WHERE Username=? LIMIT 1');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    if (!$user) throw new RuntimeException('The signed-in RigPi account was not found.');
    $accessLevel = (int)$user['Access_Level'];
    elmerEnsureActionHistory($db);
    elmerExpireActions($db);

    if ($phase === 'history') {
        $historyUser = $accessLevel === 1 ? null : $username;
        echo json_encode(['permission_classes' => elmerActionClasses(),
                          'actions' => elmerActionHistory($db, (int)($request['limit'] ?? 25), $historyUser)]);
        exit;
    }

    if ($phase === 'cw_status') {
        if ($accessLevel !== 1) throw new UnexpectedValueException('Your RigPi account is not permitted to read Elmer keyer state.');
        $radio = elmerIntegerParameter($sessionRadio, 'radio number', 1, 4);
        $cw = elmerCwState($db, $radio);
        $text = '';
        if (!empty($cw['cw_hold'])) {
            $stmt = $db->prepare("SELECT result_json FROM ElmerActionHistory
                WHERE radio=? AND action_name IN ('keyer.stage_text','macro.run') AND status='complete'
                ORDER BY id DESC LIMIT 20");
            $stmt->bind_param('i', $radio);
            $stmt->execute();
            $rows = $stmt->get_result();
            while ($row = $rows->fetch_assoc()) {
                $saved = json_decode((string)$row['result_json'], true);
                if (is_array($saved) && isset($saved['cw_text'])) {
                    $text = (string)$saved['cw_text'];
                    break;
                }
            }
        }
        echo json_encode($cw + ['radio' => $radio, 'cw_text' => $text]);
        exit;
    }

    if ($phase === 'cancel') {
        $pending = $_SESSION['elmer_rig_confirmation'] ?? null;
        unset($_SESSION['elmer_rig_confirmation']);
        if (!is_array($pending) || !hash_equals((string)$pending['id'], (string)($request['confirmation_id'] ?? ''))) {
            throw new InvalidArgumentException('The action confirmation is invalid or expired.');
        }
        elmerFinishActionHistory($db, $pending['action_id'], $username, 'cancelled', [],
                                 'The administrator cancelled the proposed action.');
        $cancelled = [
            'status' => 'cancelled', 'action' => $pending['action'], 'radio' => (int)$pending['radio'],
            'message' => 'The administrator cancelled the requested radio change.',
            'performed_at' => gmdate('c')
        ];
        $cancelledMacro = $pending['action'] === 'macro.run';
        $cancelledMacroCw = $cancelledMacro && in_array(
            ($pending['target']['macro_kind'] ?? ''), ['cw_stage','receiver_then_cw'], true);
        if ($cancelledMacro) {
            foreach (['macro_kind','macro_count','macro_sequence','macro_bank','macro_slot','macro_label','macro_command','macro_expansion'] as $field) {
                $cancelled[$field] = $pending['target'][$field] ?? null;
            }
        }
        if ($pending['action'] === 'keyer.speed') {
            $cancelled['requested_cw_speed_wpm'] = (int)$pending['target']['cw_speed_wpm'];
        } elseif ($pending['action'] === 'keyer.stage_text' || $cancelledMacroCw) {
            $cancelled['cw_text'] = (string)$pending['target']['cw_text'];
        } elseif (in_array($pending['action'], ['receiver.level', 'receiver.auto_rf_noise'], true)) {
            $cancelled['level_name'] = (string)$pending['target']['level_name'];
            $cancelled['requested_level_percent'] = isset($pending['target']['level_percent'])
                ? (int)$pending['target']['level_percent'] : null;
        } else {
            $cancelled['requested_frequency_hz'] = (int)$pending['target']['frequency_hz'];
            $cancelled['requested_mode'] = (string)$pending['target']['mode'];
            $cancelled['requested_bandwidth_hz'] = $pending['target']['bandwidth_hz'];
        }
        echo json_encode($cancelled);
        exit;
    }

    if ($phase === 'prepare') {
        $radio = array_key_exists('radio', $request) && $request['radio'] !== null
            ? elmerIntegerParameter($request['radio'], 'radio number', 1, 4) : $sessionRadio;
        $state = elmerSelectedRadio($db, $radio);
        $port = 4530 + 2 * $radio;
        $current = elmerReceiverState($port);
        $action = elmerRequestedAction($db, $username, $radio, (string)($request['action'] ?? ''), $request, $current);
        elmerAuthorizeAction($action, $accessLevel);
        $macroCw = $action['name'] === 'macro.run'
            && in_array(($action['parameters']['macro_kind'] ?? ''), ['cw_stage','receiver_then_cw'], true);
        if (in_array($action['name'], ['keyer.speed', 'keyer.stage_text'], true) || $macroCw) {
            $current += elmerCwState($db, $radio);
            elmerAssertCwIdle($current);
        } elseif (in_array($action['name'], ['receiver.level', 'receiver.auto_rf_noise'], true)) {
            $current['level_name'] = (string)$action['parameters']['level_name'];
            $current['level_value'] = elmerRigLevel($port, $current['level_name']);
            $current['level_percent'] = (int)round($current['level_value'] * 100);
        }
        $actionId = elmerCreateActionHistory($db, $username, $radio, $action, $current);
        $confirmationId = bin2hex(random_bytes(24));
        $_SESSION['elmer_rig_confirmation'] = [
            'id' => $confirmationId, 'action_id' => $actionId, 'expires' => time() + 120,
            'radio' => $radio, 'action' => $action['name'], 'target' => $action['parameters'],
            'previous' => $current,
        ];
        $radioName = trim((string)($state['RadioName'] ?? '')) ?: 'Radio ' . $radio;
        $prepared = [
            'action_id' => $actionId, 'confirmation_id' => $confirmationId, 'radio' => $radio,
            'action_class' => $action['class'], 'action_label' => $action['label'],
            'current_frequency_hz' => $current['frequency_hz'], 'current_mode' => $current['mode'],
            'current_bandwidth_hz' => $current['bandwidth_hz'],
            'confirmation' => elmerConfirmationText($radioName, $current, $action['parameters'], $action['name'])
        ];
        if ($action['name'] === 'macro.run') {
            foreach (['macro_kind','macro_count','macro_sequence','macro_bank','macro_slot','macro_label','macro_command','macro_expansion'] as $field) {
                $prepared[$field] = $action['parameters'][$field] ?? null;
            }
        }
        if ($action['name'] === 'keyer.speed') {
            $prepared['current_cw_speed_wpm'] = (int)$current['cw_speed_wpm'];
            $prepared['requested_cw_speed_wpm'] = (int)$action['parameters']['cw_speed_wpm'];
        } elseif ($action['name'] === 'keyer.stage_text' || $macroCw) {
            $prepared['cw_hold'] = (bool)$current['cw_hold'];
            $prepared['cw_text'] = (string)$action['parameters']['cw_text'];
            if (($action['parameters']['macro_kind'] ?? '') === 'receiver_then_cw') {
                $prepared['requested_frequency_hz'] = $action['parameters']['frequency_hz'];
                $prepared['requested_mode'] = $action['parameters']['mode'];
                $prepared['requested_bandwidth_hz'] = $action['parameters']['bandwidth_hz'];
            }
        } elseif (in_array($action['name'], ['receiver.level', 'receiver.auto_rf_noise'], true)) {
            $prepared['level_name'] = (string)$action['parameters']['level_name'];
            $prepared['current_level_percent'] = (int)$current['level_percent'];
            $prepared['requested_level_percent'] = isset($action['parameters']['level_percent'])
                ? (int)$action['parameters']['level_percent'] : null;
        } else {
            $prepared['requested_frequency_hz'] = $action['parameters']['frequency_hz'];
            $prepared['requested_mode'] = $action['parameters']['mode'];
            $prepared['requested_bandwidth_hz'] = $action['parameters']['bandwidth_hz'];
        }
        echo json_encode($prepared);
        exit;
    }

    if ($phase !== 'execute') throw new InvalidArgumentException('The RigPi action phase is invalid.');
    $confirmationId = (string)($request['confirmation_id'] ?? '');
    $recent = $_SESSION['elmer_rig_results'][$confirmationId] ?? null;
    if (is_array($recent)) {
        echo json_encode($recent);
        exit;
    }
    $pending = $_SESSION['elmer_rig_confirmation'] ?? null;
    unset($_SESSION['elmer_rig_confirmation']);
    if (!is_array($pending) || !hash_equals((string)$pending['id'], $confirmationId) ||
        (int)$pending['expires'] < time()) {
        throw new InvalidArgumentException('The action confirmation is invalid or expired. Please ask Elmer again.');
    }
    $radio = (int)$pending['radio'];
    $action = elmerActionDefinition((string)$pending['action']);
    elmerAuthorizeAction($action, $accessLevel);
    elmerSelectedRadio($db, $radio);
    $port = 4530 + 2 * $radio;
    $beforeExecute = elmerReceiverState($port);
    if (!elmerStateMatches($beforeExecute, $pending['previous'])) {
        elmerFinishActionHistory($db, $pending['action_id'], $username, 'failed', $beforeExecute,
                                 'Receiver state changed after confirmation; no command was sent.');
        throw new RuntimeException('The receiver changed after confirmation. Please ask Elmer again.');
    }

    $macroKind = $action['name'] === 'macro.run' ? ($pending['target']['macro_kind'] ?? '') : '';
    $macroCw = $macroKind === 'cw_stage';
    $macroMixed = $macroKind === 'receiver_then_cw';
    if ($macroMixed) {
        $beforeCw = elmerCwState($db, $radio);
        elmerAssertCwIdle($beforeCw);
        if ((bool)$beforeCw['cw_hold'] !== (bool)$pending['previous']['cw_hold'] ||
            (int)$beforeCw['cw_speed_wpm'] !== (int)$pending['previous']['cw_speed_wpm']) {
            elmerFinishActionHistory($db, $pending['action_id'], $username, 'failed', $beforeCw,
                'Keyer state changed after confirmation; no change was made.');
            throw new RuntimeException('The keyer changed after confirmation. Please ask Elmer again.');
        }
        $verified = [];
        try {
            $verified = elmerApplyReceiverState($port, $pending['target'], $verified);
            $beforeStage = elmerCwState($db, $radio);
            elmerAssertCwIdle($beforeStage);
            if ((bool)$beforeStage['cw_hold'] !== (bool)$beforeCw['cw_hold'] ||
                (int)$beforeStage['cw_speed_wpm'] !== (int)$beforeCw['cw_speed_wpm']) {
                throw new RuntimeException('The keyer changed before CW could be staged safely.');
            }
            $db->begin_transaction();
            try {
                $locked = elmerCwState($db, $radio, true);
                elmerAssertCwIdle($locked);
                if ((bool)$locked['cw_hold'] !== (bool)$beforeStage['cw_hold'] ||
                    (int)$locked['cw_speed_wpm'] !== (int)$beforeStage['cw_speed_wpm']) {
                    throw new RuntimeException('The keyer changed before the safe update could begin.');
                }
                $text = (string)$pending['target']['cw_text'];
                $replacement = chr(10) . $text;
                $stmt = $db->prepare('UPDATE RadioInterface SET CWOutCk=1,CWIn=? WHERE Radio=?');
                $stmt->bind_param('si', $replacement, $radio);
                if (!$stmt->execute() || $stmt->affected_rows !== 1) {
                    throw new RuntimeException('RigPi could not safely stage the compound CW text.');
                }
                $db->commit();
            } catch (Throwable $stageError) {
                $db->rollback();
                throw $stageError;
            }
            usleep(250000);
            elmerAssertReceive($port);
            $verifiedCw = elmerCwState($db, $radio);
            elmerAssertCwIdle($verifiedCw);
            if (empty($verifiedCw['cw_hold'])) {
                throw new ElmerSafetyInterlockException('RigPi did not confirm CW Hold. Elmer did not transmit.');
            }
            $result = [
                'status' => 'complete', 'action' => $action['name'], 'radio' => $radio,
                'requested_frequency_hz' => (int)$pending['target']['frequency_hz'],
                'verified_frequency_hz' => (int)$verified['frequency_hz'],
                'requested_mode' => (string)$pending['target']['mode'],
                'verified_mode' => (string)$verified['mode'],
                'requested_bandwidth_hz' => $pending['target']['bandwidth_hz'],
                'verified_bandwidth_hz' => $verified['bandwidth_hz'],
                'cw_text' => (string)$pending['target']['cw_text'], 'cw_hold' => true,
                'previous_state' => $pending['previous'],
                'message' => 'RigPi verified the approved receiver macros and staged the final CW macro with Hold on.',
                'performed_at' => gmdate('c')
            ];
            foreach (['macro_kind','macro_count','macro_sequence','macro_bank','macro_slot','macro_label','macro_command','macro_expansion'] as $field) {
                $result[$field] = $pending['target'][$field] ?? null;
            }
            elmerFinishActionHistory($db, $pending['action_id'], $username, 'complete', $result,
                'Compound macros were fully validated; receiver state was verified before final CW was staged with Hold on.');
            $_SESSION['elmer_rig_results'][$confirmationId] = $result;
            if (count($_SESSION['elmer_rig_results']) > 8) array_shift($_SESSION['elmer_rig_results']);
            echo json_encode($result);
            exit;
        } catch (Throwable $controlError) {
            $rollback = ['attempted' => true, 'complete' => false];
            try {
                $rollbackState = elmerApplyReceiverState($port, $pending['previous']);
                $rollback = ['attempted' => true, 'complete' => true, 'state' => $rollbackState];
            } catch (Throwable $rollbackError) {
                $rollback['error'] = $rollbackError->getMessage();
            }
            elmerFinishActionHistory($db, $pending['action_id'], $username, 'failed',
                ['verified_state' => $verified, 'rollback' => $rollback], $controlError->getMessage());
            throw $controlError;
        }
    }
    if (in_array($action['name'], ['keyer.speed', 'keyer.stage_text'], true) || $macroCw) {
        $beforeCw = elmerCwState($db, $radio);
        elmerAssertCwIdle($beforeCw);
        if ((bool)$beforeCw['cw_hold'] !== (bool)$pending['previous']['cw_hold'] ||
            (int)$beforeCw['cw_speed_wpm'] !== (int)$pending['previous']['cw_speed_wpm']) {
            elmerFinishActionHistory($db, $pending['action_id'], $username, 'failed', $beforeCw,
                'Keyer state changed after confirmation; no change was made.');
            throw new RuntimeException('The keyer changed after confirmation. Please ask Elmer again.');
        }
        $db->begin_transaction();
        try {
            $locked = elmerCwState($db, $radio, true);
            elmerAssertCwIdle($locked);
            if ((bool)$locked['cw_hold'] !== (bool)$beforeCw['cw_hold'] ||
                (int)$locked['cw_speed_wpm'] !== (int)$beforeCw['cw_speed_wpm']) {
                throw new RuntimeException('The keyer changed before the safe update could begin.');
            }
            if ($action['name'] === 'keyer.speed') {
                $speed = (int)$pending['target']['cw_speed_wpm'];
                $stmt = $db->prepare('UPDATE Keyer SET WKSpeed=?,WKSpeedOriginal=? WHERE Radio=?');
                $stmt->bind_param('iii', $speed, $speed, $radio);
                if (!$stmt->execute() || $stmt->affected_rows < 0) {
                    throw new RuntimeException('RigPi could not update the CW speed.');
                }
                $stmt = $db->prepare('UPDATE RadioInterface SET CWChangeCk=1 WHERE Radio=?');
                $stmt->bind_param('i', $radio);
                if (!$stmt->execute()) throw new RuntimeException('RigPi could not notify the keyer of its new speed.');
            } else {
                $text = (string)$pending['target']['cw_text'];
                // The Hold flag and new text become visible in the same commit.
                // A leading line feed tells the CW worker to replace its unsent
                // in-memory queue rather than append. It is consumed locally
                // while Hold remains on and is never sent to the radio.
                $replacement = chr(10) . $text;
                // This endpoint contains no path that clears the Hold flag.
                $stmt = $db->prepare('UPDATE RadioInterface SET CWOutCk=1,CWIn=? WHERE Radio=?');
                $stmt->bind_param('si', $replacement, $radio);
                if (!$stmt->execute() || $stmt->affected_rows !== 1) {
                    throw new RuntimeException('RigPi could not safely stage the CW text.');
                }
            }
            $db->commit();
        } catch (Throwable $changeError) {
            $db->rollback();
            throw $changeError;
        }
        usleep(250000);
        elmerAssertReceive($port);
        $verifiedCw = elmerCwState($db, $radio);
        elmerAssertCwIdle($verifiedCw);
        if ($action['name'] === 'keyer.speed' &&
            (int)$verifiedCw['cw_speed_wpm'] !== (int)$pending['target']['cw_speed_wpm']) {
            throw new RuntimeException('RigPi did not confirm the requested CW speed.');
        }
        if (($action['name'] === 'keyer.stage_text' || $macroCw) && empty($verifiedCw['cw_hold'])) {
            throw new ElmerSafetyInterlockException('RigPi did not confirm CW Hold. Elmer did not release Hold or transmit.');
        }
        $result = [
            'status' => 'complete', 'action' => $action['name'], 'radio' => $radio,
            'previous_state' => $pending['previous'], 'performed_at' => gmdate('c')
        ];
        if ($action['name'] === 'keyer.speed') {
            $result['requested_cw_speed_wpm'] = (int)$pending['target']['cw_speed_wpm'];
            $result['verified_cw_speed_wpm'] = (int)$verifiedCw['cw_speed_wpm'];
            $result['message'] = 'RigPi changed and verified the CW speed.';
            $detail = 'CW speed was changed and verified without keying the transmitter.';
        } else {
            $result['cw_text'] = (string)$pending['target']['cw_text'];
            $result['cw_hold'] = true;
            if ($macroCw) {
                foreach (['macro_kind','macro_count','macro_sequence','macro_bank','macro_slot','macro_label','macro_command','macro_expansion'] as $field) {
                    $result[$field] = $pending['target'][$field] ?? null;
                }
                $result['message'] = 'RigPi staged the approved CW macro with Hold on. Elmer did not transmit.';
                $detail = 'Approved CW macro text was staged atomically with Hold forced on; Elmer cannot release Hold.';
            } else {
                $result['message'] = 'RigPi staged the CW text with Hold on. Elmer did not transmit.';
                $detail = 'CW text was staged atomically with Hold forced on; Elmer cannot release Hold.';
            }
        }
        elmerFinishActionHistory($db, $pending['action_id'], $username, 'complete', $result, $detail);
        $_SESSION['elmer_rig_results'][$confirmationId] = $result;
        if (count($_SESSION['elmer_rig_results']) > 8) array_shift($_SESSION['elmer_rig_results']);
        echo json_encode($result);
        exit;
    }

    if (in_array($action['name'], ['receiver.level', 'receiver.auto_rf_noise'], true)) {
        $level = (string)$pending['target']['level_name'];
        $beforeLevel = elmerRigLevel($port, $level);
        if (abs($beforeLevel - (float)$pending['previous']['level_value']) > 0.015) {
            elmerFinishActionHistory($db, $pending['action_id'], $username, 'failed',
                ['level_name' => $level, 'level_value' => $beforeLevel],
                'Receiver level changed after confirmation; no command was sent.');
            throw new RuntimeException('The receiver level changed after confirmation. Please ask Elmer again.');
        }
        $verifiedLevel = null;
        try {
            $automatic = $action['name'] === 'receiver.auto_rf_noise';
            $automaticResult = null;
            if ($automatic) {
                $automaticResult = elmerAutoRfNoiseFloor($port, $verifiedLevel);
                $verifiedPercent = (int)$automaticResult['level_percent'];
            } else {
                $verifiedLevel = elmerApplyReceiverLevel(
                    $port, $level, (int)$pending['target']['level_percent'], $verifiedLevel);
                $verifiedPercent = (int)round($verifiedLevel * 100);
            }
            $result = [
                'status' => 'complete', 'action' => $action['name'], 'radio' => $radio,
                'level_name' => $level,
                'requested_level_percent' => isset($pending['target']['level_percent'])
                    ? (int)$pending['target']['level_percent'] : null,
                'verified_level_percent' => $verifiedPercent,
                'verified_level_value' => $verifiedLevel,
                'previous_state' => $pending['previous'],
                'message' => 'RigPi changed and verified the selected receiver level.',
                'performed_at' => gmdate('c')
            ];
            if ($automaticResult !== null) {
                $result['baseline_strength_db'] = $automaticResult['baseline_strength_db'];
                $result['verified_strength_db'] = $automaticResult['verified_strength_db'];
                $result['strength_samples_db'] = $automaticResult['strength_samples_db'];
            }
            elmerFinishActionHistory($db, $pending['action_id'], $username, 'complete', $result,
                $automatic ? 'RF gain was calibrated against stable S-meter readings through rigctld.'
                           : "Receiver {$level} level was verified through rigctld.");
            $_SESSION['elmer_rig_results'][$confirmationId] = $result;
            if (count($_SESSION['elmer_rig_results']) > 8) array_shift($_SESSION['elmer_rig_results']);
            echo json_encode($result);
            exit;
        } catch (Throwable $controlError) {
            $rollback = ['attempted' => true, 'complete' => false];
            try {
                $rollbackValue = elmerApplyReceiverLevel(
                    $port, $level, (int)round((float)$pending['previous']['level_value'] * 100));
                $rollback = ['attempted' => true, 'complete' => true,
                             'level_name' => $level, 'level_value' => $rollbackValue];
            } catch (Throwable $rollbackError) {
                $rollback['error'] = $rollbackError->getMessage();
            }
            elmerFinishActionHistory($db, $pending['action_id'], $username, 'failed',
                ['verified_level_value' => $verifiedLevel, 'rollback' => $rollback],
                $controlError->getMessage());
            throw $controlError;
        }
    }

    $verified = [];
    try {
        $verified = elmerApplyReceiverState($port, $pending['target'], $verified);
        $result = [
            'status' => 'complete', 'action' => $action['name'], 'radio' => $radio,
            'requested_frequency_hz' => (int)$pending['target']['frequency_hz'],
            'verified_frequency_hz' => (int)$verified['frequency_hz'],
            'requested_mode' => (string)$pending['target']['mode'],
            'verified_mode' => (string)$verified['mode'],
            'requested_bandwidth_hz' => $pending['target']['bandwidth_hz'],
            'verified_bandwidth_hz' => $verified['bandwidth_hz'],
            'previous_state' => $pending['previous'],
            'message' => 'RigPi changed and verified the selected receiver setting.',
            'performed_at' => gmdate('c')
        ];
        if ($action['name'] === 'macro.run') {
            foreach (['macro_kind','macro_count','macro_sequence','macro_bank','macro_slot','macro_label','macro_command','macro_expansion'] as $field) {
                $result[$field] = $pending['target'][$field] ?? null;
            }
            $result['message'] = 'RigPi ran and verified the approved receiver macro.';
        }
        elmerFinishActionHistory($db, $pending['action_id'], $username, 'complete', $result,
            $action['name'] === 'macro.run'
                ? 'Approved macro receiver settings were parsed, allowlisted, and verified through rigctld.'
                : 'Receiver frequency, mode, and bandwidth were verified through rigctld.');
        $_SESSION['elmer_rig_results'][$confirmationId] = $result;
        if (count($_SESSION['elmer_rig_results']) > 8) array_shift($_SESSION['elmer_rig_results']);
        echo json_encode($result);
    } catch (Throwable $controlError) {
        $rollback = ['attempted' => true, 'complete' => false];
        try {
            $rollbackState = elmerApplyReceiverState($port, $pending['previous']);
            $rollback = ['attempted' => true, 'complete' => true, 'state' => $rollbackState];
        } catch (Throwable $rollbackError) {
            $rollback['error'] = $rollbackError->getMessage();
        }
        elmerFinishActionHistory($db, $pending['action_id'], $username, 'failed',
            ['verified_state' => $verified, 'rollback' => $rollback], $controlError->getMessage());
        throw $controlError;
    }
} catch (UnexpectedValueException $error) {
    http_response_code(403);
    echo json_encode(['error' => $error->getMessage()]);
} catch (InvalidArgumentException $error) {
    http_response_code(400);
    echo json_encode(['error' => $error->getMessage()]);
} catch (ElmerSafetyInterlockException $error) {
    http_response_code(409);
    echo json_encode(['error' => $error->getMessage()]);
} catch (ElmerCalibrationException $error) {
    http_response_code(422);
    echo json_encode(['error' => $error->getMessage()]);
} catch (Throwable $error) {
    error_log('Elmer action service: ' . $error->getMessage());
    http_response_code(502);
    echo json_encode(['error' => $error->getMessage()]);
}
