<?php
/**
 * @author Howard Nurse, W6HN
 *
 * This routine gets spots from the cluster, loads them into db
 *
 * It must live in the html folder
 */

ini_set("error_reporting", E_ALL);
ini_set("display_errors", 1);
$dRoot = "/var/www/html";
require_once "/var/www/html/classes/phpTelnet.php";
require_once $dRoot . "/programs/GetBand.php";
require_once $dRoot . "/programs/GetSettingsFunc.php";
require_once $dRoot . "/programs/GetClusterFunc.php";
require_once $dRoot . "/programs/GetDXCC.php";
require_once $dRoot . "/programs/getModeFromFrequency.php";
require $dRoot . "/programs/sqldata.php";
require_once $dRoot . "/classes/MysqliDb.php";
$db = new MysqliDb(
    "localhost",
    $sql_radio_username,
    $sql_radio_password,
    $sql_radio_database
);
$watchCallsign = "";
$tMyRadio = "";

function sendPushover(
    $token,
    $user,
    $title,
    $message,
    $priority = 0,
    $url = "",
    $url_title = "",
    $sound = "intermission"
) {
    $ch = curl_init("https://api.pushover.net/1/messages.json");
    $fields = [
        "token" => $token,
        "user" => $user,
        "title" => $title,
        "message" => $message,
        "priority" => $priority,
        "sound" => $sound,
    ];
    if ($url) {
        $fields["url"] = $url;
    }
    if ($url_title) {
        $fields["url_title"] = $url_title;
    }
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    curl_exec($ch);
    curl_close($ch);
}

function isNeeded($db, $tDX, $tDXCC, $tBand, $tNeed, $logName)
{
    global $sql_radio_username,
        $sql_radio_password,
        $sql_radio_database,
        $tMyRadio;
    if (!$tNeed || $tNeed == "none" || $tNeed == "") {
        return false;
    }
    if (!$tNeed || $tNeed == "none" || $tNeed == "") {
        return false;
    }
    $tBandM = $tBand . "M";
    $dbLog = new MysqliDb(
        "localhost",
        $sql_radio_username,
        $sql_radio_password,
        $sql_radio_database
    );
    switch ($tNeed) {
        case "callWorked":
            $dbLog->where("Callsign", $tDX);
            break;
        case "callWorkedBand":
            $dbLog->where("Callsign", $tDX);
            $dbLog->where("(Band = '$tBand' or Band = '$tBandM')");
            break;
        case "callConfirmed":
            $dbLog->where("Callsign", $tDX);
            $dbLog->where("QSL_R", "Y");
            break;
        case "callConfirmedBand":
            $dbLog->where("Callsign", $tDX);
            $dbLog->where("QSL_R", "Y");
            $dbLog->where("(Band = '$tBand' or Band = '$tBandM')");
            break;
        case "entityWorked":
            $dbLog->where("DXCC", $tDXCC);
            break;
        case "entityWorkedBand":
            $dbLog->where("DXCC", $tDXCC);
            $dbLog->where("(Band = '$tBand' or Band = '$tBandM')");
            break;
        case "entityConfirmed":
            $dbLog->where("DXCC", $tDXCC);
            $dbLog->where("QSL_R", "Y");
            break;
        case "entityConfirmedBand":
            $dbLog->where("DXCC", $tDXCC);
            $dbLog->where("(QSL_R = 'Y')");
            $dbLog->where("(Band = '$tBand' or Band = '$tBandM')");
            break;
        case "watchCall":
            // watchCall stores the target callsign in SpotBand field
            $dbSet2 = new MysqliDb(
                "localhost",
                $sql_radio_username,
                $sql_radio_password,
                $sql_radio_database
            );
            $dbSet2->where("Radio", $tMyRadio);
            $watchRow = $dbSet2->getOne("MySettings", "WatchCall");
            $watchCallsign = trim($watchRow["WatchCall"] ?? "");
            return !empty($watchCallsign) &&
                strtoupper($tDX) == strtoupper($watchCallsign);

        default:
            return false;
    }
    if ($logName && $logName != "ALL Logs") {
        $dbLog->where("Logname", $logName);
    }
    $dbLog->getOne("Logbook");
    // "needed" means NOT in log for entity, IS in log for call filters
    if (strpos($tNeed, "entity") === 0) {
        return $dbLog->count == 0; // entity not worked/confirmed = needed
    } else {
        return $dbLog->count == 0; // call not worked/confirmed = needed
    }
}

