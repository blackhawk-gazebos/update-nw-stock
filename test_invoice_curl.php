<?php
// test_invoice_curl.php
// Sends the exact cURL you provided to create/edit invoice #30641 with two hardcoded line items.
// Replace the cookie string with a valid PHPSESSID and omins_db for your session if needed.

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors','1');

// 1) The URL (replace 30641 if you want to test against another invoice)
$url = 'https://omins.snipesoft.net.nz/modules/omins/invoices_addedit.php?tableid=1041&id=30641';

// 2) The raw POST body exactly as in your working cURL:
$postData = "is_pos=0"
    . "&tableid=1041"
    . "&recordid=30641"
    . "&lets_addNI=0"
    . "&is_pickup=0"
    . "&command=save"
    . "&omins_submit_system_id=12271"
    . "&default_report=10"
    . "&creation_type=manual"
    . "&orderdate=03%2F06%2F2025"
    . "&duedate=05%2F06%2F2025"
    . "&dispatchdate="
    . "&id=30641"
    . "&promo_group_id=33"
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
    . "&name=Dianna+Boustridge"
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

// 3) Copy your browser’s PHPSESSID and omins_db values into this string:
$cookieHeader = '__utmc=98076289; __utmz=98076289.1724383583.1.1.utmcsr=(direct)|utmccn=(direct)|utmcmd=(none); omins_db=omins_12271; PHPSESSID=91b5af7917b2462941b6ce69d9463b68; _ga=GA1.3.705757820.1727219739; _ga_WRXSKRSGHE=GS2.3.s1747871700$o4$g0$t174721837$s0$h0; __utma=98076289.1282698040.1724383583.1748902017.1748986704.280';

// 4) Send the POST exactly as your cURL did
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $postData,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/x-www-form-urlencoded',
        "Cookie: {$cookieHeader}",
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Origin: https://omins.snipesoft.net.nz',
        'Referer: https://omins.snipesoft.net.nz/modules/omins/invoices_addedit.php?tableid=1041&id=30641',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0.0.0 Safari/537.36',
    ],
]);
$response = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

if ($err) {
    echo json_encode(['status' => 'error', 'message' => $err]);
    exit;
}

echo json_encode([
    'status'       => 'success',
    'html_snippet' => substr($response, 0, 200)
]);
