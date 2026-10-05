<?php
/*
 * RigPi hamlibDo.php: starts processes from Hamlib
 *
 * Copyright (c) 2025 Howard Nurse, W6HN
 *
 * This source code is licensed under the MIT license found in the
 * LICENSE file in the root directory of this source tree.
 *
 * The radio can be Hamlib Dummy or Hamlib net rigctl or a physical radio.
 * The rigpi server class connects to this rigctl or rigctld.
 */
session_start();
$dRoot = "/var/www/html";
require_once $dRoot . "/programs/sqldata.php";
require_once $dRoot . "/classes/MysqliDb.php";
require_once $dRoot . "/programs/disconnectRadioFunc.php";
sleep(1);
if (isset($_POST["radio"])) {
    //    $test = 0;
    $test = $_POST["test"]; //if 0, normal RSS mode, if 1 run from Terminal after confirm 'else' variables below
} else {
    $test = 1; //if 0, normal RSS mode, if 1 run from Terminal after confirm 'else' variables below
}
if ($test == 1) {
    echo "TEST MODE<p><p>";
}
$useVFOMode = 0;
$tRigPid = 0;
$report = "";
$reportOut = "";
if (isset($_POST["radio"])) {
    $tMyRadio = $_POST["radio"];
} else {
    $tMyRadio = "1";
}
if (isset($_POST["keyer"])) {
    $tMyKeyer = $_POST["keyer"];
} else {
    $tMyKeyer = "rpk1";
}
if (isset($_POST["user"])) {
    $tUsername = $_POST["user"];
} else {
    $tUsername = "admin"; // //only if user not specified
}
if (isset($_POST["port"])) {
    $tMyCWPort = $_POST["port"];
} else {
    $tMyCWPort = "/dev/ttyS0";
}
if (isset($_POST["rotorPort"])) {
    $tMyRotorPort = $_POST["rotorPort"];
} else {
    $tMyRotorPort = 4533;
}
if (isset($_POST["keyerPort"])) {
    $tMyKeyerPort = $_POST["keyerPort"];
} else {
    $tMyKeyerPort = 30040;
}
if (isset($_POST["keyerIP"])) {
    $tMyKeyerIP = $_POST["keyerIP"];
} else {
    $tMyKeyerIP = "127.0.0.43";
}
if (isset($_POST["keyerFunc"])) {
    $tMyKeyerFunc = $_POST["keyerFunc"];
} else {
    $tMyKeyerFunc = 0;
}
if (isset($_POST["tcpPort"])) {
    $tMyTCPPort = $_POST["tcpPort"];
} else {
    $tMyTCPPort = 30001;
}
if (isset($_POST["UDPPort"])) {
    $tMyUDPPort = $_POST["UDPPort"];
} else {
    $tMyUDPPort = 2333;
}

$setConf = "-C auto_power_on=1";
$tMyTCPPort = $tMyTCPPort; // + ($tMyRadio - 1); //allows for connection to any account
$report = "Radio: " . $tMyRadio . "<br>";
$report .= "User: " . $tUsername . "<br>";
$tClear = "cat /dev/null > /var/log/rigpi-radio.log";
exec($tClear);
$tClear = "cat /dev/null > /var/log/rigpi-rotor.log";
exec($tClear);
doLog($test, PHP_EOL . PHP_EOL . "RIGPI RADIO DIAGNOSTIC LOG" . PHP_EOL);
doRotorLog($test, PHP_EOL . PHP_EOL . "RIGPI ROTOR DIAGNOSTIC LOG" . PHP_EOL);
//Get data from MyRadio table
$db = new MysqliDb(
    "localhost",
    $sql_radio_username,
    $sql_radio_password,
    $sql_radio_database
);
$db->where("Radio", $tMyRadio);
$row = $db->getOne("MySettings");
$dRadio = $row["Radio"];
$report .= "Radio from settings: " . $dRadio . "<br>";
$dmodel = $row["Model"];
$port = $row["Port"]; //radio port picked up here
$tMyRotor = $row["Rotor"];
$rotorID = $row["RotorID"];
$rotorName = $row["RotorModel"];
$rotorBaud = $row["RotorBaud"];
$rotorStop = $row["RotorStop"];

