<?php
// simple_create_invoice.php

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors','1');

// --- Step 1: point at your working URL and body exactly as in your browser ---
$invoiceId = 30641;  // or wherever your cURL was targeting
$tableId   = 1041;
$url       = "https://omins.snipesoft.net.nz/modules/omins/invoices_addedit.php?tableid={$tableId}&id={$invoiceId}";

// “data‐raw” must be byte-for-byte what worked in your browser (no changes):
$postBody = "is_pos=0"
    . "&tableid=1041"
    . "&recordid=30641"
    . "&lets_addNI=0"
    . "&is_pickup=0"
    . "&command=save"
    . "&omins_submit_system_id=12271"
    . /* … all the other fields you copied … */
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

// --- Step 2: send it with your session cookie ---
$cookieHeader = 'PHPSESSID=91b5af7917b2462941b6ce69d9463b68; omins_db=omins_12271';

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $postBody,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/x-www-form-urlencoded',
        "Cookie: {$cookieHeader}",
        "Referer: https://omins.snipesoft.net.nz/modules/omins/invoices_addedit.php?tableid={$tableId}&id={$invoiceId}",
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)'
    ],
]);
$response = curl_exec($ch);
$err      = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($err) {
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>$err]);
    exit;
}

echo json_encode([
    'status'       => ($httpCode === 302 ? 'success' : 'error'),
    'http_code'    => $httpCode,
    'body_snippet' => substr($response, 0, 200)
]);
