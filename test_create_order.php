<?php
/**
 * BigCommerce to OMINS Order Creation Webhook Handler
 *
 * Receives a BigCommerce order payload, processes it, and creates an invoice in OMINS.
 */

declare(strict_types=1);

// --- Script Configuration ---
// This could be moved to a separate config file or defined as constants
define('OMINS_PROMO_GROUP_ID', 9); // As used in your previous script and workingParameters.php
define('LOG_LEVEL', 'DEBUG'); // Set to 'INFO' or 'ERROR' for less verbose logging in production

// --- Global Requirements ---
require_once 'jsonRPCClient.php';
require_once '00_creds.php'; // Defines $sys_id, $username, $password, $api_url, $debug

// --- Main Execution Block ---
(function () {
    // Ensure $api_url, $sys_id, $username, $password are available from 00_creds.php
    global $api_url, $sys_id, $username, $password, $debug;

    if (!isset($api_url, $sys_id, $username, $password)) {
        log_message('OMINS API credentials or URL not defined in 00_creds.php.', 'CRITICAL');
        send_json_response(['status' => 'error', 'message' => 'Server configuration error.'], 500);
        return;
    }

    // Set default headers
    header('Content-Type: application/json');
    // Error and exception handling
    error_reporting(E_ALL);
    ini_set('display_errors', '0'); // Errors should be logged, not displayed for webhooks
    ini_set('log_errors', '1');
    // ini_set('error_log', '/path/to/your/php_error.log'); // Configure your error log path

    set_exception_handler(function (Throwable $exception) {
        log_message("Unhandled Exception: " . $exception->getMessage() . "\n" . $exception->getTraceAsString(), 'ERROR');
        if (!headers_sent()) {
            send_json_response(['status' => 'error', 'message' => 'An unexpected error occurred.'], 500);
        }
    });

    try {
        // 1. Verify Webhook Security Token (Critical Step)
        verify_webhook_token(); // Exits if token is invalid

        // 2. Get and Decode Webhook Payload
        $order_payload = get_webhook_payload();
        log_message("Received BigCommerce payload: " . json_encode($order_payload), 'DEBUG');

        // 3. Initialize OMINS Client
        $omins_client = new jsonRPCClient($api_url, $debug ?? false);
        $omins_creds = (object)[
            'system_id' => $sys_id,
            'username'  => $username,
            'password'  => $password
        ];

        // 4. Prepare OMINS Order Data from BigCommerce Payload
        $omins_order_params = prepare_omins_order_parameters($order_payload, $omins_client, $omins_creds);

        // 5. Create Order in OMINS
        log_message("Calling OMINS createOrder with params: " . json_encode($omins_order_params), 'DEBUG');
        $omins_response = $omins_client->createOrder($omins_creds, $omins_order_params);

        if ($omins_response && isset($omins_response['id'])) {
            log_message("OMINS Invoice created successfully. ID: " . $omins_response['id'] . " Response: " . json_encode($omins_response), 'INFO');
            send_json_response(['status' => 'success', 'invoice_id' => $omins_response['id'], 'omins_response' => $omins_response]);
        } else {
            log_message("OMINS createOrder call failed or returned invalid response. Response: " . json_encode($omins_response), 'ERROR');
            send_json_response(['status' => 'error', 'message' => 'Failed to create invoice in OMINS or invalid response from OMINS.', 'omins_response' => $omins_response], 500);
        }

    } catch (Exception $e) {
        log_message("Processing Error: " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString(), 'ERROR');
        // Ensure a response is sent even if an error occurs before normal send_json_response calls
        if (!headers_sent()) {
             send_json_response(['status' => 'error', 'message' => "Server Processing Error: " . $e->getMessage()], 500);
        }
    }
})();


// --- Helper Functions ---