if ($rotorBaud == "default") {
    $rotorBaud = "";
} else {
    $rotorBaud = "-s " . $rotorBaud;
}

$tMyRadioPort = $port;
if ($tMyRadioPort > 4530 && $tMyRadioPort < 5000) {
    $tMyCWRadio = $dRadio; //1 + ($tMyRadioPort - 4532) / 2;
} else {
    $tMyCWRadio = $dRadio;
}
if (!isset($_SESSION["myInstance"])) {
    $_SESSION["myInstance"] = 12341;
}
$instance = "Instance=" . $_SESSION["myInstance"];

if ($test == 1) {
    echo $report;
    $u = "radio: " . $tMyRadio . " connection attempt" . PHP_EOL;
    $u .= "	radio port: " . $port . PHP_EOL;
    $u .= "	instance: " . $instance . PHP_EOL;
    $u .= "	keyer: " . $tMyKeyer . PHP_EOL;
    $u .= "	username: " . $tUsername . PHP_EOL;
    $u .= "	cw port: " . $tMyCWPort . PHP_EOL;
    $u .= "	keyer port (remote): " . $tMyKeyerPort . PHP_EOL;
    $u .= "	keyer IP: " . $tMyKeyerIP . PHP_EOL;
    $u .= "	keyer: " . $tMyKeyer . PHP_EOL;
    $u .=
        "	keyer function: " .
        $tMyKeyerFunc .
        " (0=normal,1=radio,2=remote, 3=CTS)" .
        PHP_EOL;
    $u .= "	tcp port: " . $tMyTCPPort . PHP_EOL;
    $u .= "	udp port: " . $tMyUDPPort . PHP_EOL;
    $u .= "	dRadio: " . $dRadio . PHP_EOL;
    $u .= "	tMyCWRadio: " . $tMyCWRadio . PHP_EOL;
    $u .= "	vfoMode: " . $useVFOMode;
    doLog($test, $u);
    $u = "rotor: " . $tMyRotor . " connection attempt" . PHP_EOL;
    $u .= "	rotor port: " . $tMyRotorPort . PHP_EOL;
    $u .= "	rotor ID: " . $rotorID . PHP_EOL;
    $u .= "	rotor Name: " . $rotorName . PHP_EOL;
    $u .= "	rotor Baud: " . $rotorBaud . PHP_EOL;
    $u .= "	rotor Stop: " . $rotorStop . PHP_EOL;
    doRotorLog($test, $u);
    echo "More information is in /var/log/rigpi-radio.log and /var/log/rigpi-rotor.log<p>";
}
$report .= "Port from settings: " . $port . "<br>";
$id = $row["ID"];
$ptt = $row["PTTMode"];
$tInvert = $row["KeyerInvert"];
$db->where("NUMBER", $id);
$row1 = $db->getOne("Radios");
if ($row1["SAMEAS"] > 0) {
    $report .=
        "ID from SAMEAS: " . $id . " is same as " . $row1["SAMEAS"] . "<br>";
    $id = $row1["SAMEAS"];
} else {
    $report .= "ID from settings: " . $id . "<br>";
}
$tBaud = $row["Baud"];
if ($tBaud == "default") {
    $baud = "";
} else {
    $baud = "-s $tBaud";
}
$rd = shell_exec("ps aux | grep '[r]rigctl -'");
$rd1 = $rd;
$rd = str_replace("www", "<br>www", (string) $rd);
if ($test == 1) {
    $report .=
        "<br>To see any error details, click Disconnect Radio, then start rigctl in Terminal with this line: <br><br>" .
        "<b>rigctl -m 1" .
        "</b><br><br>";
    doLog($test, $report);
    $rd = shell_exec("ps aux | grep '[r]igctl'");
    $rd1 = $rd;
    $rd = str_replace("www", "<br>www", (string) $rd);
    if (strlen($rd) == 0) {
        $rd = "NONE (rigctl failed to start)<br>";
    }
    echo $report .
        "rigctl processes running: <br>&nbsp;&nbsp;" .
        $rd .
        "<br><br>";
    doLog($test, "rigctl processes running: " . PHP_EOL . "    " . $rd);
}

