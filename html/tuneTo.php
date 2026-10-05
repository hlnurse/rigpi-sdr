<?php
/**
 * tuneTo.php
 * Called from Pushover notification URL to tune radio and SDR.
 * Usage: /tuneTo.php?freq=14025000&user=admin
 */
$dRoot = "/var/www/html";
require_once $dRoot . "/classes/MysqliDb.php";
require $dRoot . "/programs/sqldata.php";

$freq = intval($_GET["freq"] ?? 0);
$user = preg_replace(
    "/[^a-z0-9_]/",
    "",
    strtolower(trim($_GET["user"] ?? "admin"))
);
$dx = strtoupper(preg_replace("/[^A-Za-z0-9\/]/", "", trim($_GET["dx"] ?? "")));

if (!$freq) {
    http_response_code(400);
    echo "Missing freq";
    exit();
}

$db = new MysqliDb(
    "localhost",
    $sql_radio_username,
    $sql_radio_password,
    $sql_radio_database
);

// Get user's radio number
$db->where("Username", $user);
$uRow = $db->getOne("Users", ["SelectedRadio", "MyCall", "SDRHost"]);
$radio = $uRow["SelectedRadio"] ?? 1;
$call = $uRow["MyCall"] ?? "ADMIN";
$sdrHost = $uRow["SDRHost"] ?? "";

// 1. Tune via flic.php (updates rigctld / radio)
$flicUrl =
    "http://localhost/flic.php?n=7&u=" . urlencode($user) . "&p=" . $freq;
@file_get_contents($flicUrl);

// 1b. Update DX focus call in MySettings
if ($dx) {
    $db2 = new MysqliDb(
        "localhost",
        $sql_radio_username,
        $sql_radio_password,
        $sql_radio_database
    );
    $db2->where("Radio", intval($radio));
    $db2->update("MySettings", ["DX" => $dx]);
}

// 2. Tune SDR via /api/tune if SDRHost is configured
if ($sdrHost) {
    // Extract base URL from SDRHost (e.g. http://rigpi5.local/sdr5)
    $apiUrl = rtrim($sdrHost, "/") . "/api/tune";
    $payload = json_encode([
        "freq" => $freq,
        "user" => $user,
        "callsign" => $call,
        "radio" => $radio,
    ]);
    $ctx = stream_context_create([
        "http" => [
            "method" => "POST",
            "header" =>
                "Content-Type: application/json\r\nContent-Length: " .
                strlen($payload),
            "content" => $payload,
            "timeout" => 3,
        ],
    ]);
    @file_get_contents($apiUrl, false, $ctx);
}

// 3. Redirect via HTML page that sets localStorage and redirects
$redirect = "/index.php";
if ($dx) {
    // Use HTML redirect to set localStorage before going to index.php
    echo "<!DOCTYPE html><html><head><script>
localStorage.setItem('tunetodx', " .
        json_encode($dx) .
        ");
window.location.replace('/index.php');
</script></head><body></body></html>";
    exit();
}
header("Location: $redirect");
exit();
?>
