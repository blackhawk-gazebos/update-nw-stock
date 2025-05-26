<?php
// test_create_order.php
// One-off script to test OMINS createOrder+getOrder with a single line item using JSON-RPC

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
        'thelineitems'     => [
            [
                'partnumber' => '2174',  // valid SKU in OMINS
                'qty'        => 1,
                'price'      => 7.77,
            ],
        ],
        'lineitemschanged' => 1,
    ];

    // Create the invoice
    echo "Creating invoice...\n";
    $createResponse = $client->createOrder($creds, $params);

    // Normalize invoice ID
    if (is_array($createResponse) && isset($createResponse['id'])) {
        $invoiceId = $createResponse['id'];
    } elseif (is_int($createResponse) || is_string($createResponse)) {
        $invoiceId = $createResponse;
    } else {
        throw new Exception('Unexpected createOrder response: ' . print_r($createResponse, true));
    }
    echo "Invoice created with ID: {$invoiceId}\n";

    // Now fetch the invoice details to inspect lineitems and other fields
    echo "Fetching invoice details...\n";
    $details = $client->getOrder($creds, $invoiceId);
    echo "Invoice details:\n";
    print_r($details);

    // Specifically dump lineitems key if present
    if (isset($details['lineitems'])) {
        echo "Line items returned:\n";
        print_r($details['lineitems']);
    } else {
        echo "No 'lineitems' key found in fetched details.\n";
    }

} catch (Exception $e) {
    // Catch any exception and dump its details
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "In file " . $e->getFile() . " on line " . $e->getLine() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