//echo "ID $id\n";
$service_port = 0;
$service_host = "";
/*if ($id == 2) {
  if (strpos($port,":")>1){
    $tPort=explode(":", $port);
    $service_host=$tPort[0];
    $service_port=$tPort[1];
  }else{
    $service_port = $port;
  }
} else {
  $service_port = $dRadio * 2 + 4530;
}
*/
doLog($test, "Starting RigDo from exec.");
$tRun = "";
$tw = 1;
$i = 0;
$pr = "";
$i = 0;
//$vfos = shell_exec("rigctl -m 3073 -u | grep '[V]FO list -m 1'");
//if (strstr($vfos, "VFOA")) {
//  $vfoa = "VFOA";
//  $vfob = "VFOB";
//} else {
$vfoa = "Main";
$vfob = "Sub";
//$_SESSION["myInstance"] = "1234" . $tMyRadio;
doLog($test, "Now starting RigPi radio control link.");
$vfoMode = 0;

// A reconnect can submit hamlibDo.php twice before either request records the
// new bridge PID.  Serialize this small launch section by physical radio and
// reuse the bridge that is already running.  This prevents two RigDo loops
// from sending conflicting CAT/PTT commands to one rigctld instance.
function runningRigDoPidsForRadio($radio)
{
    $result = [];
    $radioToken = "radio" . intval($radio);
    foreach (glob("/proc/[0-9]*/cmdline") ?: [] as $cmdlinePath) {
        $cmdline = @file_get_contents($cmdlinePath);
        if ($cmdline === false || strpos($cmdline, "/var/www/html/RigDo.php") === false) {
            continue;
        }
        $args = array_values(array_filter(explode("\0", $cmdline), "strlen"));
        if (in_array($radioToken, $args, true)) {
            $pid = intval(basename(dirname($cmdlinePath)));
            if ($pid > 1) {
                $result[] = $pid;
            }
        }
    }
    sort($result, SORT_NUMERIC);
    return array_values(array_unique($result));
}

$pidRD = 0;
$tRigExec = implode(" ", array_map("escapeshellarg", [
    "/usr/bin/php",
    "/var/www/html/RigDo.php",
    $tUsername,
    "radio" . $dRadio,
    $port,
    $test,
    $vfoa,
    $vfoMode,
    "instance=" . $_SESSION["myInstance"],
])) . " > /dev/null 2>/dev/null & echo $!";
$launchLock = sys_get_temp_dir() . "/rigpi-rigdo-radio-" . intval($dRadio) . ".launch";
$haveLaunchLock = false;
for ($attempt = 0; $attempt < 100; $attempt++) {
    if (@mkdir($launchLock, 0700)) {
        $haveLaunchLock = true;
        break;
    }
    // Recover automatically if a request was interrupted while holding it.
    if (is_dir($launchLock) && time() - intval(@filemtime($launchLock)) > 30) {
        @rmdir($launchLock);
    }
    usleep(50000);
}

if ($haveLaunchLock) {
    try {
        $runningRigDo = runningRigDoPidsForRadio($dRadio);
        if (count($runningRigDo) > 0) {
            $pidRD = $runningRigDo[0];
            doLog($test, "Reusing RigDo pid $pidRD for radio$dRadio.");
            // A previous release may already have created duplicates.  Keep
            // the oldest bridge and retire the extras on the next reconnect.
            foreach (array_slice($runningRigDo, 1) as $duplicatePid) {
                doLog($test, "Stopping duplicate RigDo pid $duplicatePid for radio$dRadio.");
                if (function_exists("posix_kill")) {
                    @posix_kill($duplicatePid, 15);
                } else {
                    exec("kill -TERM " . intval($duplicatePid) . " 2>/dev/null");
                }
            }
        } else {
            doLog($test, $tRigExec);
            $pidRD = intval(exec($tRigExec));
            doLog($test, "Started RigDo pid $pidRD for radio$dRadio.");
        }
    } finally {
        @rmdir($launchLock);
    }
} else {
    doLog($test, "Unable to acquire the RigDo launch lock for radio$dRadio.");
}

