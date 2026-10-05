<?php

/**
 * @author Howard Nurse, W6HN
 *
 * This routine updates a user or adds a new user if the id=0.
 *
 * It must live in the programs folder
 */
ini_set("error_reporting", E_ALL);
ini_set("display_errors", 1);
require "/var/www/html/programs/sqldata.php";
require_once "/var/www/html/classes/MysqliDb.php";
//require_once "/var/www/html/programs/provision_sdr_user.php";

$db = new MysqliDb(
    "localhost",
    $sql_radio_username,
    $sql_radio_password,
    $sql_radio_database
);
if (!$db) {
    die("Connection failed: " . mysqli_connect_error());
}
$refID = $_POST["refID"];
$defaultSdrHost = static function ($uid) {
    return "http://" . gethostname() . ".local/sdr" . max(1, (int) $uid);
};
$tPWD = $_POST["Password"];
$tCall = strtoupper($_POST["MyCall"]);
// Safe defaults for fields not always sent by all forms
$_POST["GoogleAPIKey"] = $_POST["GoogleAPIKey"] ?? "";
$_POST["SDRHostRemote"] = $_POST["SDRHostRemote"] ?? "";
$_POST["SDRPort"] = $_POST["SDRPort"] ?? 8001;
$_POST["SDRPosition"] = $_POST["SDRPosition"] ?? "none";
$_POST["SDRPage"] = $_POST["SDRPage"] ?? "none";
$Fl = $_POST["LogFldigi"];
$WS = $_POST["LogWSJTX"];
if ($Fl == 1) {
    //clears all log fields since only one instance can sync logs
    $tD = [
        "LogFldigi" => 0,
    ];
    $db->update("Users", $tD);
}

