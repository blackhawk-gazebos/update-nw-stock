<?php
// rpc_add_line_final_test.php
// Hardcoded: add two products via JSON-RPC using exactly the keys OMINS expects.

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

// 1) Existing invoice and known part_ids
$invoiceId  = 30641;  // an invoice you created manually
$firstPart  = 1868;   // valid part_id
$secondPart = 4762;   // valid part_id

// 2) Build two RPC calls with the **correct keys**:
$lines = [
    [
      'invoice_id'   => $invoiceId,
      'part_id'      => $firstPart,
      'quantity'     => 1,
      'price'        => '90.0000',
      'line_shipping'=> '0.0000',
      'description'  => 'Flag Pole - MED',
      'taxareaid'    => 1,       // tax zone
    ],
    [
      'invoice_id'   => $invoiceId,
      'part_id'      => $secondPart,
      'quantity'     => 1,
      'price'        => '0.0000',
      'line_shipping'=> '0.0000',
      'description'  => '3m Pro Steel Frame with Carry bag',
      'taxareaid'    => 1,
    ]
];

// 3) Loop and call addOrderItem()
$results = [];
foreach ($lines as $idx => $params) {
    try {
        $res = $client->addOrderItem($creds, $params);
        $results[] = [
          'line'   => $idx + 1,
          'status' => 'success',
          'result' => $res
        ];
    } catch (Exception $e) {
        $results[] = [
          'line'    => $idx + 1,
          'status'  => 'error',
          'message' => $e->getMessage(),
          'params'  => $params
        ];
    }
}

// 4) Return JSON of results
echo json_encode([
  'status'   => 'done',
  'invoice'  => $invoiceId,
  'attempts' => $results
]);
