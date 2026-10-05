<?php
/*
 * RigPi - Pushover Test Notification
 * Sends a test push notification to verify credentials.
 */
session_start();
if (!isset($_SESSION["myUsername"])) {
    echo json_encode(["ok" => false, "error" => "Not logged in"]);
    exit();
}

$token = trim($_POST["token"] ?? "");
$user = trim($_POST["user"] ?? "");

if (!$token || !$user) {
    echo json_encode(["ok" => false, "error" => "Missing token or user key"]);
    exit();
}

$ch = curl_init("https://api.pushover.net/1/messages.json");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt(
    $ch,
    CURLOPT_POSTFIELDS,
    http_build_query([
        "token" => $token,
        "user" => $user,
        "title" => "RigPi Test",
        "message" => "Pushover notification is working!",
    ])
);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200) {
    echo json_encode(["ok" => true]);
} else {
    $data = json_decode($response, true);
    $err = $data["errors"][0] ?? "HTTP $httpCode";
    echo json_encode(["ok" => false, "error" => $err]);
}
