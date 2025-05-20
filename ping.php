<?php
// ping.php
header('Content-Type: text/plain');
// Write to the logs so you can verify the ping hit the service
error_log("Ping received at " . date('c'));
echo "pong";
