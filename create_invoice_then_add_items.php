<?php
// debug_invoice_with_lines.php
// 1) create via RPC → 2) fetch “edit” form (log HTTP code + full HTML) →
// 3) parse + log all fields → 4) inject two lines (template_N placeholders) →
// 5) POST back (log HTTP code + full response body), return JSON.

// ----------------------------------------------------------------------------
// CONFIG: Replace these before running
// ----------------------------------------------------------------------------
$sessionCookie = 'PHPSESSID=91b5af7917b2462941b6ce69d9463b68; omins_db=omins_12271';
// ----------------------------------------------------------------------------

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors','1');

require_once 'jsonRPCClient.php';
require_once '00_creds.php'; // defines: $api_url, $sys_id, $username, $password

// ----------------------------------------------------------------------------
// STEP 1: CREATE A NEW INVOICE VIA RPC
// ----------------------------------------------------------------------------

$client = new jsonRPCClient($api_url, false);
$creds  = (object)[
    'system_id' => $sys_id,
    'username'  => $username,
    'password'  => $password
];

$today = new DateTime('now', new DateTimeZone('Pacific/Auckland'));
$orderDate = $today->format('Y-m-d');

$rpcParams = [
    'promo_group_id'   => 33,
    'orderdate'        => $orderDate,
    'statusdate'       => $orderDate,
    'name'             => 'Debug Customer',
    'company'          => 'Debug Co Ltd',
    'address'          => '123 Debug Road',
    'city'             => 'Wellington',
    'postcode'         => '6011',
    'state'            => '',
    'country'          => 'New Zealand',
    'phone'            => '021 111 0000',
    'mobile'           => '021 111 0001',
    'email'            => 'debug@example.nz',
    'type'             => 'invoice',
    'note'             => 'Debug → fetch form → add lines',
    'taxable'          => '1',
    'taxareaid'        => 1,
    'discountamount'   => '0.00',
    'thelineitems'     => [],
    'lineitemschanged' => 0,
];

try {
    $res = $client->createOrder($creds, $rpcParams);
    if (is_array($res) && isset($res['id'])) {
        $invoiceId = intval($res['id']);
    } elseif (is_numeric($res)) {
        $invoiceId = intval($res);
    } else {
        throw new Exception("Unexpected createOrder response: " . print_r($res, true));
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'stage'   => 'createOrder',
        'message' => $e->getMessage()
    ]);
    exit;
}

error_log("✅ [RPC] Created Invoice ID: {$invoiceId}");

// ----------------------------------------------------------------------------
// STEP 2: FETCH THE “EDIT” FORM FOR THAT INVOICE (LOG EVERYTHING)
// ----------------------------------------------------------------------------

$tableId = 1041;
$editUrl = "https://omins.snipesoft.net.nz/modules/omins/invoices_addedit.php?tableid={$tableId}&id={$invoiceId}";

$ch = curl_init($editUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true,  // we want headers + body for debugging
    CURLOPT_HTTPHEADER     => [
        "Cookie: {$sessionCookie}",
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)"
    ],
]);
$rawFetch = curl_exec($ch);
$fetchErr = curl_error($ch);
$fetchCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($fetchErr !== '') {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'stage'   => 'fetchForm',
        'message' => $fetchErr
    ]);
    exit;
}

// Separate headers vs body from fetch
list($fetchHeaders, $fetchBody) = explode("\r\n\r\n", $rawFetch, 2);

// Log HTTP code and headers
error_log("📥 [UI GET] HTTP status: {$fetchCode}");
error_log("📥 [UI GET] Headers:\n" . $fetchHeaders);

// Log full body (trimmed to first 2000 chars for safety)
error_log("📄 [UI GET] Body (first 2000 chars):\n" . substr($fetchBody, 0, 2000));

// (Insert this snippet right here, before parsing inputs)
//
// === BEGIN: Log available template_<N> options ===

