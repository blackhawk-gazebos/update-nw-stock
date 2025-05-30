<?php
// part_lookup_tester.php
require_once 'jsonRPCClient.php';
require_once '00_creds.php';

$client = new jsonRPCClient($api_url, false);
$creds  = (object)[ 'system_id'=>$sys_id, 'username'=>$username, 'password'=>$password ];

// candidate methods
$tests = ['getPartBySKU','getProductBySKU','findPart','findProduct'];
foreach ($tests as $m) {
    try {
        error_log("Testing {$m}()");
        $res = $client->{$m}($creds, ['sku'=>'Jute Light Grey 1.6 x 2.3m']);
        error_log("→ {$m} response: " . print_r($res, true));
    } catch (Exception $e) {
        error_log("× {$m} error: " . $e->getMessage());
    }
}