if ($WS == 1) {
    //clears all log fields since only one instance can sync logs
    $tD = [
        "LogWSJTX" => 0,
    ];
    $db->update("Users", $tD);
}
if (strlen((string) ($tPWD ?? "")) > 0) {
    $tPWD = md5($tPWD);
    if ($refID > 0) {
        $data = [
            "MyCall" => $tCall,
            "Username" => $_POST["Username"],
            "Access_Level" => $_POST["Access_Level"],
            "FirstName" => $_POST["FirstName"],
            "LastName" => $_POST["LastName"],
            "Password" => $tPWD,
            "QTH" => $_POST["QTH"],
            "MyZIP" => $_POST["MyZIP"],
            "MyContinent" => $_POST["MyContinent"],
            "MyCountry" => $_POST["MyCountry"],
            "MyState" => $_POST["MyState"],
            "MyCounty" => $_POST["MyCounty"],
            "MyCity" => $_POST["MyCity"],
            "My_LicenseClass" => $_POST["My_LicenseClass"] ?? "",
            "My_Email" => $_POST["My_Email"],
            "My_Phone" => $_POST["My_Phone"],
            "My_Latitude" => $_POST["My_Latitude"],
            "My_Longitude" => $_POST["My_Longitude"],
            "My_Grid" => $_POST["My_Grid"],
            "Mobile_Lat" => $_POST["Mobile_Lat"],
            "Mobile_Lon" => $_POST["Mobile_Lon"],
            "Mobile_Grid" => $_POST["Mobile_Grid"],
            "qrzPWD" => $_POST["qrzPWD"],
            "qrzUser" => $_POST["qrzUser"],
            "hamqthUser" => $_POST["hamqthUser"] ?? "",
            "hamqthPWD" => $_POST["hamqthPWD"] ?? "",
            "GoogleAPIKey" => $_POST["GoogleAPIKey"],
            "LogFldigi" => $_POST["LogFldigi"],
            "LogWSJTX" => $_POST["LogWSJTX"],
            "WSJTXPort" => $_POST["WSJTXPort"],
            "Theme" => $_POST["Theme"],
            "DeadMan" => $_POST["DeadMan"],
            "Inactivity" => $_POST["Inactivity"],
            "SDRHost" => !empty($_POST["SDRHost"])
                ? $_POST["SDRHost"]
                : $defaultSdrHost($refID),
            "SDRHostRemote" => $_POST["SDRHostRemote"],
            "SDRPort" => $_POST["SDRPort"],
            "SDRPosition" => $_POST["SDRPosition"],
            "SDRPage" => $_POST["SDRPage"],
            "PushoverToken" => $_POST["PushoverToken"] ?? "",
            "PushoverUser" => $_POST["PushoverUser"] ?? "",
            "PushoverNotify" => $_POST["PushoverNotify"] ?? "none",
            "PushoverDelay" => (int) ($_POST["PushoverDelay"] ?? 60),
        ];
        $db->where("uID", $refID);
        if ($db->update("Users", $data)) {
            echo "<br>Username " . $_POST["Username"] . " updated.<p>";
            // Trigger SDR provisioning if account just approved (9->2)
            if (intval($_POST["Access_Level"]) == 2 && $refID >= 3) {
                $chkRow = $db->getOne("Users");
                $db->where("uID", $refID);
                $chkUser = $db->getOne("Users", ["SDRHost", "Username"]);
                $sdrH = $chkUser["SDRHost"] ?? "";
                if (empty($sdrH) || strpos($sdrH, "/sdr") === false) {
                    //                    provision_sdr_user($refID, $_POST["Username"]);
                }
            }
        } else {
            echo "<br>Username must be unique!<p><p>Changes not saved.<p>";
        }
        //    echo mysqli_error($db);
    } else {
        $row = $db->getOne("Users");
        $data = [
            "uID" => $_POST["ID"],
            "SelectedRadio" => $_POST["ID"],
            "MyCall" => $tCall,
            "Username" => $_POST["Username"],
            "Access_Level" => $_POST["Access_Level"],
            "FirstName" => $_POST["FirstName"],
            "LastName" => $_POST["LastName"],
            "Password" => $tPWD,
            "QTH" => $_POST["QTH"],
            "MyZIP" => $_POST["MyZIP"],
            "MyContinent" => $_POST["MyContinent"],
            "MyCountry" => $_POST["MyCountry"],
            "MyState" => $_POST["MyState"],
            "MyCounty" => $_POST["MyCounty"],
            "MyCity" => $_POST["MyCity"],
            "My_LicenseClass" => $_POST["My_LicenseClass"] ?? "",
            "My_Email" => $_POST["My_Email"],
            "My_Phone" => $_POST["My_Phone"],
            "My_Latitude" => $_POST["My_Latitude"],
            "My_Longitude" => $_POST["My_Longitude"],
            "My_Grid" => $_POST["My_Grid"],
            "Mobile_Lat" => $_POST["Mobile_Lat"],
            "Mobile_Lon" => $_POST["Mobile_Lon"],
            "Mobile_Grid" => $_POST["Mobile_Grid"],
            "qrzPWD" => $_POST["qrzPWD"],
            "qrzUser" => $_POST["qrzUser"],
            "hamqthUser" => $_POST["hamqthUser"] ?? "",
            "hamqthPWD" => $_POST["hamqthPWD"] ?? "",
            "GoogleAPIKey" => $_POST["GoogleAPIKey"],
            "LogFldigi" => $_POST["LogFldigi"],
            "LogWSJTX" => $_POST["LogWSJTX"],
            "WSJTXPort" => $_POST["WSJTXPort"],
            "Theme" => $_POST["Theme"],
            "DeadMan" => $_POST["DeadMan"],
            "Inactivity" => $_POST["Inactivity"],
            "SDRHost" => !empty($_POST["SDRHost"])
                ? $_POST["SDRHost"]
                : $defaultSdrHost($_POST["ID"]),
            "SDRHostRemote" => $_POST["SDRHostRemote"],
            "SDRPort" => $_POST["SDRPort"],
            "SDRPosition" => $_POST["SDRPosition"],
            "SDRPage" => $_POST["SDRPage"],
            "PushoverToken" => $_POST["PushoverToken"] ?? "",
            "PushoverUser" => $_POST["PushoverUser"] ?? "",
            "PushoverNotify" => $_POST["PushoverNotify"] ?? "none",
            "PushoverDelay" => (int) ($_POST["PushoverDelay"] ?? 60),
        ];
        if ($db->insert("Users", $data)) {
            echo "<br>Username " . $_POST["Username"] . " added to Users.<p>";
        } else {
            echo "<br>Username must be unique!<p><p>Changes not saved.<p>";
        }
    }
} else {
    if ($refID > 0) {
        $data = [
            "MyCall" => $tCall,
            "Username" => $_POST["Username"],
            "Access_Level" => $_POST["Access_Level"],
            "FirstName" => $_POST["FirstName"],
            "LastName" => $_POST["LastName"],
            "QTH" => $_POST["QTH"],
            "MyZIP" => $_POST["MyZIP"],
            "MyContinent" => $_POST["MyContinent"],
            "MyCountry" => $_POST["MyCountry"],
            "MyState" => $_POST["MyState"],
            "MyCounty" => $_POST["MyCounty"],
            "MyCity" => $_POST["MyCity"],
            "My_LicenseClass" => $_POST["My_LicenseClass"] ?? "",
            "My_Email" => $_POST["My_Email"],
            "My_Phone" => $_POST["My_Phone"],
            "My_Latitude" => $_POST["My_Latitude"],
            "My_Longitude" => $_POST["My_Longitude"],
            "My_Grid" => $_POST["My_Grid"],
            "Mobile_Lat" => $_POST["Mobile_Lat"],
            "Mobile_Lon" => $_POST["Mobile_Lon"],
            "Mobile_Grid" => $_POST["Mobile_Grid"],
            "qrzPWD" => $_POST["qrzPWD"],
            "qrzUser" => $_POST["qrzUser"],
            "hamqthUser" => $_POST["hamqthUser"] ?? "",
            "hamqthPWD" => $_POST["hamqthPWD"] ?? "",
            "GoogleAPIKey" => $_POST["GoogleAPIKey"],
            "LogFldigi" => $_POST["LogFldigi"],
            "LogWSJTX" => $_POST["LogWSJTX"],
            "WSJTXPort" => $_POST["WSJTXPort"],
            "Theme" => $_POST["Theme"],
            "DeadMan" => $_POST["DeadMan"],
            "Inactivity" => $_POST["Inactivity"],
            "SDRHost" => !empty($_POST["SDRHost"])
                ? $_POST["SDRHost"]
                : $defaultSdrHost($refID),
            "SDRHostRemote" => $_POST["SDRHostRemote"],
            "SDRPort" => $_POST["SDRPort"],
            "SDRPosition" => $_POST["SDRPosition"],
            "SDRPage" => $_POST["SDRPage"],
            "PushoverToken" => $_POST["PushoverToken"] ?? "",
            "PushoverUser" => $_POST["PushoverUser"] ?? "",
            "PushoverNotify" => $_POST["PushoverNotify"] ?? "none",
            "PushoverDelay" => (int) ($_POST["PushoverDelay"] ?? 60),
        ];
        $db->where("uID", $refID);
        if ($db->update("Users", $data)) {
            echo "<br>Username " . $_POST["Username"] . " updated.<p>";
            // Trigger SDR provisioning if account just approved (9->2)
            if (intval($_POST["Access_Level"]) == 2 && $refID >= 3) {
                $db->where("uID", $refID);
                $chkUser2 = $db->getOne("Users", ["SDRHost", "Username"]);
                $sdrH2 = $chkUser2["SDRHost"] ?? "";
                if (empty($sdrH2) || strpos($sdrH2, "/sdr") === false) {
                    //                    provision_sdr_user($refID, $_POST["Username"]);
                }
            }
        } else {
            echo "<br>Username must be unique!<p><p>Changes not saved.<p>";
        }
    } else {
        $row = $db->getOne("Users");
        $data = [
            "uID" => $_POST["ID"],
            "SelectedRadio" => $_POST["ID"],
            "MyCall" => $tCall,
            "Username" => $_POST["Username"],
            "Access_Level" => $_POST["Access_Level"],
            "FirstName" => $_POST["FirstName"],
            "LastName" => $_POST["LastName"],
            "Password" => $tPWD,
            "QTH" => $_POST["QTH"],
            "MyZIP" => $_POST["MyZIP"],
            "MyContinent" => $_POST["MyContinent"],
            "MyCountry" => $_POST["MyCountry"],
            "MyState" => $_POST["MyState"],
            "MyCounty" => $_POST["MyCounty"],
            "MyCity" => $_POST["MyCity"],
            "My_LicenseClass" => $_POST["My_LicenseClass"] ?? "",
            "My_Email" => $_POST["My_Email"],
            "My_Phone" => $_POST["My_Phone"],
            "My_Latitude" => $_POST["My_Latitude"],
            "My_Longitude" => $_POST["My_Longitude"],
            "My_Grid" => $_POST["My_Grid"],
            "Mobile_Lat" => $_POST["Mobile_Lat"],
            "Mobile_Lon" => $_POST["Mobile_Lon"],
            "Mobile_Grid" => $_POST["Mobile_Grid"],
            "qrzPWD" => $_POST["qrzPWD"],
            "qrzUser" => $_POST["qrzUser"],
            "hamqthUser" => $_POST["hamqthUser"] ?? "",
            "hamqthPWD" => $_POST["hamqthPWD"] ?? "",
            "GoogleAPIKey" => $_POST["GoogleAPIKey"],
            "LogFldigi" => $_POST["LogFldigi"],
            "LogWSJTX" => $_POST["LogWSJTX"],
            "WSJTXPort" => $_POST["WSJTXPort"],
            "Theme" => $_POST["Theme"],
            "DeadMan" => $_POST["DeadMan"],
            "Inactivity" => $_POST["Inactivity"],
            "SDRHost" => !empty($_POST["SDRHost"])
                ? $_POST["SDRHost"]
                : $defaultSdrHost($_POST["ID"]),
            "SDRHostRemote" => $_POST["SDRHostRemote"],
            "SDRPort" => $_POST["SDRPort"],
            "SDRPosition" => $_POST["SDRPosition"],
            "SDRPage" => $_POST["SDRPage"],
            "PushoverToken" => $_POST["PushoverToken"] ?? "",
            "PushoverUser" => $_POST["PushoverUser"] ?? "",
            "PushoverNotify" => $_POST["PushoverNotify"] ?? "none",
            "PushoverDelay" => (int) ($_POST["PushoverDelay"] ?? 60),
        ];
        if ($db->insert("Users", $data)) {
            echo "<br>Username " . $_POST["Username"] . " added to Users.<p>";
        } else {
            echo "<br>Username must be unique!<p><p>Changes not saved.<p>";
        }
    }
}

//echo "OK";

?>
