<?php
// session_start();
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);
//
function disRadio($tMyRadio, $tUserName, $tMyRotor, $tMyInstance)
{
    $dRoot = "/var/www/html";
    $sql_radio_username = "ham";
    $sql_radio_password = "7388";
    require_once $dRoot . "/classes/MysqliDb.php";
    require $dRoot . "/programs/sqldata.php";
    // FORCE LOCAL NETWORK OVERRIDE
    $db = new MysqliDb(
        "127.0.0.1",
        $sql_radio_username,
        $sql_radio_password,
        $sql_radio_database,
        3306,
        "utf8",
        null // <--- LEAVE THIS NULL (DO NOT USE SOCKET)
    );
    $tTable = "RadioInterface";
    $db->where("Radio", $tMyRadio);
    $data = ["MainIn" => "OFF", "SubIn" => "OFF"];
    $id = $db->update($tTable, $data);
    $instance = preg_replace("/[^0-9]/", "", (string) $tMyInstance);
    $pattern = "rigctld.*[i]nstance=" . $instance;
    exec("/usr/bin/pkill -f " . escapeshellarg($tMyInstance), $output, $status);

    error_log("tMyInstance=[" . $tMyInstance . "]");
    error_log("pkill status=" . $status);
    error_log("pkill output=" . implode(" | ", $output));
    sleep(2);
}
?>
