<?php
// lineitem_full_inspector.php
// Call addOrderItem() with all positional arguments (part_id=0) to confirm the correct signature.

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors','1');

require_once 'jsonRPCClient.php';
require_once '00_creds.php';  // defines $api_url, $sys_id, $username, $password

$client = new jsonRPCClient($api_url, false);
$creds  = (object)[
    'system_id'=>$sys_id,
    'username' =>$username,
    'password' =>$password
];

// 1) Determine invoice_id via GET: ?invoice_id=123
$invId = isset($_GET['invoice_id']) ? intval($_GET['invoice_id']) : 0;
if ($invId <= 0) {
    http_response_code(400);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Supply a valid invoice_id via ?invoice_id=123'
    ]);
    exit;
}

error_log("🕵️ Running full addOrderItem() on invoice {$invId}");

// 2) Prepare all required fields
$params = [
    'invoice_id'   => $invId,       // must be a valid existing invoice
    'part_id'      => 0,            // 0 = template, so template_cost(0) works
    'qty'          => 1,            // quantity
    'price'        => '0.0000',     // unit price
    'shipping'     => '0.0000',     // per-line shipping
    'description'  => '',           // blank description
    //  - tax_area_id omitted, SQL will pick default via settings_1
    //  - date_created omitted, SQL will use NOW()
    //  - type omitted, SQL will use COALESCE(...)
    //  - notes omitted (becomes '')
    //  - date_modified omitted (becomes NOW())
    //  - taxable omitted (SQL default = 5)
    //  - discount_id omitted (SQL default = NULL)
    //  - discount_amount omitted (SQL default = '')
];

error_log("🛠️ Calling addOrderItem() with params:\n" . print_r($params, true));

// 3) Invoke RPC
try {
    $res = $client->addOrderItem($creds, $params);
    echo json_encode([
        'status' => 'success',
        'result' => $res,
        'params' => $params
    ]);
} catch (Exception $e) {
    // 4) If it still errors, dump the full SQL signature
    $msg = $e->getMessage();
    echo json_encode([
        'status'  => 'error',
        'stage'   => 'addOrderItem',
        'message' => $msg,
        'params'  => $params
    ]);
}
