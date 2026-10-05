<?php

/**
 * @author Howard Nurse, W6HN.
 *
 * This routine sets desired frequency memory
 *
 * It must live in the programs folder
 */
//return;
ini_set("error_reporting", E_ALL);
ini_set("display_errors", 1);
$tMyRadio = $_POST["radio"];
$tMain = $_POST["main"];
$tSub = ""; //$_POST['sub'];
$tMode = $_POST["mode"];
$tBW = $_POST["bw"];
$dRoot = "/var/www/html";
if (
    strstr((string) $tMain, "UNK") > 0 ||
    strstr((string) $tMain, "0000000000") > 0 ||
    strstr((string) $tSub, "UNK") > 0 ||
    strstr((string) $tBW, "UNK") > 0 ||
    strstr((string) $tMyRadio, "UNK") > 0
) {
    exit();
}

require_once $dRoot . "/programs/GetBand.php";
require_once $dRoot . "/programs/sqldata.php";
require_once $dRoot . "/classes/MysqliDb.php";

$db = new MysqliDb(
    "localhost",
    $sql_radio_username,
    $sql_radio_password,
    $sql_radio_database
);
$tMainBand = GetBandFromFrequency($tMain) . "L";
//	echo $tMainBand."\n";
$data = [
    $tMainBand => ltrim($tMain, "0"),
    $tMainBand . "M" => $tMode,
    $tMainBand . "BW" => $tBW,
];
$db->where("Number", $tMyRadio);
$db->update("FrequencyMemory", $data);
echo "OK";
?>
