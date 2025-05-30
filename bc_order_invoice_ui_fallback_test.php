<?php
// bc_order_invoice_ui_full.php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors','1');

require_once 'jsonRPCClient.php';
require_once '00_creds.php';  // $api_url, $sys_id, $username, $password

// 1) Read & parse incoming JSON
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!$data) {
    http_response_code(400);
    die(json_encode(['status'=>'error','message'=>'Invalid JSON']));
}
$products = $data['products']   ?? [];
$shipArr  = $data['shipping']   ?? [];
$ship     = $shipArr[0]         ?? [];
$dateRaw  = $data['date_created'] ?? null;
$orderId  = $data['order_id']   ?? null;

// 2) Create invoice header via RPC
$dt = $dateRaw
    ? new DateTime($dateRaw)
    : new DateTime('now', new DateTimeZone('UTC'));
$dt->setTimezone(new DateTimeZone('Pacific/Auckland'));
$orderDate = $dt->format('Y-m-d');

$client = new jsonRPCClient($api_url, false);
$creds  = (object)[ 'system_id'=>$sys_id,'username'=>$username,'password'=>$password ];

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
$res = $client->createOrder($creds, $header);
$invId = is_array($res) && isset($res['id']) ? $res['id'] : (int)$res;

// 3) Log in to UI to get session cookie
$cookieFile = sys_get_temp_dir() . '/omins_cookie.txt';
$loginUrl   = 'https://omins.snipesoft.net.nz/modules/omins/login.php';
$loginData  = http_build_query([
    'username' => $username,
    'password' => $password,
    'submit'   => 'Login'
]);
$ch = curl_init($loginUrl);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $loginData,
    CURLOPT_COOKIEJAR      => $cookieFile,
    CURLOPT_COOKIEFILE     => $cookieFile,
    CURLOPT_RETURNTRANSFER => true,
]);
curl_exec($ch);
curl_close($ch);

// 4) GET the edit page and scrape hidden inputs
$editUrl = "https://omins.snipesoft.net.nz/modules/omins/invoices_addedit.php?tableid=1041&id={$invId}&command=edit";
$ch = curl_init($editUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE     => $cookieFile,
]);
$html = curl_exec($ch);
curl_close($ch);

// extract all <input type="hidden" name="..." value="...">
preg_match_all('/<input[^>]+type="hidden"[^>]+name="([^"]+)"[^>]+value="([^"]*)"/i', 
               $html, $matches, PREG_SET_ORDER);

$form = [];
foreach ($matches as $m) {
    $form[$m[1]] = $m[2];
}

// 5) Inject line items into $form
$form['lineitemschanged'] = 1;
foreach ($products as $i => $p) {
    $n = $i + 1;
    $form["upc_{$n}"]           = $p['sku'];
    $form["partnumber_{$n}"]    = $p['sku'];
    $form["ds-partnumber_{$n}"] = $p['name_customer'];
    $form["price_{$n}"]         = number_format($p['price_inc_tax'],4,'.','');
    $form["qty_{$n}"]           = $p['quantity'];
}

// 6) POST back to save
$postUrl = "https://omins.snipesoft.net.nz/modules/omins/invoices_addedit.php?tableid=1041&id={$invId}";
$ch = curl_init($postUrl);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query($form),
    CURLOPT_COOKIEFILE     => $cookieFile,
    CURLOPT_COOKIEJAR      => $cookieFile,
    CURLOPT_RETURNTRANSFER => true,
]);
$response = curl_exec($ch);
curl_close($ch);

// 7) Return result
echo json_encode([
    'status'     => 'success',
    'invoice_id' => $invId,
    'html_snippet'=> substr($response, 0, 200)
]);