use IDCT\Net\PhpTelnet;
if (isset($argv[1])) {
    $tMyCall = $argv[1];
} else {
    $tMyCall = "W6HN";
}
if (isset($argv[2])) {
    $tMyRadio = $argv[2];
} else {
    $tMyRadio = "radio1";
}
$tMyRadio = substr($tMyRadio, strlen((string) ($tMyRadio ?? "")) - 1);
if (isset($_POST["openClose"])) {
    $tOpenClose = $_POST["openClose"];
} else {
    $tOpenClose = "open";
}
$clusterID = GetField($tMyRadio, "ClusterID", "MySettings");
$clusterRetain = GetField($tMyRadio, "RetainTime", "MySettings");
$clusterIP = GetCluster($clusterID, "IP", "Clusters");
$clusterPort = GetCluster($clusterID, "Port", "Clusters");
$telnet = "";
$keepGoing = 1;
if (strlen((string) ($tOpenClose ?? "")) > 0) {
    if ($tOpenClose == "open") {
        $telnet = new PhpTelnet();
        $keepGoing = 1;
        $telnet->connect($clusterIP, $clusterPort);
        //		echo $telnet->getlastErrorDescription();
    } else {
        $keepGoing = 0;
        $telnet->write("QUIT");
        //		echo $telnet->getlastErrorDescription();
        return;
    }
} else {
    $keepGoing = 1;
    $telnet = new PhpTelnet();
    $keepGoing = 1;
    $telnet->connect($clusterIP, $clusterPort);
    //		echo $telnet->getlastErrorDescription();
}

