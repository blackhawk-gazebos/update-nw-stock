<?php
// list_promo_groups.php
// Fetches all Promotion Rule (promo group) records so you can see their IDs and names.

require_once 'jsonRPCClient.php';
require_once '00_creds.php';  // defines $sys_id, $username, $password, $api_url

$client = new jsonRPCClient($api_url, false);
$creds  = (object)[
  'system_id' => $sys_id,
  'username'  => $username,
  'password'  => $password,
];

// Replace 1052 with the TableDef ID for Promo Groups in your OMINS install
$promoTableId = 1052;  
$limit        = 200;

try {
  $rows = $client->search($creds, [
    'url_params' => "id={$promoTableId}&limit={$limit}"
  ]);
  header('Content-Type: text/plain');
  echo "Found " . count($rows) . " promo groups:\n\n";
  foreach ($rows as $r) {
    printf("  ID %3d  →  %s\n", $r['id'], $r['name']);
  }
} catch (Exception $e) {
  echo "Error fetching promo groups: " . $e->getMessage();
}
