<?php
// bc_invoice_help.php
// Helper endpoint: Lists available OMINS JSON-RPC methods without touching bc_order_invoice.php

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors','1');

// 1) Setup OMINS RPC client
require_once 'jsonRPCClient.php';
require_once '00_creds.php'; // defines $sys_id, $username, $password, $api_url
$client = new jsonRPCClient($api_url, false);
$creds  = (object)[
    'system_id' => $sys_id,
    'username'  => $username,
    'password'  => $password
];

// 2) Invoke introspection to list methods
try {
    // Standard JSON-RPC introspection method
    $methods = $client->__call('system.listMethods', [$creds]);
    echo json_encode(['status' => 'success', 'methods' => $methods]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
