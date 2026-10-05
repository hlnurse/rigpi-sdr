<?php
$ports = (string) shell_exec("ls /dev/ttyUSB* 2>/dev/null");
$isRadio = (string) shell_exec("ls /dev/radio* 2>/dev/null");
$data = "";
$isSerial = (string) shell_exec("ls /dev/serial/by-id 2>/dev/null");
$aData = explode("\n", $isSerial);
$cData = count($aData) - 1;
for ($n = 0; $n < $cData; $n++) {
  $portx='-port';
  if (strpos($aData[$n],$portx )){
    $data =
      $data .
      "<div class='myCWPort' id='/dev/serial/by-id/$aData[$n]'><li><a class='dropdown-item' href='#'>/dev/serial/by-id/$aData[$n]</a></li></div>\n\r";
  }
}
for ($n = 0; $n < 10; $n++) {
  if (strpos($ports, "USB" . $n) !== false) {
    if (strpos($ports, "USB" . $n) !== false) {
      $data =
        $data .
        "<div class='myCWPort' id='/dev/ttyUSB$n'><li><a class='dropdown-item' href='#'>/dev/ttyUSB$n</a></li></div>\n\r";
    }
  }
}
$builtInPort = "";
if (file_exists("/dev/serial0")) {
    $builtInPort = "/dev/serial0";
} elseif (file_exists("/dev/ttyS0")) {
    $builtInPort = "/dev/ttyS0";
}
if ($builtInPort !== "") {
    $safeBuiltInPort = htmlspecialchars($builtInPort, ENT_QUOTES, "UTF-8");
    $data =
        "<div class='myCWPort' id='$safeBuiltInPort'><li><a class='dropdown-item' href='#'>$safeBuiltInPort</a></li></div>\n\r" .
        $data;
}
$data =
  "<div class='myCWPort' id='cwPortNone'><li><a class='dropdown-item' href='#'>None</a></li></div>\n\r" .
  $data;
echo $data;
?>
