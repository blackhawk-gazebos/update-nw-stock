<?php
// bc_order_invoice.php

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', '1');

// 1) Get & log raw JSON
$raw = file_get_contents('php://input');
error_log("🛎️ Webhook payload: {$raw}");

// 2) Decode JSON
$data = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'Invalid JSON']);
    exit;
}

// 3) Unwrap if BC wrapped in array/data
if (isset($data[0])) {
    $order = $data[0];
} elseif (isset($data['data'][0])) {
    $order = $data['data'][0];
} elseif (isset($data['data'])) {
    $order = $data['data'];
} else {
    $order = $data;
}

// 4) Bootstrap OMINS JSON-RPC
require_once 'jsonRPCClient.php';
require_once '00_creds.php';  // $sys_id, $username, $password, $api_url

$client = new jsonRPCClient($api_url, false);
$creds  = (object)[
    'system_id'=>$sys_id,
    'username' =>$username,
    'password' =>$password
];

// 5) Gather line items (`line_items` or fallback to V2 `products`)
$items = $order['line_items'] ?? null;
if (empty($items) && !empty($order['products'])) {
    $jsonItems = str_replace("'", '"', $order['products']);
    $items = json_decode($jsonItems, true);
    error_log("🔄 Parsed V2 products into items: " . json_last_error_msg());
}
if (empty($items) || !is_array($items)) {
    error_log("❌ No line items found");
    http_response_code(422);
    echo json_encode(['status'=>'error','message'=>'No line items']);
    exit;
}

// 6) Lookup each SKU in OMINS and build thelineitems array
$thelineitems = [];
foreach ($items as $it) {
    $sku   = $it['sku'] ?? '';
    $qty   = (int)($it['quantity'] ?? 0);
    $price = (float)($it['price_inc_tax'] ?? ($it['price_ex_tax'] ?? 0));
    if (!$sku || $qty < 1) continue;

    try {
        $meta = $client->getProductbyName($creds, ['name'=>$sku]);
        if (empty($meta['id'])) {
            error_log("⚠️ OMINS product not found for SKU {$sku}");
            continue;
        }
        // OMINS form expects partnumber, qty, unitcost
        $thelineitems[] = [
            'partnumber' => $sku,
            'qty'        => $qty,
            'unitcost'   => $price,
        ];
    } catch (Exception $e) {
        error_log("⚠️ Error looking up SKU {$sku}: " . $e->getMessage());
    }
}
if (empty($thelineitems)) {
    error_log("❌ No valid thelineitems built");
    http_response_code(422);
    echo json_encode(['status'=>'error','message'=>'No valid lines']);
    exit;
}

// 7) Parse shipping address (V2 shipping_addresses)
$shipArr = [];
if (!empty($order['shipping_addresses'])) {
    $jsonShip = str_replace("'", '"', $order['shipping_addresses']);
    $tmp      = json_decode($jsonShip, true);
    if (isset($tmp[0]) && is_array($tmp[0])) {
        $shipArr = $tmp[0];
    }
}
$name     = trim(($shipArr['first_name'] ?? '') . ' ' . ($shipArr['last_name'] ?? ''));
$email    = $shipArr['email']     ?? '';
$address  = $shipArr['street_1']  ?? '';
$city     = $shipArr['city']      ?? '';
$postcode = $shipArr['zip']       ?? '';
$phone    = $shipArr['phone']     ?? '';

// 8) Format dates & comments
$orderDate = date('Y-m-d', strtotime($order['date_created'] ?? ''));
$orderId   = $order['id'] ?? '';

// 9) Build createOrder params (exact input names)
$params = [
    'promo_group_id' => 9,               // your promo group ID
    'orderdate'      => $orderDate,      // YYYY-MM-DD
    'name'           => $name,           // <input name="name">
    'email'          => $email,          // <input name="email">
    'address'        => $address,        // <input name="address">
    'city'           => $city,           // <input name="city">
    'postcode'       => $postcode,       // <input name="postcode">
    'phone'          => $phone,          // <input name="phone">
    'note'           => "BC Order #{$orderId}", // <textarea name="note">
    'thelineitems'   => $thelineitems,   // detail array
];

// 10) Debug: show exactly what we’re sending
error_log("📤 createOrder params: " . print_r($params, true));

// 11) Call the RPC
try {
    $inv = $client->createOrder($creds, $params);
    error_log("✅ OMINS invoice created: " . ($inv['id'] ?? 'n/a'));
    http_response_code(200);
    echo json_encode(['status'=>'success','invoice_id'=>$inv['id'] ?? null]);
} catch (Exception $e) {
    error_log("❌ createOrder failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}
