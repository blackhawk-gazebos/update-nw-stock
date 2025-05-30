<?php
// rpc_method_tester.php
// Brute‐force probe of OMINS JSON-RPC for line‐item methods

require_once 'jsonRPCClient.php';
require_once '00_creds.php';  // defines $api_url, $sys_id, $username, $password

// 1) Bootstrap client
$client = new jsonRPCClient($api_url, false);
$creds  = (object)[
    'system_id' => $sys_id,
    'username'  => $username,
    'password'  => $password,
];

// 2) Invoice ID to test against
$testInvoiceId = 30601;  // replace with a real invoice ID you’ve already created

// 3) Dummy line‐item payload
$dummyParams = [
    'recordid'         => $testInvoiceId,
    'lineitemschanged' => 1,
    'upc_1'            => 'TESTSKU',
    'partnumber_1'     => 'TESTSKU',
    'ds-partnumber_1'  => 'Dummy Item',
    'price_1'          => '1.0000',
    'qty_1'            => 1,
];

// 4) Very long list of candidates
$methods = [
    // order-centric
    'addOrderItem','addOrderItems','createOrderItem','createOrderItems',
    'insertOrderItem','insertOrderItems','orderItemAdd','orderItemsAdd',
    'orderLineAdd','orderLinesAdd','orderLineCreate','orderLinesCreate',
    'addOrderLineItem','addOrderLineItems','createOrderLineItem','createOrderLineItems',
    'appendOrderItem','appendOrderItems','newOrderItem','newOrderItems',
    'pushOrderItem','pushOrderItems',

    // invoice-centric
    'addInvoiceItem','addInvoiceItems','createInvoiceItem','createInvoiceItems',
    'insertInvoiceItem','insertInvoiceItems','invoiceItemAdd','invoiceItemsAdd',
    'invoiceLineAdd','invoiceLinesAdd','invoiceLineCreate','invoiceLinesCreate',
    'addInvoiceLineItem','addInvoiceLineItems','createInvoiceLineItem','createInvoiceLineItems',
    'appendInvoiceItem','appendInvoiceItems','newInvoiceItem','newInvoiceItems',
    'pushInvoiceItem','pushInvoiceItems',

    // line-item generic
    'addLineItem','addLineItems','createLineItem','createLineItems',
    'insertLineItem','insertLineItems','lineItemAdd','lineItemsAdd',
    'lineItemCreate','lineItemsCreate','appendLineItem','appendLineItems',
    'newLineItem','newLineItems','pushLineItem','pushLineItems',
    'updateLineItem','updateLineItems','setLineItem','setLineItems',
    'saveLineItem','saveLineItems','mergeLineItem','mergeLineItems',

    // misc guesses
    'setOrderItems','setInvoiceItems','updateOrderItems','updateInvoiceItems',
    'modifyOrderItem','modifyOrderItems','editOrderItem','editOrderItems',
    'modifyInvoiceItem','modifyInvoiceItems','editInvoiceItem','editInvoiceItems',
    'orderItemSave','orderItemsSave','invoiceItemSave','invoiceItemsSave',
    'orderProductAdd','orderProductsAdd','invoiceProductAdd','invoiceProductsAdd',
    'orderProductCreate','orderProductsCreate','invoiceProductCreate','invoiceProductsCreate',
];

// 5) Loop and test
foreach ($methods as $method) {
    try {
        error_log("🔍 Testing RPC method: {$method}()");
        $res = $client->{$method}($creds, $dummyParams);
        error_log("✅ {$method}() RESPONDED: " . print_r($res, true));
    } catch (Exception $e) {
        $msg = $e->getMessage();
        if (stripos($msg, 'unknown method') !== false) {
            error_log("❌ {$method}() → unknown method");
        } else {
            error_log("⚠️ {$method}() → other error: {$msg}");
        }
    }
}

error_log("🏁 RPC method sweep complete.");
echo json_encode(['status'=>'done']);