/**
 * Verifies the webhook token. Exits if invalid.
 
function verify_webhook_token(): void {
    $secret = getenv('WEBHOOK_SECRET');
    $token = $_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? null;

    if (!$secret || !$token || !hash_equals($secret, $token)) {
        log_message('Webhook token validation failed. Secret set: ' . ($secret ? 'Yes' : 'No') . ', Token received: ' . ($token ? 'Yes' : 'No'), 'WARNING');
        send_json_response(['status' => 'error', 'message' => 'Forbidden - Invalid Token'], 403);
        // exit; // send_json_response already exits
    }
    log_message('Webhook token validated successfully.', 'INFO');
}
*/
/**
 * Retrieves and decodes the JSON payload from the webhook.
 * @return array The decoded order data.
 * @throws Exception If payload is invalid.
 */
function get_webhook_payload(): array {
    $raw_payload = file_get_contents('php://input');
    if (empty($raw_payload) && isset($_GET['raw'])) { // Allow GET raw for testing
        $raw_payload = $_GET['raw'];
        log_message('Using raw GET parameter for input.', 'DEBUG');
    } elseif (empty($raw_payload) && getenv('TEST_RAW_PAYLOAD')) { // Allow env var for testing
        $raw_payload = getenv('TEST_RAW_PAYLOAD');
        log_message('Using TEST_RAW_PAYLOAD env var for input.', 'DEBUG');
    }

    if (empty($raw_payload)) {
        throw new Exception("No payload received.");
    }
    log_message("Raw payload received: {$raw_payload}", 'DEBUG');

    $data = json_decode($raw_payload, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid main JSON payload: " . json_last_error_msg());
    }

    // Handle cases where the order might be nested (e.g., from Zapier array)
    if (isset($data[0]) && is_array($data[0])) return $data[0];
    if (isset($data['data'][0]) && is_array($data['data'][0])) return $data['data'][0];
    if (isset($data['data']) && is_array($data['data'])) return $data['data'];
    return $data;
}

/**
 * Prepares the parameters for OMINS createOrder API call.
 * @param array $bc_order BigCommerce order data.
 * @param jsonRPCClient $omins_client OMINS RPC client instance.
 * @param stdClass $omins_creds OMINS credentials object.
 * @return array Parameters for OMINS createOrder.
 * @throws Exception If essential data is missing.
 */
function prepare_omins_order_parameters(array $bc_order, jsonRPCClient $omins_client, stdClass $omins_creds): array {
    // Extract Shipping Details
    $shipping_details = extract_shipping_details($bc_order);

    // Extract Line Items
    $unmatched_skus = [];
    $omins_line_items = extract_omins_line_items($bc_order, $omins_client, $omins_creds, $unmatched_skus);
    $line_items_changed_flag = !empty($omins_line_items) ? 1 : 0;

    // Prepare Notes
    $order_id_bc = $bc_order['id'] ?? 'N/A';
    $invoice_note = "BC Order #{$order_id_bc}";
    if (!empty($unmatched_skus)) {
        $invoice_note .= " Unmatched SKUs (not added to OMINS invoice): " . implode(', ', $unmatched_skus) . ".";
    }
    if (empty($omins_line_items)) {
        $invoice_note .= " No line items processed from BC order for OMINS invoice.";
    }

    $order_date = isset($bc_order['date_created']) ? date('Y-m-d', strtotime($bc_order['date_created'])) : date('Y-m-d');

    $params = [
        'promo_group_id'      => OMINS_PROMO_GROUP_ID, //
        'orderdate'           => $order_date, //
        'statusdate'          => date('Y-m-d'), //
        'name'                => $shipping_details['name'], //
        'company'             => $shipping_details['company'], //
        'address'             => $shipping_details['street'], //
        'city'                => $shipping_details['city'], //
        'postcode'            => $shipping_details['zip'], //
        'state'               => $shipping_details['state'], //
        'country'             => $shipping_details['country'], //
        'phone'               => $shipping_details['phone'], //
        'mobile'              => $shipping_details['phone'], // Or a specific mobile field if available
        'email'               => $shipping_details['email'], //
        'type'                => 'invoice', //
        'ship_instructions'   => $bc_order['customer_message'] ?? '',
        'printedinstructions' => '',
        'specialinstructions' => '',
        'note'                => $invoice_note, //
        'thelineitems'        => $omins_line_items,
        'lineitemschanged'    => $line_items_changed_flag,
        'discountamount'      => (string)($bc_order['discount_amount'] ?? '0.00'), //
        // Optional: Order totals - uncomment if OMINS doesn't auto-calculate
        // 'subtotal'         => (string)($bc_order['subtotal_inc_tax'] ?? '0.00'),
        // 'shippingtotal'    => (string)($bc_order['shipping_cost_inc_tax'] ?? '0.00'),
        // 'taxtotal'         => (string)($bc_order['total_tax'] ?? '0.00'),
        // 'ordertotal'       => (string)($bc_order['total_inc_tax'] ?? '0.00'),
    ];

    // Optional: Add payment information if applicable
    if (isset($bc_order['payment_method']) && ($bc_order['payment_status'] === 'captured' || $bc_order['payment_status'] === 'completed')) {
        $params['paymentmethod'] = (string)$bc_order['payment_method'];
        $params['paymentdate'] = date('Y-m-d'); // Or a more specific payment date
        $params['paymentamount'] = (string)($bc_order['total_inc_tax'] ?? '0.00');
    }

    return $params;
}

