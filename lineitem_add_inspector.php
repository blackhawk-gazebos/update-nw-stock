<?php
// lineitem_add_inspector.php
// Call OMINS’s addOrderItem() with minimal params so the error reveals the stored‐procedure signature.

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors','1');

// 0) Load RPC client and credentials
require_once 'jsonRPCClient.php';
require_once '00_creds.php';  // defines $api_url, $sys_id, $username, $password

$client = new jsonRPCClient($api_url, false);
$creds  = (object)[
    'system_id'=>$sys_id,
    'username' =>$username,
    'password' =>$password
];

// 1) Decide which invoice ID to test against.
//    Either pass ?invoice_id=123 in the URL, or hard‐code a known invoice here.
$invId = isset($_GET['invoice_id'])
       ? intval($_GET['invoice_id'])
       : 0; // set a real ID if you know one

// 2) Prepare a “minimal” param set.
//    For the very first test, leave everything blank or zero to see the full SQL.
$params = [
    'invoice_id' => $invId,
    // (no other keys)
];

// 3) Try the RPC call
try {
    $res = $client->addOrderItem($creds, $params);
    // If OMINS ever does NOT error out, you’ll see the result here:
    echo json_encode([
      'status'  => 'success',
      'result'  => $res,
      'params'  => $params
    ]);
    exit;
} catch (Exception $e) {
    // 4) Catch the error (which includes the raw SQL)
    $msg = $e->getMessage();
    echo json_encode([
      'status'  => 'error',
      'message' => $msg,
      'params'  => $params
    ]);
    exit;
}