$report = "RigPi radio control link PID: <b>" . intval($pidRD) . "</b><br><br>";
doLog($test, "tRigExec pid: " . $pidRD);
usleep(2000000);
$user = exec("ps aux | grep '[R]igDo'");
$report .=
    "RigDo Processes:<br><br>" .
    $user .
    "<br><br>RigDoPID: " .
    $pidRD .
    "; Radio: " .
    $dRadio .
    "; User: " .
    $tUsername .
    "; Keyer: " .
    $tMyKeyer .
    "; CW Port: " .
    $tMyCWPort .
    "<br><br>Now starting RigPi CW control link.<br>";
doLog(
    $test,
    "RigDo Processes:" .
        PHP_EOL .
        "    " .
        $user .
        PHP_EOL .
        "    RigDoPID: " .
        $pidRD .
        "; Radio: " .
        $dRadio .
        "; User: " .
        $tUsername .
        "; Keyer: " .
        $tMyKeyer .
        "; CW Port: " .
        $tMyCWPort .
        PHP_EOL .
        "    Now starting RigPi CW control link."
);
if ($test == 1) {
    echo $report;
}
//$tMyCWPort="/dev/ttyS0";
$tCW =
    $dRadio .
    "," .
    $tMyCWRadio .
    "," .
    $tUsername .
    "," .
    $tMyKeyer .
    "," .
    $tMyCWPort;
