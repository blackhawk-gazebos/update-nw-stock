<?php
// bc_order_invoice.php

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', '1');

// 1) Read & log raw JSON
$raw = file_get_contents('php://input');
error_log("🛎️ Webhook payload: {$raw}");

// 2) Decode JSON
$data = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'Invalid JSON']);
    exit;
}

// 3) Unwrap array if needed
if (isset($data[0]) && is_array($data[0])) {
    $order = $data[0];
} elseif (isset($data['data'][0])) {
    $order = $data['data'][0];
} elseif (isset($data['data'])) {
    $order = $data['data'];
} else {
    $order = $data;
}

// 4) Bootstrap OMINS client
require_once 'jsonRPCClient.php';
require_once '00_creds.php';  // defines $sys_id, $username, $password, $api_url

$client = new jsonRPCClient($api_url, false);
$creds  = (object)[
    'system_id'=>$sys_id,
    'username' =>$username,
    'password' =>$password
];

// 5) Extract line items (V3 or fallback V2)
$items = $order['line_items'] ?? null;
if (empty($items) && !empty($order['products'])) {
    $jsonItems = str_replace("'", '"', $order['products']);
    $items = json_decode($jsonItems, true);
    error_log("🔄 Parsed V2 products: " . json_last_error_msg());
}
if (empty($items) || !is_array($items)) {
    error_log("❌ No line items found");
    http_response_code(422);
    echo json_encode(['status'=>'error','message'=>'No line items']);
    exit;
}

// 6) Build invoice lines
$thelineitems = [];
foreach ($items as $it) {
    $sku   = $it['sku'] ?? '';
    $qty   = (int)($it['quantity'] ?? 0);
    $price = (float)($it['price_inc_tax'] ?? ($it['price_ex_tax'] ?? 0));
    if (!$sku || $qty < 1) continue;
    try {
        $meta = $client->getProductbyName($creds, ['name'=>$sku]);
        if (empty($meta['id'])) {
            error_log("⚠️ SKU not found: {$sku}");
            continue;
        }
        $thelineitems[] = [
            'partnumber' => $sku,
            'qty'        => $qty,
            'unitcost'   => $price
        ];
    } catch (Exception $e) {
        error_log("⚠️ Lookup error for {$sku}: " . $e->getMessage());
    }
}
if (empty($thelineitems)) {
    error_log("❌ No valid invoice lines");
    http_response_code(422);
    echo json_encode(['status'=>'error','message'=>'No valid lines']);
    exit;
}

// 7) Parse shipping address (V2)
$shipArr = [];
if (!empty($order['shipping_addresses'])) {
    $jsonShip = str_replace("'", '"', $order['shipping_addresses']);
    $tmp      = json_decode($jsonShip, true);
    if (isset($tmp[0])) $shipArr = $tmp[0];
}

// Map shipping fields
$name              = trim(($shipArr['first_name'] ?? '') . ' ' . ($shipArr['last_name'] ?? ''));
$company           = $shipArr['company']  ?? '';
$address           = $shipArr['street_1'] ?? '';
$city              = $shipArr['city']     ?? '';
$postcode          = $shipArr['zip']      ?? '';
$state             = $shipArr['state']    ?? '';
$country           = $shipArr['country']  ?? '';
$ship_instructions = '';
$phone             = $shipArr['phone']    ?? '';\$mobile            = '';
$email             = $shipArr['email']    ?? '';

// 8) Format date & ID
$orderDate = date('Y-m-d', strtotime($order['date_created'] ?? ''));
$orderId   = $order['id'] ?? '';

// 9) Build createOrder params using form field names
$params = [
    'promo_group_id'         => 9,
    'orderdate'              => $orderDate,
    'name'                   => $name,
    'company'                => $company,
    'address'                => $address,
    'city'                   => $city,
    'postcode'               => $postcode,
    'state'                  => $state,
    'country'                => $country,
    'ship_instructions'      => $ship_instructions,
    'phone'                  => $phone,
    'mobile'                 => $mobile,
    'email'                  => $email,
    'note'                   => "BC Order #{$orderId}",
    'thelineitems'           => $thelineitems
];

// 10) Debug
error_log("📤 createOrder params: " . print_r($params, true));

// 11) Call createOrder
try {
    $inv = $client->createOrder($creds, $params);
    error_log("✅ Created invoice ID: " . ($inv['id'] ?? 'n/a'));
    http_response_code(200);
    echo json_encode(['status'=>'success','invoice_id'=>$inv['id'] ?? null]);
} catch (Exception $e) {
    error_log("❌ createOrder failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}
