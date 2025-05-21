<?php
// list_order_fields.php
// Dumps the field names & titles for your Sales Order (invoice) header tabledef

require_once 'jsonRPCClient.php';
require_once '00_creds.php';  // defines $sys_id, $username, $password, $api_url

$client = new jsonRPCClient($api_url, false);
$creds  = (object)[
  'system_id' => $sys_id,
  'username'  => $username,
  'password'  => $password,
];

// Replace 1052 with your actual Sales Order header tabledef ID
$tabledef = 1052;

try {
    $fields = $client->search($creds, [
        'url_params' => "id={$tabledef}&limit=100"
    ]);

    header('Content-Type: text/plain');
    echo "Fields for tabledef {$tabledef}:\n\n";

    foreach ($fields as $f) {
        printf("  %-20s  (%s)\n", $f['name'], $f['title']);
    }
} catch (Exception $e) {
    echo "Error fetching fields: " . $e->getMessage();
}
