<?php
// bc_order_invoice.php
// Secured endpoint: Receives BC order, parses line items (optional), and creates an invoice in OMINS via JSON-RPC

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

// 2) Decode JSON
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

// 4) Setup OMINS JSON-RPC
require_once 'jsonRPCClient.php';
require_once '00_creds.php'; // defines $sys_id, $username, $password, $api_url
$client = new jsonRPCClient($api_url, false);
$creds  = (object)['system_id'=>$sys_id,'username'=>$username,'password'=>$password];

// 5) Parse BC line items (V3) or fallback V2 products string
$items = $order['line_items'] ?? null;
if (empty($items) && !empty($order['products'])) {
    $jsonItems = str_replace("'", '"', $order['products']);
    $items = json_decode($jsonItems, true);
    error_log("🔄 Parsed V2 products: " . json_last_error_msg());
}

// Log raw SKUs for debugging
$rawSkus = [];
if (is_array($items)) {
    foreach ($items as $it) {
        $rawSkus[] = $it['sku'] ?? '';
    }
    error_log("💡 Raw SKUs from payload: " . implode(', ', $rawSkus));
}

// 6) Build invoice detail rows: lookup each SKU in OMINS
$thelineitems = [];
$unmatchedSkus = [];  // <--- fixed: added $
if (is_array($items)) {
    foreach ($items as $it) {
        $sku   = trim($it['sku'] ?? '');
        $qty   = (int)($it['quantity'] ?? 0);
        $price = (float)($it['price_inc_tax'] ?? ($it['price_ex_tax'] ?? 0));
        if (!$sku || $qty < 1) continue;

        try {
            $meta = $client->getProductbyName($creds, ['name'=>$sku]);
            if (!empty($meta['id'])) {
                error_log("✅ OMINS found product_id={$meta['id']} for SKU '{$sku}'");
                $thelineitems[] = ['partnumber'=>$sku,'qty'=>$qty,'unitcost'=>$price];
            } else {
                error_log("⚠️ OMINS no match for SKU '{$sku}'");
                $unmatchedSkus[] = $sku;
            }
        } catch (Exception $e) {
            error_log("❌ Error looking up SKU '{$sku}': " . $e->getMessage());
            $unmatchedSkus[] = $sku;
        }
    }
}

error_log("📥 Matched line items: " . count($thelineitems));
error_log("📥 Unmatched SKUs: " . implode(', ', $unmatchedSkus));

// 7) Parse shipping address
$shipArr = [];
if (!empty($order['shipping_addresses'])) {
    $jsonShip = str_replace("'", '"', $order['shipping_addresses']);
    $tmp      = json_decode($jsonShip, true);
    if (!empty($tmp[0]) && is_array($tmp[0])) {
        $shipArr = $tmp[0];
    }
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
$phone             = $shipArr['phone']    ?? '';
$mobile            = '';
$email             = $shipArr['email']    ?? '';

// 8) Format order date & retrieve order ID
$orderDate = date('Y-m-d', strtotime($order['date_created'] ?? ''));
$orderId   = $order['id'] ?? '';

// 9) Build createOrder params
$params = [
    'promo_group_id'=>9,
    'orderdate'=>$orderDate,
    'name'=>$name,
    'company'=>$company,
    'address'=>$address,
    'city'=>$city,
    'postcode'=>$postcode,
    'state'=>$state,
    'country'=>$country,
    'ship_instructions'=>$ship_instructions,
    'phone'=>$phone,
    'mobile'=>$mobile,
    'email'=>$email,
    'note'=>"BC Order #{$orderId}",
    'thelineitems'=>$thelineitems
];

// 9a) Append unmatched SKUs to note
if (!empty($unmatchedSkus)) {
    $append = "Unmatched SKUs: " . implode(', ', $unmatchedSkus);
    $params['note'] .= " | {$append}";
    error_log("⚠️ {$append}");
}

// 10) Debug output
error_log("📤 createOrder params: " . print_r($params, true));

// 11) Invoke createOrder RPC
try {
    $inv = $client->createOrder($creds, $params);
    error_log("✅ Invoice created ID: " . ($inv['id'] ?? 'n/a'));
    http_response_code(200);
    echo json_encode(['status'=>'success','invoice_id'=>$inv['id'] ?? null]);
} catch (Exception $e) {
    error_log("❌ createOrder error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}

// EOF
