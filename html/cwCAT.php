<?php
/*
 * Send a RigPi CW or keyer-setting command to rigctld.
 * Argument format: host:port|command|value
 */

$payload = isset($argv[1]) ? (string) $argv[1] : "";
$data = explode("|", $payload, 3);
if (count($data) !== 3 || $data[0] === "" || $data[1] === "") {
    fwrite(STDERR, "Invalid cwCAT command\n");
    exit(2);
}

[$target, $command, $value] = $data;
$command = trim($command);
if (!in_array($command, ["b", "L"], true)) {
    fwrite(STDERR, "Unsupported cwCAT command\n");
    exit(2);
}

$rigctlArgs = ["rigctl", "-m", "2", "-r", $target, $command];
if ($command === "L") {
    $levelParts = preg_split('/\s+/', trim($value), 2);
    if (count($levelParts) !== 2) {
        fwrite(STDERR, "Invalid cwCAT level command\n");
        exit(2);
    }
    $rigctlArgs[] = $levelParts[0];
    $rigctlArgs[] = $levelParts[1];
} else {
    $rigctlArgs[] = $value;
}

// Passing an argument array to proc_open bypasses the shell.  CW punctuation
// such as apostrophes is therefore delivered literally and cannot corrupt the
// command line.
$process = proc_open(
    $rigctlArgs,
    [
        0 => ["pipe", "r"],
        1 => ["pipe", "w"],
        2 => ["pipe", "w"],
    ],
    $pipes
);
if (!is_resource($process)) {
    fwrite(STDERR, "Unable to start rigctl\n");
    exit(1);
}

fclose($pipes[0]);
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$status = proc_close($process);

if ($stdout !== "") {
    echo $stdout;
}
if ($stderr !== "") {
    fwrite(STDERR, $stderr);
}
exit($status);
?>
