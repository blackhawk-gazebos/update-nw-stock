<?php
// bc_order_invoice_noitems.php
// Secured endpoint: Receives BC order via Zapier or direct input, parses customer data only, creates an invoice in OMINS without line items

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors','1');

// 0) Security: verify secret token
$secret = getenv('WEBHOOK_SECRET');
$token  = $_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? null;
if (!$secret || !$token || !hash_equals($secret, $token)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
    exit;
}

// 1) Read raw payload (allow override for local testing)
if (getenv('TEST_RAW_PAYLOAD')) {
    $raw = getenv('TEST_RAW_PAYLOAD');
    error_log("🛠️ Using TEST_RAW_PAYLOAD env var for raw input");
} elseif (isset($_GET['raw'])) {
    $raw = $_GET['raw'];
    error_log("🛠️ Using raw GET parameter for raw input");
} else {
    $raw = file_get_contents('php://input');
}
error_log("🛎️ Webhook payload: {$raw}");

// 2) Decode JSON into array
$data = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON: ' . json_last_error_msg()]);
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

// 4) Normalize & parse shipping address
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

// 6) Skip line item parsing for now
$rows = [];
$unmatched = [];
error_log("⚠️ Skipping product/line item parsing. Invoice will have no items.");

// 7) Map shipping vars
$name    = trim(($order['shipping_addresses_first_name'] ?? '') . ' ' . ($order['shipping_addresses_last_name'] ?? ''));
$company = $order['shipping_addresses_company'] ?? '';
$street  = $order['shipping_addresses_street_1'] ?? '';
$city    = $order['shipping_addresses_city'] ?? '';
$zip     = $order['shipping_addresses_zip'] ?? '';
$state   = $order['shipping_addresses_state'] ?? '';
$country = $order['shipping_addresses_country'] ?? '';
$phone   = $order['shipping_addresses_phone'] ?? '';
$email   = $order['shipping_addresses_email'] ?? '';
error_log("📦 Shipping to: $name, $street, $city $zip, $country, $email");

// 8) Build createOrder params without line items
$orderDate   = date('Y-m-d', strtotime($order['date_created'] ?? ''));
$currentDate = date('Y-m-d');
$orderId     = $order['id'] ?? '';
$params = [
    'promo_group_id'     => 9,
    'orderdate'          => $orderDate,
    'statusdate'         => $currentDate,
    'name'               => $name,
    'company'            => $company,
    'address'            => $street,
    'city'               => $city,
    'postcode'           => $zip,
    'state'              => $state,
    'country'            => $country,
    'phone'              => $phone,
    'mobile'             => $phone,
    'email'              => $email,
    'type'               => 'invoice',
    'ship_instructions'=> '',
    'printedinstructions' => '',
    'specialinstructions' => '',
    'note'               => "BC Order #{$orderId} (no line items)",

    // Provide empty items array to prevent errors
    'thelineitems'       => $rows,
    'lineitemschanged'   => 0,
];

// 9) Call createOrder
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
