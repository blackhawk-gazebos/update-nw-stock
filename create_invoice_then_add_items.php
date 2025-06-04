<?php
// create_and_edit_invoice.php
// 1) Create a new invoice via JSON‐RPC (createOrder).
// 2) GET that invoice’s “edit” form, parse hidden fields + existing inputs.
// 3) Inject two hardcoded line items and POST back to save on that invoice.
// 4) Log verbose output (both RPC and UI steps).

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors','1');

require_once 'jsonRPCClient.php';
require_once '00_creds.php'; // defines: $api_url, $sys_id, $username, $password

// === STEP 1: CREATE A NEW INVOICE HEADER VIA JSON‐RPC ===

$client = new jsonRPCClient($api_url, false);
$creds  = (object)[
    'system_id' => $sys_id,
    'username'  => $username,
    'password'  => $password
];

// Build minimal createOrder parameters
// (no line items yet—just header info)
$today = new DateTime('now', new DateTimeZone('Pacific/Auckland'));
$orderDate = $today->format('Y-m-d');

$rpcParams = [
    'promo_group_id'   => 33,
    'orderdate'        => $orderDate,
    'statusdate'       => $orderDate,
    'name'             => 'RPC+UI Customer',
    'company'          => 'RPC Co Ltd',
    'address'          => '123 RPC Road',
    'city'             => 'Wellington',
    'postcode'         => '6011',
    'state'            => '',
    'country'          => 'New Zealand',
    'phone'            => '021 555 0000',
    'mobile'           => '021 555 0001',
    'email'            => 'rpc@example.nz',
    'type'             => 'invoice',
    'note'             => 'Created via RPC; now adding lines via UI form',
    'taxable'          => '1',
    'taxareaid'        => 1,
    'discountamount'   => '0.00',
    // no lines yet:
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
        throw new Exception("Unexpected createOrder response");
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

// === STEP 2: FETCH THAT INVOICE’S EDIT FORM ===

// 2a) Copy your valid OMINS session cookie (just PHPSESSID & omins_db).
//     Log into OMINS in your browser, open dev tools → Application → Cookies,
//     then copy the “PHPSESSID=…; omins_db=…” string exactly.
$sessionCookie = 'PHPSESSID=91b5af7917b2462941b6ce69d9463b68; omins_db=omins_12271';

// 2b) Build the URL for editing that invoice
$tableId = 1041;
$editUrl = "https://omins.snipesoft.net.nz/modules/omins/invoices_addedit.php?tableid={$tableId}&id={$invoiceId}";

$ch = curl_init($editUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE     => $sessionCookie,
    CURLOPT_COOKIEJAR      => $sessionCookie,
    CURLOPT_HTTPHEADER     => [
        "Cookie: {$sessionCookie}",
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/136.0.0.0 Safari/537.36",
    ],
]);
$html = curl_exec($ch);
$err  = curl_error($ch);
curl_close($ch);

if ($err) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'stage'   => 'fetchEditForm',
        'message' => $err
    ]);
    exit;
}

// === STEP 3: PARSE HIDDEN INPUTS (AND ANY EXISTING TEXT FIELDS) ===

// We’ll collect all <input type="hidden" name="..." value="..."> and also
// any <input name="field" value="..." /> for fields we need to preserve.
//
// Regex to match:
//   <input ... type="hidden" ... name="X" value="Y" ...
//   <input ... name="X" value="Y" ...>  (some visible fields are also needed, e.g. promo_group_id)

$formData = [];

// 3a) Hidden inputs
preg_match_all(
    '/<input[^>]+type=["\']hidden["\'][^>]*name=["\']([^"\']+)["\'][^>]*value=["\']([^"\']*)["\'][^>]*>/i',
    $html,
    $hiddenMatches,
    PREG_SET_ORDER
);
foreach ($hiddenMatches as $m) {
    $formData[$m[1]] = $m[2];
}

// 3b) Some editable text fields we want to preserve (e.g. promo_group_id, name, company, address, etc.)
//    We only override the ones we intend to set. To be safe, grab <input name="X" value="Y"> (no type attr required).
preg_match_all(
    '/<input[^>]+name=["\']([^"\']+)["\'][^>]*value=["\']([^"\']*)["\'][^>]*>/i',
    $html,
    $textMatches,
    PREG_SET_ORDER
);
foreach ($textMatches as $m) {
    // If not already stored by hidden, add it:
    if (!isset($formData[$m[1]])) {
        $formData[$m[1]] = $m[2];
    }
}

// 3c) Some <textarea name="X">...</textarea> might exist (e.g. “note”, “ship_instructions”).
//    We’ll capture those too.
preg_match_all(
    '/<textarea[^>]+name=["\']([^"\']+)["\'][^>]*>([^<]*)<\/textarea>/i',
    $html,
    $areaMatches,
    PREG_SET_ORDER
);
foreach ($areaMatches as $m) {
    $formData[$m[1]] = $m[2];
}

// 3d) Debug: log how many fields we’ve grabbed
error_log("🔍 Parsed form fields count: " . count($formData));

// === STEP 4: OVERRIDE/SET HEADER VALUES & INJECT LINE ITEMS ===

// 4a) Mark as “save” and override any header fields we want:
$formData['command']                = 'save';
$formData['recordid']               = $invoiceId;        // ensure we are updating this invoice
$formData['omins_submit_system_id'] = $sys_id;           // from 00_creds.php
$formData['lineitemschanged']       = '1';

// 4b) Example: override name/company/address if you wish (optional)
//$formData['name']     = 'Overridden Name';
//$formData['company']  = 'Overridden Company';

// 4c) Remove any existing line-item keys, so we start with a clean slate:
foreach ($formData as $key => $val) {
    if (preg_match('/^(upc|ds-upc|partnumber|ds-partnumber|description|line_shipping|price|qty|extended|line_id)_[0-9]+$/', $key)) {
        unset($formData[$key]);
    }
}

// 4d) Inject two hardcoded line items (replace with dynamic Zapier data as needed)
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
    $formData["upc_{$n}"]            = $p['upc'];
    $formData["partnumber_{$n}"]     = $p['partnumber'];
    $formData["ds-partnumber_{$n}"]  = $p['ds_partnumber'];
    $formData["description_{$n}"]    = $p['description'];
    $formData["line_shipping_{$n}"]  = $p['line_shipping'];
    $formData["price_{$n}"]          = $p['price'];
    $formData["qty_{$n}"]            = $p['qty'];
    $formData["extended_{$n}"]       = $p['extended'];
}

// 4e) Log a subset of final form data for debugging
error_log("⚙️ Final form data (showing key=value pairs):");
foreach ($formData as $k => $v) {
    // Only show up to the first 50 fields for brevity
    error_log("    {$k} => {$v}");
}

// === STEP 5: POST BACK THE COMPLETE FORM ===

$postUrl = $editUrl; // same URL we fetched a moment ago

$ch = curl_init($postUrl);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query($formData),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true,  // so we can inspect the redirect
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

if ($curlErr) {
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

// Log the result
error_log("✅ UI POST HTTP status: {$httpCode}");
error_log("🔍 UI response body (first 200 chars):\n" . substr($respBody, 0, 200));

// === STEP 6: RETURN JSON FEEDBACK ===

echo json_encode([
    'status'       => ($httpCode >= 200 && $httpCode < 300) ? 'success' : 'error',
    'invoice_id'   => $invoiceId,
    'http_code'    => $httpCode,
    'headers'      => $respHeaders,
    'body_snippet' => substr($respBody, 0, 200)
]);
