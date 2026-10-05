<?php
// Capture startup failures even when the launcher redirects output.
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        error_log(date('c').' '.json_encode($error).PHP_EOL, 3, '/tmp/rigpi-cw-startup-error.log');
    }
});

ini_set('log_errors', '1');
ini_set('error_log', '/var/log/rigpi-keyer-diagnostic.log');
error_log('Keyer diagnostic worker starting PID='.getmypid());

/*
 * CWDo.php - RigPi CW Port Server Launcher
 *
 * Starts a CWPortServer instance for keying CW via serial, UDP, or rigctl network ports.
 *
 * Copyright (c) 2025 Howard Nurse, W6HN
 * Licensed under the MIT License.
 */

// Show all PHP errors
ini_set("error_reporting", E_ALL);
ini_set("display_errors", 1);

// Include CWPortServer class definition
require_once "/var/www/html/classes/CWPORTServer.class.php";

/**
 * Simple logging function
 * Logs a string to test.log if utest flag is 1
 */
function doLog($utest, $what)
{
    if ($utest == 1) {
        error_log(
            date("Y-m-d H:i:s", time()) . " " . $what . PHP_EOL,
            3,
            "test.log"
        );
    }
}

// Parse command-line arguments if supplied
if ($argc > 1) {
    $tUser = $argv[1];
    $tMyRadio = $argv[2];
    $tMyKeyer = $argv[3];
    // $tMyCWPort = $argv[4];  // no longer used here, pulled from database below
    $tPortTrans = $argv[5];
    $utest = $argv[6];
} else {
    // Defaults when no arguments supplied (typically test/debug)
    $tUser = "admin";
    $tMyRadio = "radio1";
    $tMyKeyer = "wkr";
    $tMyCWPort = "30040";
    $tPortTrans = 0;
    $utest = 1;
}

// Strip radio number from "radioX"
$tMyRadio = substr($tMyRadio, strlen((string) ($tMyRadio ?? "")) - 1);

// Output for debugging
echo "tMyRadio: $tMyRadio\n";

// Retrieve CW port from MySettings for this radio
$tMyCWPort = GetField($tMyRadio, "KeyerPort", "MySettings");

// Log and display
echo "CWPORT: $tMyCWPort \n MyKeyer: $tMyKeyer\n";
doLog(1, "CWPORT: $tMyCWPort MyKeyer: $tMyKeyer");
$port = 30040;
// Only proceed if a keyer is specified
if ($tMyKeyer !== "none") {
    // Set up $port and adjust $tMyCWPort depending on its content
    if ($tMyKeyer === "cat") {
        // CAT keying uses the selected radio's rigctld connection, not the
        // hardware-keyer serial port.  Preserve an explicit host:port target
        // for remote rigs; otherwise use this radio's standard local port.
        if (
            strstr((string) ($tMyCWPort ?? ""), ":") &&
            !strstr((string) ($tMyCWPort ?? ""), "/dev")
        ) {
            $tP = explode(":", $tMyCWPort);
            $port = $tP[1];
            $tMyCWPort = $tP[0];
        } else {
            $tMyCWPort = "127.0.0.1";
            $port = 4530 + 2 * (int) $tMyRadio;
        }

        $tCW = "rigctl -m 2 -r $tMyCWPort:$port > /dev/null 2>/dev/null &";

        echo "tCW $tCW to $port\n";
    } elseif (strstr((string) ($tMyCWPort ?? ""), "/dev")) {
        // Serial port device
        $port = $tMyCWPort;
    } elseif ($tMyCWPort > 30000 && $tMyCWPort < 30012) {
        // Port-only specification, assumes localhost IP
        $port = $tMyCWPort;
        $tMyCWPort = trim(str_replace("\n", "", shell_exec("hostname -I")));
    } elseif (strstr((string) ($tMyCWPort ?? ""), ":")) {
        // IP:Port pair
        $tP = explode(":", $tMyCWPort);
        $port = $tP[1];
        $tMyCWPort = $tP[0];
        // Use host IP if localhost or empty
        if (
            $tMyCWPort == "localhost" ||
            $tMyCWPort == "127.0.0.1" ||
            $tMyCWPort == ""
        ) {
            $tMyCWPort = trim(str_replace("\n", "", shell_exec("hostname -I")));
        }
    } elseif (strstr((string) ($tMyCWPort ?? ""), ".")) {
        // IP address only — use default CW port based on radio number
        $port = 30000 + $tMyRadio;
        $tMyCWPort = trim(str_replace("\n", "", shell_exec("hostname -I")));
    }

    // Display adjusted port/IP values
    echo "MyCWPort now: $tMyCWPort\n";
    echo "Port now $port\n";

    // Log final settings before launching server
    doLog(1, "setting up portserver: $port $tMyCWPort $tMyKeyer $tMyRadio");

    // Start CWPortServer instance with determined parameters
    $cwServer = new CWPortServer(
        "0.0.0.0", // Listen on all network interfaces
        $port, // Port number for local server
        $tMyCWPort, // Target IP (or serial port)
        $tMyKeyer, // Keyer type
        $tMyRadio, // Radio number
        $tUser, // Username
        $tPortTrans, // Whether this is a port translation instance
        $utest // Test mode flag
    );

    // Small delay for server to stabilize
    usleep(100000);

    // Enter infinite event loop for this server instance
    $cwServer->infinite_loop();
}

?>
