<?php
// bc_order_invoice_ui_fallback_test.php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors','1');

// —— LOCAL TEST PAYLOAD ——
// To test locally, uncomment and paste your JSON here:
/*
$test_raw_payload = <<<'JSON'
{
  "order_id": 43455,
  "date_created": "2025-05-20T12:34:56Z",
  "products": [
    {
      "sku": "JuteLight230x160",
      "name_customer": "Medium Size Handwoven Indian Jute Rug – Light Grey",
      "price_inc_tax": "259.0000",
      "quantity": 1
    }
  ],
  "shipping": [
    {
      "first_name":"Tanya",
      "last_name":"Jeffery",
      "company":"",
      "street_1":"31a Crane Street",
      "city":"Mount maunganui",
      "zip":"3116",
      "country":"New Zealand",
      "phone":"0223153244",
      "email":"tanya.jeffery@gmail.com"
    }
  ]
}
JSON;
*/
// —— END TEST PAYLOAD ——

// 1) Read raw input
if (!empty($test_raw_payload)) {
    $raw = $test_raw_payload;
    error_log("🛠️ Using TEST_RAW_PAYLOAD");
} else {
    $raw = file_get_contents('php://input');
}
error_log("📥 Raw payload: {$raw}");

$data = json_decode($raw, true);
if (json_last_error()) {
    http_response_code(400);
    die(json_encode(['status'=>'error','message'=>'Invalid JSON: '.json_last_error_msg()]));
}
// unwrap Zapier empty‐key if needed
if (isset($data['']) && is_string($data[''])) {
    $data = json_decode($data[''], true) ?: $data;
}

// 2) Extract & normalize
$orderId    = $data['order_id'] ?? null;
$dateRaw    = $data['date_created'] ?? null;
$products   = $data['products']   ?? [];
$shipping   = $data['shipping'][0] ?? [];

error_log("📦 Products: ".count($products));
error_log("🚚 Shipping: ".print_r($shipping, true));

// 3) Compute dates
try {
    $dt = new DateTime($dateRaw ?: 'now', new DateTimeZone('UTC'));
} catch (Exception $e) {
    $dt = new DateTime('now', new DateTimeZone('UTC'));
}
$dt->setTimezone(new DateTimeZone('Pacific/Auckland'));
$orderDate = $dt->format('Y-m-d');

// 4) JSON-RPC createOrder
require_once 'jsonRPCClient.php';
require_once '00_creds.php'; // defines $api_url, $sys_id, $username, $password
$client = new jsonRPCClient($api_url, false);
$creds  = (object)[ 'system_id'=>$sys_id, 'username'=>$username, 'password'=>$password ];

$header = [
    'promo_group_id'   => 1,
    'orderdate'        => $orderDate,
    'statusdate'       => $orderDate,
    'name'             => trim(($shipping['first_name'] ?? '') . ' ' . ($shipping['last_name'] ?? '')),
    'company'          => $shipping['company'] ?? '',
    'address'          => $shipping['street_1'] ?? '',
    'city'             => $shipping['city'] ?? '',
    'postcode'         => $shipping['zip'] ?? '',
    'state'            => $shipping['state'] ?? '',
    'country'          => $shipping['country'] ?? '',
    'phone'            => $shipping['phone'] ?? '',
    'mobile'           => $shipping['phone'] ?? '',
    'email'            => $shipping['email'] ?? '',
    'type'             => 'invoice',
    'note'             => $orderId ? "BC Order #{$orderId}" : "BC Order",
    'lineitemschanged' => 0,
];
error_log("🛠️ createOrder params: ".print_r($header, true));

try {
    $res = $client->createOrder($creds, $header);
    error_log("🎯 createOrder response: ".print_r($res, true));
    if (is_array($res) && isset($res['id'])) {
        $invId = $res['id'];
    } elseif (is_numeric($res)) {
        $invId = (int)$res;
    } else {
        throw new Exception("No invoice ID");
    }
    error_log("✅ Invoice ID: {$invId}");
} catch (Exception $e) {
    http_response_code(500);
    error_log("❌ createOrder error: ".$e->getMessage());
    die(json_encode(['status'=>'error','stage'=>'createOrder','message'=>$e->getMessage()]));
}

// 5) Build UI form‐POST
$tableid = 1041;
$postUrl = "https://omins.snipesoft.net.nz/modules/omins/invoices_addedit.php"
         . "?tableid={$tableid}&id={$invId}";

$form = [
    'command'                => 'save',
    'recordid'               => $invId,
    'omins_submit_system_id' => $sys_id,
    'lineitemschanged'       => 1,
];

foreach ($products as $i => $p) {
    $n = $i + 1;
    $form["upc_{$n}"]           = $p['sku'];
    $form["partnumber_{$n}"]    = $p['sku'];
    $form["ds-partnumber_{$n}"] = $p['name_customer'];
    $form["price_{$n}"]         = number_format($p['price_inc_tax'],4,'.','');
    $form["qty_{$n}"]           = $p['quantity'];
}
error_log("🛠️ UI form fields: ".print_r($form, true));

// 6) cURL POST to UI
$ch = curl_init($postUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR,  '/tmp/omins_cookies.txt');
curl_setopt($ch, CURLOPT_COOKIEFILE, '/tmp/omins_cookies.txt');
$response = curl_exec($ch);
if ($err = curl_error($ch)) {
    http_response_code(500);
    error_log("❌ UI cURL error: {$err}");
    die(json_encode(['status'=>'error','stage'=>'uiPost','message'=>$err]));
}
curl_close($ch);
error_log("🎯 UI response: ".substr($response,0,200));

// 7) Done
echo json_encode([
    'status'      => 'success',
    'invoice_id'  => $invId,
    'ui_response' => substr($response,0,200)
]);
