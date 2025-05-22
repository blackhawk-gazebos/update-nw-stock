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

// 4) Setup OMINS RPC client
require_once 'jsonRPCClient.php';
require_once '00_creds.php'; // $sys_id, $username, $password, $api_url
$client = new jsonRPCClient($api_url, false);
$creds  = (object)[ 'system_id'=>$sys_id, 'username'=>$username, 'password'=>$password ];

// 5) Parse BC line items (V3) or fallback V2 products string
$items = $order['line_items'] ?? null;
if (empty($items) && !empty($order['products'])) {
    $jsonItems = str_replace("'", '"', $order['products']);
    $items = json_decode($jsonItems, true);
    error_log("🔄 Parsed V2 products: " . json_last_error_msg());
}

// 6) Build invoice detail rows
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
                $thelineitems[] = ['partnumber'=>$sku,'qty'=>$qty,'unitcost'=>$price];
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

// 7) Determine shipping address
$shipArr = [];
// a) Zapier flat shippingAddress* fields
if (isset($order['shippingAddressFirstName'])) {
    $shipArr = [
        'first_name' => $order['shippingAddressFirstName'],
        'last_name'  => $order['shippingAddressLastName'] ?? '',
        'company'    => $order['shippingAddressCompany'] ?? '',
        'street_1'   => $order['shippingAddressStreet1'] ?? '',
        'street_2'   => $order['shippingAddressStreet2'] ?? '',
        'city'       => $order['shippingAddressCity'] ?? '',
        'state'      => $order['shippingAddressState'] ?? '',
        'zip'        => $order['shippingAddressZip'] ?? '',
        'country'    => $order['shippingAddressCountry'] ?? '',
        'email'      => $order['shippingAddressEmail'] ?? '',
        'phone'      => $order['shippingAddressPhone'] ?? ''
    ];
    error_log("🚚 Parsed Zapier shippingAddress* fields");
}
// b) V2 JSON string in shipping_addresses
elseif (!empty($order['shipping_addresses']) && is_string($order['shipping_addresses'])) {
    $rawSh = $order['shipping_addresses'];
    $step1 = preg_replace("/'([^']+)':/", '"$1":', $rawSh);
    $step2 = preg_replace_callback(
      "/:\s*'((?:[^'\\]|\\.)*)'/",
      function($m){ return ': "'. addslashes($m[1]) . '"'; },
      $step1
    );
    $dec = json_decode($step2, true);
    if (isset($dec[0]) && is_array($dec[0])) {
        $shipArr = $dec[0];
        error_log("🚚 Parsed V2 shipping_addresses JSON");
    } else {
        error_log("⚠️ shipping_addresses JSON invalid: " . json_last_error_msg());
    }
}
// c) Fallback to billing_address
if (empty($shipArr) && !empty($order['billing_address']) && is_array($order['billing_address'])) {
    $shipArr = $order['billing_address'];
    error_log("🚚 Using billing_address fallback");
}

// 8) Map shipping vars
$name    = trim(($shipArr['first_name'] ?? '') . ' ' . ($shipArr['last_name'] ?? ''));
$company = $shipArr['company']  ?? '';
$address = $shipArr['street_1'] ?? '';
$city    = $shipArr['city']     ?? '';
$post    = $shipArr['zip']      ?? '';
$state   = $shipArr['state']    ?? '';
$country = $shipArr['country']  ?? '';
$phone   = $shipArr['phone']    ?? '';
$email   = $shipArr['email']    ?? '';

// 9) Format order date and get ID
$orderDate = date('Y-m-d', strtotime($order['date_created'] ?? ''));
$orderId   = $order['id'] ?? '';

// 10) Build createOrder params
$params = [
    'promo_group_id'=>9,
    'orderdate'=> $orderDate,
    'name'=> $name,
    'company'=> $company,
    'address'=> $address,
    'city'=> $city,
    'postcode'=> $post,
    'state'=> $state,
    'country'=> $country,
    'ship_instructions'=> '',
    'phone'=> $phone,
    'mobile'=> $phone,
    'email'=> $email,
    'note'=> "BC Order #{$orderId}",
    'thelineitems'=> $thelineitems
];
if (!empty($unmatchedSkus)) {
    $append = "Unmatched SKUs: " . implode(', ', $unmatchedSkus);
    $params['note'] .= " | {$append}";
}
error_log("📤 createOrder params: " . print_r($params, true));

// 11) Call createOrder
try {
    $inv = $client->createOrder($creds, $params);
    error_log("✅ Invoice created ID: " . ($inv['id'] ?? 'n/a'));
    echo json_encode(['status'=>'success','invoice_id'=>$inv['id'] ?? null]);
} catch (Exception $e) {
    error_log("❌ createOrder error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}

// EOF