$tMyCWRadio = $dRadio;
DoCW($dRadio, $tMyCWRadio, $tUsername, $tMyKeyer, $tMyCWPort, $test);
$report = "Now starting RigPi CW Server, radio is $dRadio and cw is $tMyCWRadio.<br>";
doLog(
    $test,
    "Now starting RigPi CW Server, radio is $dRadio and cw is $tMyCWRadio with command $tCW."
);
if ($test == 1) {
    echo $report;
}
$report = "Now starting RigPi TCP server on port $tMyTCPPort.<br>";
doLog($test, "Now starting RigPi TCP server on port $tMyTCPPort.");
DoTCP($dRadio, $tUsername, $tMyTCPPort);
if ($test == 1) {
    echo $report;
}
DoUDP($dRadio, $tUsername, $tMyUDPPort);
$sp = $service_port + 1;
$report = "Now starting RigPi UDP Server on port $tMyUDPPort.<br><br>";
doLog($test, "Now starting RigPi UDP Server on port $tMyUDPPort.");
if ($tMyKeyerFunc == 0) {
    //this is for rpk /dev/ttyS0
} elseif ($tMyKeyerFunc == 1) {
    //only start if this hamlib is at radio end for manual cw remote
    doLog($test, "Remote CW: $dRadio, $tUsername, $tMyKeyerPort, $tMyCWPort.");
    DoCWUDP($dRadio, $tUsername, $tMyKeyerPort, $tMyCWPort, $test);
    if ($test == 1) {
        $report = "Now starting RigPi remote CW link on UDP port $tMyCWPort.<br><br>";
        echo $report;
        doLog(
            $test,
            "Now starting RigPi remote CW link on UDP port $tMyCWPort."
        );
    }
} elseif ($tMyKeyerFunc == 2) {
    //only start if this hamlib is at remote end for manual cw remote
    DoCWUDP2($tMyKeyerIP, $tMyKeyerPort, $tInvert, $test);
    if ($test == 1) {
        echo "Now starting RigPi Remote end on port: $tMyKeyerPort, ip: $tMyKeyerIP, invert: $tInvert, test: $test " .
            PHP_EOL;
        doLog(
            $test,
            "Now starting RigPi Remote end on port: $tMyKeyerPort, IP: $tMyKeyerIP, invert: $tInvert, test: $test."
        );
    }
} elseif ($tMyKeyerFunc == 3) {
    //only start if using CTS cw
    doLog($test, "Remote CW func 3: $dRadio, $tUsername, $tMyCWPort.");

    if ($test == 1) {
        echo "Now starting RigPi CTS driver on port: $tMyKeyerPort, CW POrt: $tMyCWPort, user: $tUsername, radio: $dRadio, test: $test.<br><br>";
        doLog(
            $test,
            "Now starting RigPi CTS 'ext' driver on port: $tMyKeyerPort, IP: $tMyKeyerIP, user: $tUsername, radio: $dRadio, test: $test."
        );
    }
    DoCWUDP($dRadio, $tUsername, $tMyKeyerPort, $tMyCWPort, $test);
    if ($test == 1) {
        echo "last error: " . error_get_last() . "\n";
    }
}
//  $rotorID="1";
if ($rotorID == "1" || $rotorID == "2") {
    $tRotorPort = $tMyRotorPort;
    $report .= "Rotor: " . $rotorID . " (" . $rotorName . ")<br>";
    $report .= "Rotor port: " . $tMyRotorPort . "<br>";
    doRotorLog(
        $test,
        "Now starting RigPi Rotor Server on port: $tRotorPort using rotor " .
            $rotorID .
            "."
    );
    $tRotorIP = "";
    $tRotorPort = 0;
    if (strpos($tMyRotorPort, ":") > 0) {
        $tR = explode(":", $tMyRotorPort);
        $tRotorPort = $tR[1];
        $tRotorIP = $tR[0];
    } else {
        $tRotorIP = "0.0.0.0";
        $tRotorPort = $tMyRotorPort;
    }
    if ($rotorID == "1") {
        $r = "Hamlib Rotor Dummy";
        if ($tMyRotorPort == "None") {
            $report .=
                "Error: Set " .
                $rotorName .
                " Rotor port to 4531 + 2 * Radio number.\n";
            doRotorLog(
                $test,
                "Error: Set " .
                    $rotorName .
                    " Rotor port to 4531 + 2 * Radio number."
            );
            if ($test == 1) {
                echo $report;
            }
            exit();
        }
        $execDum =
            "rotctld -m 1 -T $tRotorIP -t $tRotorPort " . //////////////////
            " > /dev/null 2> /dev/null &";
    } else {
        $r = "Hamlib Rotor Netctl rotctl";
        if (!$tMyRotorPort > 4532 || $tRotorPort == "None") {
            $report .=
                "Error: Set " .
                $rotorName .
                " Rotor port to 4531 + 2 * other Radio number.\n";
            if ($test == 1) {
                echo $report;
            }
            doRotorLog($test, $report);
            exit();
        }
        $execDum =
            //    "rotctl -m 2 -r 172.16.0.28:4533" .
            "rotctl -m 2 -r $tRotorIP:$tRotorPort " .
            " > /dev/null 2> /dev/null &";
    }
    $report .= "<br>Rotor ID $rotorID (" . $rotorName . ") start attempt.<br>";
    doRotorLog($test, "Rotor ID $rotorID (" . $rotorName . ") start attempt.");
    doRotorLog($test, "exec: $execDum\n");
    shell_exec($execDum);
    if ($test == 1) {
        $report .=
            "To see any Rotor error details, click Disconnect Radio, then start rotctl in Terminal with this line: <br><br><b>" .
            "rotctl -m 1<br><br></b>";
        echo $report;
        $rd = shell_exec("ps aux | grep '[r]otctld -'");
        $rd1 = $rd;
        $rd = str_replace("www", "<br>www", (string) $rd);
        if (strlen($rd) == 0) {
            $rd = "NONE (rotctld failed to start)";
        }
        echo "rotctld processes running: <br>" . $rd . "<br><br>";
        doRotorLog(
            $test,
            "rotctld processes running: " . PHP_EOL . "    " . $rd1
        );
    }
} else {
    $execMe = "rotctld -m $rotorID -r $tMyRotorPort -T localhost -t $sp $rotorBaud > /dev/null 2>/dev/null &";
    //  $execMe = "rotctld -m $rotorID -T $tRotorIP -t $tRotorPort $rotorBaud > /dev/null 2>/dev/null &";
    //echo "\n" . $execMe . "\n";
    if ($test == 1) {
        $execReport = "rotctl -m $rotorID -r $tRotorIP:  $tRotorPort";
        $report =
            "To see any rotctl error details, click Disconnect Radio then start rotctl in Terminal with this line: <br><br><b>" .
            $execReport .
            "</b><br>";
        echo $report;
        doLog($test, $report);
    }
    shell_exec($execMe);
    usleep(100000);
    $rd = shell_exec("ps aux | grep '[r]otctld -'");
    $rd = str_replace("www", "<br>www", (string) $rd);
    if (strlen($rd) > 0) {
        if ($test == 1) {
            $report = "<br>rotctld processes running:<br>" . $rd . "<br><br>";
            echo $report;
            doRotorLog(
                $test,
                "rotctld processes running: " . PHP_EOL . "    " . $rd
            );
        }
    } else {
        $report = "\nError: Rotor control not started, not continuing.\n";
        doRotorLog($test, "Error: Rotor control not started, exiting startup.");
        if ($test == 1) {
            echo $report;
        }
        exit();
    }
}
usleep(100000);
DoRotor($dRadio, $tUsername, "$tRotorIP:$tRotorPort", $test);
$con = new mysqli(
    "localhost",
    $sql_radio_username,
    $sql_radio_password,
    $sql_radio_database
);
if ($con->connect_error) {
    die("Connection failed: " . $con->connect_error);
}
$report =
    "Report is complete. Select the text in this report, copy, then paste it to a destination (such as an email).";
