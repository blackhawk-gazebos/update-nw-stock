<?php
// list_omins_methods.php
// Simple script to enumerate all available RPC methods on your OMINS JSON-RPC endpoint
// Safe: catches any introspection errors and outputs available methods or error message

require_once 'jsonRPCClient.php';
require_once '00_creds.php'; // defines $sys_id, $username, $password, $api_url

$client = new jsonRPCClient($api_url, false);
$creds  = (object)[
    'system_id' => $sys_id,
    'username'  => $username,
    'password'  => $password
];

$methods = [];
// Try successive introspection calls
try {
    $methods = $client->system_listMethods();
} catch (Exception $e1) {
    try {
        $methods = $client->listMethods();
    } catch (Exception $e2) {
        try {
            $methods = $client->rpc_listMethods();
        } catch (Exception $e3) {
            $errorMsg = 'Introspection unavailable: ' . $e3->getMessage();
        }
    }
}

header('Content-Type: application/json');
if (!empty($methods)) {
    echo json_encode(['available_methods' => $methods], JSON_PRETTY_PRINT);
} else {
    echo json_encode(['available_methods' => [], 'error' => $errorMsg ?? 'No methods found'], JSON_PRETTY_PRINT);
}
