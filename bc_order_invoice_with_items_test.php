<?php
// bc_order_invoice_with_items_full.php

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors','1');

// 0) Security: verify secret token
$secret = getenv('WEBHOOK_SECRET');
$token  = $_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? null;
if ($secret && (! $token || ! hash_equals($secret, $token))) {
    http_response_code(403);
    error_log("❌ Forbidden: invalid webhook token");
    echo json_encode(['status'=>'error','message'=>'Forbidden']);
    exit;
}

// 1) Read raw payload
$raw = file_get_contents('php://input');
error_log("📥 Received raw webhook payload: {$raw}");

// 2) Decode outer JSON
$data = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    error_log("❌ Invalid JSON: " . json_last_error_msg());
    echo json_encode(['status'=>'error','message'=>'Invalid JSON: '.json_last_error_msg()]);
    exit;
}

// 3) Unwrap if Zapier nested under empty key
if (isset($data['']) && is_string($data[''])) {
    error_log("🔄 Unwrapping nested JSON under empty key");
    $inner = $data[''];
    $data  = json_decode($inner, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        error_log("❌ Invalid nested JSON: " . json_last_error_msg());
        echo json_encode(['status'=>'error','message'=>'Invalid nested JSON: '.json_last_error_msg()]);
        exit;
    }
}

// 4) Extract products & shipping arrays
$products    = $data['products']   ?? [];
$shippingArr = $data['shipping']   ?? [];
if (isset($products[0]) && is_array($products[0]) && ! isset($products[0]['sku'])) {
    // some wrapping variants
    $products = $products[0];
}
$ship = $shippingArr[0] ?? [];

error_log("📦 Products count: " . count($products));
error_log("🚚 Shipping info: " . print_r($ship, true));

// 5) Determine order date & ID
$orderIdRaw = $data['order_id'] ?? ($products[0]['order_id'] ?? null);
$orderId    = $orderIdRaw;
$dateRaw    = $data['date_created'] ?? null;
if ($dateRaw) {
    $dt = new DateTime($dateRaw);
} else {
    $dt = new DateTime('now', new DateTimeZone('UTC'));
}
$dt->setTimezone(new DateTimeZone('Pacific/Auckland'));
$orderDate = $dt->format('Y-m-d');
error_log("📅 Order date set to: {$orderDate}, Order ID: {$orderId}");

// 6) Bootstrap OMINS JSON-RPC client
require_once 'jsonRPCClient.php';
require_once '00_creds.php';  // defines $api_url, $sys_id, $username, $password
$client = new jsonRPCClient($api_url, false);
$creds  = (object)[
    'system_id' => $sys_id,
    'username'  => $username,
    'password'  => $password
];

// 7) Build header params for createOrder
$header = [
    'promo_group_id'   => 1,
    'orderdate'        => $orderDate,
    'statusdate'       => $orderDate,
    'name'             => trim(($ship['first_name'] ?? '') . ' ' . ($ship['last_name'] ?? '')),
    'company'          => $ship['company'] ?? '',
    'address'          => $ship['street_1'] ?? '',
    'city'             => $ship['city'] ?? '',
    'postcode'         => $ship['zip'] ?? '',
    'state'            => $ship['state'] ?? '',
    'country'          => $ship['country'] ?? '',
    'phone'            => $ship['phone'] ?? '',
    'mobile'           => $ship['phone'] ?? '',
    'email'            => $ship['email'] ?? '',
    'type'             => 'invoice',
    'note'             => $orderId ? "BC Order #{$orderId}" : "BC Order",
    'lineitemschanged' => 0,  // no items yet
];

error_log("🛠️ Calling createOrder with header params:\n" . print_r($header, true));

// 8) Call createOrder()
try {
    $res = $client->createOrder($creds, $header);
    error_log("🎯 createOrder raw response:\n" . print_r($res, true));
    
    // 9) Extract invoice ID
    $invId = null;
    if (is_array($res)) {
        if (isset($res['id'])) {
            $invId = $res['id'];
        } elseif (isset($res['result']['id'])) {
            $invId = $res['result']['id'];
        } elseif (isset($res[0]['id'])) {
            $invId = $res[0]['id'];
        }
    } elseif (is_numeric($res)) {
        $invId = (int)$res;
    }
    if (empty($invId)) {
        throw new Exception("Could not find invoice ID in createOrder response");
    }
    error_log("✅ Resolved invoice ID: {$invId}");
} catch (Exception $e) {
    http_response_code(500);
    error_log("❌ createOrder error: " . $e->getMessage());
    echo json_encode([
        'status'  => 'error',
        'stage'   => 'createOrder',
        'message' => $e->getMessage(),
        'raw'     => $res ?? null
    ]);
    exit;
}

// 10) Build updateOrder params to add line items
$update = [
    'recordid'         => $invId,
    'lineitemschanged' => 1
];
$idx = 1;
foreach ($products as $p) {
    $sku   = $p['sku'] ?? '';
    $name  = $p['name_customer'] ?? ($p['name'] ?? '');
    $qty   = intval($p['quantity'] ?? 1);
    $price = number_format(floatval($p['price_inc_tax'] ?? 0), 4, '.', '');

    $update["upc_{$idx}"]           = $sku;
    $update["partnumber_{$idx}"]    = $sku;
    $update["ds-partnumber_{$idx}"] = $name;
    $update