doLog(
    $test,
    "hamlibDo.php is exiting. Reporting continues from RigServer.class.php."
);
doRotorLog(
    $test,
    "hamlibDo.php is exiting. Reporting continues from RotorServer.class.php."
);
if ($test == 1) {
    echo $report;
} else {
    $db->where("Radio", $dRadio);
    $row = $db->getOne("RadioInterface");
    $tFreq = $row["MainIn"];
    if (!$tFreq > 0) {
        usleep(1000000);
        $row = $db->getOne("RadioInterface");
        $tFreq = $row["MainIn"];
    }
    echo $tFreq; //this is important feedback to calling programs confirming connection
}

function DoCW($realRadio, $whichRadio, $Username, $myKeyer, $myCWPort, $myTest)
{
    $portTrans = 0;
    if ($realRadio != $whichRadio && $whichRadio > 0) {
        $portTrans = 1;
    }
    if ($whichRadio == 0) {
        $whichRadio = $realRadio;
    }
    //$myCWPort="localhost:30002";
    $tWKExec =
        "php /var/www/html/CWDo.php " .
        $Username .
        " radio" .
        $whichRadio .
        " " .
        $myKeyer .
        " " .
        $myCWPort .
        " " .
        $portTrans .
        " " .
        $myTest .
        " " .
        $_SESSION["myInstance"] .
        " > /dev/null 2> /dev/null & echo $!";
    //S    $tWKExec="php /var/www/html/CWDo.php admin radio1 rpk1 /dev/ttyS0 0 1 > /dev/null 2> /dev/null & echo $!";

    doLog($myTest, "Entering DoCW function:$tWKExec");
    doLog(
        $myTest,
        "Command to start CW server:" . PHP_EOL . "     " . $tWKExec
    );
    $pidWK = exec($tWKExec);
    doLog($myTest, "CW PID: " . $pidWK);
    require "/var/www/html/programs/vendor/autoload.php";
    system("gpio write 13 1"); //key up
    system("gpio mode 13 out");
}

