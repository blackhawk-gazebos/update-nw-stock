<?php
// list_omins_methods.php
// Simple script to enumerate all available RPC methods on your OMINS JSON-RPC endpoint

require_once 'jsonRPCClient.php';
require_once '00_creds.php'; // defines $sys_id, $username, $password, $api_url

$client = new jsonRPCClient($api_url, false);
$creds  = (object)[
    'system_id' => $sys_id,
    'username'  => $username,
    'password'  => $password
];

try {
    // Try the standard introspection call
    $methods = $client->system_listMethods();
} catch (Exception $e) {
    try {
        // Fallback: without the "system_" prefix
        $methods = $client->listMethods();
    } catch (Exception $e2) {
        // Last resort: RPC namespace
        $methods = $client->rpc_listMethods();
    }
}

header('Content-Type: application/json');
echo json_encode([
    'available_methods' => $methods
], JSON_PRETTY_PRINT);
