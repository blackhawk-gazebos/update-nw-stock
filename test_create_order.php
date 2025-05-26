<?php
require_once 'jsonRPCClient.php';
require_once '00_creds.php';

$client = new jsonRPCClient($api_url, false);
$creds  = (object)[
  'system_id' => $sys_id,
  'username'  => $username,
  'password'  => $password,
];

// mirror all of your form fields here:
$params = [
  'promo_group_id'     => 1,
  'orderdate'          => '2025-05-26',
  'statusdate'         => '2025-05-26',
  'name'               => 'Test Customer',
  'company'            => 'Acme Co',
  'address'            => '123 Fake St',
  'city'               => 'Wellington',
  'postcode'           => '6011',
  'state'              => 'Wellington',
  'country'            => 'New Zealand',
  'phone'              => '021 000 000',
  'mobile'             => '021 000 000',
  'email'              => 'test@acme.co.nz',
  'type'               => 'invoice',
  'note'               => 'Test via RPC',
  // this is your single line item from the form:
  'thelineitems'       => [
    [
      'partnumber' => '2174',
      'qty'        => 1,
      'price'      => 7.77,
    ]
  ],
  'lineitemschanged'   => 1,
];

try {
  $inv = $client->createOrder($creds, $params);
  echo \"Created invoice ID \" . $inv['id'] . \"\\n\";
  // now fetch it back to confirm…
  $details = $client->getOrder($creds, ['id'=>$inv['id']]);
  print_r($details['lineitems']);
} catch (Exception $e) {
  echo \"ERROR: \" . $e->getMessage();
}
