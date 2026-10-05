<?php
header("Content-Type: text/plain; charset=utf-8");
header("X-Content-Type-Options: nosniff");

function macro_fail($message, $json = false)
{
    http_response_code(400);
    if ($json) {
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode(array("res" => false, "data" => $message));
    } else {
        echo "ERROR: " . $message;
    }
    exit;
}

function macro_first_line($data)
{
    $data = str_replace(array("
", ""), "
", (string) $data);
    $parts = explode("
", $data, 2);
    return trim($parts[0]);
}

function macro_line_is_valid($line)
{
    if ($line === "" || strlen($line) > 262144) return false;
    $entries = explode("~", rawurldecode($line));
    if (count($entries) < 32) return false;
    for ($i = 0; $i < 32; $i++) {
        if (strpos($entries[$i], "|") === false) return false;
    }
    return true;
}

if (isset($_FILES["files"])) {
    $upload = $_FILES["files"];
    $error = is_array($upload["error"]) ? $upload["error"][0] : $upload["error"];
    $tmp = is_array($upload["tmp_name"]) ? $upload["tmp_name"][0] : $upload["tmp_name"];
    $name = is_array($upload["name"]) ? $upload["name"][0] : $upload["name"];
    $size = is_array($upload["size"]) ? $upload["size"][0] : $upload["size"];

    if ($error !== UPLOAD_ERR_OK) macro_fail("Upload failed.", true);
    if ($size < 1 || $size > 262144) macro_fail("Macro file size is invalid.", true);
    if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== "txt") macro_fail("Select a RigPi macro .txt file.", true);
    if (!is_uploaded_file($tmp)) macro_fail("Uploaded macro file is invalid.", true);

    $line = macro_first_line(file_get_contents($tmp));
    if (!macro_line_is_valid($line)) macro_fail("The selected file is not a valid RigPi macro bank.", true);

    header("Content-Type: application/json; charset=utf-8");
    echo json_encode(array("res" => true, "data" => array($line)));
    exit;
}

$radio = isset($_POST["radio"]) ? trim((string) $_POST["radio"]) : "";
$bank = filter_input(INPUT_POST, "bank", FILTER_VALIDATE_INT);
$line = macro_first_line(isset($_POST["file"]) ? $_POST["file"] : "");

if ($bank === false || $bank < 1 || $bank > 4) macro_fail("Invalid macro bank.");
if ($radio === "" || strlen($radio) > 64) macro_fail("Invalid radio.");
if (!macro_line_is_valid($line)) macro_fail("Invalid macro data.");

require "/var/www/html/programs/sqldata.php";
$con = new mysqli("localhost", $sql_radio_username, $sql_radio_password, $sql_radio_database);
if ($con->connect_error) {
    http_response_code(500);
    die("ERROR: Database connection failed.");
}

$field = "Macros" . $bank;
$stmt = $con->prepare("UPDATE RadioInterface SET " . $field . "=? WHERE Radio=?");
if (!$stmt) {
    http_response_code(500);
    die("ERROR: Unable to prepare macro restore.");
}
$stmt->bind_param("ss", $line, $radio);
if (!$stmt->execute()) {
    http_response_code(500);
    die("ERROR: Unable to restore macro bank.");
}
$stmt->close();
$con->close();

echo $line;
?>
