<?php
// test_create_order.php
// One-off script to test OMINS createOrder+getOrder with a single line item

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
    'note'             => 'Test via RPC',

    // Single line item array
    'thelineitems'     => [
        [
            'partnumber' => '2174',
            'qty'        => 1,
            'price'      => 7.77,
        ],
    ],
    'lineitemschanged' => 1,
];

try {
    // Create invoice
    $inv = $client->createOrder($creds, $params);

    // Normalize createOrder result
    if (is_array($inv) && isset($inv['id'])) {
        $invoice_id = $inv['id'];
    } elseif (is_int($inv) || is_string($inv)) {
        $invoice_id = $inv;
    } else {
        echo "ERROR: Unexpected createOrder response: ";
        print_r($inv);
        exit;
    }

    echo "Created invoice ID: {$invoice_id}\n";

    // Fetch back to verify line items
    $details = $client->getOrder($creds, ['id' => $invoice_id]);
    echo "Fetched invoice details:\n";
    print_r($details);

    if (!empty($details['lineitems']) && is_array($details['lineitems'])) {
        echo "Line items on invoice:\n";
        print_r($details['lineitems']);
    } else {
        echo "No line items returned or key missing.\n";
    }

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
