<?php
session_start();
sleep(4);
require_once "/var/www/html/classes/MysqliDb.php";
require_once "/var/www/html/programs/sqldata.php";
$db = new MysqliDb(
    "localhost",
    $sql_radio_username,
    $sql_radio_password,
    $sql_radio_database
);
$db->update("RadioInterface", ["MainIn" => "OFF", "SubIn" => "OFF"]);

$db->where("Username", "admin");
$db->update("Users", ["Active" => "0"]);

$db->where("Username", "admin");
$db->delete("LoggedIn");

session_destroy();
system("sudo reboot");
?>