/**
 * Extracts and formats shipping details from BigCommerce order.
 * @param array $bc_order BigCommerce order data.
 * @return array Shipping details.
 */
function extract_shipping_details(array $bc_order): array {
    $shipping_address_data = [];
    if (!empty($bc_order['shipping_addresses']) && is_string($bc_order['shipping_addresses'])) {
        $decoded_shipping_array = json_decode($bc_order['shipping_addresses'], true);
        if (json_last_error() === JSON_ERROR_NONE && !empty($decoded_shipping_array[0])) {
            $shipping_address_data = $decoded_shipping_array[0];
        } else {
            log_message("Could not decode shipping_addresses JSON or it's empty. Error: " . json_last_error_msg() . ". JSON: " . $bc_order['shipping_addresses'], 'WARNING');
        }
    }

    $billing_address = $bc_order['billing_address'] ?? [];

    $details = [];
    $details['name']    = trim(($shipping_address_data['first_name'] ?? $billing_address['first_name'] ?? '') . ' ' . ($shipping_address_data['last_name'] ?? $billing_address['last_name'] ?? ''));
    $details['company'] = $shipping_address_data['company'] ?? $billing_address['company'] ?? '';
    $details['street']  = $shipping_address_data['street_1'] ?? $billing_address['street_1'] ?? '';
    $street_2           = $shipping_address_data['street_2'] ?? $billing_address['street_2'] ?? '';
    if (!empty($street_2)) {
        $details['street'] .= ' ' . $street_2;
    }
    $details['city']    = $shipping_address_data['city'] ?? $billing_address['city'] ?? '';
    $details['zip']     = $shipping_address_data['zip'] ?? $billing_address['zip'] ?? '';
    $details['state']   = $shipping_address_data['state'] ?? $billing_address['state'] ?? '';
    $details['country'] = $shipping_address_data['country'] ?? $billing_address['country'] ?? '';
    $details['phone']   = $shipping_address_data['phone'] ?? $billing_address['phone'] ?? '';
    $details['email']   = $shipping_address_data['email'] ?? $billing_address['email'] ?? '';

    log_message("Extracted shipping details: " . json_encode($details), 'DEBUG');
    return $details;
}

/**
 * Extracts line items from BC order, fetches OMINS product IDs, and formats for OMINS.
 * @param array $bc_order BigCommerce order data.
 * @param jsonRPCClient $omins_client OMINS RPC client.
 * @param stdClass $omins_creds OMINS credentials.
 * @param array &$unmatched_skus Passed by reference to collect SKUs not found in OMINS.
 * @return array Formatted line items for OMINS.
 */
