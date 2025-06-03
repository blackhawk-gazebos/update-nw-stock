<?php
// lineitem_final_inspector.php
// Call addOrderItem() with quantity, price, line_shipping & item_description
// to surface any remaining missing slots in the SQL signature.

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

// 1) Grab invoice_id from ?invoice_id=123
$invId = isset($_GET['invoice_id']) ? intval($_GET['invoice_id']) : 0;
if ($invId <= 0) {
    http_response_code(400);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Please supply a valid invoice_id via ?invoice_id=123'
    ]);
    exit;
}

error_log("🔍 Testing addOrderItem() with all four keys on invoice {$invId}");

// 2) Build params with the corrected key names
$params = [
    'invoice_id'       => $invId,       // existing invoice
    'part_id'          => 0,            // keep 0 so template_cost(0) works
    'quantity'         => 1,            // 3rd slot
    'price'            => '0.0000',     // 5th slot
    'line_shipping'    => '0.0000',     // 6th slot
    'item_description' => '',           // 7th slot (description)
    // everything beyond this can be omitted (defaults in SQL):
    //   taxareaid (defaults via settings_1),
    //   date_created (NOW()),
    //   type (COALESCE(...) → ‘order’),
    //   notes (‘’),
    //   date_modified (NOW()),
    //   taxable (default: 5),
    //   discount_id (NULL),
    //   discount_amount (‘’)
];

error_log("🛠️ Calling addOrderItem() with params:\n" . print_r($params, true));

// 3) Call the RPC and catch any SQL errors
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
