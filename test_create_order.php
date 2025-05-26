<?php
// test_create_order.php
// One-off script to test OMINS createOrder with a single line item using JSON-RPC

require_once 'jsonRPCClient.php';
require_once '00_creds.php';

try {
    // Initialize client and credentials
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

        // Provide line items as a PHP array (JSON-RPC will handle serialization)
        'thelineitems'     => [
            [
                'partnumber' => '2174',  // valid SKU in OMINS
                'qty'        => 1,
                'price'      => 7.77,
            ],
        ],
        'lineitemschanged' => 1,
    ];

    // Dump params for inspection
    echo "=== PHP Params ===\n";
    print_r($params);

    echo "=== JSON Payload ===\n";
    echo json_encode($params, JSON_PRETTY_PRINT) . "\n";

    echo "=== Sending RPC call... ===\n";
    $response = $client->createOrder($creds, $params);

    echo "=== RPC Response ===\n";
    print_r($response);

    echo "\nInvoice created. Check OMINS UI or fetch via getOrder for line items.\n";

} catch (Exception $e) {
    // Catch any exception and dump its details
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "In file " . $e->getFile() . " on line " . $e->getLine() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
