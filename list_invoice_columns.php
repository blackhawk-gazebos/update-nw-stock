<?php
// list_invoice_columns.php
header('Content-Type: text/plain');
error_reporting(E_ALL);
ini_set('display_errors','1');

require_once 'jsonRPCClient.php';
require_once '00_creds.php';  // your creds file

// Build the client
$client = new jsonRPCClient($api_url, false);
$creds  = (object)[
  'system_id' => $sys_id,
  'username'  => $username,
  'password'  => $password,
];

// Fetch just one invoice row from tabledef 1041
$tabledef = 1041;
$rows = $client->search($creds, [
  'url_params' => "id={$tabledef}&limit=1"
]);

if (empty($rows)) {
  echo "No rows found in tabledef {$tabledef}\n";
  exit;
}

// Print the column names
$columns = array_keys($rows[0]);
echo "Columns in invoice table (tabledef {$tabledef}):\n\n";
foreach ($columns as $col) {
  echo "  - {$col}\n";
}
