<?php
require_once 'jsonRPCClient.php';
require_once '00_creds.php';

$client = new jsonRPCClient($api_url, $debug);
$creds  = (object)[
  'system_id'=>$sys_id,
  'username' =>$username,
  'password' =>$password
];

$candidates = [
  'createInvoice',
  'insertInvoice',
  'saveInvoice',
  'addInvoice',
  'createOrder',
  'insertOrder',
  'saveOrder',
];

foreach ($candidates as $method) {
    try {
        echo "Trying {$method}...\n";
        // minimal params array just to test
        $res = $client->$method($creds, ['customer_id'=>0,'lines'=>[]]);
        print_r($res);
    } catch (Exception $e) {
        echo "{$method} → ".$e->getMessage()."\n";
    }
    echo "—\n";
}
