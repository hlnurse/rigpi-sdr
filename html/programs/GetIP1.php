<?php
// IP of the Pi interface that received the request
$piIP = $_SERVER["SERVER_ADDR"] ?? "unknown";
return $piIP;
?>
