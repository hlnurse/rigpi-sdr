<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
$file = "/tmp/spot_filter.json";
if (!file_exists($file)) {
    echo json_encode(["ok" => true, "params" => null]);
    exit();
}
$params = json_decode(file_get_contents($file), true);
echo json_encode(["ok" => true, "params" => $params]);
