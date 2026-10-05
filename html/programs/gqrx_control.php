<?php
// Simple endpoint to send commands to Gqrx TCP remote port
$host = '127.0.0.1';
$port = 7356;

if (!isset($_GET['freq']) && !isset($_GET['mode'])) {
    http_response_code(400);
    echo "Specify freq and/or mode";
    exit;
}

$cmds = [];
if (isset($_GET['freq'])) {
    $cmds[] = "F ".$_GET['freq']; // frequency in Hz
}
if (isset($_GET['mode'])) {
    $cmds[] = "M ".$_GET['mode']; // mode: AM, FM, USB, LSB, CW
}

$socket = fsockopen($host, $port, $errno, $errstr, 1);
if (!$socket) {
    http_response_code(500);
    echo "Cannot connect to Gqrx: $errstr ($errno)";
    exit;
}

foreach ($cmds as $cmd) {
    fwrite($socket, $cmd."\n");
}
fclose($socket);
echo "OK";