while ($keepGoing == 1) {
    $cmdResult = $telnet->read();
    if ($cmdResult == "login:" || strpos($cmdResult, "call:") > 0) {
        $telnet->writeln($tMyCall);
    }
    //	$s="DX de EA3AVQ: 10489520.0  YB5QZ        QO 100 CW                      1330Z"
    //	$s="DX de TA2NC:    144174.0  G4DCV        <ES> FT8 -13 dB 2014 Hz        1326Z";
    //	echo $cmdResult."\n";
    if (strpos($cmdResult, "DX de") === 0) {
        $tFrom = substr($cmdResult, strpos($cmdResult, "de") + 3);
        $tFrom = substr($tFrom, 0, strpos($tFrom, ":"));
        $tFreq = substr($cmdResult, strpos($cmdResult, ":") + 1);
        $tFreq1 = trim(substr($tFreq, 0, strpos($tFreq, ".") + 2));
        $tFreq1 = str_replace(".", "", $tFreq1) . "00";
        $tDX = trim(substr($cmdResult, strpos($cmdResult, ".") + 3));
        $tDX = trim(substr($tDX, 0, strpos($tDX, " ")));
        //		$tDX="x".$tDX;
        $tNote = "";
        $tNote = substr($cmdResult, strpos($cmdResult, ".") + 13);
        $tNote = trim(substr($tNote, 0, 16));
        $tNote = str_replace("<", "&lt", $tNote);
        $tNote = str_replace(">", "&gt", $tNote);
        $tTime = substr($cmdResult, strpos($cmdResult, ".") + 48);
        $tTime = trim(substr($tTime, 0, 4));
        $tBand = GetBandFromFrequency($tFreq1);
        $tDXCC = GetLocationData($tDX);
        $aDXCC = explode("|", $tDXCC);
        $tDist = getDXDistance($aDXCC[3], $aDXCC[4]);
        $aDist = explode("|", $tDist);
        $tSpotter = GetLocationData($tFrom);
        $aSpotter = explode("|", $tSpotter);
        $tSDist = getDXDistance($aSpotter[3], $aSpotter[4]);
        $aSDist = explode("|", $tSDist);
        if ($aDXCC[1] == 291) {
        }

        $data = [
            "Radio" => $tMyRadio,
            "Folder" => "Inbox",
            "Spotter" => $tFrom,
            "Frequency" => $tFreq1,
            "Band" => $tBand,
            "DX" => $tDX,
            "Webtime" => $tTime,
            "Webdate" => time(),
            "Note" => $tNote,
            "Source" => "Telnet",
            "Longitude" => $aDXCC[4],
            "Latitude" => $aDXCC[3],
            "Email" => "0",
            "Push" => "0",
            "Hide" => "0",
            "Tune" => "0",
            "County" => "",
            "State" => "",
            "Country" => $aDXCC[2],
            "Continent" => $aDXCC[6],
            "DXCC" => $aDXCC[1],
            "Mode" => GetMode($tFreq1),
            "DXBearing" => $aDist[3],
            "DXDistance" => $aDist[1],
            "SpotterContinent" => "",
            "Usecolor" => "0",
            "Backcolor" => 0xfff,
            "Forecolor" => 0x0,
            "SpotterContinent" => $aSpotter[6],
            "SpotterDistance" => $aSDist[1],
        ];
        try {
            $dbIns = new MysqliDb(
                "localhost",
                $sql_radio_username,
                $sql_radio_password,
                $sql_radio_database
            );
            $dbIns->insert("Spots", $data);
        } catch (Exception $e) {
        }
        // Get admin's Pushover app token (shared by all users)
        $dbAdmin = new MysqliDb(
            "localhost",
            $sql_radio_username,
            $sql_radio_password,
            $sql_radio_database
        );
        $dbAdmin->where("uID", 1);
        $adminRow = $dbAdmin->getOne("Users", ["PushoverToken"]);
        $adminToken = trim($adminRow["PushoverToken"] ?? "");

        // ELMER: Check if spot is needed and send Pushover notification
        $dbUsers = new MysqliDb(
            "localhost",
            $sql_radio_username,
            $sql_radio_password,
            $sql_radio_database
        );
        $dbUsers->where("Active", "1");
        $allUsers = $dbUsers->get(
            "Users",
            null,
            "uID, MyCall, Username, Access_Level, SelectedRadio, PushoverToken, PushoverUser, PushoverNotify, PushoverDelay, PushoverLast"
        );

        if ($allUsers) {
            foreach ($allUsers as $uRow) {
                $pushToken = trim($uRow["PushoverToken"] ?? "");
                $pushUser = trim($uRow["PushoverUser"] ?? "");
                $pushNotify = trim($uRow["PushoverNotify"] ?? "none");
                $pushDelay = intval($uRow["PushoverDelay"] ?? 60);
                $pushLast = intval($uRow["PushoverLast"] ?? 0);
                $userLevel = intval($uRow["Access_Level"] ?? 2);
                $userRadio = intval($uRow["SelectedRadio"] ?? 1);
                $username = $uRow["Username"] ?? "admin";

                if (!$adminToken || !$pushUser) {
                    continue;
                }
                $spotNotify = in_array($pushNotify, [
                    "spots",
                    "both+spots",
                    "login+spots",
                    "logout+spots",
                ]);
                if (!$spotNotify) {
                    continue;
                }
                if (time() - $pushLast < $pushDelay) {
                    continue;
                }

                // Get user's own SpotNeed from their radio's MySettings
                $dbSet = new MysqliDb(
                    "localhost",
                    $sql_radio_username,
                    $sql_radio_password,
                    $sql_radio_database
                );
                $dbSet->where("Radio", $userRadio);
                $setRow = $dbSet->getOne(
                    "MySettings",
                    "SpotNeed, LogName, WatchCall"
                );
                $tNeed = $setRow["SpotNeed"] ?? "";
                $logName = $setRow["LogName"] ?? "ALL Logs";
                $watchCallsign = trim($setRow["WatchCall"] ?? "");

                if (!isNeeded($db, $tDX, $aDXCC[1], $tBand, $tNeed, $logName)) {
                    continue;
                }

                // Build notification
                $freqMHz = number_format($tFreq1 / 1000000, 3);
                $mode = GetMode($tFreq1);
                $country = $aDXCC[2] ?? "";
                $cont = $aDXCC[6] ?? "";
                $bearing = $aDist[3] ?? "";
                $dist = $aDist[1] ?? "";

                // Need rule label
                $needLabels = [
                    "callWorked" => "Call Worked",
                    "callConfirmed" => "Call Confirmed",
                    "callWorkedBand" => "Call Worked this Band",
                    "callConfirmedBand" => "Call Confirmed this Band",
                    "entityWorked" => "Entity NOT Worked",
                    "entityConfirmed" => "Entity NOT Confirmed",
                    "entityWorkedBand" => "Entity NOT Worked this Band",
                    "entityConfirmedBand" => "Entity NOT Confirmed this Band",
                    "watchCall" => "Watching for Call",
                ];
                $needLabel = $needLabels[$tNeed] ?? $tNeed;

                // Find other current spots for same DX on different freqs
                $dbOther = new MysqliDb(
                    "localhost",
                    $sql_radio_username,
                    $sql_radio_password,
                    $sql_radio_database
                );
                $dbOther->where("DX", $tDX);
                $dbOther->where("Frequency", $tFreq1, "!=");
                $dbOther->where("Hide", "0");
                $otherSpots = $dbOther->get(
                    "Spots",
                    null,
                    "Frequency, Band, Mode, Spotter"
                );

                $title = "DX: $tDX — $needLabel";
                $message = "$tDX on {$tBand}m — $freqMHz MHz $mode\n$country ($cont)\nBearing {$bearing}° · {$dist}km\nSpotter: $tFrom";
                if ($otherSpots && count($otherSpots) > 0) {
                    $message .= "\n\nAlso spotted:";
                    foreach ($otherSpots as $os) {
                        $oFreq = number_format($os["Frequency"] / 1000000, 3);
                        $message .= "\n{$os["Band"]}m {$oFreq} MHz {$os["Mode"]} via {$os["Spotter"]}";
                    }
                }
                // Build tune URL for Pushover click-to-tune
                $tuneUrl =
                    "http://rigpi5.local/tuneTo.php?freq={$tFreq1}&user=" .
                    urlencode($username) .
                    "&dx=" .
                    urlencode($tDX);
                $tuneTitle = "Tune to $tDX on $freqMHz MHz";

                sendPushover(
                    $adminToken,
                    $pushUser,
                    $title,
                    $message,
                    0,
                    $tuneUrl,
                    $tuneTitle
                );

                // Update PushoverLast timestamp
                $dbLast = new MysqliDb(
                    "localhost",
                    $sql_radio_username,
                    $sql_radio_password,
                    $sql_radio_database
                );
                $dbLast->where("uID", $uRow["uID"]);
                $dbLast->update("Users", ["PushoverLast" => time()]);
            }
        }
        // END ELMER Pushover

        $now = time();

        // Build the DELETE query
        $dbDel = new MysqliDb(
            "localhost",
            $sql_radio_username,
            $sql_radio_password,
            $sql_radio_database
        );
        $sql =
            "DELETE FROM Spots WHERE Webdate <= " .
            ($now - 60 * $clusterRetain);
        $dbDel->query($sql);
    }
}

function getDXDistance($hisLat, $hisLon)
{
    $dRoot = "/var/www/html";
    require_once $dRoot . "/programs/GetDistanceFunc.php";
    require $dRoot . "/programs/sqldata.php";
    require_once $dRoot . "/classes/MysqliDb.php";
    $user = 2;
    $db = new MysqliDb(
        "localhost",
        $sql_radio_username,
        $sql_radio_password,
        $sql_radio_database
    );
    $db->where("User", "2");
    $rowDist = $db->getOne("Callbook");
    $dxlat = $hisLat;
    $dxlon = $hisLon;
    $db->where("uID", $user);
    $rowDist = $db->getOne("Users");
    $mylat = $rowDist["My_Latitude"];
    $mylon = $rowDist["My_Longitude"];
    $dist = getDistance($dxlat, $dxlon, $mylat, $mylon);
    return $dist;
}

?>
