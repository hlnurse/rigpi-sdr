<?php
// Ensure this file starts exactly with "<?php" on line 1. No spaces before it!
exit(); /////too sensitive
if (php_sapi_name() !== "cli") {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
    header("Content-Type: application/json");
    if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
        exit(0);
    }
}

$host = "127.0.0.1";
$port = 4532;
$connection_timeout = 1;

// 1. Safe process locator: Verify if rigctld is currently up on port 4532
$check_process = shell_exec("pgrep -f 't\ $port'");

if (empty($check_process)) {
    // If it's completely missing, start it up fresh
    $launch_cmd = "rigctld -m 3073 -r /dev/serial/by-id/usb-Silicon_Labs_CP2102_USB_to_UART_Bridge_Controller_IC-7300_02020433-if00-port0 -s 115200 -T 0.0.0.0 -t $port -C timeout=3000,retry=2,loosen_init=1,instance=12341 > /dev/null 2>&1 &";
    shell_exec($launch_cmd);
    sleep(1); // Give it a moment to bind the port
}

// 2. Open the socket connection to the daemon
$fp = @fsockopen($host, $port, $errno, $errstr, $connection_timeout);

if (!$fp) {
    // Port is bricked or locked -> Issue an explicit kill and EXIT.
    // DO NOT try to restart rigctld on this turn. Let the next 5-second poll handle it.
    shell_exec("fuser -k -9 $port/tcp > /dev/null 2>&1");

    echo json_encode([
        "status" => "offline",
        "message" =>
            "Rig port was locked and cleared. Standby for self-healing restart on next sync.",
    ]);
    exit();
}

// 3. Enforce network streaming rules
stream_set_blocking($fp, true);
stream_set_timeout($fp, 1, 500);

fwrite($fp, "f\n");
$response = trim(fgets($fp, 128));
$metadata = stream_get_meta_data($fp);
fclose($fp);

// 4. Evaluate stream and handle timeouts with fuser if needed
if ($metadata["timed_out"]) {
    // Socket hung up mid-stream -> Hard kill this specific instance via port number
    shell_exec("fuser -k $port/tcp > /dev/null 2>&1");

    echo json_encode([
        "status" => "timeout",
        "message" => "rigctld timed out mid-stream and was cleared.",
    ]);
} elseif (empty($response) || strpos($response, "RPRT") !== false) {
    echo json_encode([
        "status" => "timeout",
        "message" =>
            "The radio is powered down or returned a communication error.",
    ]);
} else {
    echo json_encode([
        "status" => "online",
        "frequency" => $response,
    ]);
}
?>
