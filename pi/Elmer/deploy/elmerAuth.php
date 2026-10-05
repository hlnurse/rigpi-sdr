<?php
session_start();
header('Cache-Control: no-store');

if (empty($_SESSION['myUsername'])) {
    http_response_code(401);
    exit;
}

require_once '/var/www/html/programs/GetUserFieldFunc.php';
$level = (int) getUserField($_SESSION['myUsername'], 'Access_Level');
if ($level !== 1) {
    http_response_code(403);
    exit;
}

http_response_code(204);
