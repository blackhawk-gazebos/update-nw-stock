<?php
// bc_order_invoice.php
// Receives a BigCommerce order payload via Zapier, unwraps it, and creates an invoice in OMINS.

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', '1');

// 1) Read & log the raw request
$raw = file_get_contents('php://input');
error_log("🛎️ Webhook payload: {$raw}");

// 2) Decode JSON
$data = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
    exit;
}

// 3) Unwrap array if necessary
if (isset($data[0]) && is_array($data[0])) {
    $order = $data[0];
} elseif (isset($data['data']) && is_array($data['data']) && isset($data['data'][0])) {
    $order = $data['data'][0];
} elseif (isset($data['data']) && is_array($data['data'])) {
    $order = $data['data'];
} else {
    $order = $data;
}

// 4) Bootstrap OMINS client
require_once 'jsonRPCClient.php';
require_once '00_creds.php';  // $sys_id, $username, $password, $api_url

$client = new jsonRPCClient($api_url, false);
$creds  = (object)[
    'system_id' => $sys_id,
    'username'  => $username,
    'password'  => $password,
];

// 5) Extract items: parse V2 'products' string
$items = $order['line_items'] ?? null;
if (empty($items) && !empty($order['products'])) {
    $jsonItems = str_replace("'", '"', $order['products']);
    $items = json_decode($jsonItems, true);
    error_log("🔄 Parsed V2 'products' into items: " . json_last_error_msg());
}

if (empty($items) || !is_array($items)) {
    error_log("❌ No line items found in payload.");
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'No line items to invoice']);
    exit;
}

// 6) Build OMINS invoice lines
$lines = [];
foreach ($items as $item) {
    $sku   = $item['sku'] ?? '';
    $qty   = (int)($item['quantity'] ?? 0);
    $price = (float)($item['price_inc_tax'] ?? ($item['price_ex_tax'] ?? 0));
    if (!$sku || $qty < 1) {
        error_log("⚠️ Skipping invalid item: " . json_encode($item));
        continue;
    }
    try {
        $meta = $client->getProductbyName($creds, ['name' => $sku]);
        if (empty($meta['id'])) {
            error_log("⚠️ OMINS product not found for SKU {$sku}");
            continue;
        }
        $lines[] = [
            'product_id' => $meta['id'],
            'quantity'   => $qty,
            'unit_price' => $price,
        ];
    } catch (Exception $e) {
        error_log("⚠️ Error looking up SKU {$sku}: " . $e->getMessage());
    }
}

if (empty($lines)) {
    error_log("❌ No valid invoice lines after lookup.");
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'No valid invoice lines']);
    exit;
}

// 7) Parse shipping address from V2 'shipping_addresses'
$shipArr = [];
if (!empty($order['shipping_addresses'])) {
    $jsonShip = str_replace("'", '"', $order['shipping_addresses']);
    $decoded  = json_decode($jsonShip, true);
    if (is_array($decoded) && isset($decoded[0])) {
        $shipArr = $decoded[0];
    }
}

$ship_to_name     = trim(($shipArr['first_name'] ?? '') . ' ' . ($shipArr['last_name'] ?? ''));
$ship_to_address1 = $shipArr['street_1'] ?? '';
$ship_to_city     = $shipArr['city'] ?? '';
$ship_to_postcode = $shipArr['zip'] ?? '';
$ship_to_phone    = $shipArr['phone'] ?? '';
$ship_to_email    = $shipArr['email'] ?? '';

// 8) Format order date
$orderDate = date('Y-m-d', strtotime($order['date_created'] ?? ''));
$orderId   = $order['id'] ?? '';

// 9) Build createOrder parameters
$params = [
    'promo_group_id' => 9,
    'orderdate'      => $orderDate,
    'sortit4'        => $ship_to_name,
    'address1'       => $ship_to_address1,
    'city'           => $ship_to_city,
    'zip'            => $ship_to_postcode,
    'phone'          => $ship_to_phone,
    'email'          => $ship_to_email,
    'comments'       => "BigCommerce Order #{$orderId}",
    'lines'          => $lines,
];

// 10) Debug log
error_log("📤 createOrder params: " . print_r($params, true));

// 11) Call createOrder
try {
    $invoice = $client->createOrder($creds, $params);
    error_log("✅ Created OMINS invoice ID: " . ($invoice['id'] ?? 'n/a'));
    http_response_code(200);
    echo json_encode(['status' => 'success', 'invoice_id' => $invoice['id'] ?? null]);
} catch (Exception $e) {
    error_log("❌ Failed to create invoice: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
