<?php
// create_and_edit_invoice_keep_headers.php
// 1) Create via RPC → 2) Fetch that invoice’s “edit” form → 3) Parse & preserve all fields →
// 4) Inject two new line items → 5) POST back to save them on the same invoice.

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors','1');

require_once 'jsonRPCClient.php';
require_once '00_creds.php';  // defines $api_url, $sys_id, $username, $password

// -----------------------------------------------------------------------------
// STEP 1: CREATE A NEW INVOICE VIA JSON‐RPC
// -----------------------------------------------------------------------------

$client = new jsonRPCClient($api_url, false);
$creds  = (object)[
    'system_id' => $sys_id,
    'username'  => $username,
    'password'  => $password
];

// Minimal “header only” parameters:
$today = new DateTime('now', new DateTimeZone('Pacific/Auckland'));
$orderDate = $today->format('Y-m-d'); 

$rpcParams = [
    'promo_group_id'   => 33,
    'orderdate'        => $orderDate,
    'statusdate'       => $orderDate,
    'name'             => 'RPC/API Customer',
    'company'          => 'KeepHeaders Ltd',
    'address'          => '456 Main Avenue',
    'city'             => 'Wellington',
    'postcode'         => '6011',
    'state'            => '',
    'country'          => 'New Zealand',
    'phone'            => '021 777 0000',
    'mobile'           => '021 777 0001',
    'email'            => 'keepheaders@example.nz',
    'type'             => 'invoice',
    'note'             => 'RPC → fetch form → add items',
    'taxable'          => '1',
    'taxareaid'        => 1,
    'discountamount'   => '0.00',
    'thelineitems'     => [],    // no items yet
    'lineitemschanged' => 0
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

error_log("✅ RPC created invoice ID: {$invoiceId}");

// -----------------------------------------------------------------------------
// STEP 2: FETCH THE “EDIT INVOICE” FORM FOR THAT ID
// -----------------------------------------------------------------------------

// 2a) Copy exactly your OMINS session cookie (only PHPSESSID & omins_db)
//     from your browser. No other cookies are needed.
$sessionCookie = 'PHPSESSID=91b5af7917b2462941b6ce69d9463b68; omins_db=omins_12271';

// 2b) Build the edit‐URL
$tableId = 1041;
$editUrl = "https://omins.snipesoft.net.nz/modules/omins/invoices_addedit.php?tableid={$tableId}&id={$invoiceId}";

$ch = curl_init($editUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        "Cookie: {$sessionCookie}",
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)"
    ],
]);
$html = curl_exec($ch);
$err  = curl_error($ch);
curl_close($ch);

if ($err !== '') {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'stage'   => 'fetchForm',
        'message' => $err
    ]);
    exit;
}

// Log the full fetched HTML (for debugging—inspect this if something’s missing)
error_log("📄 Fetched “Edit Invoice” HTML for invoice {$invoiceId}:\n" . $html);

// -----------------------------------------------------------------------------
// STEP 3: PARSE (AND PRESERVE) ALL FORM FIELDS FROM THAT HTML
// -----------------------------------------------------------------------------

$formData = [];

// 3a) Capture every <input … name="X" value="Y" …>
preg_match_all(
    '/<input[^>]+name=["\']([^"\']+)["\'][^>]*value=["\']([^"\']*)["\'][^>]*>/Ui',
    $html,
    $inputMatches,
    PREG_SET_ORDER
);
foreach ($inputMatches as $m) {
    // Since it matches *both* type="hidden" and visible inputs, we preserve everything
    $formData[$m[1]] = $m[2];
}

// 3b) Capture every <textarea name="X">…</textarea>
preg_match_all(
    '/<textarea[^>]+name=["\']([^"\']+)["\'][^>]*>(.*?)<\/textarea>/is',
    $html,
    $areaMatches,
    PREG_SET_ORDER
);
foreach ($areaMatches as $m) {
    $formData[$m[1]] = $m[2];
}

// Note: Most of OMINS’s form uses <input> rather than <select>, so we already have
//       promo_group_id, orderdate, type, oldType, etc., collected above.

// Log how many fields we parsed
error_log("🔍 Parsed formData fields count: " . count($formData));

// -----------------------------------------------------------------------------
// STEP 4: OVERRIDE ONLY THE NECESSARY FIELDS & INJECT LINE ITEMS
// -----------------------------------------------------------------------------

// 4a) We must tell OMINS this is a “Save” on that same invoice
$formData['command']                = 'save';
$formData['recordid']               = $invoiceId;         // ensure we update, not create a new one
$formData['omins_submit_system_id'] = $sys_id;            // from 00_creds.php
$formData['lineitemschanged']       = '1';                // flag that lines have changed

// 4b) Remove any previously existing line‐item keys so we can insert ours cleanly
foreach ($formData as $key => $_) {
    // We clear out any key matching “upc_N, ds-upc_N, partnumber_N, ds-partnumber_N, 
    // description_N, line_shipping_N, price_N, qty_N, extended_N, template_N”
    if (preg_match('/^(upc|ds\-upc|partnumber|ds\-partnumber|description|line_shipping|price|qty|extended|template)_[0-9]+$/i', $key)) {
        unset($formData[$key]);
    }
}

// 4c) Inject exactly two line items (you can replace these hardcoded values with dynamic ones later)
//     Note: We include “template_1” and “template_2”—OMINS usually requires a template ID. 
//           If “0” causes SQL errors, change to a valid template ID from your OMINS “Product Templates.”
$products = [
    [
        'template'    => '0',                       // placeholder: replace with a valid template ID if needed
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
    $formData["line_shipping_{$n}"] = $p['line_ship'];
    $formData["price_{$n}"]        = $p['price'];
    $formData["qty_{$n}"]          = $p['qty'];
    $formData["extended_{$n}"]     = $p['extended'];
}

// 4d) Debug: log a sample of the final formData keys/values
error_log("⚙️ Final formData (partial):");
$keys = array_keys($formData);
for ($i = 0; $i < min(30, count($keys)); $i++) {
    $k = $keys[$i];
    error_log("    {$k} => {$formData[$k]}");
}

// -----------------------------------------------------------------------------
// STEP 5: POST BACK THE FULL FORM TO SAVE THE LIST ITEMS
// -----------------------------------------------------------------------------

$postUrl = $editUrl; 

$ch = curl_init($postUrl);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query($formData),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true,
    CURLOPT_HTTPHEADER     => [
        "Content-Type: application/x-www-form-urlencoded",
        "Cookie: {$sessionCookie}",
        "Referer: {$editUrl}",
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)"
    ],
]);
$response = curl_exec($ch);
$curlErr  = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($curlErr !== '') {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'stage'   => 'uiFormPost',
        'message' => $curlErr
    ]);
    exit;
}

// Separate headers & body for logging
list($respHeaders, $respBody) = explode("\r\n\r\n", $response, 2);

error_log("✅ UI POST HTTP status: {$httpCode}");
error_log("🔍 UI response body (first 200 chars):\n" . substr($respBody, 0, 200));

// -----------------------------------------------------------------------------
// FINAL: RETURN JSON SUMMARY
// -----------------------------------------------------------------------------

echo json_encode([
    'status'       => ($httpCode === 302) ? 'success' : 'error',
    'invoice_id'   => $invoiceId,
    'http_code'    => $httpCode,
    'body_snippet' => substr($respBody, 0, 200)
]);
