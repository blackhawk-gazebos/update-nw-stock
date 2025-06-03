<?php
// test_create_new_invoice_ui.php
// 1) Log in → 2) Fetch “new invoice” form → 3) Inject header + two line items → 4) POST to create

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors','1');

require_once '00_creds.php';   // must define $username, $password, and $sys_id

// === 1) Log in to OMINS UI to get session cookie ===
$cookieFile = sys_get_temp_dir() . '/omins_new_invoice_cookie.txt';
$loginUrl   = 'https://omins.snipesoft.net.nz/modules/omins/login.php';

$loginData = http_build_query([
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

// === 2) Fetch the “new invoice” form (no id= parameter) ===
$tableId = 1041;  // your OMINS “invoices” table ID
$newUrl  = "https://omins.snipesoft.net.nz/modules/omins/invoices_addedit.php?tableid={$tableId}";
$ch = curl_init($newUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE     => $cookieFile,
]);
$html = curl_exec($ch);
curl_close($ch);

// Scrape every <input type="hidden" name="..." value="..."> from that form
preg_match_all(
    '/<input[^>]+type="hidden"[^>]+name="([^"]+)"[^>]+value="([^"]*)"/i',
    $html,
    $hiddenMatches,
    PREG_SET_ORDER
);

$form = [];
foreach ($hiddenMatches as $m) {
    $form[$m[1]] = $m[2];
}

// === 3) Overwrite/create the fields we need for a new invoice + two line items ===

// Keep all hidden‐field values (so we preserve existence of any CSRF tokens, default promo_group_id, etc.),
// then override/add key values for the invoice header.

// Mandatory for “save”:
$form['command']                = 'save';
$form['recordid']               = '';               // blank for new record
$form['omins_submit_system_id'] = $sys_id;
$form['lineitemschanged']       = '1';

// Invoice header fields (hard‐coded example):
$form['promo_group_id']   = '33';                    // pick any valid promo group in your system
// Dates must be DD/MM/YYYY:
$form['orderdate']        = '05/06/2025';
$form['statusdate']       = '05/06/2025';
// Customer info (hard‐coded):
$form['name']             = 'Test Customer';
$form['company']          = 'Example Co';
$form['address']          = '123 Example Street';
$form['city']             = 'Wellington';
$form['postcode']         = '6011';
$form['state']            = '';
$form['country']          = 'New Zealand';
$form['phone']            = '021 000 000';
$form['mobile']           = '021 000 000';
$form['email']            = 'test@example.co.nz';
$form['type']             = 'invoice';
$form['statusid']         = '1-processing';
$form['taxable']          = '1';
$form['taxareaid']        = '1';
$form['taxpercentage']    = '15.00000%';
$form['note']             = 'Created via PHP test script';
$form['cash_sale']        = '1';

// Remove any existing “upc_N”, “partnumber_N” etc. so we only inject our two test lines:
foreach ($form as $k => $v) {
    if (preg_match('/^(upc|partnumber|ds-partnumber|description|line_shipping|price|qty|extended)_[0-9]+$/', $k)) {
        unset($form[$k]);
    }
    if (preg_match('/^line_id_[0-9]+$/', $k)) {
        unset($form[$k]);
    }
}

// === Insert two hard‐coded line items ===
$products = [
    [
        'upc'           => '1868',
        'partnumber'    => '1868',
        'ds_partnumber' => 'MED FLAG POLE',
        'description'   => 'Flag Pole - MED',
        'line_shipping' => '$0.00',
        'price'         => '$90.0000',
        'qty'           => '1',
        'extended'      => '$90.00',
    ],
    [
        'upc'           => '4762',
        'partnumber'    => '4762',
        'ds_partnumber' => '3m Frame Pro Steel 24new',
        'description'   => '3m Pro Steel Frame with Carry bag',
        'line_shipping' => '$0.00',
        'price'         => '$0.0000',
        'qty'           => '1',
        'extended'      => '$0.00',
    ]
];

foreach ($products as $i => $p) {
    $n = $i + 1;
    // No “line_id_N” needed for new lines.
    $form["upc_{$n}"]            = $p['upc'];
    $form["partnumber_{$n}"]     = $p['partnumber'];
    $form["ds-partnumber_{$n}"]  = $p['ds_partnumber'];
    $form["description_{$n}"]    = $p['description'];
    $form["line_shipping_{$n}"]  = $p['line_shipping'];
    $form["price_{$n}"]          = $p['price'];
    $form["qty_{$n}"]            = $p['qty'];
    $form["extended_{$n}"]       = $p['extended'];
}

// === 4) POST the entire form back to create/save the invoice ===
$postUrl = "https://omins.snipesoft.net.nz/modules/omins/invoices_addedit.php?tableid={$tableId}";

$ch = curl_init($postUrl);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query($form),
    CURLOPT_COOKIEFILE     => $cookieFile,
    CURLOPT_COOKIEJAR      => $cookieFile,
    CURLOPT_RETURNTRANSFER => true,
]);
$saveResp = curl_exec($ch);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>"cURL error: {$curlErr}"]);
    exit;
}

// === 5) Return a snippet of the HTML response (for debugging) ===
echo json_encode([
    'status'       => 'success',
    'html_snippet' => substr($saveResp, 0, 200)
]);
