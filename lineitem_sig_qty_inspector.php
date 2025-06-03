<?php
// lineitem_sig_qty_inspector.php
// Inspect addOrderItem() by supplying 'quantity' instead of 'qty'.

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors','1');

require_once 'jsonRPCClient.php';
require_once '00_creds.php';  // defines $api_url, $sys_id, $username, $password

$client = new jsonRPCClient($api_url, false);
$creds  = (object)[
    'system_id'=>$sys_id,
    'username' =>$username,
    'password' =>$password
];

// 1) Get invoice_id from ?invoice_id=#
$invId = isset($_GET['invoice_id']) ? intval($_GET['invoice_id']) : 0;
if ($invId <= 0) {
    http_response_code(400);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Please supply a valid invoice_id via ?invoice_id=123'
    ]);
    exit;
}

error_log("🕵️ Testing addOrderItem() with 'quantity' on invoice {$invId}");

// 2) Build params using 'quantity' instead of 'qty'
$params = [
    'invoice_id'  => $invId,        // must be valid
    'part_id'     => 0,             // so template_cost(0) works
    'quantity'    => 1,             // try this key for the third slot
    'price'       => '0.0000',
    'shipping'    => '0.0000',
    'description' => ''
];

error_log("🛠️ Calling addOrderItem() with params:\n" . print_r($params, true));

try {
    $res = $client->addOrderItem($creds, $params);
    echo json_encode([
      'status' => 'success',
      'result' => $res,
      'params' => $params
    ]);
} catch (Exception $e) {
    $msg = $e->getMessage();
    echo json_encode([
      'status'  => 'error',
      'stage'   => 'addOrderItem',
      'message' => $msg,
      'params'  => $params
    ]);
}
