<?php
// test_create_and_update_with_direct_curl_template.php
// Create order via JSON-RPC, then replay the full UI cURL with injected variables.

require_once 'jsonRPCClient.php';
require_once '00_creds.php';

try {
    // 1) JSON-RPC create header
    $client  = new jsonRPCClient($api_url, false);
    $creds   = (object)[ 'system_id'=>$sys_id, 'username'=>$username, 'password'=>$password ];
    $header  = [
        'promo_group_id'=>1,
        'orderdate'=>date('Y-m-d'),
        'duedate'=>date('Y-m-d',strtotime('+2 days')),
        'statusdate'=>date('Y-m-d'),
        'type'=>'order',
        'name'=>'Test Customer',
        'note'=>'Direct cURL template'
    ];
    $resp    = $client->createOrder($creds, $header);
    $orderId = is_array($resp) && isset($resp['id']) ? $resp['id'] : $resp;
    echo "Created Order ID: {$orderId}\n";

    // 2) Define variables for line item
    $sku      = '2768';
    $qty      = 1;
    $cookie   = 'PHPSESSID=91b5af7917b2462941b6ce69d9463b68; omins_db=omins_12271';

    // 3) Full cURL command template copied from browser, with placeholders {ID} and {SKU}:
    $curlTemplate = <<<'CURL'
curl -s -X POST 'https://omins.snipesoft.net.nz/modules/omins/invoices_addedit.php?tableid=1041&id={ID}' \
  -H 'Cookie: {COOKIE}' \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  --data-raw 'is_pos=0&tableid=1041&recordid={ID}&lets_addNI=0&is_pickup=0&command=save&omins_submit_system_id=12271&default_report=10&creation_type=manual&orderdate={DATE}&duedate={DATE2}&dispatchdate=&id={ID}&promo_group_id=1&type=order&oldType=order&ponumber=&remote_id=N%2FA&transactions=&note=&specialsetpaid=0&specialsetpaiddate=0&statusid=1-processing&statuschanged=+0&statusdate={DATE}&paid=0&origpaid=0&assignedtoid=0&ds-assignedtoid=&emailid=9&cid=0&contact_id=0&username=&cash_sale=1&shippingmethod_override=none&name=test&company=&address=&city=&postcode=&state=&country=New+Zealand&ship_instructions=&phone=&mobile=&email=&trackingno_0=&static_code=&thelineitems=&lineitemschanged=1&unitcost=0&unitweight=0&taxable=1&imgpath=%2Fcommon%2Fstylesheet%2Fmozilla%2Fimage&thisuser=&lets_addNI=0&is_pos=0&upc=&ds-upc=&matching_upc_to_id=&partnumber=&ds-partnumber=&matching_partnumber_to_id=&stock_count=&item_description=&ds-item_description=&line_shipping=%240.00&price=%240.0000&qty={QTY}&extended=%240.00&upc_1={SKU}&ds-upc_1=&matching_upc_to_id=&partnumber_1={SKU}&ds-partnumber_1=Jute+Natural+1.4+x+2m&matching_partnumber_to_id=&stock_count_1=25&description_1=Indian+JUTE+140++x+200cm+&line_shipping_1=%240.00&price_1=%240.0000&qty_1={QTY}&extended_1=%240.0000&linenumber=1&shipping=%240.00&subextended=%240.00&discountid=0&discount=0&tax_inclusive=1&taxareaid=1&taxpercentage=15.00000%25&totalBD=0&discountamount=%240.00&totaltni=%240.00&tax=%240.00&totalti=%240.00&totaltaxable=0&payment_notes=&payment_date={DATE}&payment_method=1&payment_amount=%240.00&total_paid=%240.00&balance=%240.00&paymentsnumber=0&specialinstructions=&printedinstructions=Thank+You+For+Your+Order.&createdby=&creationdate=&modifiedby=&cancelclick=0&modifieddate='
CURL;

    // 4) Replace placeholders
    $curlCmd = strtr($curlTemplate, [
        '{ID}'    => $orderId,
        '{SKU}'   => $sku,
        '{QTY}'   => $qty,
        '{COOKIE}'=> $cookie,
        '{DATE}'  => urlencode(date('d/m/Y')),
        '{DATE2}' => urlencode(date('d/m/Y',strtotime('+2 days'))),
    ]);

    // 5) Execute
    echo "Executing cURL:\n{$curlCmd}\n";
    $output = shell_exec($curlCmd);
    echo "cURL output snippet:\n" . ($output===null?"<no output>":substr($output,0,200)) . "...\n";

    // 6) Verify via RPC
    $updated = $client->getOrder($creds, $orderId);
    echo "Updated items via RPC:\n";
    print_r($updated['items'] ?? []);

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "In " . $e->getFile() . " on line " . $e->getLine() . "\n";
    echo $e->getTraceAsString() . "\n";
}
