<?php

/**
 * @author Howard Nurse, W6HN
 *
 * This routine sets the settings data
 *
 * It must live in the programs folder
 */
if (isset($_POST["field"])) {
    $tField = $_POST["field"];
    $tRadio = $_POST["radio"];
    $tData = $_POST["data"];
    $tTable = $_POST["table"];
} else {
    $tField = "IsAlive";
    $tRadio = "1";
    $tData = "1";
    $tTable = "RadioInterface";
}
$elmerDxSessionId = "";
$elmerDxCall = "";
$elmerRefreshCallbook = false;
if ($tField === "DX" && $tTable === "MySettings") {
    $candidate = strtoupper(trim((string) $tData));
    if (
        preg_match(
            '/^(?:[A-Z0-9]{1,3}\/)?[A-Z0-9]{1,3}\d[A-Z]{1,4}(?:\/[A-Z0-9]{1,4})?$/',
            $candidate
        )
    ) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (!empty($_SESSION["myUsername"])) {
            $elmerDxSessionId = session_id();
            $elmerDxCall = $candidate;
        }
        session_write_close();
    }
}
ini_set("error_reporting", E_ALL);
ini_set("display_errors", 1);
require "sqldata.php";
require_once "/var/www/html/classes/MysqliDb.php";
$db = new MysqliDb(
    "localhost",
    $sql_radio_username,
    $sql_radio_password,
    $sql_radio_database
);
if ($elmerDxCall !== "") {
    $db->where("Radio", $tRadio);
    $existingDxRow = $db->getOne("MySettings");
    $existingDx = strtoupper(trim((string) ($existingDxRow["DX"] ?? "")));
    $elmerRefreshCallbook = $existingDx !== $elmerDxCall;
}
if ($tRadio != 0) {
    $db->where("Radio", $tRadio);
}
$db->where("Radio", $tRadio);
$dT = $db->getOne("RadioInterface");

//$row=$db->getOne($tTable);
//$db->setLockMethod("WRITE")->lock("RadioInterface");
if ($tField == "MainOut") {
    $data = ["MainOut" => $tData, "MainOutCk" => "1"];

    // $db->where("MainOutCk",0);
} elseif ($tField == "SubOut") {
    $data = [
        "Test" => $tData,
        "SubIn" => $tData,
        "SubOut" => $tData,
        "SubOutCk" => 1,
    ];
} elseif ($tField == "ModeOut") {
    $data = ["ModeOut" => $tData, "ModeOutCk" => "1"];
} elseif ($tField == "Test") {
    $db->where("Radio", $tRadio);
    $data = ["Test" => $tData];
} elseif ($tField == "BWOut") {
    $data = ["BWOut" => $tData, "BWOutCk" => "1"];
} elseif ($tField == "SplitOut") {
    $data = ["SplitOut" => $tData, "SplitOutCk" => "1"];
} elseif ($tField == "PTTOut") {
    $data = ["PTTIn" => $tData, "PTTOut" => $tData, "PTTOutCk" => "1"];
} elseif ($tField == "CommandOut") {
    $data = ["CommandOut" => $tData, "CommandOutCk" => "1"];
} elseif ($tField == "RFGain") {
    $data = ["RFGain" => $tData, "RFGainCk" => "1"];
} elseif ($tField == "AFGain") {
    $data = ["AFGain" => $tData, "AFGainCk" => "1"];
    $db->where("Radio", $tRadio);
} elseif ($tField == "USBAFGain") {
    $data = ["USBAFGain" => $tData, "USBAFGainCk" => "1"];
    $db->where("Radio", $tRadio);
} elseif ($tField == "MicLvl") {
    $data = ["MicLvl" => $tData, "MicLvlCk" => "1"];
} elseif ($tField == "PwrOut") {
    $data = ["PwrOut" => $tData, "PwrOutCk" => "1"];
} elseif ($tField == "Slave") {
    $data = ["Slave" => $tData, "SlaveCk" => "1"];
} elseif ($tField == "CWIn") {
    $data = ["CWIn" => $tData];
} elseif ($tField == "CWOut") {
    $data = ["CWOut" => $tData];
} elseif ($tField == "IsAlive") {
    $data = ["IsAlive" => $tData];
} elseif ($tField == "WKRemoteIP") {
    $db->where("Radio", $tRadio);
    $tTable = "Keyer";
    $data = ["WKRemoteIP" => $tData];
    $db->update($tTable, $data);
    $tTable = "RadioInterface";
    $data = [
        "CWChangeCk" => 1,
    ];
} elseif ($tField == "WKRemotePort") {
    $db->where("Radio", $tRadio);
    $tTable = "Keyer";
    $data = ["WKRemotePort" => $tData];
    $db->update($tTable, $data);
    $tTable = "RadioInterface";
    $data = [
        "CWChangeCk" => 1,
    ];
} elseif ($tField == "WKSpeed") {
    $db->where("Radio", $tRadio);
    $tTable = "Keyer";
    $data = ["WKSpeed" => $tData];
    $db->update($tTable, $data);
    $tTable = "RadioInterface";
    $data = [
        "CWChangeCk" => 1,
    ];
} elseif ($tField == "CWInWK") {
    $db->where("Radio", $tRadio);
    $db->update($tTable, $tData);
    $tTable = "RadioInterface";
    $data = [
        "CWInWKCk" => 1,
    ];
} elseif ($tField == "waitReset") {
    $db->where("Radio", $tRadio);
    $data = [$tField => $tData];
} else {
    $data = [$tField => $tData];
}
$db->where("Radio", $tRadio);

try {
    $id = $db->update($tTable, $data);
} catch (mysqli_sql_exception $e) {
    error_log(
        "SetSettings FAILED: Radio=[" .
            $tRadio .
            "] Field=[" .
            $tField .
            "] Data=[" .
            print_r($tData, true) .
            "] Length=[" .
            strlen((string) $tData) .
            "] Table=[" .
            $tTable .
            "] Error=[" .
            $e->getMessage() .
            "]"
    );

    http_response_code(500);
    exit("Database update failed");
}

$id = $db->update($tTable, $data);
$id = $db->update($tTable, $data);
if ($elmerRefreshCallbook && $elmerDxSessionId !== "") {
    $payload = json_encode([
        "call" => $elmerDxCall,
        "include" => ["biography", "details"],
        "provider" => "auto",
    ]);
    $context = stream_context_create([
        "http" => [
            "method" => "POST",
            "timeout" => 20,
            "ignore_errors" => true,
            "header" =>
                "Content-Type: application/json\r\n" .
                "Cookie: PHPSESSID=" .
                $elmerDxSessionId .
                "\r\n",
            "content" => $payload,
        ],
    ]);
    $result = @file_get_contents(
        "http://127.0.0.1/programs/ElmerCallbook.php",
        false,
        $context
    );
    if (!is_string($result)) {
        error_log(
            "SetSettings: background callbook refresh failed for " .
                $elmerDxCall
        );
    }
}
//echo "field: " . $tField . " " . print_r($tData) . " " . $tTable . " " . $id;

//$db->unlock();
//$d = $db->getOne($tTable);
//$e = $db->$d["MainIn"];
//$db->where("Radio", $tRadio);
//$dT = $db->getOne("RadioInterface");
//echo "\nAfter: " . $dT["MainOut"] . "\n";
//if ($id){
//echo "\n".$id . "\nOK: " . print_r($data) . "\n";
//not supported  $db->query("UNLOCK TABLES;");
?>
