<?php
// create_and_edit_invoice_debug.php
// 1) create via RPC, 2) fetch “Edit Invoice” form HTML (full), 3) parse + inject line items (with template_1/template_2), 4) POST back, 5) log everything.

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors','1');

require_once 'jsonRPCClient.php';
require_once '00_creds.php'; // defines: $api_url, $sys_id, $username, $password

// ----------------------------------------------------------------------------
// STEP 1: CREATE A NEW INVOICE VIA JSON-RPC
// ----------------------------------------------------------------------------

$client = new jsonRPCClient($api_url, false);
$creds  = (object)[
    'system_id'=> $sys_id,
    'username' => $username,
    'password' => $password
];

$today = new DateTime('now', new DateTimeZone('Pacific/Auckland'));
$orderDate = $today->format('Y-m-d');

$rpcParams = [
    'promo_group_id'   => 33,
    'orderdate'        => $orderDate,
    'statusdate'       => $orderDate,
    'name'             => 'RPC/UI Debug Customer',
    'company'          => 'Debug Co Ltd',
    'address'          => '45 Debug Lane',
    'city'             => 'Wellington',
    'postcode'         => '6011',
    'state'            => '',
    'country'          => 'New Zealand',
    'phone'            => '021 999 0000',
    'mobile'           => '021 999 0001',
    'email'            => 'debug@example.nz',
    'type'             => 'invoice',
    'note'             => 'Debug: RPC → UI form',
    'taxable'          => '1',
    'taxareaid'        => 1,
    'discountamount'   => '0.00',
    'thelineitems'     => [],
    'lineitemschanged' => 0,
];

try {
    $createRes = $client->createOrder($creds, $rpcParams);
    if (is_array($createRes) && isset($createRes['id'])) {
        $invoiceId = intval($createRes['id']);
    } elseif (is_numeric($createRes)) {
        $invoiceId = intval($createRes);
    } else {
        throw new Exception("Unexpected createOrder response: " . print_r($createRes, true));
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

// ----------------------------------------------------------------------------
// STEP 2: FETCH THE INVOICE “EDIT” FORM (RAW HTML)
// ----------------------------------------------------------------------------

// 2a) Paste your OMINS session cookie (PHPSESSID & omins_db). Only these two.
$sessionCookie = 'PHPSESSID=91b5af7917b2462941b6ce69d9463b68; omins_db=omins_12271';

// 2b) Build the Edit URL
$tableId = 1041;
$editUrl = "https://omins.snipesoft.net.nz/modules/omins/invoices_addedit.php?tableid={$tableId}&id={$invoiceId}";

$ch = curl_init($editUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => false,
    CURLOPT_HTTPHEADER     => [
        "Cookie: {$sessionCookie}",
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/136.0.0.0 Safari/537.36",
    ],
]);
$html = curl_exec($ch);
$curlErr = curl_error($ch);
curl_close($ch);

if ($curlErr !== '') {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'stage'   => 'fetchForm',
        'message' => $curlErr
    ]);
    exit;
}

// Log full HTML for debugging (it can be large)
error_log("📄 Full “Edit Invoice” HTML for invoice {$invoiceId}:\n" . $html);

// ----------------------------------------------------------------------------
// STEP 3: PARSE HIDDEN INPUTS + TEXT FIELDS + TEXTAREAS
// ----------------------------------------------------------------------------

$formData = [];

// 3a) Capture every <input type="hidden" name="X" value="Y">
preg_match_all(
    '/<input[^>]+type=["\']hidden["\'][^>]*name=["\']([^"\']+)["\'][^>]*value=["\']([^"\']*)["\'][^>]*>/i',
    $html,
    $hiddenMatches,
    PREG_SET_ORDER
);
foreach ($hiddenMatches as $m) {
    $formData[$m[1]] = $m[2];
}

// 3b) Capture any <input name="X" value="Y"> (visible or hidden)
preg_match_all(
    '/<input[^>]+name=["\']([^"\']+)["\'][^>]*value=["\']([^"\']*)["\'][^>]*>/i',
    $html,
    $textMatches,
    PREG_SET_ORDER
);
foreach ($textMatches as $m) {
    if (!isset($formData[$m[1]])) {
        $formData[$m[1]] = $m[2];
    }
}

