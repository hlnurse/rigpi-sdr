<?php
/** Central registry, permission classes, and history for Ask Elmer actions. */

function elmerActionClasses(): array {
    return [
        'read_only' => [
            'label' => 'Read only', 'access_levels' => [1, 2, 3, 4],
            'confirmation' => 'none', 'may_transmit' => false,
        ],
        'receive_control' => [
            'label' => 'Receive control', 'access_levels' => [1],
            'confirmation' => 'each_action', 'may_transmit' => false,
        ],
        'station_change' => [
            'label' => 'Station change', 'access_levels' => [1],
            'confirmation' => 'each_action', 'may_transmit' => false,
        ],
        'transmit_capable' => [
            'label' => 'Transmit capable', 'access_levels' => [],
            'confirmation' => 'operator_only', 'may_transmit' => true,
        ],
    ];
}

function elmerActionRegistry(): array {
    return [
        'receiver.tune' => [
            'name' => 'receiver.tune', 'label' => 'Tune receiver',
            'class' => 'receive_control', 'enabled' => true,
            'parameters' => [], 'continuous' => false, 'reversible' => true,
        ],
        'receiver.level' => [
            'name' => 'receiver.level', 'label' => 'Set receiver level',
            'class' => 'receive_control', 'enabled' => true,
            'parameters' => [], 'continuous' => false, 'reversible' => false,
        ],
        'receiver.auto_rf_noise' => [
            'name' => 'receiver.auto_rf_noise', 'label' => 'Set RF gain to noise floor',
            'class' => 'receive_control', 'enabled' => true,
            'parameters' => ['level_name' => 'RF'], 'continuous' => false, 'reversible' => false,
        ],
        'receiver.restore' => [
            'name' => 'receiver.restore', 'label' => 'Restore previous receiver setting',
            'class' => 'receive_control', 'enabled' => true,
            'parameters' => [], 'continuous' => false, 'reversible' => true,
        ],
        'keyer.speed' => [
            'name' => 'keyer.speed', 'label' => 'Set CW speed',
            'class' => 'station_change', 'enabled' => true,
            'parameters' => [], 'continuous' => false, 'reversible' => false,
        ],
        'keyer.stage_text' => [
            'name' => 'keyer.stage_text', 'label' => 'Stage CW text on Hold',
            'class' => 'station_change', 'enabled' => true,
            'parameters' => [], 'continuous' => false, 'reversible' => false,
        ],
        'macro.run' => [
            'name' => 'macro.run', 'label' => 'Run approved macro',
            'class' => 'station_change', 'enabled' => true,
            'parameters' => [], 'continuous' => false, 'reversible' => false,
        ],
        'tune_wwv_10mhz' => [
            'name' => 'tune_wwv_10mhz', 'label' => 'Tune to WWV on 10 MHz',
            'class' => 'receive_control', 'enabled' => true,
            'parameters' => ['frequency_hz' => 10000000, 'mode' => 'AM'],
            'continuous' => false, 'reversible' => true,
        ],
    ];
}

function elmerActionDefinition(string $name): array {
    $registry = elmerActionRegistry();
    if (!isset($registry[$name])) throw new InvalidArgumentException('That RigPi action is not registered.');
    $action = $registry[$name];
    $classes = elmerActionClasses();
    if (empty($action['enabled']) || !isset($classes[$action['class']])) {
        throw new InvalidArgumentException('That RigPi action is not currently enabled.');
    }
    $action['permission'] = $classes[$action['class']];
    return $action;
}

function elmerActionWithParameters(array $action, array $parameters, ?string $label = null): array {
    $action['parameters'] = $parameters;
    if ($label !== null && trim($label) !== '') $action['label'] = trim($label);
    return $action;
}

function elmerAuthorizeAction(array $action, int $accessLevel): void {
    if (!in_array($accessLevel, $action['permission']['access_levels'], true)) {
        throw new UnexpectedValueException('Your RigPi account is not permitted to perform this action.');
    }
    if (!empty($action['permission']['may_transmit'])) {
        throw new UnexpectedValueException('Ask Elmer is not permitted to execute transmit-capable actions.');
    }
}

