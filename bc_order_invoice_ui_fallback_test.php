<?php
// bc_order_invoice_ui_fallback_test.php
// 1) create invoice via RPC, 2) log in via UI form, 3) POST line items via UI form

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors','1');

// 0) Load creds & payload
require_once 'jsonRPCClient.php';
require_once '00_creds.php';   // provides $api_url, $sys_id, $username, $password

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
$orderId    = $data['order_id']   ?? null;
$dateRaw    = $data['date_created'] ?? null;
$products   = $data['products']   ?? [];
$shipping   = $data['shipping'][0] ?? [];

// 1) Create invoice header
$dt = $dateRaw
    ? new DateTime($dateRaw)
    : new DateTime('now', new DateTimeZone('UTC'));
$dt->setTimezone(new DateTimeZone('Pacific/Auckland'));
$orderDate = $dt->format('Y-m-d');

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
$res = $client->createOrder($creds, $header);
if (isset($res['id'])) {
    $invId = $res['id'];
} elseif (is_numeric($res)) {
    $invId = (int)$res;
} else {
    http_response_code(500);
    die(json_encode(['status'=>'error','message'=>'No invoice ID returned']));
}

// 2) Log in to OMINS UI to get a session cookie
$cookieFile = sys_get_temp_dir() . '/omins_session.txt';
$loginUrl   = 'https://omins.snipesoft.net.nz/modules/omins/login.php';
$loginData  = http_build_query([
    'username' => $username,
    'password' => $password,
    'submit'   => 'Login'
]);
$ch = curl_init($loginUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $loginData);
curl_setopt($ch, CURLOPT_COOKIEJAR,  $cookieFile);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$loginResp = curl_exec($ch);
curl_close($ch);
// You may check $loginResp to confirm a successful login

// 3) Build & send the line-item form POST
$postUrl = "https://omins.snipesoft.net.nz/modules/omins/invoices_addedit.php?tableid=1041&id={$invId}";
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
$ch = curl_init($postUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
curl_setopt($ch, CURLOPT_COOKIEJAR,  $cookieFile);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$uiResp = curl_exec($ch);
$curlErr = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    http_response_code(500);
    die(json_encode(['status'=>'error','stage'=>'uiPost','message'=>$curlErr]));
}

// 4) Done!
echo json_encode([
    'status'      => 'success',
    'invoice_id'  => $invId,
    'ui_response' => substr($uiResp,0,200)
]);
