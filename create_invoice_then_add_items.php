<?php
// create_invoice_with_curl.php
// Creates a brand new invoice (header + two line items) entirely via OMINS’s UI form and cURL.

// ————————————————————————————
// STEP 0: CONFIGURATION
// ————————————————————————————
//  • Make sure jsonRPCClient.php and 00_creds.php are NOT required here—
//    we do everything via HTTP, not via RPC.
//  • Paste your OMINS session cookie (only PHPSESSID & omins_db) below:
$sessionCookie = 'PHPSESSID=91b5af7917b2462941b6ce69d9463b68; omins_db=omins_12271';
// ————————————————————————————

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors','1');

// -----------------------------------------------------------------------------
// STEP 1: FETCH THE “NEW INVOICE” FORM (no id or id=0)
// -----------------------------------------------------------------------------
$tableId = 1041;
$newInvoiceUrl = "https://omins.snipesoft.net.nz/modules/omins/invoices_addedit.php?tableid={$tableId}&id=0";

$ch = curl_init($newInvoiceUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true,     // we want headers + body
    CURLOPT_HTTPHEADER     => [
        "Cookie: {$sessionCookie}",
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)"
    ],
]);
$rawFetch = curl_exec($ch);
$fetchErr = curl_error($ch);
$fetchCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($fetchErr) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'stage'   => 'fetchNewForm',
        'message' => $fetchErr
    ]);
    exit;
}

list($fetchHeaders, $fetchBody) = explode("\r\n\r\n", $rawFetch, 2);
error_log("📥 [UI GET new invoice] HTTP status: {$fetchCode}");
error_log("📄 [UI GET new invoice] First 1000 chars of body:\n" . substr($fetchBody, 0, 1000));

// If OMINS redirected us (login page), fetchCode would be 302—check that:
if ($fetchCode >= 300 && $fetchCode < 400) {
    error_log("⚠️ [UI GET] Redirected—possible login required. Verify your session cookie.");
    echo json_encode([
      'status' => 'error',
      'stage'  => 'fetchNewForm',
      'message'=> 'Redirect detected—login required.'
    ]);
    exit;
}

// -----------------------------------------------------------------------------
// STEP 2: PARSE & PRESERVE ALL FORM FIELDS FROM THE “NEW INVOICE” PAGE
// -----------------------------------------------------------------------------

$formData = [];

// 2a) Capture every <input name="X" value="Y">
preg_match_all(
    '/<input[^>]+name=["\']([^"\']+)["\'][^>]*value=["\']([^"\']*)["\'][^>]*>/Ui',
    $fetchBody,
    $inputMatches,
    PREG_SET_ORDER
);
foreach ($inputMatches as $m) {
    $formData[$m[1]] = $m[2];
}

// 2b) Capture every <textarea name="X">…</textarea>
preg_match_all(
    '/<textarea[^>]+name=["\']([^"\']+)["\'][^>]*>(.*?)<\/textarea>/is',
    $fetchBody,
    $areaMatches,
    PREG_SET_ORDER
);
foreach ($areaMatches as $m) {
    $formData[$m[1]] = $m[2];
}

// 2c) Log how many fields we parsed and sample a few
error_log("🔍 Parsed " . count($formData) . " form fields from “New Invoice” form.");
$i = 0;
foreach ($formData as $key => $val) {
    if ($i++ >= 30) break;
    error_log("    [Field] {$key} => “" . substr($val,0,100) . (strlen($val)>100?"…":"") . "”");
}
if (count($formData) > 30) {
    error_log("    …and " . (count($formData)-30) . " more fields.");
}

// -----------------------------------------------------------------------------
// STEP 3: OVERRIDE NECESSARY HEADER FIELDS
// -----------------------------------------------------------------------------

// 3a) In a “new” invoice, there’s no recordid yet. OMINS expects:
//     • promo_group_id, orderdate, statusdate, type, oldType, etc.
//     Most are already in $formData from step 2—just override those you want.

$today = new DateTime('now', new DateTimeZone('Pacific/Auckland'));
$orderDateIso = $today->format('d/m/Y');  // OMINS uses DD/MM/YYYY format in forms

