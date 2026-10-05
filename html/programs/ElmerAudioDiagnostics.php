<?php
/** Read-only receive-audio diagnostics for Ask Elmer. */
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

/** Test whether a TCP port is listening without invoking a shell command. */
function elmerPortListening(int $port): bool
{
    $portHex = strtoupper(str_pad(dechex($port), 4, "0", STR_PAD_LEFT));
    foreach (["/proc/net/tcp", "/proc/net/tcp6"] as $tcpTable) {
        $lines = @file($tcpTable, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) < 4 || ($parts[3] ?? "") !== "0A") continue;
            $local = explode(":", (string) ($parts[1] ?? ""));
            if (strtoupper((string) end($local)) === $portHex) return true;
        }
    }
    return false;
}

/** Find the expected SDR web process without exposing its full command line. */
function elmerSdrProcessRunning(int $port): bool
{
    foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $path) {
        $cmd = str_replace("\0", " ", (string) @file_get_contents($path));
        if ($cmd !== "" && str_contains($cmd, "sdr_web_server_minimal.py") &&
            preg_match('/(?:--port\s+|--port=)' . preg_quote((string) $port, '/') . '\b/', $cmd)) {
            return true;
        }
    }
    return false;
}

/** Retrieve a small JSON status response from the local SDR service. */
function elmerLocalJson(string $url): ?array
{
    $context = stream_context_create(["http" => [
        "method" => "GET",
        "timeout" => 1.0,
        "ignore_errors" => true,
        "header" => "Connection: close\r\n",
    ]]);
    $raw = @file_get_contents($url, false, $context);
    if ($raw === false || strlen($raw) > 65536) return null;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

try {
    $request = json_decode(file_get_contents("php://input"), true, 8, JSON_THROW_ON_ERROR);
    if (($request["query_mode"] ?? "") !== "audio_diagnostics") {
        throw new InvalidArgumentException("The audio diagnostic query mode is invalid.");
    }

    $username = (string) $_SESSION["myUsername"];
    $userId = (int) getUserField($username, "uID");
    if ($userId < 1) throw new RuntimeException("The signed-in RigPi account was not found.");

    $db = new MysqliDb("localhost", $sql_radio_username, $sql_radio_password, $sql_radio_database);
    $db->where("uID", $userId);
    $user = $db->getOne("Users", "uID,SelectedRadio,SDRHost,SDRHostRemote,SDRPort,SDRPage");
    if (!$user) throw new RuntimeException("RigPi user record was not found.");

    $radioId = (int) ($user["SelectedRadio"] ?? 0);
    if ($radioId < 1 || $radioId > 999) throw new RuntimeException("No valid radio is selected.");
    $db->where("Radio", $radioId);
    $radio = $db->getOne("MySettings", "Radio,Manufacturer,Model,RadioName,Port");

    $streamPort = (int) ($user["SDRPort"] ?? 0);
    if ($streamPort < 1 || $streamPort > 65535) $streamPort = 8000 + $radioId;
    $listenerActive = elmerPortListening($streamPort);
    $processRunning = elmerSdrProcessRunning($streamPort);

    $safeUser = preg_replace('/[^A-Za-z0-9_.-]/', '', $username) ?: "admin";
    $base = "http://127.0.0.1:" . $streamPort;
    $devices = elmerLocalJson($base . "/api/audio/devices?user=" . rawurlencode(strtolower($safeUser)));
    $state = elmerLocalJson($base . "/api/audio/state");

    $source = is_array($devices) ? trim((string) ($devices["rx_source"] ?? "")) : "";
    $deviceId = is_array($devices) && isset($devices["rx_device"]) ? (int) $devices["rx_device"] : null;
    $sources = [];
    if (is_array($devices["sources"] ?? null)) {
        foreach (array_slice($devices["sources"], 0, 16) as $candidate) {
            if (!is_array($candidate)) continue;
            $sources[] = [
                "index" => (int) ($candidate["index"] ?? -1),
                "name" => mb_substr(trim((string) ($candidate["name"] ?? "")), 0, 120),
            ];
        }
    }
    $selectedDeviceName = null;
    foreach ($sources as $candidate) {
        if ($deviceId !== null && $candidate["index"] === $deviceId) {
            $selectedDeviceName = $candidate["name"];
            break;
        }
    }

    $observations = [];
    $add = static function (string $severity, string $code, string $message) use (&$observations): void {
        if (count($observations) < 12) {
            $observations[] = ["severity" => $severity, "code" => $code, "message" => mb_substr($message, 0, 240)];
        }
    };

    if (!$processRunning) $add("error", "stream_process_missing", "The selected radio's browser-stream process is not running.");
    if (!$listenerActive) $add("error", "stream_listener_missing", "Nothing is listening on the selected browser-stream port.");
    if ($devices === null || $state === null) {
        $add("error", "audio_status_unavailable", "The browser-stream service did not return its audio status.");
    } elseif ($source === "radio") {
        if ($deviceId === null) {
            $add("error", "radio_source_unselected", "Radio receive audio is selected, but no input device is configured.");
        } elseif ($selectedDeviceName === null) {
            $add("error", "radio_device_missing", "The configured radio receive-audio device is not in the current input-device list.");
        } else {
            $add("info", "radio_device_ready", "Radio receive audio is configured on " . $selectedDeviceName . ".");
        }
    } elseif ($source === "sdr") {
        $add("info", "sdr_audio_selected", "The SDR demodulator, not the radio USB audio input, is selected for receive audio.");
    } else {
        $add("warning", "audio_source_unknown", "The saved receive-audio source is missing or unrecognized.");
    }

    $activeSession = is_array($state) ? (bool) ($state["running"] ?? false) : false;
    $connectionCount = is_array($state) ? max(0, (int) ($state["count"] ?? 0)) : 0;
    if ($state !== null) {
        if ($activeSession) {
            $add("info", "browser_audio_active", "A browser audio session is active now.");
        } else {
            $add("info", "no_active_browser_audio", "No browser audio session was active at the instant of this check; that alone is not a fault.");
        }
    }

    $hasError = count(array_filter($observations, static fn(array $o): bool => $o["severity"] === "error")) > 0;
    echo json_encode(["audio_diagnostics" => [
        "schema_version" => 1,
        "query_mode" => "audio_diagnostics",
        "direction" => "receive",
        "status" => $hasError ? "attention" : "ready",
        "selected_radio" => [
            "id" => $radioId,
            "name" => trim((string) ($radio["RadioName"] ?? "")),
            "manufacturer" => trim((string) ($radio["Manufacturer"] ?? "")),
            "model" => trim((string) ($radio["Model"] ?? "")),
        ],
        "browser_stream" => [
            "port" => $streamPort,
            "local_url" => trim((string) ($user["SDRHost"] ?? "")),
            "remote_url" => trim((string) ($user["SDRHostRemote"] ?? "")),
            "page" => trim((string) ($user["SDRPage"] ?? "")),
            "process_running" => $processRunning,
            "listener_active" => $listenerActive,
            "audio_api_available" => $devices !== null && $state !== null,
            "active_audio_session" => $activeSession,
            "connection_count" => $connectionCount,
        ],
        "receive_audio" => [
            "source" => $source !== "" ? $source : null,
            "device_index" => $deviceId,
            "device_name" => $selectedDeviceName,
            "device_present" => $selectedDeviceName !== null,
            "available_input_count" => count($sources),
        ],
        "observations" => $observations,
        "source" => "RigPi local station database and live SDR browser-stream status",
        "guidance" => "This is a read-only receive-audio snapshot. An available configured device and service do not prove that audio samples contain a usable radio signal. No transmit or audio-capture test was performed.",
        "privacy_notice" => "Passwords, account secrets, and full device identifiers were excluded.",
    ]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (JsonException | InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(["error" => $e->getMessage()]);
} catch (Throwable $e) {
    error_log("ElmerAudioDiagnostics: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["error" => "RigPi could not retrieve receive-audio diagnostics."]);
}
