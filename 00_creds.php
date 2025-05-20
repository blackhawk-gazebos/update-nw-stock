<?php
// 00_creds.php
$sys_id   = getenv('SYS_ID');
$username = getenv('USERNAME');
$password = getenv('PASSWORD');
// If you set API_URL in Secrets, use that; otherwise fallback to your default.
$api_url  = getenv('API_URL') ?: 'http://api.snipesoft.net.nz/ominst/api/jsonrpc.php';
$debug    = true;
