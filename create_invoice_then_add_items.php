<?php
// create_invoice_then_add_items.php
// Step 1: create an invoice via RPC
// Step 2: use cURL + session cookie to add line items to the new invoice

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors','1');

require_once 'jsonRPCClient.php';
require_once '00_creds.php';   // must define $api_url, $sys_id, $username, $password

// -----------------------------------------------------------------------------
// STEP 1: CREATE A NEW INVOICE HEADER VIA JSON-RPC
// -----------------------------------------------------------------------------

$client = new jsonRPCClient($api_url, false);
$creds  = (object)[
    'system_id'=>$sys_id,
    'username' =>$username,
    'password' =>$password
];

// Build minimal createOrder parameters (no line items, just header)
$today = new DateTime('now', new DateTimeZone('Pacific/Auckland'));
$orderDate = $today->format('Y-m-d');

$rpcParams = [
    'promo_group_id'   => 33,               // your chosen promo group
    'orderdate'        => $orderDate,
    'statusdate'       => $orderDate,
    'name'             => 'Test Customer RPC',
    'company'          => 'Example Co RPC',
    'address'          => '123 Main St',
    'city'             => 'Wellington',
    'postcode'         => '6011',
    'state'            => '',
    'country'          => 'New Zealand',
    'phone'            => '021 555 123',
    'mobile'           => '021 555 123',
    'email'            => 'rpc-test@example.nz',
    'type'             => 'invoice',
    'note'             => 'Created via RPC then UI curl',
    'taxable'          => '1',
    'taxareaid'        => 1,
    'discountamount'   => '0.00',
    // No line items here:
    'thelineitems'     => [],
    'lineitemschanged' => 0,
];

