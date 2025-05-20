<?php
// bc_order_invoice.php

// grab raw body
$raw = file_get_contents('php://input');

// immediately echo it back so Zapier test action will show it
header('Content-Type: application/json');
echo json_encode([
  'received_raw_body' => $raw,
]);

// stop execution so you’re only debugging the payload
exit;

// Dump raw request into the logs
$raw = file_get_contents('php://input');
error_log("🛎️ Webhook payload: {$raw}");

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors','1');

// 1) Grab the raw payload
$payload = json_decode(file_get_contents('php://input'), true);

// 2) Load your OMINS & BC helpers
require_once 'jsonRPCClient.php';
require_once '00_creds.php';    // sets $sys_id, $username, $password, $api_url

$client = new jsonRPCClient($api_url, false);
$creds  = (object)[
  'system_id'=>$sys_id,
  'username' =>$username,
  'password' =>$password
];

// 3) Map BigCommerce order → OMINS invoice fields
$orderId    = $payload['data']['id'];
$customerId = 0;  // or map from BC customer email to OMINS customer_id
$orderDate  = substr($payload['data']['date_created'], 0, 10); 

// Build invoice lines
$lines = [];
foreach ($payload['data']['line_items'] as $item) {
    $sku   = $item['sku'];
    $qty   = (int)$item['quantity'];
    $price = (float)$item['price_inc_tax'];  // or use price_ex_tax

    // 3a) Lookup the OMINS product ID by code/name
    $meta = $client->getProductbyName($creds, ['name'=>$sku]);
    if (empty($meta['id'])) {
        continue; // skip unknown SKUs
    }
    $prodId = $meta['id'];

    $lines[] = [
      'product_id'   => $prodId,
      'quantity'     => $qty,
      'unit_price'   => $price,
      // add other fields as your OMINS API expects, e.g. tax, discount, etc.
    ];
}

// 4) Call the OMINS invoice-creation RPC
try {
    $invParams = [
      'customer_id' => $customerId,
      'order_date'  => $orderDate,
      'lines'       => $lines,
      'comments'    => "BC Order #{$orderId}",
      // add billing/shipping address fields if your OMINS method supports them
    ];

    // Replace `createInvoice` with whatever your OMINS JSON-RPC method is called
    $result = $client->createInvoice($creds, $invParams);

    // 5) Return success  
    echo json_encode([
      'status'     => 'ok',
      'bc_order'   => $orderId,
      'omins_inv'  => $result['id'] ?? 'n/a'
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
      'status'  => 'error',
      'message' => $e->getMessage()
    ]);
}
