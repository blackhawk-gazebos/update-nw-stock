<?php
// bc_order_invoice_with_items_ui_fallback.php
// Creates an OMINS invoice via JSON-RPC, then POSTS line items through the UI endpoint.

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors','1');

// 0) Security: verify shared secret (optional)
$secret = getenv('WEBHOOK_SECRET');
$token  = $_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? null;
if ($secret && (! $token || ! hash_equals($secret, $token))) {
    http_response_code(403);
    error_log("❌ Forbidden: invalid token");
    echo json_encode(['status'=>'error','message'=>'Forbidden']);
    exit;
}

// 1) Read & decode incoming JSON
$raw = file_get_contents('php://input');
error_log("📥 Payload: {$raw}");
$data = json_decode($raw, true);
if (json_last_error()) {
    http_response_code(400);
    error_log("❌ Invalid JSON: " . json_last_error_msg());
    echo json_encode(['status'=>'error','message'=>'Invalid JSON']);
    exit;
}

// 2) Unwrap Zapier nesting if present
if (isset($data['']) && is_string($data[''])) {
    $data = json_decode($data[''], true) ?: $data;
}

// 3) Extract arrays
$products    = $data['products'] ?? [];
$shippingArr = $data['shipping']  ?? [];
// handle extra nesting
if (isset($products[0]) && is_array($products[0]) && !isset($products[0]['sku'])) {
    $products = $products[0];
}
$ship = $shippingArr[0] ?? [];

// 4) Determine dates & order ID
$orderIdRaw = $data['order_id'] ?? ($products[0]['order_id'] ?? null);
$orderId    = $orderIdRaw;
$dateRaw    = $data['date_created'] ?? null;
if ($dateRaw) {
    try { $dt = new DateTime($dateRaw); }
    catch (Exception $e) { $dt = new DateTime('now', new DateTimeZone('UTC')); }
} else {
    $dt = new DateTime('now', new DateTimeZone('UTC'));
}
$dt->setTimezone(new DateTimeZone('Pacific/Auckland'));
$orderDate = $dt->format('Y-m-d');

// 5) Bootstrap JSON-RPC client
require_once 'jsonRPCClient.php';
require_once '00_creds.php';  // $api_url, $sys_id, $username, $password
$client = new jsonRPCClient($api_url, false);
$creds  = (object)[
    'system_id'=>$sys_id,
    'username' =>$username,
    'password' =>$password,
];

// 6) Build header params
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
    'lineitemschanged' => 0,
];
error_log("🛠️ createOrder header:\n" . print_r($header, true));

// 7) Call createOrder()
try {
    $res = $client->createOrder($creds, $header);
    error_log("🎯 createOrder response: " . print_r($res, true));
    // OMINS returns a bare ID or ['id'=>...]
    if (is_array($res) && isset($res['id'])) {
        $invId = $res['id'];
    } elseif (is_numeric($res)) {
        $invId = (int)$res;
    } else {
        throw new Exception("No invoice ID in response");
    }
    error_log("✅ Invoice ID: {$invId}");
} catch (Exception $e) {
    http_response_code(500);
    error_log("❌ createOrder error: " . $e->getMessage());
    echo json_encode(['status'=>'error','stage'=>'createOrder','message'=>$e->getMessage(),'raw'=>$res]);
    exit;
}

// 8) Prepare UI POST to invoices_addedit.php
$tableid = 1041; // your Omins table ID
$postUrl = "https://omins.snipesoft.net.nz/modules/omins/invoices_addedit.php?tableid={$tableid}&id={$invId}";

$form = [
    'command'                => 'save',
    'recordid'               => $invId,
    'omins_submit_system_id' => $sys_id,
    'lineitemschanged'       => 1,
];
// add each line item
$idx = 1;
foreach ($products as $p) {
    $sku   = $p['sku']           ?? '';
    $name  = $p['name_customer'] ?? ($p['name'] ?? '');
    $qty   = intval($p['quantity'] ?? 1);
    $price = number_format(floatval($p['price_inc_tax'] ?? 0), 4, '.', '');

    $form["upc_{$idx}"]           = $sku;
    $form["partnumber_{$idx}"]    = $sku;
    $form["ds-partnumber_{$idx}"] = $name;
    $form["price_{$idx}"]         = $price;
    $form["qty_{$idx}"]           = $qty;
    $idx++;
}
error_log("🛠️ invoices_addedit.php form:\n" . print_r($form, true));

// 9) Execute cURL POST
$ch = curl_init($postUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, '/tmp/omins_cookies.txt');
curl_setopt($ch, CURLOPT_COOKIEFILE, '/tmp/omins_cookies.txt');
$response = curl_exec($ch);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    http_response_code(500);
    error_log("❌ cURL error: {$curlErr}");
    echo json_encode(['status'=>'error','stage'=>'uiPost','message'=>$curlErr]);
    exit;
}

error_log("🎯 UI POST response (truncated): " . substr($response,0,200));

// 10) Success!
echo json_encode([
    'status'      => 'success',
    'invoice_id'  => $invId,
    'ui_response' => substr($response,0,200),
]);
