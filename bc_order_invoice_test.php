<?php
// bc_order_invoice.php
// Secured endpoint: Receives BC order via Zapier, parses line items, and creates an invoice with items in one RPC call.

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors','1');

// 0) Security: verify secret token
$secret = getenv('WEBHOOK_SECRET');
$token  = $_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? null;
if (!$secret || !$token || !hash_equals($secret, $token)) {
    http_response_code(403);
    echo json_encode(['status' => 'error','message' => 'Forbidden']);
    exit;
}

// 1) Read raw payload
if (getenv('TEST_RAW_PAYLOAD')) {
    $raw = getenv('TEST_RAW_PAYLOAD');
} elseif (isset($_GET['raw'])) {
    $raw = $_GET['raw'];
} else {
    $raw = file_get_contents('php://input');
}
// Log the exact webhook payload received for debugging
error_log("🛎️ Received raw webhook payload: " . $raw);


/*
// 2) Decode JSON
$data = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'Invalid JSON: '.json_last_error_msg()]);
    exit;
}

// 3) Extract order object
$order = $data[0] ?? $data['data'][0] ?? $data['data'] ?? $data;

// 4) Parse shipping address (same as your existing code)
error_log("🔍 Raw billing_address: " . print_r($order['billing_address'] ?? [], true));
if (!empty($order['shipping_addresses']) && is_string($order['shipping_addresses'])) {
    // parse string into individual shipping fields
    $rawShip = $order['shipping_addresses'];
    $fields = ['first_name','last_name','company','street_1','street_2','city','zip','country','email','phone','state'];
    foreach ($fields as $f) {
        if (preg_match("/'{$f}'\s*:\s*'([^']*)'/", $rawShip, $m)) {
            $order["shipping_addresses_{$f}"] = $m[1];
        } else {
            $order["shipping_addresses_{$f}"] = '';
        }
    }
}
if (empty($order['shipping_addresses_first_name'])) {
    // fallback to billing address
    foreach (['first_name','last_name','company','street_1','street_2','city','zip','country','email','phone','state'] as $f) {
        $order["shipping_addresses_{$f}"] = $order['billing_address'][$f] ?? '';
    }
}
// 5) Setup OMINS RPC client
require_once 'jsonRPCClient.php';
require_once '00_creds.php'; // defines $sys_id, $username, $password, $api_url
$client = new jsonRPCClient($api_url, false);
$creds  = (object)[ 'system_id'=>$sys_id,'username'=>$username,'password'=>$password ];

// 6) Parse BigCommerce line items
$itemsRaw = $order['line_items'] ?? null;
if (empty($itemsRaw) && !empty($order['products'])) {
    $itemsRaw = json_decode(str_replace("'", '"', $order['products']), true);
}

// 7) Build invoice rows array for RPC
$rows = [];
foreach ((array)$itemsRaw as $it) {
    $sku = trim($it['sku'] ?? '');
    $qty = (int)($it['quantity'] ?? 0);
    $unitPrice = (float)($it['price_inc_tax'] ?? $it['price_ex_tax'] ?? 0);
    if ($sku && $qty > 0) {
        try {
            $meta = $client->getProductbyName($creds, ['name'=>$sku]);
            if (!empty($meta['id'])) {
                $rows[] = ['partnumber'=>$sku, 'qty'=>$qty, 'price'=>$unitPrice];
            }
        } catch (Exception $e) {
            // skip unmatched
        }
    }
}

// 8) Map shipping/customer fields (same as your existing code)
// $name, $company, $street, $city, $zip, $state, $country, $phone, $email

// 9) Build single RPC createOrder params including items
$params = [
    'promo_group_id'   => 9,
    'orderdate'        => date('Y-m-d', strtotime($order['date_created'] ?? 'now')),
    'statusdate'       => date('Y-m-d'),
    'name'             => $name,
    'company'          => $company,
    'address'          => $street,
    'city'             => $city,
    'postcode'         => $zip,
    'state'            => $state,
    'country'          => $country,
    'phone'            => $phone,
    'mobile'           => $phone,
    'email'            => $email,
    'note'             => "BC Order #" . ($order['id'] ?? ''),
    'thelineitems'     => $rows,
    'lineitemschanged' => 1,
];

// 10) Log RPC endpoint and parameters
error_log("🔗 RPC URL: {$api_url}");
error_log("📥 RPC Params: " . print_r($params, true));

// 11) Call createOrder once with items
try {
    $inv = $client->createOrder($creds, $params);
    $invoiceId = is_array($inv) && isset($inv['id']) ? $inv['id'] : $inv;
    error_log("✅ Invoice created with items ID: {$invoiceId}");
    echo json_encode(['status'=>'success','invoice_id'=>$invoiceId]);
} catch (Exception $e) {
    error_log("❌ createOrder error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}

// EOF
*/