// 1a) Search for any <select name="template_X"> in the HTML
preg_match_all(
    '/<select[^>]+name=["\'](template_[0-9]+)["\'][^>]*>(.*?)<\/select>/is',
    $fetchBody,
    $selectMatches,
    PREG_SET_ORDER
);
foreach ($selectMatches as $sm) {
    $fieldName = $sm[1];      // e.g. "template_1"
    $innerHtml = $sm[2];      // the <option>…</option> block
    error_log("🔎 Found <select name=\"{$fieldName}\">…</select> with options:");

    // Now extract every <option value="X">…</option>
    preg_match_all('/<option[^>]+value=["\']([0-9]+)["\'][^>]*>([^<]*)<\/option>/i', $innerHtml, $optMatches, PREG_SET_ORDER);
    foreach ($optMatches as $om) {
        $val = $om[1];
        $txt = trim($om[2]);
        error_log("    • Option: value=\"{$val}\", label=\"{$txt}\"");
    }
}

// 1b) Also check for any hidden <input name="template_X" value="Y">
preg_match_all(
    '/<input[^>]+name=["\'](template_[0-9]+)["\'][^>]*value=["\']([0-9]+)["\'][^>]*>/i',
    $fetchBody,
    $inputTemplateMatches,
    PREG_SET_ORDER
);
foreach ($inputTemplateMatches as $itm) {
    $field = $itm[1];   // e.g. "template_1"
    $val   = $itm[2];   // e.g. "123"
    error_log("🔎 Found <input name=\"{$field}\" value=\"{$val}\" />");
}
// === END: Log available template_<N> options ===


// If fetch code is 302 or 301, it likely redirected to login → show that
if ($fetchCode >= 300 && $fetchCode < 400) {
    error_log("⚠️ [UI GET] Received a redirect (likely login). Please verify your session cookie.");
}

// ----------------------------------------------------------------------------
// STEP 3: PARSE & LOG ALL FORM FIELDS
// ----------------------------------------------------------------------------

$formData = [];

// 3a) Capture every <input name="X" value="Y">
preg_match_all(
    '/<input[^>]+name=["\']([^"\']+)["\'][^>]*value=["\']([^"\']*)["\'][^>]*>/Ui',
    $fetchBody,
    $inputMatches,
    PREG_SET_ORDER
);
foreach ($inputMatches as $m) {
    $formData[$m[1]] = $m[2];
}

// 3b) Capture every <textarea name="X">…</textarea>
preg_match_all(
    '/<textarea[^>]+name=["\']([^"\']+)["\'][^>]*>(.*?)<\/textarea>/is',
    $fetchBody,
    $areaMatches,
    PREG_SET_ORDER
);
foreach ($areaMatches as $m) {
    $formData[$m[1]] = $m[2];
}

// Log parsing results
error_log("🔍 Parsed " . count($formData) . " form fields.");
$i = 0;
foreach ($formData as $key => $val) {
    if ($i++ >= 50) break;
    // Show first 50 fields only
    error_log("    [Field] {$key} => “" . substr($val,0,100) . (strlen($val)>100?"…":"") . "”");
}
if (count($formData) > 50) {
    error_log("    …and " . (count($formData)-50) . " more fields.");
}

// ----------------------------------------------------------------------------
// STEP 4: OVERRIDE ONLY WHAT WE NEED & INJECT TWO LINE ITEMS
// ----------------------------------------------------------------------------

// 4a) Ensure “save” and correct invoice ID
$formData['command']                = 'save';
$formData['recordid']               = $invoiceId;
$formData['omins_submit_system_id'] = $sys_id;
$formData['lineitemschanged']       = '1';

// 4b) Purge any existing line‐item keys to re‐inject ours
foreach ($formData as $key => $_) {
    if (preg_match('/^(upc|ds\-upc|partnumber|ds\-partnumber|description|line_shipping|price|qty|extended|template)_[0-9]+$/i', $key)) {
        unset($formData[$key]);
    }
}

