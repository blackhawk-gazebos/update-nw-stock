<?php
// createOrder_params_help.php
// Helper: attempts a createOrder call with empty params to reveal required fields in the error message

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors','1');

// Setup OMINS client
require_once 'jsonRPCClient.php';
require_once '00_creds.php'; // defines $sys_id, $username, $password, $api_url
$client = new jsonRPCClient($api_url, false);
$creds  = (object)[ 'system_id' => $sys_id, 'username' => $username, 'password' => $password ];

// Attempt createOrder with no parameters
try {
    $result = $client->createOrder($creds, []);
    // Unexpected success
    echo json_encode(['status'=>'success','result'=>$result]);
} catch (Exception $e) {
    // Output the exact error message
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}
