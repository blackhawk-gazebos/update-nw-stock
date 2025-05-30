<?php
// debug_invoice_form.php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors','1');

require_once 'jsonRPCClient.php';
require_once '00_creds.php';

// 1) Parse incoming JSON (as before)
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
$products = $data['products'] ?? [];
$orderId  = $data['order_id'] ?? null;

// 2) Create blank invoice via RPC (omitted here—assume $invId is known)
$invId = $orderId; // for debug, you can simply set this to an existing invoice ID

// 3) Log in to get session cookie
$cookieFile = sys_get_temp_dir() . '/omins_debug_cookie.txt';
$ch = curl_init('https://omins.snipesoft.net.nz/modules/omins/login.php');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query(['username'=>$username,'password'=>$password,'submit'=>'Login']),
    CURLOPT_COOKIEJAR      => $cookieFile,
    CURLOPT_COOKIEFILE     => $cookieFile,
    CURLOPT_RETURNTRANSFER => true,
]);
curl_exec($ch); curl_close($ch);

// 4) Fetch the edit form
$editUrl = "https://omins.snipesoft.net.nz/modules/omins/invoices_addedit.php?tableid=1041&id={$invId}&command=edit";
$ch = curl_init($editUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE     => $cookieFile,
]);
$html = curl_exec($ch);
curl_close($ch);

// 5) Scrape all hidden inputs
preg_match_all(
    '/<input[^>]+type=["\']hidden["\'][^>]*>/i',
    $html,
    $hiddenMatches
);

$hiddenData = [];
foreach ($hiddenMatches[0] as $inputTag) {
    if (preg_match('/name=["\']([^"\']+)["\']/', $inputTag, $n) &&
        preg_match('/value=["\']([^"\']*)["\']/', $inputTag, $v)) {
        $hiddenData[$n[1]] = $v[1];
    }
}

error_log("🔍 Hidden fields:\n" . print_r($hiddenData, true));

// 6) Build final form array (hidden + line items)
$form = $hiddenData;
$form['lineitemschanged'] = 1;
foreach ($products as $i => $p) {
    $n = $i + 1;
    $form["upc_{$n}"]           = $p['sku'];
    $form["partnumber_{$n}"]    = $p['sku'];
    $form["ds-partnumber_{$n}"] = $p['name_customer'];
    $form["price_{$n}"]         = $p['price_inc_tax'];
    $form["qty_{$n}"]           = $p['quantity'];
}

error_log("🛠️ Final form to POST:\n" . print_r($form, true));

// 7) Just return success so Zapier sees 200
echo json_encode(['status'=>'debugged']);
