<?php
// lineitem_signature_inspector.php
// Use an existing invoice ID to dump the lineitem_add(...) signature via SQL error.

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

// 1) Determine invoice_id to test against:
//    - Prefer GET parameter ?invoice_id=123
//    - Otherwise look for JSON body { "invoice_id": 123 }
$invId = 0;
if (isset($_GET['invoice_id'])) {
    $invId = intval($_GET['invoice_id']);
} else {
    $raw = file_get_contents('php://input');
    if ($raw) {
        $data = json_decode($raw, true);
        if (isset($data['invoice_id'])) {
            $invId = intval($data['invoice_id']);
        }
    }
}

if ($invId <= 0) {
    http_response_code(400);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Please supply a valid invoice_id via ?invoice_id= or JSON {"invoice_id":123}'
    ]);
    exit;
}

error_log("🕵️ Inspecting addOrderItem() for invoice_id: {$invId}");

// 2) Call addOrderItem() with ONLY invoice_id, catch resulting SQL error
$params = ['invoice_id' => $invId];
try {
    $res = $client->addOrderItem($creds, $params);
    echo json_encode([
      'status' => 'success',
      'result' => $res,
      'params' => $params
    ]);
    exit;
} catch (Exception $e) {
    $msg = $e->getMessage();
    echo json_encode([
      'status'  => 'error',
      'stage'   => 'addOrderItem',
      'message' => $msg,
      'params'  => $params
    ]);
    exit;
}
