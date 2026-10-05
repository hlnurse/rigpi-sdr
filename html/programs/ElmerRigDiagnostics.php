<?php
/** Read-only selected-radio diagnostics for Ask Elmer. */
session_start();
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");
header("X-Content-Type-Options: nosniff");

$root = "/var/www/html";
if (empty($_SESSION["myUsername"])) {
    http_response_code(401);
    echo json_encode(["error" => "Please sign in to RigPi."]);
    exit();
}

require_once $root . "/programs/sqldata.php";
require_once $root . "/programs/GetUserFieldFunc.php";
require_once $root . "/classes/MysqliDb.php";
ini_set("display_errors", "0");
ini_set("log_errors", "1");

try {
    $request = json_decode(file_get_contents("php://input"), true, 8, JSON_THROW_ON_ERROR);
    if (($request["query_mode"] ?? "") !== "rig_diagnostics") {
        throw new InvalidArgumentException("The rig diagnostic query mode is invalid.");
    }

    $username = (string) $_SESSION["myUsername"];
    $user = (int) getUserField($username, "uID");
    if ($user < 1) throw new RuntimeException("The signed-in RigPi account was not found.");

    $db = new MysqliDb("localhost", $sql_radio_username, $sql_radio_password, $sql_radio_database);
    $db->where("uID", $user);
    $u = $db->getOne("Users", "uID,SelectedRadio,rigctldPort,DeadMan,BusyBlock");
    if (!$u) throw new RuntimeException("RigPi user record was not found.");

    $radio = (int) ($u["SelectedRadio"] ?? 0);
    if ($radio < 1 || $radio > 999) throw new RuntimeException("No valid radio is selected.");

    $db->where("Radio", $radio);
    $s = $db->getOne("MySettings", "Radio,Manufacturer,Model,RadioName,Port,Baud,Bits,Parity,Stop,CIV_Code,DTR,RTS,PTTCAT,PTTMode,PTTDelay,DisableSplitPolling,PowerControl,Keyer,KeyerPort,KeyerInvert,Rotor,RotorID,RotorModel,RotorPort,RotorBaud,RotorStop");
    $db->where("Radio", $radio);
    $i = $db->getOne("RadioInterface", "Radio,IsAlive,Transmit,MainIn,ModeIn,SplitIn,BWIn,PTTIn,Close_Watch,waitReset,Comm,PowerControl,Test,CWOut,CWOutWK,CWIn,CWInWK,CWBusy,CWDeadman,RotorAzIn,RotorElIn");
    $db->where("Radio", $radio);
    $k = $db->getOne("Keyer", "Radio,WKSpeed,WKPTT,WKFunction,WKRemotePort,WKRemoteIP");

    $observations = [];
    $add = static function (string $severity, string $code, string $message) use (&$observations): void {
        $observations[] = ["severity" => $severity, "code" => $code, "message" => $message];
    };

    $port = trim((string) ($s["Port"] ?? ""));
    $dummy = strcasecmp(trim((string) ($s["Model"] ?? "")), "Dummy") === 0;
    $isDevice = str_starts_with($port, "/dev/");
    $portPresent = $isDevice ? file_exists($port) : null;
    $portReadable = $isDevice && $portPresent ? is_readable($port) : null;
    $portWritable = $isDevice && $portPresent ? is_writable($port) : null;
    $isAlive = (string) ($i["IsAlive"] ?? "0") === "1";

    $basePort = (int) ($u["rigctldPort"] ?? 4532);
    $expectedPort = $basePort + $radio - 1;
    $listenerActive = false;
    $portHex = strtoupper(str_pad(dechex($expectedPort), 4, "0", STR_PAD_LEFT));
    foreach (["/proc/net/tcp", "/proc/net/tcp6"] as $tcpTable) {
        $lines = @file($tcpTable, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $parts = preg_split('/\\s+/', trim($line));
            if (count($parts) < 4 || ($parts[3] ?? "") !== "0A") continue;
            $local = explode(":", (string) ($parts[1] ?? ""));
            if (strtoupper((string) end($local)) === $portHex) {
                $listenerActive = true;
                break 2;
            }
        }
    }

    $processRunning = false;
    $rigctldPid = null;
    $rigctldModel = null;
    foreach (glob("/proc/[0-9]*/cmdline") ?: [] as $cmdlineFile) {
        $raw = @file_get_contents($cmdlineFile);
        if ($raw === false || $raw === "") continue;
        $args = array_values(array_filter(explode("\\0", $raw), static fn($v) => $v !== ""));
        if (!$args || basename((string) $args[0]) !== "rigctld") continue;
        $listenPort = null;
        $modelId = null;
        for ($n = 1; $n < count($args) - 1; $n++) {
            if ($args[$n] === "-t") $listenPort = (int) $args[$n + 1];
            if ($args[$n] === "-m") $modelId = (int) $args[$n + 1];
        }
        if ($listenPort === $expectedPort) {
            $processRunning = true;
            $rigctldPid = (int) basename(dirname($cmdlineFile));
            $rigctldModel = $modelId;
            break;
        }
    }

    // Rotor status is read-only. Prefer a live rotctld position query, then
    // retain the RadioInterface angles as a clearly identified fallback.
    $rotorId = (int) ($s["RotorID"] ?? 0);
    $rotorName = trim((string) ($s["RotorModel"] ?? ""));
    $rotorPort = trim((string) ($s["RotorPort"] ?? ""));
    $rotorSerial = str_starts_with($rotorPort, "/dev/");
    $rotorConfigured = $rotorId > 0
        && strcasecmp($rotorName, "None") !== 0
        && ($rotorId === 1 || ($rotorPort !== "" && strcasecmp($rotorPort, "None") !== 0));
    $rotorHost = "127.0.0.1";
    $rotorTcpPort = null;
    if ($rotorConfigured) {
        if (str_contains($rotorPort, ":")) {
            [$candidateHost, $candidatePort] = array_pad(explode(":", $rotorPort, 2), 2, "");
            if (trim($candidateHost) !== "") $rotorHost = trim($candidateHost);
            if (ctype_digit(trim($candidatePort))) $rotorTcpPort = (int) trim($candidatePort);
        } elseif ($rotorSerial || $rotorId === 1) {
            $rotorTcpPort = $expectedPort + 1;
        } elseif (ctype_digit($rotorPort)) {
            $rotorTcpPort = (int) $rotorPort;
        }
    }

    $rotorProcessRunning = false;
    $rotorProcessPid = null;
    if ($rotorTcpPort !== null) {
        foreach (glob("/proc/[0-9]*/cmdline") ?: [] as $cmdlineFile) {
            $raw = @file_get_contents($cmdlineFile);
            if ($raw === false || $raw === "") continue;
            $args = array_values(array_filter(explode("\0", $raw), static fn($v) => $v !== ""));
            if (!$args || basename((string) $args[0]) !== "rotctld") continue;
            $listenPort = null;
            for ($n = 1; $n < count($args) - 1; $n++) {
                if ($args[$n] === "-t") $listenPort = (int) $args[$n + 1];
            }
            if ($listenPort === $rotorTcpPort) {
                $rotorProcessRunning = true;
                $rotorProcessPid = (int) basename(dirname($cmdlineFile));
                break;
            }
        }
    }

    $rotorResponsive = false;
    $rotorProbeAzimuth = null;
    $rotorProbeElevation = null;
    if ($rotorConfigured && $rotorTcpPort !== null && $rotorTcpPort > 0 && $rotorTcpPort < 65536) {
        $rotorErrno = 0;
        $rotorError = "";
        $rotorSocket = @fsockopen($rotorHost, $rotorTcpPort, $rotorErrno, $rotorError, 0.75);
        if (is_resource($rotorSocket)) {
            stream_set_timeout($rotorSocket, 0, 750000);
            @fwrite($rotorSocket, "p\n");
            $azimuthReply = trim((string) @fgets($rotorSocket));
            $elevationReply = trim((string) @fgets($rotorSocket));
            @fclose($rotorSocket);
            if (is_numeric($azimuthReply) && is_numeric($elevationReply)) {
                $rotorResponsive = true;
                $rotorProbeAzimuth = round((float) $azimuthReply, 1);
                $rotorProbeElevation = round((float) $elevationReply, 1);
            }
        }
    }

    $databaseAzimuth = is_numeric($i["RotorAzIn"] ?? null) ? round((float) $i["RotorAzIn"], 1) : null;
    $databaseElevation = is_numeric($i["RotorElIn"] ?? null) ? round((float) $i["RotorElIn"], 1) : null;
    $rotorObservations = [];
    if (!$rotorConfigured) {
        $rotorObservations[] = ["severity" => "info", "code" => "rotor_not_configured", "message" => "No rotor is configured for the selected radio."];
    } elseif ($rotorTcpPort === null) {
        $rotorObservations[] = ["severity" => "warning", "code" => "rotor_port_invalid", "message" => "The configured rotor target could not be resolved to a live control port."];
    } elseif ($rotorResponsive) {
        $rotorObservations[] = ["severity" => "info", "code" => "rotor_live", "message" => "The configured rotctld endpoint answered a live read-only position request."];
    } else {
        $rotorObservations[] = ["severity" => "warning", "code" => "rotor_not_responding", "message" => "The configured rotor endpoint did not answer a live read-only position request."];
    }

    $cwPids = [];
    $cwRuntimeKeyer = null;
    $cwDeviceOpen = null;
    $cwRadioToken = "radio" . $radio;
    foreach (glob("/proc/[0-9]*/cmdline") ?: [] as $cmdlineFile) {
        $raw = @file_get_contents($cmdlineFile);
        if ($raw === false || $raw === "") continue;
        $args = array_values(array_filter(explode("\0", $raw), static fn($v) => $v !== ""));
        if (count($args) < 5 || basename((string) ($args[1] ?? "")) !== "CWDo.php" || (string) ($args[3] ?? "") !== $cwRadioToken) continue;
        $pid = (int) basename(dirname($cmdlineFile));
        if ($pid < 1) continue;
        $cwPids[] = $pid;
        if ($cwRuntimeKeyer === null) $cwRuntimeKeyer = strtolower(trim((string) ($args[4] ?? "")));
    }

// The web worker may be unable to read a root-owned rigctld cmdline. Confirm
// the expected listener by issuing Hamlib's read-only get-frequency command.
$rigctldProbeFrequency = null;
if ($listenerActive && !$processRunning) {
    $probeErrno = 0;
    $probeError = "";
    $probeSocket = @fsockopen("127.0.0.1", $expectedPort, $probeErrno, $probeError, 0.75);
    if (is_resource($probeSocket)) {
        stream_set_timeout($probeSocket, 0, 750000);
        @fwrite($probeSocket, "f\n");
        $probeReply = trim((string) @fgets($probeSocket));
        @fclose($probeSocket);
        if (preg_match('/^[0-9]{4,12}$/', $probeReply) && (int) $probeReply > 0) {
            $processRunning = true;
            $rigctldProbeFrequency = (int) $probeReply;
        }
    }
}

// Ask the already-running Hamlib backend whether it can send Morse. This is
// capability evidence only; unlike a /dev path check, it does not imply that
// any external keyer hardware is physically attached.
$catMorseSupported = null;
if ($listenerActive) {
    $capsErrno = 0;
    $capsError = "";
    $capsSocket = @fsockopen("127.0.0.1", $expectedPort, $capsErrno, $capsError, 0.75);
    if (is_resource($capsSocket)) {
        stream_set_timeout($capsSocket, 0, 750000);
        @fwrite($capsSocket, "\\dump_caps\n");
        for ($capsLineNumber = 0; $capsLineNumber < 400; $capsLineNumber++) {
            $capsLine = @fgets($capsSocket);
            if ($capsLine === false) break;
            if (preg_match('/^Can send Morse:\\s*([YN])\\s*$/i', trim($capsLine), $capsMatch)) {
                $catMorseSupported = strtoupper($capsMatch[1]) === "Y";
            }
            if (preg_match('/^RPRT\\s+-?\\d+\\s*$/', trim($capsLine))) break;
        }
        @fclose($capsSocket);
    }
}


    if (!$s) $add("error", "missing_radio_settings", "The selected radio has no MySettings record.");
    elseif ($dummy) $add("info", "dummy_backend", "The selected radio uses Hamlib Dummy rather than physical CAT hardware.");
    elseif ($port === "" || strcasecmp($port, "None") === 0) $add("error", "port_not_configured", "No CAT port is configured for the selected radio.");
    elseif ($isDevice && !$portPresent) $add("error", "port_missing", "The configured local CAT device is not currently present.");
    elseif ($isDevice && (!$portReadable || !$portWritable)) $add("warning", "port_permissions", "The configured CAT device exists but is not both readable and writable by the web process.");
    else $add("info", "port_configuration_present", "A CAT connection target is configured for the selected radio.");

    if (!$processRunning && !$listenerActive) {
        $add("error", "rigctld_not_running", "No rigctld process or TCP listener is active for the selected radio.");
    $add("info", "connect_radio_first", "First action: in Tuner, use Connect Radio. A stopped rigctld after Disconnect Radio is normal. Reboot only if Connect Radio fails or leaves a partial runtime. Stay on CAT/rig control; omit Mumble/WebRTC unless the question reports audio.");
    } elseif ($processRunning && !$listenerActive) {
        $add("error", "rigctld_not_listening", "The selected radio has a rigctld process but its expected TCP listener is not active.");
    } elseif (!$processRunning && $listenerActive) {
        $add("warning", "unexpected_listener", "The expected CAT port is listening, but no matching rigctld process was found.");
    } else {
        $add("info", "rigctld_live", "A responsive rigctld endpoint and TCP listener are active for the selected radio. Stay on CAT/rig control; omit Mumble/WebRTC unless the question reports audio.");
    }

    if (!$i) $add("error", "missing_interface_state", "The selected radio has no RadioInterface state record.");
    elseif ($isAlive && (!$processRunning || !$listenerActive)) $add("warning", "stale_database_alive", "RadioInterface is marked alive, but the selected radio has no complete live rigctld runtime.");
    elseif (!$isAlive && $processRunning && $listenerActive) $add("warning", "stale_database_offline", "RadioInterface is marked offline even though the selected radio has a live rigctld runtime.");
    elseif (!$isAlive) $add("info", "interface_not_alive", "The database marks the selected radio interface as not alive.");
    else $add("info", "interface_alive", "The database and live rigctld checks both indicate an active radio interface.");

    $frequency = filter_var($i["MainIn"] ?? null, FILTER_VALIDATE_INT);
    if ($i && ($frequency === false || $frequency < 1000)) $add("warning", "no_frequency_feedback", "No valid frequency feedback is present in RadioInterface.");
    if ((string) ($i["Transmit"] ?? "0") === "1" || (string) ($i["PTTIn"] ?? "0") === "1") $add("warning", "transmit_active", "The database currently reports transmit or PTT active.");

    $keyerName = trim((string) ($s["Keyer"] ?? "None"));
    $keyerPort = trim((string) ($s["KeyerPort"] ?? ""));
    $keyerToken = match (strtolower($keyerName)) {
        "rigpi keyer" => "rpk",
        "via cat" => "cat",
        "winkeyer" => "wkr",
        "external cts" => "ext",
        "none", "" => "none",
        default => "other"
    };
    $keyerEnabled = $keyerToken !== "none";
    $keyerUsesDevice = in_array($keyerToken, ["rpk", "wkr", "ext"], true);
    $keyerIsDevice = str_starts_with($keyerPort, "/dev/");
    $keyerPortPresent = $keyerUsesDevice && $keyerIsDevice ? file_exists($keyerPort) : null;
    $keyerPortReadable = $keyerPortPresent ? is_readable($keyerPort) : null;
    $keyerPortWritable = $keyerPortPresent ? is_writable($keyerPort) : null;
    $resolvedKeyerDevice = $keyerPortPresent ? realpath($keyerPort) : false;
    $resolvedKeyerDevice = $resolvedKeyerDevice === false ? null : $resolvedKeyerDevice;
    $keyerPortDisplay = $keyerPort;
    if (str_starts_with($keyerPortDisplay, "/dev/serial/by-id/")) $keyerPortDisplay = "/dev/serial/by-id/…";
    if (strlen($keyerPortDisplay) > 80) $keyerPortDisplay = substr($keyerPortDisplay, 0, 77) . "…";

    if ($resolvedKeyerDevice !== null && $cwPids) {
        $cwDeviceOpen = false;
        foreach ($cwPids as $cwPid) {
            foreach (glob("/proc/" . $cwPid . "/fd/*") ?: [] as $fd) {
                $target = @readlink($fd);
                if ($target !== false && ($target === $resolvedKeyerDevice || @realpath($target) === $resolvedKeyerDevice)) {
                    $cwDeviceOpen = true;
                    break 2;
                }
            }
        }
    }

    $cwProcessRunning = count($cwPids) > 0;
    $mode = strtoupper(trim((string) ($i["ModeIn"] ?? "")));
    $modeCw = in_array($mode, ["CW", "CWR", "CW-R", "CWREV", "CW-REV"], true);
    $cwBusy = (string) ($i["CWBusy"] ?? "0") === "1";
    $pendingOutput = strlen((string) ($i["CWOut"] ?? "")) > 0 || strlen((string) ($i["CWOutWK"] ?? "")) > 0 || strlen((string) ($i["CWIn"] ?? "")) > 0 || strlen((string) ($i["CWInWK"] ?? "")) > 0;
    $remoteConfigured = trim((string) ($k["WKRemoteIP"] ?? "")) !== "" || (int) ($k["WKRemotePort"] ?? 0) > 0;

    if (!$keyerEnabled) {
        $add("error", "cw_keyer_disabled", "CW transmission is disabled because Advanced Radio Settings has Keyer set to None.");
    } elseif ($keyerUsesDevice && !$keyerIsDevice) {
        $add("error", "cw_port_invalid", "The selected hardware keyer does not have a local /dev keyer port configured.");
    } elseif ($keyerUsesDevice && !$keyerPortPresent) {
        $add("error", "cw_port_missing", "The configured CW keyer device is not currently present.");
    } elseif ($keyerUsesDevice && (!$keyerPortReadable || !$keyerPortWritable)) {
        $add("error", "cw_port_permissions", "The CW keyer device exists but is not both readable and writable by the RigPi web process.");
    } elseif ($keyerUsesDevice) {
        $add("info", "cw_port_openable", "The configured serial path exists and is openable; this does not confirm that physical keyer hardware is connected.");
    } elseif ($keyerToken === "cat") {
        $add("info", "cw_cat_selected", "CW keying is configured to use the selected radio's local rigctld connection.");
    }

    if ($keyerToken === "rpk" && $catMorseSupported === true) {
        $add("warning", "cw_keyer_selection_mismatch", "Hamlib reports that this radio can send Morse over CAT, but Advanced Radio Settings is set to RigPi Keyer. If no physical RigPi Keyer is connected, set Keyer to via CAT and reconnect the radio.");
    } elseif ($keyerToken === "cat" && $catMorseSupported === true) {
        $add("info", "cw_cat_supported", "Hamlib reports that the selected radio supports sending Morse over CAT.");
    } elseif ($keyerToken === "cat" && $catMorseSupported === false) {
        $add("error", "cw_cat_unsupported", "Advanced Radio Settings is set to via CAT, but Hamlib reports that the selected radio cannot send Morse over CAT.");
    }

    if ($keyerEnabled && !$cwProcessRunning) {
        $add($processRunning && $listenerActive ? "error" : "warning", "cw_server_not_running", $processRunning && $listenerActive ? "Radio control is live, but no CWDo server is running for the selected radio." : "No CWDo server is running; use Connect Radio first because RigPi starts CW control with the radio connection.");
    } elseif ($keyerEnabled && count($cwPids) > 1) {
        $add("warning", "duplicate_cw_servers", "More than one CWDo server is running for the selected radio.");
    } elseif ($keyerEnabled) {
        $add("info", "cw_server_live", "A CWDo server is running for the selected radio.");
    }

    if ($keyerEnabled && $cwRuntimeKeyer !== null && !in_array($cwRuntimeKeyer, [$keyerToken, $keyerToken . "1", $keyerToken . "2"], true)) {
        $add("warning", "cw_runtime_mismatch", "The running CW server keyer type does not match Advanced Radio Settings; reconnect the radio after saving keyer changes.");
    }
    if ($keyerUsesDevice && $cwProcessRunning && $cwDeviceOpen === false) $add("warning", "cw_device_not_open", "The CW server is running, but its configured keyer device was not found among the process's open files.");
    if ($keyerEnabled && !$modeCw) $add("warning", "radio_not_in_cw_mode", "The radio is not currently in CW or CW-R mode.");
    if ($cwBusy) $add("info", "cw_busy", "RigPi currently reports the CW sender busy.");
    if ($pendingOutput) $add("info", "cw_text_pending", "RigPi has CW text pending or staged; message content was excluded.");

    $runtime = [
        "expected_port" => $expectedPort,
        "process_running" => $processRunning,
        "listener_active" => $listenerActive,
        "pid" => $rigctldPid,
        "model_id" => $rigctldModel,
        "database_alive" => $isAlive,
        "status" => $processRunning && $listenerActive ? "connected" : (!$processRunning && !$listenerActive ? "disconnected" : "inconsistent")
    ];

    $configuration = [
        "manufacturer" => trim((string) ($s["Manufacturer"] ?? "")),
        "model" => trim((string) ($s["Model"] ?? "")),
        "radio_name" => trim((string) ($s["RadioName"] ?? "")),
        "port_kind" => $isDevice ? "local_device" : ($dummy ? "dummy" : ($port === "" || strcasecmp($port, "None") === 0 ? "none" : "network_or_other")),
        "port_present" => $portPresent,
        "port_readable" => $portReadable,
        "port_writable" => $portWritable,
        "baud" => trim((string) ($s["Baud"] ?? "")),
        "data_bits" => (int) ($s["Bits"] ?? 0),
        "parity" => trim((string) ($s["Parity"] ?? "")),
        "stop_bits" => trim((string) ($s["Stop"] ?? "")),
        "civ_code" => trim((string) ($s["CIV_Code"] ?? "")),
        "ptt_cat" => (string) ($s["PTTCAT"] ?? "") === "1",
        "ptt_mode" => trim((string) ($s["PTTMode"] ?? "")),
        "disable_split_polling" => (string) ($s["DisableSplitPolling"] ?? "") === "1",
        "power_control" => trim((string) ($s["PowerControl"] ?? ""))
    ];

    $interface = [
        "is_alive" => $isAlive,
        "transmitting" => (string) ($i["Transmit"] ?? "0") === "1",
        "frequency_hz" => $frequency === false ? null : $frequency,
        "mode" => trim((string) ($i["ModeIn"] ?? "")),
        "bandwidth_hz" => (int) ($i["BWIn"] ?? 0),
        "split" => (string) ($i["SplitIn"] ?? "0") === "1",
        "ptt" => (string) ($i["PTTIn"] ?? "0") === "1",
        "close_watch" => trim((string) ($i["Close_Watch"] ?? "")),
        "wait_reset" => (int) ($i["waitReset"] ?? 0)
    ];

    $cw = [
        "enabled" => $keyerEnabled,
        "keyer_name" => $keyerName,
        "keyer_type" => $keyerToken,
        "configured_port" => $keyerPortDisplay,
        "port_kind" => $keyerUsesDevice ? ($keyerIsDevice ? "local_device" : "invalid") : ($keyerToken === "cat" ? "rigctld" : ($keyerEnabled ? "network_or_other" : "none")),
        "port_present" => $keyerPortPresent,
        "port_readable" => $keyerPortReadable,
        "port_writable" => $keyerPortWritable,
        "resolved_device" => $resolvedKeyerDevice,
        "device_open" => $cwDeviceOpen,
        "server_process_running" => $cwProcessRunning,
        "server_process_count" => count($cwPids),
        "pid" => $cwPids[0] ?? null,
        "runtime_keyer_type" => $cwRuntimeKeyer,
        "cat_morse_supported" => $catMorseSupported,
        "mode_cw" => $modeCw,
        "cw_busy" => $cwBusy,
        "pending_output" => $pendingOutput,
        "cw_deadman" => (int) ($i["CWDeadman"] ?? 0),
        "keyer_ptt" => (string) ($k["WKPTT"] ?? "0") === "1",
        "keyer_function" => (int) ($k["WKFunction"] ?? 0),
        "remote_configured" => $remoteConfigured,
        "speed_wpm" => (int) ($k["WKSpeed"] ?? 0)
    ];

    $rotorPortDisplay = $rotorPort;
    if ($rotorSerial) $rotorPortDisplay = "/dev/…";
    elseif (str_contains($rotorPortDisplay, ":")) {
        $rotorPortDisplay = preg_replace('/^.+:/', '…:', $rotorPortDisplay);
    }
    $rotor = [
        "configured" => $rotorConfigured,
        "rotor_id" => $rotorId,
        "model" => $rotorName,
        "configured_target" => $rotorPortDisplay,
        "target_kind" => !$rotorConfigured ? "none" : ($rotorSerial ? "local_device" : (str_contains($rotorPort, ":") ? "network" : "tcp_port")),
        "tcp_port" => $rotorTcpPort,
        "rotctld_process_running" => $rotorProcessRunning,
        "rotctld_pid" => $rotorProcessPid,
        "responsive" => $rotorResponsive,
        "status" => !$rotorConfigured ? "not_configured" : ($rotorResponsive ? "connected" : "not_responding"),
        "azimuth_degrees" => $rotorProbeAzimuth ?? $databaseAzimuth,
        "elevation_degrees" => $rotorProbeElevation ?? $databaseElevation,
        "position_source" => $rotorResponsive ? "live_rotctld" : (($databaseAzimuth !== null || $databaseElevation !== null) ? "RadioInterface" : "unavailable"),
        "baud" => trim((string) ($s["RotorBaud"] ?? "")),
        "stop_bits" => trim((string) ($s["RotorStop"] ?? "")),
        "observations" => $rotorObservations
    ];

    echo json_encode(["rig_diagnostics" => [
        "schema_version" => 1,
        "selected_radio" => $radio,
        "runtime" => $runtime,
        "configuration" => $configuration,
        "interface" => $interface,
        "cw" => $cw,
        "rotor" => $rotor,
        "observations" => $observations,
        "source" => "RigPi local station database",
        "guidance" => "Answer only about the subsystem the user asked about. For radio status, lead with live rigctld and frequency readback. For keyer status, never infer physical keyer presence from a serial path; lead with keyer selection, Hamlib Morse capability, mode, and CWDo runtime. For rotor status, lead with the live read-only rotctld probe and current bearing; identify RadioInterface angles as cached when the live probe did not answer.",
        "privacy_notice" => "Passwords, personal account fields, CW message text, and full persistent USB identifiers were excluded."
    ]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (JsonException | InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(["error" => $e->getMessage()]);
} catch (Throwable $e) {
    error_log("ElmerRigDiagnostics: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["error" => "RigPi could not retrieve rig diagnostics."]);
}
