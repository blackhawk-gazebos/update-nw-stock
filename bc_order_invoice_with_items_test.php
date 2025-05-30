<?php
// bc_order_invoice_with_items_test.php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors','1');

// —— LOCAL TEST PAYLOAD ——
// Paste your full payload here for one-off testing:
// $test_raw_payload = '{
//   "products":[ { … } ],
//   "shipping":[ { … } ]
// }';
// —— END LOCAL TEST PAYLOAD ——

if (!empty($test_raw_payload)) {
    $raw = $test_raw_payload;
    error_log("🛠️ Using TEST_RAW_PAYLOAD");
} else {
    $raw = file_get_contents('php://input');
}
error_log("Received raw webhook payload: {$raw}");

// 1) Decode outer JSON
$data = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    die(json_encode(['status'=>'error','message'=>'Invalid JSON: '.json_last_error_msg()]));
}

// 2) Unwrap if nested under empty key (“”)
if (isset($data['']) && is_string($data[''])) {
    $data = json_decode($data[''], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        die(json_encode(['status'=>'error','message'=>'Invalid nested JSON: '.json_last_error_msg()]));
    }
}

// 3) Extract arrays
$products   = $data['products'] ?? [];
$shippingArr= $data['shipping']  ?? [];
$ship       = $shippingArr[0]    ?? [];

// 4) Determine dates & order ID
$orderId    = $data['order_id']
            ?? ($products[0]['order_id'] ?? null);
$dateRaw    = $data['date_created'] ?? null;
$dt         = $dateRaw
            ? new DateTime($dateRaw)
            : new DateTime('now', new DateTimeZone('UTC'));
$dt->setTimezone(new DateTimeZone('Pacific/Auckland'));
$orderDate  = $dt->format('Y-m-d');

// 5) Bootstrap OMINS RPC client
require_once 'jsonRPCClient.php';
require_once '00_creds.php';  // define $api_url, $sys_id, $username, $password
$client = new jsonRPCClient($api_url, false);
$creds  = (object)[
    'system_id'=>$sys_id,
    'username' =>$username,
    'password' =>$password
];

// 6) Build createOrder header params
$params = [
    'promo_group_id'   => 1,
    'orderdate'        => $orderDate,
    'statusdate'       => $orderDate,
    'name'             => trim(($ship['first_name'] ?? '') . ' ' . ($ship['last_name'] ?? '')),
    'company'          => $ship['company']          ?? '',
    'address'          => $ship['street_1']         ?? '',
    'city'             => $ship['city']             ?? '',
    'postcode'         => $ship['zip']              ?? '',
    'state'            => $ship['state']            ?? '',
    'country'          => $ship['country']          ?? '',
    'phone'            => $ship['phone']            ?? '',
    'mobile'           => $ship['phone']            ?? '',
    'email'            => $ship['email']            ?? '',
    'type'             => 'invoice',
    'note'             => $orderId
                          ? "BC Order #{$orderId}"
                          : "BC Order",
    'lineitemschanged' => 1,
];

// 7) Inject each product as line item
$idx = 1;
foreach ($products as $p) {
    $sku   = $p['sku']              ?? '';
    $name  = $p['name_customer']    ?? ($p['name'] ?? '');
    $qty   = intval($p['quantity']  ?? 1);
    $price = number_format(floatval($p['price_inc_tax'] ?? 0), 4, '.', '');

    $params["upc_{$idx}"]           = $sku;
    $params["partnumber_{$idx}"]    = $sku;
    $params["ds-partnumber_{$idx}"] = $name;
    $params["price_{$idx}"]         = $price;
    $params["qty_{$idx}"]           = $qty;
    $idx++;
}

// DEBUG: show built params
error_log("OMINS createOrder params:\n" . print_r($params, true));

// 8) Call createOrder
try {
    $res   = $client->createOrder($creds, $params);
    $invId = $res['id'] ?? null;
    echo json_encode(['status'=>'success','invoice_id'=>$invId,'raw_response'=>$res]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}
