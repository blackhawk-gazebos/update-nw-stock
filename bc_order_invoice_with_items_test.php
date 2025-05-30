<?php
// bc_order_invoice_with_items_test.php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors','1');

// —— LOCAL TEST PAYLOAD ——
// $test_raw_payload = '{ "order_id":43455, "date_created":"2025-05-20T00:00:00Z",
//   "products": [ /* … */ ], "shipping":[ /* … */ ] }';
// —— END LOCAL TEST PAYLOAD ——

$raw = !empty($test_raw_payload)
     ? $test_raw_payload
     : file_get_contents('php://input');

$data = json_decode($raw, true);
if (json_last_error()) {
    http_response_code(400);
    die(json_encode(['status'=>'error','message'=>'Invalid JSON']));
}

// Unwrap empty-key nesting if needed...
if (isset($data['']) && is_string($data[''])) {
    $data = json_decode($data[''], true);
    if (json_last_error()) {
        http_response_code(400);
        die(json_encode(['status'=>'error','message'=>'Invalid nested JSON']));
    }
}

// Extract products & shipping
$products    = $data['products']   ?? [];
$shippingArr = $data['shipping']    ?? [];
$ship        = $shippingArr[0]      ?? [];

// Determine order date & ID
$orderId   = $data['order_id'] 
           ?? ($products[0]['order_id'] ?? null);
$dtRaw     = $data['date_created'] ?? null;
$dt        = $dtRaw 
           ? new DateTime($dtRaw) 
           : new DateTime('now', new DateTimeZone('UTC'));
$dt->setTimezone(new DateTimeZone('Pacific/Auckland'));
$orderDate = $dt->format('Y-m-d');

// Bootstrap OMINS RPC client
require_once 'jsonRPCClient.php';
require_once '00_creds.php';  // $api_url, $sys_id, $username, $password
$client = new jsonRPCClient($api_url, false);
$creds  = (object)[
    'system_id'=>$sys_id,
    'username' =>$username,
    'password' =>$password
];

// 1) Create the invoice header (no items yet)
$header = [
    'promo_group_id'   => 1,
    'orderdate'        => $orderDate,
    'statusdate'       => $orderDate,
    'name'             => trim(($ship['first_name'] ?? '') . ' ' . ($ship['last_name'] ?? '')),
    'company'          => $ship['company']  ?? '',
    'address'          => $ship['street_1'] ?? '',
    'city'             => $ship['city']     ?? '',
    'postcode'         => $ship['zip']      ?? '',
    'state'            => $ship['state']    ?? '',
    'country'          => $ship['country']  ?? '',
    'phone'            => $ship['phone']    ?? '',
    'mobile'           => $ship['phone']    ?? '',
    'email'            => $ship['email']    ?? '',
    'type'             => 'invoice',
    'note'             => $orderId 
                          ? "BC Order #{$orderId}"
                          : "BC Order",
    // signal we’ll be updating items next
    'lineitemschanged'=> 0,
];

try {
    $res    = $client->createOrder($creds, $header);
    $invId  = $res['id'] ?? null;
    if (! $invId) {
        throw new Exception("No invoice ID returned");
    }
} catch (Exception $e) {
    http_response_code(500);
    die(json_encode(['status'=>'error','message'=>"createOrder failed: ".$e->getMessage()]));
}

// 2) Build the update payload with line items
$update = ['recordid' => $invId, 'lineitemschanged' => 1];
$idx = 1;
foreach ($products as $p) {
    $sku   = $p['sku']              ?? '';
    $name  = $p['name_customer']    ?? ($p['name'] ?? '');
    $qty   = intval($p['quantity']  ?? 1);
    $price = number_format(floatval($p['price_inc_tax'] ?? 0), 4, '.', '');

    $update["upc_{$idx}"]           = $sku;
    $update["partnumber_{$idx}"]    = $sku;
    $update["ds-partnumber_{$idx}"] = $name;
    $update["price_{$idx}"]         = $price;
    $update["qty_{$idx}"]           = $qty;
    $idx++;
}

try {
    $uRes = $client->updateOrder($creds, $update);
    echo json_encode([
       'status'      => 'success',
       'invoice_id'  => $invId,
       'update_resp' => $uRes
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
       'status'=>'error',
       'message'=>"updateOrder failed: ".$e->getMessage()
    ]);
}
