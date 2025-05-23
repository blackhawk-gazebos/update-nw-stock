<?php
// bc_order_invoice.php
// Secured endpoint: Receives BC order via Zapier, parses line items, and creates an invoice in OMINS via JSON-RPC

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', '1');

// 0) Security: verify secret token
$secret = getenv('WEBHOOK_SECRET');
$token  = $_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? null;
if (!$secret || !$token || !hash_equals($secret, $token)) {
    http_response_code(403);
    echo json_encode(['status'=>'error','message'=>'Forbidden']);
    exit;
}

// 1) Read & log raw payload
$raw = file_get_contents('php://input');
error_log("🛎️ Webhook payload: {$raw}");

// 2) Decode JSON into array
$data = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'Invalid JSON']);
    exit;
}

// 3) Extract order object
if (isset($data[0]) && is_array($data[0])) {
    $order = $data[0];
} elseif (!empty($data['data'][0])) {
    $order = $data['data'][0];
} elseif (!empty($data['data'])) {
    $order = $data['data'];
} else {
    $order = $data;
}

// 4) Normalize and debug shipping_addresses
error_log("🔍 Raw billing_address: " . print_r($order['billing_address'] ?? null, true));

if (!empty($order['shipping_addresses']) && is_array($order['shipping_addresses'])) {
    error_log("🔍 Raw shipping_addresses array: " . print_r($order['shipping_addresses'], true));
    if (isset($order['shipping_addresses'][0]) && is_array($order['shipping_addresses'][0])) {
        $order['shipping_addresses'] = $order['shipping_addresses'][0];
        error_log("🔄 Converted shipping_addresses array to single object: " . print_r($order['shipping_addresses'], true));
    }
}

// 5) Flatten shipping_addresses fields with prefix and debug
if (!empty($order['shipping_addresses']) && is_array($order['shipping_addresses'])) {
    foreach ($order['shipping_addresses'] as $key => $val) {
        $order["shipping_addresses_{$key}"] = $val;
    }
    error_log("📦 Flattened shipping_addresses into order array, keys: " . implode(',', array_keys($order['shipping_addresses'])));
}

// detect fallback to billing_address
if (!isset($order['shipping_addresses_first_name'])) {
    error_log("🚚 Falling back to billing_address for shipping info");
}

// 6) Setup OMINS RPC client
require_once 'jsonRPCClient.php';
require_once '00_creds.php'; // $sys_id, $username, $password, $api_url
$client = new jsonRPCClient($api_url, false);
$creds  = (object)['system_id'=>$sys_id,'username'=>$username,'password'=>$password];

// 7) Parse BC line items
$items = $order['line_items'] ?? null;
if (empty($items) && !empty($order['products'])) {
    $jsonItems = str_replace("'", '"', $order['products']);
    $items = json_decode($jsonItems, true);
    error_log("🔄 Parsed V2 products: " . json_last_error_msg());
}

// 8) Build invoice detail rows
$thelineitems = [];
$unmatchedSkus = [];
if (is_array($items)) {
    foreach ($items as $it) {
        $sku   = trim($it['sku'] ?? '');
        $qty   = (int)($it['quantity'] ?? 0);
        $price = (float)($it['price_inc_tax'] ?? ($it['price_ex_tax'] ?? 0));
        if (!$sku || $qty < 1) continue;
        try {
            $meta = $client->getProductbyName($creds, ['name'=>$sku]);
            if (!empty($meta['id'])) {
                $thelineitems[] = [
                    'ds-partnumber' => $sku,
                    'qty'           => $qty,
                    'price'         => $price
                ];
            } else {
                $unmatchedSkus[] = $sku;
            }
        } catch (Exception $e) {
            $unmatchedSkus[] = $sku;
        }
    }
}
error_log("📥 Matched lines: " . count($thelineitems));
error_log("📥 Unmatched SKUs: " . implode(', ', $unmatchedSkus));

// 9) Map shipping vars identical to billing_address structure
$name    = trim((
    $order['shipping_addresses_first_name'] ??
    $order['billing_address']['first_name'] ??
    '') . ' ' . (
    $order['shipping_addresses_last_name'] ??
    $order['billing_address']['last_name'] ??
    ''));

$company = $order['shipping_addresses_company'] ?? $order['billing_address']['company'] ?? '';
$address = $order['shipping_addresses_street_1'] ?? $order['billing_address']['street_1'] ?? '';
$city    = $order['shipping_addresses_city'] ?? $order['billing_address']['city'] ?? '';
$post    = $order['shipping_addresses_zip'] ?? $order['billing_address']['zip'] ?? '';
$state   = $order['shipping_addresses_state'] ?? $order['billing_address']['state'] ?? '';
$country = $order['shipping_addresses_country'] ?? $order['billing_address']['country'] ?? '';
$phone   = $order['shipping_addresses_phone'] ?? $order['billing_address']['phone'] ?? '';
$email   = $order['shipping_addresses_email'] ?? $order['billing_address']['email'] ?? '';

error_log("📦 Final shipping vars - Name: {$name}, Address: {$address}, City: {$city}, Zip: {$post}, Email: {$email}");

// 10) Format order date & get ID
$orderDate = date('Y-m-d', strtotime($order['date_created'] ?? ''));
$orderId   = $order['id'] ?? '';

// 11) Build createOrder params
$params = [
    'promo_group_id'   => 9,
    'orderdate'        => $orderDate,
    'name'             => $name,
    'company'          => $company,
    'address'          => $address,
    'city'             => $city,
    'postcode'         => $post,
    'state'            => $state,
    'country'          => $country,
    'ship_instructions'=> '',
    'phone'            => $phone,
    'mobile'           => $phone,
    'email'            => $email,
    'note'             => "BC Order #{$orderId}",
    'thelineitems'     => $thelineitems
];
if (!empty($unmatchedSkus)) {
    $params['note'] .= ' | Unmatched SKUs: ' . implode(', ', $unmatchedSkus);
}
error_log("📤 createOrder params: " . print_r($params, true));

// 12) Call createOrder
/* try {
    $inv = $client->createOrder($creds, $params);
    error_log("✅ Invoice created ID: " . ($inv['id'] ?? 'n/a'));
    echo json_encode(['status'=>'success','invoice_id'=>$inv['id'] ?? null]);
} catch (Exception $e) {
    error_log("❌ createOrder error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
} */

// EOF