// 3c) Capture any <textarea name="X">…</textarea>
preg_match_all(
    '/<textarea[^>]+name=["\']([^"\']+)["\'][^>]*>(.*?)<\/textarea>/is',
    $html,
    $areaMatches,
    PREG_SET_ORDER
);
foreach ($areaMatches as $m) {
    $formData[$m[1]] = $m[2];
}

// 3d) (Debug) Show total parsed fields count
error_log("🔍 Parsed form data count: " . count($formData));

// 3e) (Debug) Log first 20 keys of parsed form data
$keys = array_keys($formData);
for ($i = 0; $i < min(20, count($keys)); $i++) {
    error_log("    Parsed field: {$keys[$i]} => {$formData[$keys[$i]]}");
}

// ----------------------------------------------------------------------------
// STEP 4: OVERRIDE/SET HEADER VALUES & INJECT LINE ITEMS (WITH TEMPLATE IDs)
// ----------------------------------------------------------------------------

// 4a) Ensure we’re saving this existing invoice
$formData['command']                = 'save';
$formData['recordid']               = $invoiceId;
$formData['omins_submit_system_id'] = $sys_id;
$formData['lineitemschanged']       = '1';

// 4b) Remove any existing line-item keys, so we start “fresh”
foreach ($formData as $key => $val) {
    if (preg_match('/^(upc|ds-upc|partnumber|ds-partnumber|description|line_shipping|price|qty|extended|line_id|template)_[0-9]+$/i', $key)) {
        unset($formData[$key]);
    }
}

// 4c) Inject two line items—including “template_1” and “template_2” fields
//     (Change “0” to a valid template ID if your OMINS requires it.)

$products = [
    [
        'template'    => '0',                     // placeholder; replace with valid template ID if needed
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
    ],
];

foreach ($products as $i => $p) {
    $idx = $i + 1;
    // If a <select name="template_{$idx}"> exists in the form, setting this ensures OMINS knows which template to use.
    $formData["template_{$idx}"]    = $p['template'];
    $formData["upc_{$idx}"]         = $p['upc'];
    $formData["partnumber_{$idx}"]  = $p['partnumber'];
    $formData["ds-partnumber_{$idx}"]= $p['ds_partno'];
    $formData["description_{$idx}"] = $p['description'];
    $formData["line_shipping_{$idx}"]= $p['line_ship'];
    $formData["price_{$idx}"]       = $p['price'];
    $formData["qty_{$idx}"]         = $p['qty'];
    $formData["extended_{$idx}"]    = $p['extended'];
}

// 4d) (Debug) Log final form data keys (first 30)
error_log("⚙️ Final form data keys (partial):");
$allKeys = array_keys($formData);
for ($i = 0; $i < min(30, count($allKeys)); $i++) {
    error_log("    {$allKeys[$i]} => {$formData[$allKeys[$i]]}");
}

// ----------------------------------------------------------------------------
// STEP 5: POST BACK THE COMPLETE FORM TO SAVE LINES
// ----------------------------------------------------------------------------

$postUrl = $editUrl; // same endpoint we fetched

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
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/136.0.0.0 Safari/537.36",
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

// Separate headers & body
list($respHeaders, $respBody) = explode("\r\n\r\n", $response, 2);

// Log response details
error_log("✅ UI POST HTTP status: {$httpCode}");
error_log("🔍 UI response body (first 200 chars):\n" . substr($respBody, 0, 200));

// ----------------------------------------------------------------------------
// STEP 6: RETURN JSON SUMMARY
// ----------------------------------------------------------------------------

echo json_encode([
    'status'       => ($httpCode >= 200 && $httpCode < 300) ? 'success' : 'error',
    'invoice_id'   => $invoiceId,
    'http_code'    => $httpCode,
    'headers'      => $respHeaders,
    'body_snippet' => substr($respBody, 0, 200)
]);
