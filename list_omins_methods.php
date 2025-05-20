<?php
// list_omins_methods.php
require_once 'jsonRPCClient.php';
require_once '00_creds.php';  // $sys_id, $username, $password, $api_url

$client = new jsonRPCClient($api_url, false);
$creds  = (object)[
  'system_id' => $sys_id,
  'username'  => $username,
  'password'  => $password,
];

try {
    // Call the JSON-RPC system.listMethods endpoint
    $methods = $client->system_listMethods($creds);
    // If that doesn’t work, try with a dot name:
    if (!$methods) {
        $methods = $client->{"system.listMethods"}($creds);
    }
    header('Content-Type: text/plain');
    print_r($methods);
} catch (Exception $e) {
    echo "Error listing methods: " . $e->getMessage();
}
