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
function doLog($what)
{
    error_log(
        date("Y-m-d H:i:s", time()) . " " . $what . PHP_EOL,
        3,
        "/var/log/rigpi-radio.log"
    );
}
header("Content-Type: application/json");
if (isset($_POST["ptt"])) {
    $ptt = intval($_POST["ptt"]) === 1 ? 1 : 0;
    $radio = isset($_POST["radio"]) ? intval($_POST["radio"]) : 1;

    if ($radio < 1) {
        $radio = 1;
    }

    /*
     * Get the configured PTT command for this radio.
     *
     * PTTCmd = "default":
     *     PTT ON  -> T 1
     *     PTT OFF -> T 0
     *
     * PTTCmd != "default":
     *     PTT ON  -> PTTCmd exactly as stored in MySettings
     *                (for example: w TX1)
     *     PTT OFF -> T 0
     */
    $pttCmd = "default";

    require "sqldata.php";

    $mysqli = new mysqli(
        "localhost",
        $sql_radio_username,
        $sql_radio_password,
        $sql_radio_database
    );

    if ($mysqli->connect_errno) {
        doLog("FastCAT PTT DB connection failed: " . $mysqli->connect_error);
    } else {
        $stmt = $mysqli->prepare(
            "SELECT PTTCmd FROM MySettings WHERE Radio = ? LIMIT 1"
        );

        if ($stmt) {
            $stmt->bind_param("i", $radio);

            if ($stmt->execute()) {
                $stmt->bind_result($dbPTTCmd);

                if ($stmt->fetch()) {
                    $candidate = trim((string) $dbPTTCmd);

                    if ($candidate !== "") {
                        $pttCmd = $candidate;
                    }
                }
            }

            $stmt->close();
        }

        $mysqli->close();
    }

    /*
     * A custom PTTCmd applies only when going to TX.
     * RX always uses the normal Hamlib PTT-off command.
     */
    if ($ptt === 1 && strtolower($pttCmd) !== "default") {
        $rigCommand = $pttCmd;
    } else {
        $rigCommand = "T " . $ptt;
    }

    doLog("FastCAT PTT radio=$radio state=$ptt command=[$rigCommand]");

    /*
     * Feed the command to rigctl on stdin rather than making the
     * database value part of a shell command.
     */
    $process = proc_open(
        ["rigctl", "-m", "2", "-r", $port],
        [
            0 => ["pipe", "r"],
            1 => ["pipe", "w"],
            2 => ["pipe", "w"],
        ],
        $pipes
    );

    if (is_resource($process)) {
        fwrite($pipes[0], $rigCommand . "\n");
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $status = proc_close($process);

        if ($status === 0) {
            echo json_encode([
                "status" => "success",
                "ptt" => $ptt,
                "command" => $rigCommand,
            ]);
        } else {
            doLog(
                "FastCAT PTT FAILED radio=$radio state=$ptt " .
                    "port=[$port] command=[$rigCommand] status=$status " .
                    "stdout=[" .
                    trim($stdout) .
                    "] stderr=[" .
                    trim($stderr) .
                    "]"
            );
            http_response_code(502);
            echo json_encode([
                "status" => "error",
                "message" => trim($stderr . " " . $stdout),
            ]);
        }
    } else {
        doLog("FastCAT PTT FAILED: unable to start rigctl");

        http_response_code(502);
        echo json_encode([
            "status" => "error",
            "message" => "Unable to start rigctl",
        ]);
    }

    exit();
}

$freq = isset($_POST["freq"]) ? intval($_POST["freq"]) : null;
// Optional mode/bw. When absent, behavior is byte-identical to the freq-only
// original — existing tune calls that send no mode are unaffected.
$mode = isset($_POST["mode"])
    ? preg_replace("/[^A-Za-z0-9\-]/", "", $_POST["mode"])
    : "";
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
