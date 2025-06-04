<?php
// invoice_ui_direct.php
// Send a “save invoice” POST using an existing session cookie (no login step).
// Provides verbose feedback: logs the full POST data, HTTP status code, and returned HTML (truncated).

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors','1');

// === CONFIGURATION ===
// 1) Set these before running:
$invoiceId    = '';   // Leave blank to create a new invoice, or set to an existing invoice ID (e.g. '30641')
$tableId      = '1041';       // OMINS invoice table ID
$systemId     = '12271';      // Your OMINS system ID
$sessionCookie = 'PHPSESSID=YOUR_SESS_ID; omins_db=omins_12271'; 
   // Paste the exact “PHPSESSID=…; omins_db=…” portion from your browser here.

// 2) Product template ID(s) (numeric) that OMINS requires for each line.
//    You must replace these with valid template IDs from your OMINS installation.
$line1_template = 0;  // e.g. 0 if you have a generic template, or 123 for a specific product template
$line2_template = 0;

// === STEP 1: Build the POST payload ===
// Start with the minimal required fields; adjust dates, customer info, etc. as needed.

$postFields = [
    // Core save flags
    'command'               => 'save',
    'tableid'               => $tableId,
    'recordid'              => $invoiceId,           // blank string -> new invoice
    'omins_submit_system_id'=> $systemId,
    'lineitemschanged'      => '1',

    // Invoice header (hardcoded example values; change to taste)
    'promo_group_id'        => '33',
    'orderdate'             => '10/06/2025',         // DD/MM/YYYY
    'statusdate'            => '10/06/2025',
    'type'                  => 'invoice',
    'name'                  => 'Test Customer',
    'company'               => 'Example Co Ltd',
    'address'               => '45 Example Street',
    'city'                  => 'Wellington',
    'postcode'              => '6011',
    'state'                 => '',
    'country'               => 'New Zealand',
    'phone'                 => '021 555 123',
    'mobile'                => '021 555 123',
    'email'                 => 'test@example.nz',
    'statusid'              => '1-processing',
    'taxable'               => '1',
    'taxareaid'             => '1',
    'taxpercentage'         => '15.00000%',
    'cash_sale'             => '1',
    'specialsetpaid'        => '0',
    'specialsetpaiddate'    => '0',
    'paid'                  => '0',
    'origpaid'              => '0',
    'assignedtoid'          => '0',
    'cid'                   => '0',
    'contact_id'            => '0',

    // Remove any “blank” line-item placeholders (UI requires upc, partnumber, etc. but we’ll override)
    'thelineitems'          => '',
    'upc'                   => '',
    'ds-upc'                => '',
    'matching_upc_to_id'    => '',
    'partnumber'            => '',
    'ds-partnumber'         => '',
    'matching_partnumber_to_id'=> '',
    'stock_count'           => '',
    'item_description'      => '',
    'ds-item_description'   => '',
    'line_shipping'         => '',
    'price'                 => '',
    'qty'                   => '',
    'extended'              => '',

    // ————— LINE #1 (hardcoded) —————
    'template_1'            => (string)$line1_template,    // REQUIRED: the product template ID
    'upc_1'                 => '1868',
    'partnumber_1'          => '1868',
    'ds-partnumber_1'       => 'MED FLAG POLE',
    'description_1'         => 'Flag Pole - MED',
    'line_shipping_1'       => '$0.00',
    'price_1'               => '$90.0000',
    'qty_1'                 => '1',
    'extended_1'            => '$90.00',

    // ————— LINE #2 (hardcoded) —————
    'template_2'            => (string)$line2_template,
    'upc_2'                 => '4762',
    'partnumber_2'          => '4762',
    'ds-partnumber_2'       => '3m Frame Pro Steel 24new',
    'description_2'         => '3m Pro Steel Frame with Carry bag',
    'line_shipping_2'       => '$0.00',
    'price_2'               => '$0.0000',
    'qty_2'                 => '1',
    'extended_2'            => '$0.00',
];

// === STEP 2: Log full POST data for debugging ===
error_log("🔧 POST payload:");
foreach ($postFields as $k => $v) {
    error_log("    {$k} => {$v}");
}

// === STEP 3: Build and execute the cURL request ===
$url = "https://omins.snipesoft.net.nz/modules/omins/invoices_addedit.php?tableid={$tableId}&id={$invoiceId}";

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query($postFields),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true,               // to capture response headers
    CURLOPT_HTTPHEADER     => [
        "Cookie: {$sessionCookie}",
        "Content-Type: application/x-www-form-urlencoded",
    ],
]);
$response = curl_exec($ch);
$curlErr  = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// === STEP 4: Prepare feedback ===
if ($curlErr) {
    error_log("❌ cURL error: {$curlErr}");
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'stage'   => 'curl',
        'message' => $curlErr
    ]);
    exit;
}

// Split headers/body
list($respHeaders, $respBody) = explode("\r\n\r\n", $response, 2);

// Log HTTP status and first 200 chars of body
error_log("✅ HTTP status: {$httpCode}");
error_log("🔍 Response body (first 200 chars):\n" . substr($respBody, 0, 200));

// === STEP 5: Return JSON feedback ===
echo json_encode([
    'status'       => ($httpCode >= 200 && $httpCode < 300) ? 'success' : 'error',
    'http_code'    => $httpCode,
    'headers'      => $respHeaders,
    'body_snippet' => substr($respBody, 0, 200)
]);
