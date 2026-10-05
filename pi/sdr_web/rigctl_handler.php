<?php
if (isset($_POST["port"])) {
    $tMyPort = $_POST["port"];
} else {
    $tMyPort = "127.0.0.1:4532";
}
// Sanitize port
if (preg_match('/^[\d\.]+:\d+$/', $tMyPort)) {
    $port = $tMyPort;
} else {
    $port = "127.0.0.1:4532";
}
$freq = isset($_POST["freq"]) ? intval($_POST["freq"]) : "28012500";
// Optional mode/bw. When absent, behavior is byte-identical to the freq-only
// original — existing tune calls that send no mode are unaffected.
$mode = isset($_POST["mode"])
    ? preg_replace("/[^A-Za-z0-9\-]/", "", $_POST["mode"])
    : "CWR";
$bw = isset($_POST["bw"]) ? intval($_POST["bw"]) : 0;

// Build the set portion: F <freq> and/or M <mode> <bw>.
// Read first (f m t) then write — atomic, no race, matches original intent.
$set = "";
if ($freq && $freq > 100000) {
    $set .= " F " . $freq;
}
if ($mode !== "") {
    $set .= " M " . $mode . " " . ($bw > 0 ? $bw : 0);
}

if ($set !== "") {
    $tExec = "rigctl -m 2 -r " . $port . " f m t" . $set . " 2>&1";
} else {
    $tExec = "rigctl -m 2 -r " . $port . " f m t 2>&1";
}
$output = shell_exec($tExec);
if ($output !== null) {
    $output = str_replace("\n", "|", $output);
    echo json_encode(["status" => "success", "data" => trim($output)]);
} else {
    echo json_encode([
        "status" => "error",
        "message" => "Command failed to execute",
    ]);
}
?>
