<?php
// create_and_add_items.php
// 1) Creates a new OMINS invoice via JSON-RPC (createOrder).
// 2) Immediately issues the same POST (cURL) that your working script uses—adjusted to target that new invoice ID.
// 3) Assumes jsonRPCClient.php and 00_creds.php are in the same directory.

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors','1');

// ——————————————————————————————————————————————————————————————————————————————
// STEP 0: LOAD RPC CLIENT & CREDENTIALS
// ——————————————————————————————————————————————————————————————————————————————
require_once 'jsonRPCClient.php';
require_once '00_creds.php';  // must define: $api_url, $sys_id, $username, $password

// ——————————————————————————————————————————————————————————————————————————————
// STEP 1: CREATE A NEW INVOICE VIA JSON-RPC
// ——————————————————————————————————————————————————————————————————————————————
$client = new jsonRPCClient($api_url, false);
$creds  = (object)[
    'system_id' => $sys_id,
    'username'  => $username,
    'password'  => $password
];

$today = new DateTime('now', new DateTimeZone('Pacific/Auckland'));
$orderDate = $today->format('Y-m-d');

// Adjust these header‐only params as needed; minimal fields to create an invoice
$rpcParams = [
    'promo_group_id'   => 1,
    'orderdate'        => $orderDate,
    'statusdate'       => $orderDate,
    'name'             => 'AutoCurl Customer',
    'company'          => 'AutoCurl Co',
    'address'          => '123 Example St',
    'city'             => 'Wellington',
    'postcode'         => '6011',
    'state'            => '',
    'country'          => 'New Zealand',
    'phone'            => '021 111 2222',
    'mobile'           => '021 111 3333',
    'email'            => 'auto@example.nz',
    'type'             => 'invoice',
    'note'             => 'Created via JSON-RPC + cURL combo',
    'taxable'          => '1',
    'taxareaid'        => 1,
    'discountamount'   => '0.00',
    'thelineitems'     => [],   // no lines at creation
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

error_log("✅ Created invoice via RPC, ID: {$invoiceId}");

// ——————————————————————————————————————————————————————————————————————————————
// STEP 2: USE YOUR EXISTING cURL PAYLOAD, TARGETING THE NEW INVOICE ID
// ——————————————————————————————————————————————————————————————————————————————

$id = $invoiceId;            // shorthand

// 2a) Build the URL (replace 30641 with $id)
$url = "https://omins.snipesoft.net.nz/modules/omins/invoices_addedit.php?tableid=1041&id={$id}";

// 2b) Rebuild your working POST body, swapping every “30641” for "{$id}"
$postData = "is_pos=0"
    . "&tableid=1041"
    . "&recordid={$id}"
    . "&lets_addNI=0"
    . "&is_pickup=0"
    . "&command=save"
    . "&omins_submit_system_id=12271"
    . "&default_report=10"
    . "&creation_type=manual"
    . "&orderdate=03%2F06%2F2025"
    . "&duedate=05%2F06%2F2025"
    . "&dispatchdate="
    . "&id={$id}"
    . "&promo_group_id=1"
    . "&type=invoice"
    . "&oldType=invoice"
    . "&ponumber="
    . "&remote_id=N%2FA"
    . "&transactions="
    . "&note="
    . "&specialsetpaid=0"
    . "&specialsetpaiddate=0"
    . "&statusid=1-processing"
    . "&statuschanged=+0"
    . "&statusdate=03%2F06%2F2025"
    . "&paid=0"
    . "&origpaid=0"
    . "&assignedtoid=19"
    . "&ds-assignedtoid=Will.h"
    . "&emailid=9"
    . "&cid=0"
    . "&contact_id=0"
    . "&username="
    . "&cash_sale=1"
    . "&shippingmethod_override=none"
    . "&name=Test+Boustridge"
    . "&company=APB+Electrical+2008+Ltd"
    . "&address=7+Gladstone+Street"
    . "&city=Feilding"
    . "&postcode=4702"
    . "&state="
    . "&country=New+Zealand"
    . "&ship_instructions="
    . "&phone=0800+272363"
    . "&mobile=021865059"
    . "&email=dianna%40apbelectrical.co.nz"
    . "&trackingno_0="
    . "&static_code="
    . "&thelineitems="
    . "&lineitemschanged=1"
    . "&unitcost=0"
    . "&unitweight=0"
    . "&taxable=1"
    . "&imgpath=%2Fcommon%2Fstylesheet%2Fmozilla%2Fimage"
    . "&thisuser="
    . "&lets_addNI=0"
    . "&is_pos=0"
    . "&upc="
    . "&ds-upc="
    . "&matching_upc_to_id="
    . "&partnumber="
    . "&ds-partnumber="
    . "&matching_partnumber_to_id="
    . "&stock_count="
    . "&item_description="
    . "&ds-item_description="
    . "&line_shipping="
    . "&price="
    . "&qty="
    . "&extended="
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
    . "&modifieddate=03%2F06%2F2025+10%3A08+am";

// 2c) Copy your browser’s PHPSESSID and omins_db values, if they changed:
$cookieHeader = 'PHPSESSID=91b5af7917b2462941b6ce69d9463b68; omins_db=omins_12271';

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $postData,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/x-www-form-urlencoded',
        "Cookie: {$cookieHeader}",
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Origin: https://omins.snipesoft.net.nz',
        "Referer: https://omins.snipesoft.net.nz/modules/omins/invoices_addedit.php?tableid=1041&id={$id}",
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0.0.0 Safari/537.36',
    ],
]);
$response = curl_exec($ch);
$err      = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($err) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'stage'   => 'curlPost',
        'message' => $err
    ]);
    exit;
}

echo json_encode([
    'status'       => ($httpCode >= 300 && $httpCode < 400) ? 'success' : 'error',
    'invoice_id'   => $invoiceId,
    'http_code'    => $httpCode,
    'body_snippet' => substr($response, 0, 200)
]);