try {
    $newInvoice = $client->createOrder($creds, $rpcParams);
    // OMINS sometimes returns an array ['id'=>12345], or sometimes just 12345
    if (is_array($newInvoice) && isset($newInvoice['id'])) {
        $invoiceId = intval($newInvoice['id']);
    } elseif (is_numeric($newInvoice)) {
        $invoiceId = intval($newInvoice);
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

// Log for debugging
error_log("✅ RPC created invoice ID: {$invoiceId}");

// -----------------------------------------------------------------------------
// STEP 2: ADD LINE ITEMS VIA UI‐STYLE cURL POST
// -----------------------------------------------------------------------------

// 2a) Paste your valid session cookie here (just PHPSESSID and omins_db).  
//     You can grab these by logging into OMINS in your browser, viewing cookies,
//     and copying “PHPSESSID=…; omins_db=…” for this domain.

$sessionCookie = 'PHPSESSID=YOUR_PHPSESSID_VALUE; omins_db=omins_12271';

// 2b) URL for saving (edit) that invoice we just created:
$tableId = 1041;
$url = "https://omins.snipesoft.net.nz/modules/omins/invoices_addedit.php?tableid={$tableId}&id={$invoiceId}";

// 2c) Build the raw POST body exactly as from your working cURL.
//     We simply replace every “30641” with our dynamic $invoiceId.
//     (Dates here are hardcoded; adjust to suit.)

$postFields = [
    // ——— CORE SAVE FIELDS ———
    'is_pos'                  => '0',
    'tableid'                 => (string)$tableId,
    'recordid'                => (string)$invoiceId,
    'lets_addNI'              => '0',
    'is_pickup'               => '0',
    'command'                 => 'save',
    'omins_submit_system_id'  => '12271',
    'default_report'          => '10',
    'creation_type'           => 'manual',
    'orderdate'               => '03/06/2025',    // DD/MM/YYYY
    'duedate'                 => '05/06/2025',
    'dispatchdate'            => '',
    'id'                      => (string)$invoiceId,
    'promo_group_id'          => '33',
    'type'                    => 'invoice',
    'oldType'                 => 'invoice',
    'ponumber'                => '',
    'remote_id'               => 'N/A',
    'transactions'            => '',
    'note'                    => '',
    'specialsetpaid'          => '0',
    'specialsetpaiddate'      => '0',
    'statusid'                => '1-processing',
    'statuschanged'           => '+0',
    'statusdate'              => '03/06/2025',
    'paid'                    => '0',
    'origpaid'                => '0',
    'assignedtoid'            => '19',
    'ds-assignedtoid'         => 'Will.h',
    'emailid'                 => '9',
    'cid'                     => '0',
    'contact_id'              => '0',
    'username'                => '',
    'cash_sale'               => '1',
    'shippingmethod_override' => 'none',

    // ——— CUSTOMER & SHIPPING INFO ———
    'name'                    => 'Dianna Boustridge',
    'company'                 => 'APB Electrical 2008 Ltd',
    'address'                 => '7 Gladstone Street',
    'city'                    => 'Feilding',
    'postcode'                => '4702',
    'state'                   => '',
    'country'                 => 'New Zealand',
    'ship_instructions'       => '',
    'phone'                   => '0800 272363',
    'mobile'                  => '021865059',
    'email'                   => 'dianna@apbelectrical.co.nz',

    // ——— LINE ITEM FLAGS ———
    'trackingno_0'            => '',
    'static_code'             => '',
    'thelineitems'            => '',
    'lineitemschanged'        => '1',
    'unitcost'                => '0',
    'unitweight'              => '0',
    'taxable'                 => '1',
    'imgpath'                 => '/common/stylesheet/mozilla/image',
    'thisuser'                => '',
    'lets_addNI'              => '0',
    'is_pos'                  => '0',

    // Blank placeholders (UI expects these keys even if unused)
    'upc'                     => '',
    'ds-upc'                  => '',
    'matching_upc_to_id'      => '',
    'partnumber'              => '',
    'ds-partnumber'           => '',
    'matching_partnumber_to_id' => '',
    'stock_count'             => '',
    'item_description'        => '',
    'ds-item_description'     => '',
    'line_shipping'           => '',
    'price'                   => '',
    'qty'                     => '',
    'extended'                => '',

    // ——— HARD CODED LINE #1 ———
    'line_id_1'               => '119339',
    'shipping_description_1'  => '',
    'upc_1'                   => '1868',
    'ds-upc_1'                => '',
    'partnumber_1'            => '1868',
    'ds-partnumber_1'         => 'MED FLAG POLE',
    'description_1'           => 'Flag Pole – MED',
    'line_shipping_1'         => '$0.00',
    'price_1'                 => '$90.0000',
    'qty_1'                   => '1',
    'extended_1'              => '$90.00',

    // ——— HARD CODED LINE #2 ———
    'upc_2'                   => '4762',
    'ds-upc_2'                => '',
    'matching_upc_to_id'      => '',
    'partnumber_2'            => '4762',
    'ds-partnumber_2'         => '3m Frame Pro Steel 24new',
    'matching_partnumber_to_id' => '',
    'stock_count_2'           => '153',
    'description_2'           => '3m Pro Steel Frame with Carry bag',
    'line_shipping_2'         => '$0.00',
    'price_2'                 => '$0.0000',
    'qty_2'                   => '1',
    'extended_2'              => '$0.00',

    // Totals & taxes (can usually be omitted and recalculated, but included here)
    'linenumber'              => '2',
    'shipping'                => '$0.00',
    'subextended'             => '$90.00',
    'discountid'              => '0',
    'discount'                => '0',
    'tax_inclusive'           => '1',
    'taxareaid'               => '1',
    'taxpercentage'           => '15.00000%25',
    'totalBD'                 => '90',
    'discountamount'          => '$0.00',
    'totaltni'                => '$90.00',
    'tax'                     => '$11.74',
    'totalti'                 => '$90.00',
    'totaltaxable'            => '0',
    'payment_notes'           => '',
    'payment_date'            => '04/06/2025',
    'payment_method'          => '1',
    'payment_amount'          => '$0.00',
    'total_paid'              => '$0.00',
    'balance'                 => '$90.00',
    'paymentsnumber'          => '0',
    'specialinstructions'     => '',
    'printedinstructions'     => 'Thank You For Your Order.',
    'createdby'               => '',
    'creationdate'            => '03/06/2025 10:06 am',
    'modifiedby'              => '',
    'cancelclick'             => '0',
    'modifieddate'            => '03/06/2025 10:08 am',
];

// Convert to URL‐encoded string
$postData = http_build_query($postFields);

// === STEP 3: DEBUG LOG THE POST PAYLOAD ===
error_log("🔧 POST payload to UI:");
foreach ($postFields as $k => $v) {
    error_log("    {$k} => {$v}");
}

// === STEP 4: cURL the UI save ===
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $postData,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true,  // so we can inspect headers
    CURLOPT_HTTPHEADER     => [
        "Content-Type: application/x-www-form-urlencoded",
        "Cookie: {$sessionCookie}",
        "Referer: https://omins.snipesoft.net.nz/modules/omins/invoices_addedit.php?tableid={$tableId}&id={$invoiceId}",
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/136.0.0.0 Safari/537.36",
    ],
]);
$response = curl_exec($ch);
$err = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($err) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'stage'   => 'UI POST',
        'message' => $err
    ]);
    exit;
}

// Separate headers & body
list($respHeaders, $respBody) = explode("\r\n\r\n", $response, 2);

// Log response details
error_log("✅ HTTP status: {$httpCode}");
error_log("🔍 Response body (first 200 chars):\n" . substr($respBody, 0, 200));

// === STEP 5: RETURN JSON FEEDBACK FOR YOUR LOGS ===
echo json_encode([
    'status'       => ($httpCode >= 200 && $httpCode < 300) ? 'success' : 'error',
    'http_code'    => $httpCode,
    'headers'      => $respHeaders,
    'body_snippet' => substr($respBody, 0, 200)
]);