function extract_omins_line_items(array $bc_order, jsonRPCClient $omins_client, stdClass $omins_creds, array &$unmatched_skus): array {
    $omins_items = [];
    $bc_products_list = [];

    if (!empty($bc_order['products']) && is_string($bc_order['products'])) {
        $decoded_products = json_decode($bc_order['products'], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded_products)) {
            $bc_products_list = $decoded_products;
        } else {
            log_message("Could not decode 'products' JSON string or it's not an array. Error: " . json_last_error_msg() . ". JSON: " . $bc_order['products'], 'WARNING');
        }
    }

    if (empty($bc_products_list)) {
        log_message("No products found in BigCommerce order or 'products' field was invalid.", 'INFO');
        return [];
    }

    log_message("Processing " . count($bc_products_list) . " product(s) from BigCommerce.", 'DEBUG');
    foreach ($bc_products_list as $bc_item) {
        $sku = $bc_item['sku'] ?? null;
        $product_name = $bc_item['name'] ?? $sku;
        $quantity = $bc_item['quantity'] ?? 0;
        // Using price_inc_tax based on BC payload example ("is_tax_inclusive_pricing":"true")
        $price = $bc_item['price_inc_tax'] ?? ($bc_item['price_ex_tax'] ?? '0.00');

        if (!$sku || (int)$quantity <= 0) {
            log_message("Skipping item with missing SKU ('{$sku}') or zero/invalid quantity ('{$quantity}').", 'WARNING');
            continue;
        }

        $omins_product_id = get_omins_product_id_by_sku((string)$sku, $omins_client, $omins_creds);

        if ($omins_product_id) {
            $omins_items[] = [
                'stockid'     => (string)$omins_product_id,
                'partnumber'  => (string)$sku,
                'description' => (string)$product_name,
                'quantity'    => (string)$quantity,
                'price'       => (string)$price,
            ];
            log_message("Added SKU '{$sku}' (OMINS ID: {$omins_product_id}) to OMINS line items.", 'DEBUG');
        } else {
            $unmatched_skus[] = (string)$sku;
            log_message("SKU '{$sku}' not found in OMINS or error fetching.", 'WARNING');
        }
    }
    return $omins_items;
}

/**
 * Fetches OMINS product ID by SKU.
 * @param string $sku Product SKU.
 * @param jsonRPCClient $omins_client OMINS RPC client.
 * @param stdClass $omins_creds OMINS credentials.
 * @return string|null OMINS product ID or null if not found/error.
 */
function get_omins_product_id_by_sku(string $sku, jsonRPCClient $omins_client, stdClass $omins_creds): ?string {
    try {
        $product_query_data = ['name' => $sku]; // 'name' is used for SKU lookup in getProductbyName
        $omins_product_info = $omins_client->getProductbyName($omins_creds, $product_query_data); //

        if ($omins_product_info && isset($omins_product_info['id'])) {
            return (string)$omins_product_info['id'];
        }
        log_message("OMINS getProductbyName for SKU '{$sku}' returned no ID. Response: " . json_encode($omins_product_info), 'DEBUG');
        return null;
    } catch (Exception $e) {
        log_message("API error fetching OMINS product ID for SKU '{$sku}': " . $e->getMessage(), 'ERROR');
        return null;
    }
}

/**
 * Logs a message using error_log.
 * @param string $message The message to log.
 * @param string $level Log level (e.g., INFO, DEBUG, WARNING, ERROR, CRITICAL).
 */
function log_message(string $message, string $level = 'INFO'): void {
    // Simple filtering based on defined log level
    $log_levels = ['DEBUG' => 0, 'INFO' => 1, 'WARNING' => 2, 'ERROR' => 3, 'CRITICAL' => 4];
    $current_log_level_val = $log_levels[LOG_LEVEL] ?? $log_levels['INFO'];
    $message_level_val = $log_levels[$level] ?? $log_levels['INFO'];

    if ($message_level_val >= $current_log_level_val) {
        error_log(date('[Y-m-d H:i:s T] ') . "[$level] " . $message);
    }
}

/**
 * Sends a JSON response and exits.
 * @param array $data Data to encode as JSON.
 * @param int $status_code HTTP status code.
 */
function send_json_response(array $data, int $status_code = 200): void {
    if (!headers_sent()) {
        http_response_code($status_code);
    }
    echo json_encode($data);
    exit;
}

?>