function startSingletonBridge($script, $whichRadio, $Username, $port, $kind, $extraArgs = [])
{
    $radioToken = "radio" . intval($whichRadio);
    $portToken = (string) intval($port);
    $lock = sys_get_temp_dir() . "/rigpi-" . strtolower($kind) . "-" . $radioToken . ".launch";
    $haveLock = false;
    for ($attempt = 0; $attempt < 100; $attempt++) {
        if (@mkdir($lock, 0700)) {
            $haveLock = true;
            break;
        }
        if (is_dir($lock) && time() - intval(@filemtime($lock)) > 30) {
            @rmdir($lock);
        }
        usleep(50000);
    }
    if (!$haveLock) return 0;

    try {
        $running = [];
        foreach (glob("/proc/[0-9]*/cmdline") ?: [] as $cmdlinePath) {
            $cmdline = @file_get_contents($cmdlinePath);
            if ($cmdline === false || strpos($cmdline, $script) === false) continue;
            $args = array_values(array_filter(explode("\0", $cmdline), "strlen"));
            if (in_array($radioToken, $args, true) && in_array($portToken, $args, true)) {
                $pid = intval(basename(dirname($cmdlinePath)));
                if ($pid > 1) $running[] = $pid;
            }
        }
        sort($running, SORT_NUMERIC);
        if ($running) {
            foreach (array_slice($running, 1) as $duplicatePid) {
                if (function_exists("posix_kill")) @posix_kill($duplicatePid, 15);
                else exec("kill -TERM " . intval($duplicatePid) . " 2>/dev/null");
            }
            return $running[0];
        }

        $command = implode(" ", array_map("escapeshellarg", [
            "/usr/bin/php", $script, $Username, $radioToken, $portToken, ...$extraArgs,
        ])) . " > /dev/null 2>/dev/null & echo $!";
        return intval(exec($command));
    } finally {
        @rmdir($lock);
    }
}

function DoTCP($whichRadio, $Username, $port)
{
    return startSingletonBridge("/var/www/html/TCPDo.php", $whichRadio, $Username, $port, "tcp");
}

function DoUDP($whichRadio, $Username, $udpport)
{
    return startSingletonBridge("/var/www/html/UDPDo.php", $whichRadio, $Username, $udpport, "udp");
}

function DoCWUDP($whichRadio, $Username, $tMyKeyerPort, $adapterPort, $utest)
{
    return startSingletonBridge("/var/www/html/CWUDPDo.php", $whichRadio, $Username,
        $tMyKeyerPort, "cwudp", [(string)$adapterPort, (string)$utest]);
}

function DoCWUDP2($IP, $port, $invert, $utest)
{
    //  $IP='172.16.0.5';
    //  $port=30040;
    //  $invert=0;
    $tCWUDPExec2 =
        "sudo /usr/share/rigpi/cw.sh " . $IP . " " . $port . " " . $invert;
    //$tCWUDPExec2 = "php /var/www/html/programs/GPIO/GPIOInt1.php $IP $port $invert > /dev/null 2> /dev/null & ";
    doLog($utest, "Starting GPIOInt1 with: " . $tCWUDPExec2);
    $pidCWUDP2 = exec($tCWUDPExec2);
    doLog(
        1,
        "UDP2: " . print_r(error_get_last()) . " out: " . $pidCWUDP2 . PHP_EOL
    );
}

function DoRotor($whichRotor, $Username, $port, $utest)
{
    $tRotorExec =
        "php /var/www/html/RotorDo.php $Username rotor$whichRotor $port $utest " .
        $_SESSION["myInstance"] .
        "> /dev/null 2> /dev/null & ";
    $pidRotor = shell_exec($tRotorExec);
    doRotorLog(
        $utest,
        "DoRotor: " . $tRotorExec . " \n rotctl PID: " . $pidRotor
    );
}

function doLog($utest, $what)
{
    if ($utest == 1) {
        error_log(
            date("Y-m-d H:i:s", time()) . " " . $what . PHP_EOL,
            3,
            "/var/log/rigpi-radio.log"
        );
    }
}

function doRotorLog($utest, $what)
{
    if ($utest == 1) {
        error_log(
            date("Y-m-d H:i:s", time()) . " " . $what . PHP_EOL,
            3,
            "/var/log/rigpi-rotor.log"
        );
    }
}

?>
