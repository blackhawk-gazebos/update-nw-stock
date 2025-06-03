<?php
// test_invoice_ui.php
// Hardcoded test: log in, fetch invoice 30641’s edit form, inject two sample lines, and save.

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors','1');

require_once 'jsonRPCClient.php';
require_once '00_creds.php';   // defines $username, $password, $sys_id

// 1) Hard‐coded invoice to test against
$invId = 30641;  // replace with a valid invoice ID you created manually

// 2) Hard‐coded sample products to inject
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
        // 'line_id' => '119339', // optional if updating an existing line
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
    ],
];

// 3) Log in to OMINS UI (to get a session cookie)
$cookieFile = sys_get_temp_dir() . '/omins_ui_cookie_test.txt';
$loginUrl   = 'https://omins.snipesoft.net.nz/modules/omins/login.php';
$loginData  = http_build_query([
    'username' => $username,
    'password' => $password,
    'submit'   => 'Login',
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

// 4) Fetch the invoice “edit” page and scrape hidden inputs
$editUrl = "https://omins.snipesoft.net.nz/modules/omins/invoices_addedit.php?tableid=1041&id={$invId}&command=edit";
$ch = curl_init($editUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE     => $cookieFile,
]);
$html = curl_exec($ch);
curl_close($ch);

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

// 5) Overwrite necessary save flags and remove any existing line‐item fields
$form['command']                = 'save';
$form['recordid']               = $invId;
$form['omins_submit_system_id'] = $sys_id;
$form['lineitemschanged']       = 1;

// Remove existing line‐item keys (upc_N, partnumber_N, etc.)
foreach ($form as $k => $v) {
    if (preg_match('/^(upc|partnumber|ds-partnumber|description|line_shipping|price|qty|extended)_[0-9]+$/', $k)) {
        unset($form[$k]);
    }
    if (preg_match('/^line_id_[0-9]+$/', $k)) {
        unset($form[$k]);
    }
}

// 6) Inject our two hard‐coded lines
foreach ($products as $i => $p) {
    $n = $i + 1;
    // Only include line_id_N if you want to update an existing line; omit to create new
    // $form["line_id_{$n}"]       = $p['line_id'] ?? $n;
    $form["upc_{$n}"]              = $p['upc'];
    $form["partnumber_{$n}"]       = $p['partnumber'];
    $form["ds-partnumber_{$n}"]    = $p['ds_partnumber'];
    $form["description_{$n}"]      = $p['description'];
    $form["line_shipping_{$n}"]    = $p['line_shipping'];
    $form["price_{$n}"]            = $p['price'];
    $form["qty_{$n}"]              = $p['qty'];
    $form["extended_{$n}"]         = $p['extended'];
}

// (Optional) Set tax fields if not already present
$form['taxable']     = '1';
$form['taxareaid']   = '1';
$form['taxpercentage'] = '15.00000%';

// 7) POST the full form back to save the invoice
$postUrl = "https://omins.snipesoft.net.nz/modules/omins/invoices_addedit.php?tableid=1041&id={$invId}";
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

// 8) Return success and snippet of the HTML response
echo json_encode([
    'status'      => 'success',
    'invoice_id'  => $invId,
    'html_snippet'=> substr($saveResp, 0, 200)
]);
