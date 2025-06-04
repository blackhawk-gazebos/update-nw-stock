<?php
// create_then_add_items.php
// 1) create invoice via RPC → 2) add two hardcoded line items via UI curl (using the exact tested payload)

// ——————————————————————————————————————————————————————————————————————————————
// REQUIREMENTS:
// • jsonRPCClient.php
// • 00_creds.php  (must define: $api_url, $sys_id, $username, $password)
// ——————————————————————————————————————————————————————————————————————————————

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors','1');

require_once 'jsonRPCClient.php';
require_once '00_creds.php';

// ---------------------
// STEP 1: CREATE VIA RPC
// ---------------------

$client = new jsonRPCClient($api_url, false);
$creds  = (object)[
    'system_id'=>$sys_id,
    'username' =>$username,
    'password' =>$password
];

$today = new DateTime('now', new DateTimeZone('Pacific/Auckland'));
$orderDate = $today->format('Y-m-d');

$rpcParams = [
    'promo_group_id'   => 33,
    'orderdate'        => $orderDate,
    'statusdate'       => $orderDate,
    'name'             => 'RPC→UI Customer',
    'company'          => 'Integrated Co Ltd',
    'address'          => '123 Integration Way',
    'city'             => 'Wellington',
    'postcode'         => '6011',
    'state'            => '',
    'country'          => 'New Zealand',
    'phone'            => '021 888 0000',
    'mobile'           => '021 888 0001',
    'email'            => 'rpcui@example.nz',
    'type'             => 'invoice',
    'note'             => 'Created via RPC, adding lines via UI curl',
    'taxable'          => '1',
    'taxareaid'        => 1,
    'discountamount'   => '0.00',
    'thelineitems'     => [],      // no lines yet
    'lineitemschanged' => 0
];

try {
    $res = $client->createOrder($creds, $rpcParams);
    // JSON-RPC might return ['id'=>12345] or just 12345
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

// ---------------------
// STEP 2: ADD LINE ITEMS VIA UI CURL
// ---------------------

// 2a) Paste your OMINS session cookie (only PHPSESSID & omins_db)
$cookieHeader = 'PHPSESSID=91b5af7917b2462941b6ce69d9463b68; omins_db=omins_12271';

// 2b) Build the URL with the new invoice ID
$tableId = 1041;
$url     = "https://omins.snipesoft.net.nz/modules/omins/invoices_addedit.php?tableid={$tableId}&id={$invoiceId}";

// 2c) Use the exact working POST body from `test_invoice_curl.php` (no changes except replace 30641 → $invoiceId)
$postData = str_replace(
    '30641',
    (string)$invoiceId,
    "is_pos=0"
  . "&tableid=1041"
  . "&recordid=30641"
  . "&command=save"
  . "&omins_submit_system_id=12271"
  . "&default_report=10"
  . "&creation_type=manual"
  // Line #1
  . "&line_id_1=119339"
  . "&shipping_description_1="
  . "&upc_1=1868"
  . "&ds-upc_1="
  . "&partnumber_1=1868"
  . "&ds-partnumber_1=MED+FLAG+POLE"
  . "&description_1=Flag+Pole+-+MED"
  . "&line_shipping_1=%240.00"
  . "&price_1=%2490.0000"
  . "&qty_1=1"
  . "&extended_1=%2490.00"
  // Line #2
  . "&upc_2=4762"
  . "&ds-upc_2="
  . "&matching_upc_to_id="
  . "&partnumber_2=4762"
  . "&ds-partnumber_2=3m+Frame+Pro+Steel+24new"
  . "&matching_partnumber_to_id="
  . "&stock_count_2=153"
  . "&description_2=3m+Pro+Steel+Frame+with+Carry+bag"
  . "&line_shipping_2=%240.00"
  . "&price_2=%240.0000"
  . "&qty_2=1"
  . "&extended_2=%240.00"
  . "&linenumber=2"
  . "&shipping=%240.00"
  . "&subextended=%2490.00"
  . "&discountid=0"
  . "&discount=0"
  . "&tax_inclusive=1"
  . "&taxareaid=1"
  . "&taxpercentage=15.00000%25"
  . "&totalBD=90"
  . "&discountamount=%240.00"
  . "&totaltni=%2490.00"
  . "&tax=%2411.74"
  . "&totalti=%2490.00"
  . "&totaltaxable=0"
  . "&payment_notes="
  . "&payment_date=04%2F06%2F2025"
  . "&payment_method=1"
  . "&payment_amount=%240.00"
  . "&total_paid=%240.00"
  . "&balance=%2490.00"
  . "&paymentsnumber=0"
  . "&specialinstructions="
  . "&printedinstructions=Thank+You+For+Your+Order."
  . "&omins_submit_system_id=12271"
  . "&createdby="
  . "&creationdate=03%2F06%2F2025+10%3A06+am"
  . "&modifiedby="
  . "&cancelclick=0"
  . "&modifieddate=03%2F06%2F2025+10%3A08+am"
);

// Log the exact POST payload for debugging
error_log("🔧 UI POST payload for invoice {$invoiceId}:");
foreach (explode('&', $postData) as $chunk) {
    error_log("    {$chunk}");
}

// 2d) Perform the cURL
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $postData,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true, 
    CURLOPT_HTTPHEADER     => [
        "Content-Type: application/x-www-form-urlencoded",
        "Cookie: {$cookieHeader}",
        "Referer: https://omins.snipesoft.net.nz/modules/omins/invoices_addedit.php?tableid={$tableId}&id={$invoiceId}",
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

// Split headers & body
list($respHeaders, $respBody) = explode("\r\n\r\n", $response, 2);

// Log the UI response
error_log("✅ UI POST HTTP status: {$httpCode}");
error_log("🔍 UI response body (first 200 chars):\n" . substr($respBody, 0, 200));

// ---------------------
// FINAL RESPONSE
// ---------------------

echo json_encode([
    'status'       => ($httpCode === 302) ? 'success' : 'error',
    'invoice_id'   => $invoiceId,
    'http_code'    => $httpCode,
    'body_snippet' => substr($respBody, 0, 200)
]);
