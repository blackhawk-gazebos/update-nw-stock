<?php
// test_create_order.php
// One-off script to test OMINS createOrder with a single line item (line-items debug only)

require_once 'jsonRPCClient.php';
require_once '00_creds.php';

$client = new jsonRPCClient($api_url, false);
$creds  = (object)[
    'system_id' => $sys_id,
    'username'  => $username,
    'password'  => $password,
];

// Build test parameters for a single line item
$params = [
    'promo_group_id'   => 1,
    'orderdate'        => '2025-05-26',
    'statusdate'       => '2025-05-26',
    'name'             => 'Test Customer',
    'company'          => 'Acme Co',
    'address'          => '123 Fake St',
    'city'             => 'Wellington',
    'postcode'         => '6011',
    'state'            => 'Wellington',
    'country'          => 'New Zealand',
    'phone'            => '021 000 000',
    'mobile'           => '021 000 000',
    'email'            => 'test@acme.co.nz',
    'type'             => 'invoice',
    'creation_type'    => 'manual',  // mirror form-based creation
    'note'             => 'Test via RPC',

    // Single line item
    'thelineitems'     => [
        [
            'partnumber' => '2174',
            'qty'        => 1,
            'price'      => 7.77,
        ],
    ],
    'lineitemschanged' => 1,
];

// Dump params for inspection
echo "=== RPC createOrder parameters ===\n";
print_r($params);

echo "=== Sending RPC call... ===\n";
try {
    $response = $client->createOrder($creds, $params);
    echo "=== RPC Response ===\n";
    print_r($response);
} catch (Exception $e) {
    echo "ERROR during createOrder: " . $e->getMessage() . "\n";
}