$formData['promo_group_id']   = '33';
$formData['orderdate']        = $orderDateIso;
$formData['statusdate']       = $orderDateIso;
$formData['type']             = 'invoice';
$formData['oldType']          = 'invoice';
$formData['taxable']          = '1';
$formData['taxareaid']        = '1';
$formData['discountamount']   = '0.00';

// 3b) Set customer/shipping details (override if OMINS form had defaults)
$formData['name']    = 'Curl Test Customer';
$formData['company'] = 'Curl Co Ltd';
$formData['address'] = '789 Curl Street';
$formData['city']    = 'Auckland';
$formData['postcode']= '1010';
$formData['state']   = '';
$formData['country'] = 'New Zealand';
$formData['phone']   = '09 555 0000';
$formData['mobile']  = '027 555 0000';
$formData['email']   = 'curltest@example.nz';
$formData['note']    = 'Created entirely via cURL.';

// 3c) Ensure OMINS knows we will add line items
$formData['lineitemschanged'] = '1';

// -----------------------------------------------------------------------------
// STEP 4: INSERT TWO LINE ITEMS (INCLUDE “template_N” FIELDS)
// -----------------------------------------------------------------------------

// 4a) Remove any existing line‐item keys (it’s a new form, but just in case):
foreach ($formData as $key => $_) {
    if (preg_match('/^(upc|ds\-upc|partnumber|ds\-partnumber|description|line_shipping|price|qty|extended|template)_[0-9]+$/i', $key)) {
        unset($formData[$key]);
    }
}

// 4b) Now add two hardcoded line items.  
//     • The “template_N” must be a valid ID from OMINS. 
//     • If you’re not sure, inspect the fetched HTML for a <select name="template_1">…</select> and pick an <option value="…">.  
//     • Here we’ll guess “123” and “456”—**replace** these with real IDs from your OMINS.

$products = [
    [
        'template'    => '123',         // <— replace with a valid template ID from your OMINS
        'upc'         => '1868',
        'partnumber'  => '1868',
        'ds_partno'   => 'MED CURL POLE',
        'description' => 'Medium Curl Pole',
        'line_ship'   => '$0.00',
        'price'       => '$75.0000',
        'qty'         => '2',
        'extended'    => '$150.00',
    ],
    [
        'template'    => '456',         // <— replace with another valid template ID
        'upc'         => '4762',
        'partnumber'  => '4762',
        'ds_partno'   => 'Curl Frame Pro 2.4m',
        'description' => 'Curl Frame Pro 2.4m',
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

// 4c) Log the newly injected fields
error_log("⚙️ Injected line‐item fields for new invoice:");
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

// -----------------------------------------------------------------------------
// STEP 5: POST BACK TO “CREATE” THE INVOICE (HEADER + ITEMS)
// -----------------------------------------------------------------------------

$postUrl = $newInvoiceUrl; // same URL as the GET, but now with filled formData

$ch = curl_init($postUrl);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query($formData),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true, 
    CURLOPT_HTTPHEADER     => [
        "Content-Type: application/x-www-form-urlencoded",
        "Cookie: {$sessionCookie}",
        "Referer: {$newInvoiceUrl}",
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)"
    ],
]);
$rawPost = curl_exec($ch);
$postErr  = curl_error($ch);
$postCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($postErr) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'stage'   => 'uiFormPost',
        'message' => $postErr
    ]);
    exit;
}

list($postHeaders, $postBody) = explode("\r\n\r\n", $rawPost, 2);

error_log("✅ [UI POST new invoice] HTTP status: {$postCode}");
error_log("🔍 [UI POST new invoice] First 2000 chars of body:\n" . substr($postBody, 0, 2000));

// If OMINS created successfully, it will redirect (302) back to edit that new invoice:
$status = ($postCode >= 300 && $postCode < 400) ? 'success' : 'error';

echo json_encode([
    'status'       => $status,
    'http_code'    => $postCode,
    'headers'      => $postHeaders,
    'body_snippet' => substr($postBody, 0, 500)
]);