function elmerEnsureActionHistory(mysqli $db): void {
    if (!$db->query("CREATE TABLE IF NOT EXISTS ElmerActionHistory (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        action_id CHAR(32) NOT NULL UNIQUE,
        created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
        expires_at DATETIME NULL, username VARCHAR(100) NOT NULL,
        radio TINYINT UNSIGNED NOT NULL, action_name VARCHAR(64) NOT NULL,
        action_label VARCHAR(120) NOT NULL, action_class VARCHAR(32) NOT NULL,
        status VARCHAR(32) NOT NULL, requires_confirmation TINYINT(1) NOT NULL,
        request_json TEXT NOT NULL, previous_state_json TEXT NOT NULL,
        result_json TEXT NOT NULL, detail VARCHAR(240) NOT NULL,
        INDEX(created_at), INDEX(username), INDEX(radio), INDEX(status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4")) {
        throw new RuntimeException('RigPi could not initialize the Elmer action history.');
    }
}

function elmerExpireActions(mysqli $db): void {
    $db->query("UPDATE ElmerActionHistory SET status='expired',updated_at=UTC_TIMESTAMP(),
        detail='Confirmation expired without execution.'
        WHERE status='pending_confirmation' AND expires_at IS NOT NULL AND expires_at<UTC_TIMESTAMP()");
}

function elmerCreateActionHistory(mysqli $db, string $username, int $radio,
                                  array $action, array $previous): string {
    $actionId = bin2hex(random_bytes(16));
    $now = gmdate('Y-m-d H:i:s');
    $expires = gmdate('Y-m-d H:i:s', time() + 120);
    $status = 'pending_confirmation';
    $requires = $action['permission']['confirmation'] === 'each_action' ? 1 : 0;
    $actionName = (string)$action['name'];
    $actionLabel = (string)$action['label'];
    $actionClass = (string)$action['class'];
    $requestJson = json_encode($action['parameters'], JSON_UNESCAPED_SLASHES);
    $previousJson = json_encode($previous, JSON_UNESCAPED_SLASHES);
    $empty = '{}';
    $detail = 'Waiting for administrator confirmation.';
    $stmt = $db->prepare('INSERT INTO ElmerActionHistory
        (action_id,created_at,updated_at,expires_at,username,radio,action_name,
         action_label,action_class,status,requires_confirmation,request_json,
         previous_state_json,result_json,detail)
         VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->bind_param('sssssissssissss', $actionId, $now, $now, $expires, $username,
                      $radio, $actionName, $actionLabel, $actionClass,
                      $status, $requires, $requestJson, $previousJson, $empty, $detail);
    if (!$stmt->execute()) throw new RuntimeException('RigPi could not record the proposed action.');
    return $actionId;
}

function elmerFinishActionHistory(mysqli $db, string $actionId, string $username,
                                  string $status, array $result, string $detail): void {
    if (!in_array($status, ['complete', 'failed', 'cancelled'], true)) {
        throw new InvalidArgumentException('The action history status is invalid.');
    }
    $updated = gmdate('Y-m-d H:i:s');
    $resultJson = json_encode($result, JSON_UNESCAPED_SLASHES);
    $detail = substr($detail, 0, 240);
    $stmt = $db->prepare('UPDATE ElmerActionHistory SET updated_at=?,expires_at=NULL,
        status=?,result_json=?,detail=? WHERE action_id=? AND username=?');
    $stmt->bind_param('ssssss', $updated, $status, $resultJson, $detail, $actionId, $username);
    if (!$stmt->execute() || $stmt->affected_rows !== 1) {
        throw new RuntimeException('RigPi could not update the action history.');
    }
}

function elmerActionHistory(mysqli $db, int $limit = 25, ?string $username = null): array {
    $limit = max(1, min(50, $limit));
    $sql = 'SELECT action_id,created_at,updated_at,username,radio,
        action_name,action_label,action_class,status,requires_confirmation,
        request_json,previous_state_json,result_json,detail
        FROM ElmerActionHistory';
    if ($username === null) {
        $result = $db->query($sql . ' ORDER BY id DESC LIMIT ' . $limit);
    } else {
        $sql .= ' WHERE username=? ORDER BY id DESC LIMIT ' . $limit;
        $stmt = $db->prepare($sql);
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $result = $stmt->get_result();
    }
    if (!$result) throw new RuntimeException('RigPi could not read the action history.');
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        foreach (['request_json' => 'request', 'previous_state_json' => 'previous_state',
                  'result_json' => 'result'] as $source => $target) {
            $decoded = json_decode((string)$row[$source], true);
            $row[$target] = is_array($decoded) ? $decoded : [];
            unset($row[$source]);
        }
        $row['radio'] = (int)$row['radio'];
        $row['requires_confirmation'] = (bool)$row['requires_confirmation'];
        $rows[] = $row;
    }
    return $rows;
}


function elmerLatestRestorableState(mysqli $db, string $username, int $radio): array {
    $stmt = $db->prepare("SELECT previous_state_json FROM ElmerActionHistory
        WHERE username=? AND radio=? AND action_class='receive_control' AND status='complete'
          AND action_name IN ('receiver.tune','receiver.restore','tune_wwv_10mhz')
        ORDER BY id DESC LIMIT 1");
    $stmt->bind_param('si', $username, $radio);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $state = $row ? json_decode((string)$row['previous_state_json'], true) : null;
    if (!is_array($state) || !isset($state['frequency_hz']) || !isset($state['mode'])) {
        throw new InvalidArgumentException('There is no previous Elmer receiver setting to restore.');
    }
    return $state;
}
