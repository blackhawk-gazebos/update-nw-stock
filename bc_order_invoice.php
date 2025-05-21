<?php
// bc_order_invoice.php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors','1');

// 1) Read & log the raw request
$raw = file_get_contents('php://input');
error_log("🛎️ Webhook payload: {$raw}");

// 2) Decode JSON
$data = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'Invalid JSON']);
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
require_once '00_creds.php';  // defines $sys_id, $username, $password, $api_url

$client = new jsonRPCClient($api_url, false);
$creds  = (object)[
    'system_id'=>$sys_id,
    'username' =>$username,
    'password' =>$password
];

// 5) Extract items: prefer V3 `line_items`, else parse V2 `products`
$items = $order['line_items'] ?? null;
if (empty($items) && !empty($order['products'])) {
    // Convert single‐quoted array to valid JSON
    $json = str_replace("'", '"', $order['products']);
    $items = json_decode($json, true);
    error_log("🔄 Parsed V2 `products` into items: " . json_last_error_msg());
}

if (empty($items) || !is_array($items)) {
    error_log("❌ No line items found in payload.");
    http_response_code(422);
    echo json_encode(['status'=>'error','message'=>'No line items to invoice']);
    exit;
}

// 6) Build invoice lines
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
        $meta = $client->getProductbyName($creds, ['name'=>$sku]);
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
    echo json_encode(['status'=>'error','message'=>'No valid invoice lines']);
    exit;
}

// 7) Gather shipping & customer info
$ship = $order['shipping_address'] ?? [];
$params = [
    'promo_group_id'   => 9,
    //'customer_id'      => (int)($order['customer_id'] ?? 0),
    //'order_date'       => substr($order['date_created'] ?? '', 0, 10),
    'order_date'       => date('d-m-Y', strtotime($order['date_created'])),
    'name'             => trim(($ship['first_name'] ?? '') . ' ' . ($ship['last_name'] ?? '')),
    'ship_to_address1' => $ship['street_1']  ?? '',
    'ship_to_city'     => $ship['city']      ?? '',
    'ship_to_postcode' => $ship['zip']       ?? '',
    'ship_to_phone'    => $ship['phone']     ?? '',
    'email'    => $ship['email']     ?? '',
    'lines'            => $lines,
    'comments'         => "BigCommerce Order #" . ($order['id'] ?? ''),
];

// 7.5) DEBUG: log the createOrder params
error_log("📤 createOrder params: " . print_r($params, true));

// 8) Call createInvoice
try {
    $invoice = $client->createOrder($creds, $params);
    error_log("✅ Created OMINS invoice ID: " . ($invoice['id'] ?? 'n/a'));
    http_response_code(200);
    echo json_encode(['status'=>'success','invoice_id'=>$invoice['id'] ?? null]);
} catch (Exception $e) {
    error_log("❌ Failed to create invoice: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}

// 8.5) DEBUG: retrieve and log the new invoice
try {
    $fetched = $client->getOrder($creds, ['id' => $invoice['id']]);
    error_log("📥 Fetched invoice: " . print_r($fetched, true));
} catch (Exception $e) {
    error_log("⚠️ Error fetching invoice: " . $e->getMessage());
}
