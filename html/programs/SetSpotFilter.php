<?php
$allowed = [
    "radio",
    "folder",
    "order",
    "need",
    "band",
    "mode",
    "direction",
    "sort",
];
$params = [];
foreach ($allowed as $k) {
    if (isset($_POST[$k])) {
        $params[$k] = $_POST[$k];
    }
}
file_put_contents("/tmp/spot_filter.json", json_encode($params));
echo json_encode(["ok" => true]);
