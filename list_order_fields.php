<?php
// list_order_fields.php
require_once 'jsonRPCClient.php';
require_once '00_creds.php';

$client = new jsonRPCClient($api_url, false);
$creds  = (object)[
  'system_id'=>$sys_id,
  'username' =>$username,
  'password' =>$password
];

// Replace 1052 with your Sales Order header tabledef ID
$tabledef = 1041;

try {
  $fields = $client->search($creds, ['url_params'=>"id={$tabledef}&limit=100"]);
  header('Content-Type: text/plain');
  echo \"Fields for tabledef {$tabledef}:\\n\\n\";
  foreach ($fields as $f) {
    printf(\"  %-20s  (%s)\\n\", $f['name'], $f['title']);
  }
} catch (Exception $e) {
  echo \"Error: \" . $e->getMessage();
}