// 4c) Insert two line items. Note: replace “0” with a real template ID if needed.
$products = [
    [
        'template'    => '0',
        'upc'         => '1868',
        'partnumber'  => '1868',
        'ds_partno'   => 'MED FLAG POLE',
        'description' => 'Flag Pole - MED',
        'line_ship'   => '$0.00',
        'price'       => '$90.0000',
        'qty'         => '1',
        'extended'    => '$90.00',
    ],
    [
        'template'    => '0',
        'upc'         => '4762',
        'partnumber'  => '4762',
        'ds_partno'   => '3m Frame Pro Steel 24new',
        'description' => '3m Pro Steel Frame with Carry bag',
        'line_ship'   => '$0.00',
        'price'       => '$0.0000',
        'qty'         => '1',
        'extended'    => '$0.00',
    ]
];

foreach ($products as $i => $p) {
    $n = $i + 1;
    $formData["template_{$n}"]     = $p['template'];
    $formData["upc_{$n}"]          = $p['upc'];
    $formData["partnumber_{$n}"]   = $p['partnumber'];
    $formData["ds-partnumber_{$n}"]= $p['ds_partno'];
    $formData["description_{$n}"]  = $p['description'];
    $formData["line_shipping_{$n}"]= $p['line_ship'];
    $formData["price_{$n}"]        = $p['price'];
    $formData["qty_{$n}"]          = $p['qty'];
    $formData["extended_{$n}"]     = $p['extended'];
}

// 4d) Log the injected line-item keys
error_log("⚙️ Injected line‐item fields:");
foreach ($products as $i => $p) {
    $n = $i + 1;
    error_log("    template_{$n} => {$formData["template_{$n}"]}");
    error_log("    upc_{$n} => {$formData["upc_{$n}"]}");
    error_log("    partnumber_{$n} => {$formData["partnumber_{$n}"]}");
    error_log("    ds-partnumber_{$n} => {$formData["ds-partnumber_{$n}"]}");
    error_log("    description_{$n} => {$formData["description_{$n}"]}");
    error_log("    line_shipping_{$n} => {$formData["line_shipping_{$n}"]}");
    error_log("    price_{$n} => {$formData["price_{$n}"]}");
    error_log("    qty_{$n} => {$formData["qty_{$n}"]}");
    error_log("    extended_{$n} => {$formData["extended_{$n}"]}");
}

// ----------------------------------------------------------------------------
// STEP 5: POST BACK THE FORM (LOG HTTP CODE + FULL RESPONSE BODY)
// ----------------------------------------------------------------------------

$postUrl = $editUrl; // same URL where we fetched

$ch = curl_init($postUrl);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query($formData),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true,  // so we can inspect headers + body
    CURLOPT_HTTPHEADER     => [
        "Content-Type: application/x-www-form-urlencoded",
        "Cookie: {$sessionCookie}",
        "Referer: {$editUrl}",
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)"
    ],
]);
$rawPost = curl_exec($ch);
$postErr = curl_error($ch);
$postCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($postErr !== '') {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'stage'   => 'uiFormPost',
        'message' => $postErr
    ]);
    exit;
}

// Separate headers vs body for the POST response
list($postHeaders, $postBody) = explode("\r\n\r\n", $rawPost, 2);

// Log HTTP code + headers + first 2000 chars of body
error_log("✅ [UI POST] HTTP status: {$postCode}");
error_log("✅ [UI POST] Headers:\n" . $postHeaders);
error_log("🔍 [UI POST] Body (first 2000 chars):\n" . substr($postBody, 0, 2000));

// ----------------------------------------------------------------------------
// FINAL RESPONSE (JSON)
// ----------------------------------------------------------------------------

echo json_encode([
    'status'       => ($postCode >= 200 && $postCode < 400) ? 'success' : 'error',
    'invoice_id'   => $invoiceId,
    'fetch_code'   => $fetchCode,
    'post_code'    => $postCode,
    'fetch_header' => $fetchHeaders,
    'post_header'  => $postHeaders,
    'body_snippet' => substr($postBody, 0, 500)  // first 500 chars of final response
]);
