<?php
// bc_order_invoice_with_items_test.php

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors','1');

// —— LOCAL TEST PAYLOAD ——
// Uncomment and paste your sample payload here for local tests:
// $test_raw_payload = '{
//   "order_id": 43455,
//   "date_created": "2025-05-20T00:00:00Z",
//   "products": [ /* your products array */ ],
//   "shipping": [ /* your shipping array */ ]
// }';
// —— END LOCAL TEST PAYLOAD ——

// 1) Read raw input (or use test payload)
if (!empty($test_raw_payload)) {
    $raw = $test_raw_payload;
    error_log("🛠️ Using TEST_RAW_PAYLOAD");
} else {
    $raw = file_get_contents('php://input');
}
error_log("📥 Received raw webhook payload: {$raw}");

// 2) Decode outer JSON
$data = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    die(json_encode([
        'status'  => 'error',
        'message' => 'Invalid JSON: ' . json_last_error_msg(),
    ]));
}

// 3) Unwrap if nested under empty key
if (isset($data['']) && is_string($data[''])) {
    error_log("🔄 Unwrapping nested JSON under empty key");
    $inner = $data[''];
    $data  = json_decode($inner, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        die(json_encode([
            'status'  => 'error',
            'message' => 'Invalid nested JSON: ' . json_last_error_msg(),
        ]));
    }
}

// 4) Extract products & shipping
$products    = $data['products'] ?? [];
$shippingArr = $data['shipping']  ?? [];
// Handle Zapier wrapping one extra array level
if (isset($products[0]) && is_array($products[0]) && !isset($products[0]['sku'])) {
    $products = $products[0];
}
$ship = $shippingArr[0] ?? [];

error_log("📦 Products count: " . count($products));
error_log("🚚 Shipping info: " . print_r($ship, true));

// 5) Determine order date & ID
$orderIdRaw = $data['order_id'] ?? ($products[0]['order_id'] ?? null);
$orderId    = $orderIdRaw;
$dateRaw    = $data['date_created'] ?? null;

if (!empty($dateRaw)) {
    try {
        $dt = new DateTime($dateRaw);
    } catch (Exception $e) {
        $dt = new DateTime('now', new DateTimeZone('UTC'));
    }
} else {
    $dt = new DateTime('now', new DateTimeZone('UTC'));
}
$dt->setTimezone(new DateTimeZone('Pacific/Auckland'));
$orderDate = $dt->format('Y-m-d');

error_log("📅 Order date: {$orderDate}, Order ID: {$orderId}");

// 6) Bootstrap OMINS RPC client
require_once 'jsonRPCClient.php';
require_once '00_creds.php';  // defines $api_url, $sys_id, $username, $password
$client = new jsonRPCClient($api_url, false);
$creds  = (object)[
    'system_id' => $sys_id,
    'username'  => $username,
    'password'  => $password,
];

// 7) Build header for createOrder
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
    'lineitemschanged' => 0,  // initially no line items
];

error_log("🛠️ createOrder header params:\n" . print_r($header, true));

// 8) Call createOrder()
try {
    $res = $client->createOrder($creds, $header);
    error_log("🎯 createOrder response:\n" . print_r($res, true));
    
    // Extract invoice ID from various possible locations
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
        throw new Exception("Could not locate invoice ID in createOrder response");
    }
    error_log("✅ Invoice created with ID: {$invId}");
} catch (Exception $e) {
    http_response_code(500);
    error_log("❌ createOrder error: " . $e->getMessage());
    echo json_encode([
        'status'  => 'error',
        'stage'   => 'createOrder',
        'message' => $e->getMessage(),
        'raw'     => isset($res) ? $res : null,
    ]);
    exit;
}

// 9) Build updateOrder to add line items
$update = [
    'recordid'         => $invId,
    'lineitemschanged' => 1,
];
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

error_log("🛠️ updateOrder params:\n" . print_r($update, true));

// 10) Call updateOrder()
try {
    $uRes = $client->updateOrder($creds, $update);
    error_log("🎯 updateOrder response:\n" . print_r($uRes, true));
    echo json_encode([
        'status'      => 'success',
        'invoice_id'  => $invId,
        'update_resp' => $uRes,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    error_log("❌ updateOrder error: " . $e->getMessage());
    echo json_encode([
        'status'  => 'error',
        'stage'   => 'updateOrder',
        'message' => $e->getMessage(),
    ]);
}
