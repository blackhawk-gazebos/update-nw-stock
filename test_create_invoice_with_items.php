<?php
// test_create_invoice_with_items.php
// 1) Log in to OMINS UI, 2) POST a full "save invoice" form with two hardcoded line items.

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors','1');

require_once '00_creds.php';   // defines $username, $password, $sys_id

// === 1) Log in to OMINS UI to get a valid session cookie ===
$cookieFile = sys_get_temp_dir() . '/omins_ui_cookie_test.txt';
$loginUrl   = 'https://omins.snipesoft.net.nz/modules/omins/login.php';

$loginData = http_build_query([
    'username' => $username,
    'password' => $password,
    'submit'   => 'Login'
]);

$ch = curl_init($loginUrl);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $loginData,
    CURLOPT_COOKIEJAR      => $cookieFile,
    CURLOPT_COOKIEFILE     => $cookieFile,
    CURLOPT_RETURNTRANSFER => true,
]);
$loginResp = curl_exec($ch);
curl_close($ch);

// (Optional) Check login success by inspecting $loginResp contents
// echo $loginResp;

// === 2) Build and send the “save invoice” POST ===
// Replace “30641” with the invoice ID you want to edit
$invoiceId  = 30641;
$tableId    = 1041;   // your OMINS table ID
$systemId   = $sys_id; // from 00_creds.php

$postUrl = "https://omins.snipesoft.net.nz/modules/omins/invoices_addedit.php"
         . "?tableid={$tableId}&id={$invoiceId}";

// These fields are taken verbatim from your previously‐working curl --data-raw. 
// We’re hardcoding two line items here (line 1 and line 2).
$form = [
    // ————————— CORE SAVE FIELDS —————————
    'is_pos'                  => '0',
    'tableid'                 => (string)$tableId,
    'recordid'                => (string)$invoiceId,
    'lets_addNI'              => '0',
    'is_pickup'               => '0',
    'command'                 => 'save',
    'omins_submit_system_id'  => (string)$systemId,
    'default_report'          => '10',
    'creation_type'           => 'manual',
    // dates must be in DD/MM/YYYY format:
    'orderdate'               => '03/06/2025',
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
    // ———————— CUSTOMER ADDRESS FIELDS ————————
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
    // ———————— LINE ITEM FLAGS ————————
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
    // (the “blank” item fields—these are required but empty)
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
    // ———————— HARDCODED LINE #1 ————————
    'line_id_1'               => '119339',
    'shipping_description_1'  => '',
    'upc_1'                   => '1868',
    'ds-upc_1'                => '',
    'partnumber_1'            => '1868',
    'ds-partnumber_1'         => 'MED FLAG POLE',
    'description_1'           => 'Flag Pole - MED',
    'line_shipping_1'         => '$0.00',
    'price_1'                 => '$90.0000',
    'qty_1'                   => '1',
    'extended_1'              => '$90.00',
    // ———————— HARDCODED LINE #2 ————————
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
    // (these totals must match what OMINS calculates or you can leave them blank for recalculation)
    'linenumber'              => '2',
    'shipping'                => '$0.00',
    'subextended'             => '$90.00',
    'discountid'              => '0',
    'discount'                => '0',
    'tax_inclusive'           => '1',
    'taxareaid'               => '1',
    'taxpercentage'           => '15.00000%',
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
    'modifieddate'            => '03/06/2025 10:08 am'
];

// Perform the POST
$ch = curl_init($postUrl);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query($form),
    CURLOPT_COOKIEFILE     => $cookieFile,
    CURLOPT_COOKIEJAR      => $cookieFile,
    CURLOPT_RETURNTRANSFER => true,
]);
$saveResp = curl_exec($ch);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>"cURL error: {$curlErr}"]);
    exit;
}

// If OMINS accepts it, you’ll see an HTML‐redirect or success message—return a snippet:
echo json_encode([
    'status'      => 'success',
    'invoice_id'  => $invoiceId,
    'html_snippet'=> substr($saveResp, 0, 200)
]);
