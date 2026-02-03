<?php
/**
 * WayForPay Payment Gateway Class.
 *
 * @package learnpress-wayforpay
 */

defined('ABSPATH') || exit;

if (!class_exists('LP_Gateway_WayForPay')) {

    /**
     * Class LP_Gateway_WayForPay
     */
    class LP_Gateway_WayForPay extends LP_Gateway_Abstract
    {

        /**
         * @var string
         */
        public $id = 'wayforpay';

        /**
         * @var string
         */
        protected $merchant_account;

        /**
         * @var string
         */
        protected $secret_key;

        /**
         * @var string
         */
        protected $url = 'https://secure.wayforpay.com/pay';

        /**
         * @var int
         */
        protected $order_timeout;

        /**
         * @var bool
         */
        protected $debug_mode;

        /**
         * @var array Supported currencies
         */
        protected $supported_currencies = array('UAH', 'USD', 'EUR');

        /**
         * Constructor.
         */
        public function __construct()
        {
            $this->method_title = 'WayForPay';
            $this->method_description = __('Pay with WayForPay', 'learnpress-wayforpay');
            $this->icon = '';

            // Load settings
            parent::__construct();

            $this->title = $this->settings->get('title', 'WayForPay');
            $this->description = $this->settings->get('description', __('Pay securely via WayForPay.', 'learnpress-wayforpay'));
            $this->merchant_account = $this->settings->get('merchant_account');
            $this->secret_key = $this->settings->get('secret_key');
            $this->order_timeout = absint($this->settings->get('order_timeout', 49000));
            $this->debug_mode = $this->settings->get('debug_mode') === 'yes';

            // Hooks
            add_filter('learn-press/payment-gateway/' . $this->id . '/available', array($this, 'is_available'), 10, 2);
        }

        /**
         * Check if gateway is available.
         *
         * @return bool
         */
        public function is_available()
        {
            if (!$this->is_enabled()) {
                return false;
            }
            if (empty($this->merchant_account) || empty($this->secret_key)) {
                return false;
            }

            // Check if currency is supported
            $currency = strtoupper(learn_press_get_currency());
            if (!in_array($currency, $this->supported_currencies)) {
                $this->log('Currency not supported: ' . $currency);
                return false;
            }

            return true;
        }

        /**
         * Admin settings.
         *
         * @return array
         */
        public function get_settings()
        {
            return array(
                array(
                    'type' => 'title',
                    'title' => __('WayForPay Settings', 'learnpress-wayforpay'),
                ),
                array(
                    'title' => __('Enable/Disable', 'learnpress-wayforpay'),
                    'id' => 'enable',
                    'type' => 'checkbox',
                    'default' => 'no',
                    'desc' => __('Enable WayForPay payment', 'learnpress-wayforpay'),
                ),
                array(
                    'title' => __('Title', 'learnpress-wayforpay'),
                    'id' => 'title',
                    'type' => 'text',
                    'default' => 'WayForPay',
                ),
                array(
                    'title' => __('Description', 'learnpress-wayforpay'),
                    'id' => 'description',
                    'type' => 'textarea',
                    'default' => __('Pay securely via WayForPay.', 'learnpress-wayforpay'),
                ),
                array(
                    'title' => __('Merchant Account', 'learnpress-wayforpay'),
                    'id' => 'merchant_account',
                    'type' => 'text',
                    'desc' => __('Your WayForPay Merchant Account ID.', 'learnpress-wayforpay'),
                ),
                array(
                    'title' => __('Secret Key', 'learnpress-wayforpay'),
                    'id' => 'secret_key',
                    'type' => 'text',
                    'desc' => __('Your WayForPay Secret Key.', 'learnpress-wayforpay'),
                ),
                array(
                    'title' => __('Order Timeout', 'learnpress-wayforpay'),
                    'id' => 'order_timeout',
                    'type' => 'number',
                    'default' => '49000',
                    'desc' => __('Time in seconds before the order expires (default: 49000).', 'learnpress-wayforpay'),
                ),
                array(
                    'title' => __('Debug Mode', 'learnpress-wayforpay'),
                    'id' => 'debug_mode',
                    'type' => 'checkbox',
                    'default' => 'no',
                    'desc' => __('Enable debug logging (logs will be saved to WordPress debug.log).', 'learnpress-wayforpay'),
                ),
                array(
                    'type' => 'sectionend',
                ),
            );
        }

        /**
         * Payment form on checkout.
         */
        public function get_payment_form()
        {
            $currency = strtoupper(learn_press_get_currency());
            $supported_text = sprintf(
                    __('Supported currencies: %s', 'learnpress-wayforpay'),
                    implode(', ', $this->supported_currencies)
            );

            return wpautop($this->description) . '<p><small>' . esc_html($supported_text) . '</small></p>';
        }

        /**
         * Process payment.
         *
         * @param int $order_id
         * @return array
         */
        public function process_payment($order_id)
        {
            $order = learn_press_get_order($order_id);

            if (!$order) {
                $this->log('Order not found: ' . $order_id);
                return array(
                        'result' => 'fail',
                        'message' => __('Order not found', 'learnpress-wayforpay'),
                );
            }

            // Validate currency
            $currency = strtoupper(learn_press_get_currency());
            if (!in_array($currency, $this->supported_currencies)) {
                $this->log('Unsupported currency for order: ' . $order_id . ' - ' . $currency);
                return array(
                        'result' => 'fail',
                        'message' => sprintf(__('Currency %s is not supported by WayForPay.', 'learnpress-wayforpay'), $currency),
                );
            }

            // Create nonce for security
            $nonce = wp_create_nonce('lp_wayforpay_submit_' . $order_id);

            // Return redirect to our intermediate submit handler
            return array(
                'result' => 'success',
                'redirect' => add_query_arg(
                    array(
                        'lp-wayforpay-submit' => $order_id,
                        'nonce' => $nonce,
                    ),
                    home_url('/')
                ),
            );
        }

        /**
         * Handle the intermediate page generation using POST method to WayForPay.
         *
         * @param int $order_id
         * @param string $nonce
         */
        public function process_wayforpay_submit($order_id, $nonce)
        {
            // Verify nonce
            if (!wp_verify_nonce($nonce, 'lp_wayforpay_submit_' . $order_id)) {
                $this->log('Nonce verification failed for order: ' . $order_id);
                wp_die(__('Security check failed', 'learnpress-wayforpay'), 'Security Error', array('response' => 403));
            }

            $order = learn_press_get_order($order_id);
            if (!$order) {
                $this->log('Invalid order on submit: ' . $order_id);
                wp_die(__('Invalid Order', 'learnpress-wayforpay'));
            }

            $order_no = $order->get_order_number();
            $date = strtotime($order->get_order_date());
            $amount = round($order->get_total(), 2);
            $currency = strtoupper(learn_press_get_currency());

            // Validate currency
            if (!in_array($currency, $this->supported_currencies)) {
                $currency = 'UAH'; // Fallback
                $this->log('Currency fallback to UAH for order: ' . $order_id);
            }

            $productNames = array();
            $productPrices = array();
            $productCounts = array();

            // Get order items
            $items = $order->get_items();
            if (!empty($items)) {
                foreach ($items as $item) {
                    $productNames[] = sanitize_text_field($item['name'] ?? __('Course', 'learnpress-wayforpay'));
                    $productCounts[] = absint($item['quantity'] ?? 1);
                    $productPrices[] = round(floatval($item['total'] ?? 0), 2);
                }
            } else {
                // Fallback
                $productNames[] = sprintf(__('Order %s', 'learnpress-wayforpay'), $order_no);
                $productCounts[] = 1;
                $productPrices[] = $amount;
            }

            // Get user info
            $user = $order->get_user();
            $user_email = sanitize_email($order->get_user_email());

            // Try to get user names
            $first_name = '';
            $last_name = '';
            if ($user) {
                $first_name = sanitize_text_field(get_user_meta($user->ID, 'first_name', true));
                $last_name = sanitize_text_field(get_user_meta($user->ID, 'last_name', true));

                if (empty($first_name) && empty($last_name)) {
                    $first_name = sanitize_text_field($user->display_name);
                }
            }

            // Callback URLs
            $return_url = $this->get_return_url($order);
            $service_url = add_query_arg('lp-wayforpay-callback', '1', home_url('/'));

            // Generate unique order reference
            $order_reference = $order_id . '_lp_' . time();

            $fields = array(
                'merchantAccount' => $this->merchant_account,
                'merchantAuthType' => 'SimpleSignature',
                'merchantDomainName' => sanitize_text_field($_SERVER['SERVER_NAME']),
                'orderReference' => $order_reference,
                'orderDate' => $date,
                'amount' => $amount,
                'currency' => $currency,
                'orderTimeout' => $this->order_timeout,
                'productName' => $productNames,
                'productPrice' => $productPrices,
                'productCount' => $productCounts,
                'clientFirstName' => $first_name,
                'clientLastName' => $last_name,
                'clientEmail' => $user_email,
                'clientPhone' => '',
                'language' => 'AUTO',
                'returnUrl' => $return_url,
                'serviceUrl' => $service_url,
            );

            // Generate Signature
            $fields['merchantSignature'] = $this->generate_signature($fields);

            // Store transaction metadata
            $this->save_transaction_meta($order_id, array(
                'order_reference' => $order_reference,
                'amount' => $amount,
                'currency' => $currency,
                'timestamp' => time(),
            ));

            $this->log('Redirecting to WayForPay for order: ' . $order_id . ' - Reference: ' . $order_reference);

            // Render Form
            ?>
            <!DOCTYPE html>
            <html <?php language_attributes(); ?>>

            <head>
                <meta charset="<?php bloginfo('charset'); ?>">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title>
                    <?php _e('Redirecting to Payment...', 'learnpress-wayforpay'); ?>
                </title>
                <style>
                    body {
                        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                        text-align: center;
                        padding: 50px;
                        background: #f5f5f5;
                    }

                    .container {
                        max-width: 500px;
                        margin: 0 auto;
                        background: white;
                        padding: 40px;
                        border-radius: 8px;
                        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
                    }

                    .loader {
                        border: 5px solid #f3f3f3;
                        border-top: 5px solid #3498db;
                        border-radius: 50%;
                        width: 50px;
                        height: 50px;
                        animation: spin 1s linear infinite;
                        margin: 0 auto 20px;
                    }

                    @keyframes spin {
                        0% {
                            transform: rotate(0deg);
                        }

                        100% {
                            transform: rotate(360deg);
                        }
                    }

                    h3 {
                        color: #333;
                        font-weight: 400;
                    }

                    .error {
                        color: #d9534f;
                        margin-top: 20px;
                    }
                </style>
            </head>

            <body>
            <div class="container">
                <div class="loader"></div>
                <h3>
                    <?php _e('Please wait, redirecting to payment gateway...', 'learnpress-wayforpay'); ?>
                </h3>
                <p class="error" id="error-message" style="display: none;">
                    <?php _e('If you are not redirected automatically, please click the button below.', 'learnpress-wayforpay'); ?>
                </p>
                <form id="wayforpay_form" action="<?php echo esc_url($this->url); ?>" method="POST">
                    <?php
                    foreach ($fields as $key => $value) {
                        if (is_array($value)) {
                            foreach ($value as $v) {
                                echo '<input type="hidden" name="' . esc_attr($key) . '[]" value="' . esc_attr($v) . '" />';
                            }
                        } else {
                            echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '" />';
                        }
                    }
                    ?>
                    <noscript>
                        <button type="submit" style="margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; border: none; border-radius: 4px; cursor: pointer;">
                            <?php _e('Continue to Payment', 'learnpress-wayforpay'); ?>
                        </button>
                    </noscript>
                </form>
                <script type="text/javascript">
                    setTimeout(function () {
                        document.getElementById('wayforpay_form').submit();
                    }, 1000);

                    // Show error message if redirect fails
                    setTimeout(function () {
                        document.getElementById('error-message').style.display = 'block';
                    }, 5000);
                </script>
            </div>
            </body>

            </html>
            <?php
            exit;
        }

        /**
         * Generate Signature.
         *
         * @param array $data
         * @return string
         */
        private function generate_signature($data)
        {
            $keysForSignature = array(
                'merchantAccount',
                'merchantDomainName',
                'orderReference',
                'orderDate',
                'amount',
                'currency',
                'productName',
                'productCount',
                'productPrice'
            );

            $hash = array();
            foreach ($keysForSignature as $key) {
                if (!isset($data[$key])) {
                    continue;
                }
                if (is_array($data[$key])) {
                    foreach ($data[$key] as $v) {
                        $hash[] = $v;
                    }
                } else {
                    $hash[] = $data[$key];
                }
            }

            $string = implode(';', $hash);
            return hash_hmac('md5', $string, $this->secret_key);
        }

        /**
         * Handle Callback from WayForPay.
         */
        public function handle_wayforpay_callback()
        {
            $data = json_decode(file_get_contents('php://input'), true);

            if (!$data) {
                // Try POST fallback
                $data = $_POST;
            }

            if (empty($data)) {
                $this->log('No data received in callback');
                wp_die('No data', 'WayForPay', array('response' => 400));
            }

            $this->log('Callback received: ' . json_encode($data));

            // Validate Signature
            $received_signature = $data['merchantSignature'] ?? '';

            $sign_fields = array(
                'merchantAccount',
                'orderReference',
                'amount',
                'currency'
            );

            // Add additional fields based on transaction status
            if (!empty($data['authCode'])) {
                $sign_fields[] = 'authCode';
            }
            if (!empty($data['cardPan'])) {
                $sign_fields[] = 'cardPan';
            }
            if (!empty($data['transactionStatus'])) {
                $sign_fields[] = 'transactionStatus';
            }
            if (!empty($data['reasonCode'])) {
                $sign_fields[] = 'reasonCode';
            }

            $hash = array();
            foreach ($sign_fields as $key) {
                if (isset($data[$key])) {
                    $hash[] = $data[$key];
                }
            }

            $string = implode(';', $hash);
            $my_signature = hash_hmac('md5', $string, $this->secret_key);

            if ($received_signature !== $my_signature) {
                $this->log('Signature mismatch. Expected: ' . $my_signature . ', Received: ' . $received_signature);
                wp_die('Invalid Signature', 'WayForPay', array('response' => 403));
            }

            // Get Order ID
            $parts = explode('_', $data['orderReference'] ?? '');
            $order_id = absint($parts[0]);
            $order = learn_press_get_order($order_id);

            if (!$order) {
                $this->log('Order not found in callback: ' . $order_id);
                $this->response_to_gateway($data['orderReference'], 'decline');
                wp_die('Order not found', 'WayForPay', array('response' => 404));
            }

            $transaction_status = sanitize_text_field($data['transactionStatus'] ?? '');

            if ($transaction_status === 'Approved') {
                // Payment Success
                $order->payment_complete($data['orderReference'] ?? '');

                $note = sprintf(
                    __('WayForPay payment approved. Transaction ID: %s, Amount: %s %s', 'learnpress-wayforpay'),
                    $data['orderReference'] ?? '',
                    $data['amount'] ?? '',
                    $data['currency'] ?? ''
                );
                $order->add_note($note);

                // Save transaction data
                $this->save_transaction_meta($order_id, array(
                    'transaction_id' => $data['orderReference'] ?? '',
                    'transaction_status' => $transaction_status,
                    'auth_code' => $data['authCode'] ?? '',
                    'card_pan' => $data['cardPan'] ?? '',
                    'payment_date' => current_time('mysql'),
                ));

                $this->log('Payment approved for order: ' . $order_id);
            } else {
                // Payment failed or declined
                $reason = sanitize_text_field($data['reason'] ?? __('Unknown reason', 'learnpress-wayforpay'));
                $reason_code = sanitize_text_field($data['reasonCode'] ?? '');

                $note = sprintf(
                    __('WayForPay payment failed. Status: %s, Reason: %s (Code: %s)', 'learnpress-wayforpay'),
                    $transaction_status,
                    $reason,
                    $reason_code
                );

                $order->update_status('failed', $note);

                $this->log('Payment failed for order: ' . $order_id . ' - ' . $note);
            }

            $this->response_to_gateway($data['orderReference'], 'accept');
        }

        /**
         * Send response back to WayForPay.
         *
         * @param string $order_ref
         * @param string $status
         */
        private function response_to_gateway($order_ref, $status)
        {
            $time = time();
            $response = array(
                'orderReference' => $order_ref,
                'status' => $status,
                'time' => $time,
            );

            // Generate signature: orderReference;status;time
            $sign_string = $order_ref . ';' . $status . ';' . $time;
            $response['signature'] = hash_hmac('md5', $sign_string, $this->secret_key);

            $this->log('Sending response to WayForPay: ' . json_encode($response));

            header('Content-Type: application/json');
            echo json_encode($response);
            exit;
        }

        /**
         * Save transaction metadata.
         *
         * @param int $order_id
         * @param array $data
         */
        private function save_transaction_meta($order_id, $data)
        {
            foreach ($data as $key => $value) {
                update_post_meta($order_id, '_wayforpay_' . $key, $value);
            }
        }

        /**
         * Log messages for debugging.
         *
         * @param string $message
         */
        private function log($message)
        {
            if ($this->debug_mode && function_exists('error_log')) {
                error_log('[WayForPay] ' . $message);
            }
        }
    }
}
