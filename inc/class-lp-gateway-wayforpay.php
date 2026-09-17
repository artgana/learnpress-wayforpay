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
         * Cached raw request body, so callback logging and callback parsing
         * both read php://input exactly once instead of racing each other.
         *
         * @var string|null
         */
        protected $raw_input_cache;

        /**
         * @var array Supported currencies
         * For example: array('UAH', 'USD', 'EUR')
         */
        protected $supported_currencies = array('UAH');

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
                    'id' => '[enable]',
                    'type' => 'checkbox',
                    'default' => 'no',
                    'desc' => __('Enable WayForPay payment', 'learnpress-wayforpay'),
                ),
                array(
                    'title' => __('Title', 'learnpress-wayforpay'),
                    'id' => '[title]',
                    'type' => 'text',
                    'default' => 'WayForPay',
                ),
                array(
                    'title' => __('Description', 'learnpress-wayforpay'),
                    'id' => '[description]',
                    'type' => 'textarea',
                    'default' => __('Pay securely via WayForPay.', 'learnpress-wayforpay'),
                ),
                array(
                    'title' => __('Merchant Account', 'learnpress-wayforpay'),
                    'id' => '[merchant_account]',
                    'type' => 'text',
                    'desc' => __('Your WayForPay Merchant Account ID.', 'learnpress-wayforpay'),
                ),
                array(
                    'title' => __('Secret Key', 'learnpress-wayforpay'),
                    'id' => '[secret_key]',
                    'type' => 'text',
                    'desc' => __('Your WayForPay Secret Key.', 'learnpress-wayforpay'),
                ),
                array(
                    'title' => __('Order Timeout', 'learnpress-wayforpay'),
                    'id' => '[order_timeout]',
                    'type' => 'number',
                    'default' => '49000',
                    'desc' => __('Time in seconds before the order expires (default: 49000).', 'learnpress-wayforpay'),
                ),
                array(
                    'title' => __('Debug Mode', 'learnpress-wayforpay'),
                    'id' => '[debug_mode]',
                    'type' => 'checkbox',
                    'default' => 'no',
                    'desc' => __('Log every submit/callback request (headers, raw body, signature check, URLs sent to WayForPay) to wp-content/wayforpay-logs/wayforpay-YYYY-MM-DD.log. Enable only while diagnosing an issue.', 'learnpress-wayforpay'),
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
            /*
			$currency = strtoupper(learn_press_get_currency());
            $supported_text = sprintf(
                    __('Supported currencies: %s', 'learnpress-wayforpay'),
                    implode(', ', $this->supported_currencies)
            );

            return wpautop($this->description) . '<p><small>' . esc_html($supported_text) . '</small></p>'; */
            return wpautop($this->description);
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
         * Log that a submit/callback request reached this plugin's code at all,
         * before any parsing or business logic runs. If debug mode is on and this
         * line never shows up for an attempted payment, the request is being
         * stopped upstream of WordPress (WAF/CDN/hosting firewall), not by
         * anything in this plugin. Also registers a shutdown check so a PHP
         * fatal partway through still leaves a trace instead of silence.
         *
         * @param string $type 'submit' or 'callback'
         */
        public function log_incoming_request($type)
        {
            $this->log('Incoming ' . $type . ' request', array(
                'method' => $_SERVER['REQUEST_METHOD'] ?? '',
                'uri' => $_SERVER['REQUEST_URI'] ?? '',
                'host' => $_SERVER['HTTP_HOST'] ?? '',
                'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? '',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'content_type' => $_SERVER['CONTENT_TYPE'] ?? ($_SERVER['HTTP_CONTENT_TYPE'] ?? ''),
                'content_length' => $_SERVER['CONTENT_LENGTH'] ?? '',
                'get' => $_GET,
                'post' => $_POST,
                'raw_body' => $this->get_raw_input(),
            ));

            register_shutdown_function(function () use ($type) {
                $error = error_get_last();
                if ($error && in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
                    $this->log('FATAL during ' . $type . ' request', array(
                        'message' => $error['message'],
                        'file' => $error['file'],
                        'line' => $error['line'],
                    ));
                }
            });
        }

        /**
         * Read the raw request body once and cache it, so callback logging and
         * callback parsing don't each consume php://input independently.
         *
         * @return string
         */
        private function get_raw_input()
        {
            if ($this->raw_input_cache === null) {
                $this->raw_input_cache = (string) file_get_contents('php://input');
            }
            return $this->raw_input_cache;
        }

        /**
         * Receive the debug-only client-side beacon from the redirect page (see
         * process_wayforpay_submit()'s inline script). No nonce/auth check - this
         * only ever runs anything when Debug Mode is on, and it does nothing but
         * write a log line, so there's nothing here worth protecting; when Debug
         * Mode is off it responds without logging, so a stray/replayed beacon
         * can't be used to grow the log file.
         */
        public function handle_client_log()
        {
            header('Content-Type: application/json');

            if (!$this->debug_mode) {
                echo json_encode(array('ok' => false, 'reason' => 'debug mode off'));
                exit;
            }

            $data = json_decode($this->get_raw_input(), true);
            if (!is_array($data)) {
                $data = array();
            }

            $this->log('Client-side event on redirect page', array(
                'event' => isset($data['event']) ? sanitize_text_field($data['event']) : null,
                'order_id' => isset($data['orderId']) ? absint($data['orderId']) : null,
                'message' => isset($data['message']) ? sanitize_text_field($data['message']) : null,
                'filename' => isset($data['filename']) ? sanitize_text_field($data['filename']) : null,
                'lineno' => isset($data['lineno']) ? absint($data['lineno']) : null,
                'violated_directive' => isset($data['violatedDirective']) ? sanitize_text_field($data['violatedDirective']) : null,
                'blocked_uri' => isset($data['blockedURI']) ? sanitize_text_field($data['blockedURI']) : null,
            ));

            echo json_encode(array('ok' => true));
            exit;
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
                $itemTotals = array();
                foreach ($items as $item) {
                    $productNames[] = sanitize_text_field($item['name'] ?? __('Course', 'learnpress-wayforpay'));
                    $productCounts[] = max(1, absint($item['quantity'] ?? 1));
                    $itemTotals[] = round(floatval($item['total'] ?? 0), 2);
                }

                // WayForPay requires amount === sum(productPrice * productCount). Item totals
                // reflect the pre-discount course price, so any coupon/promo that lowers the
                // order total below the raw subtotal must be prorated across the line items,
                // otherwise the signature/amount WayForPay receives won't match what we charge.
                $subtotal = round($order->get_subtotal(), 2);
                $rawTotalsSum = array_sum($itemTotals);
                $needsProration = $rawTotalsSum > 0 && abs($rawTotalsSum - $amount) > 0.01;

                if ($needsProration) {
                    $baseline = $subtotal > 0 ? $subtotal : $rawTotalsSum;
                    $ratio = $baseline > 0 ? $amount / $baseline : 1;
                    $lineTotals = array();
                    $runningSum = 0;
                    $lastIndex = count($itemTotals) - 1;
                    foreach ($itemTotals as $i => $rawTotal) {
                        if ($i === $lastIndex) {
                            // Last item absorbs any rounding remainder so the sum matches $amount exactly.
                            $lineTotals[] = round($amount - $runningSum, 2);
                        } else {
                            $prorated = round($rawTotal * $ratio, 2);
                            $lineTotals[] = $prorated;
                            $runningSum += $prorated;
                        }
                    }
                } else {
                    $lineTotals = $itemTotals;
                }

                foreach ($lineTotals as $i => $lineTotal) {
                    // productPrice is a per-unit price; WayForPay validates productPrice * productCount.
                    $productPrices[] = round($lineTotal / $productCounts[$i], 2);
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

            $this->log('Redirecting to WayForPay for order: ' . $order_id . ' - Reference: ' . $order_reference, array(
                'merchantAccount' => $fields['merchantAccount'],
                'merchantDomainName' => $fields['merchantDomainName'],
                'returnUrl' => $fields['returnUrl'],
                'serviceUrl' => $fields['serviceUrl'],
                'amount' => $fields['amount'],
                'currency' => $fields['currency'],
                'productName' => $fields['productName'],
                'productPrice' => $fields['productPrice'],
                'productCount' => $fields['productCount'],
                'orderTimeout' => $fields['orderTimeout'],
                // Distinguishes "logged into an existing account" from "account just
                // created during this checkout" without having to guess from timing -
                // user_registered vs. now, in seconds, is ~0 for a brand new signup.
                'user_id' => $user ? $user->ID : null,
                'account_age_seconds' => $user ? ( time() - strtotime( $user->user_registered . ' UTC' ) ) : null,
            ));

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
                <?php if ($this->debug_mode): ?>
                <script type="text/javascript">
                    // Debug Mode only. Our own server-side log can prove this page rendered
                    // with valid-looking fields, but not whether the browser actually reached
                    // WayForPay afterward - a JS error or a security-plugin CSP could silently
                    // stop the auto-submit before it leaves this page. This reports that part
                    // back to the same debug log via a tiny beacon, so a failed attempt shows
                    // whether the submit fired, threw, was blocked, or the page ever unloaded.
                    (function () {
                        var logUrl = <?php echo wp_json_encode( add_query_arg( 'lp-wayforpay-client-log', '1', home_url( '/' ) ) ); ?>;
                        function beacon(event, extra) {
                            try {
                                var payload = JSON.stringify(Object.assign({event: event, orderId: <?php echo (int) $order_id; ?>}, extra || {}));
                                if (navigator.sendBeacon) {
                                    navigator.sendBeacon(logUrl, new Blob([payload], {type: 'text/plain'}));
                                } else {
                                    fetch(logUrl, {method: 'POST', body: payload, keepalive: true});
                                }
                            } catch (e) {}
                        }
                        window.addEventListener('error', function (e) {
                            beacon('js_error', {message: e.message, filename: e.filename, lineno: e.lineno});
                        });
                        window.addEventListener('securitypolicyviolation', function (e) {
                            beacon('csp_violation', {violatedDirective: e.violatedDirective, blockedURI: e.blockedURI});
                        });
                        window.addEventListener('pagehide', function () {
                            beacon('page_hidden');
                        });
                        beacon('page_loaded');
                        var originalTimeout = window.setTimeout;
                        originalTimeout(function () {
                            beacon('submit_attempted');
                        }, 900);
                    })();
                </script>
                <?php endif; ?>
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
            $raw_body = $this->get_raw_input();
            $data = json_decode($raw_body, true);
            $source = $data ? 'json_body' : null;

            if (!$data) {
                // Try POST fallback
                $data = wp_unslash($_POST);
                $source = !empty($data) ? 'post_fallback' : 'none';
            }

            $this->log('Callback payload parsed', array(
                'source' => $source,
                'raw_body_length' => strlen($raw_body),
                'parsed_keys' => is_array($data) ? array_keys($data) : null,
            ));

            if (empty($data)) {
                $this->log('No data received in callback', array(
                    'headers' => function_exists('getallheaders') ? getallheaders() : array(),
                ));
                wp_die('No data', 'WayForPay', array('response' => 400));
            }

            $this->log('Callback received: ' . json_encode($data));

            // Validate Signature
            $received_signature = $data['merchantSignature'] ?? '';

            // WayForPay always signs this fixed set of fields in this order, even when a
            // field is blank (e.g. authCode/cardPan on a declined transaction). Omitting
            // blank fields - as this used to do - shifts every value after the first gap
            // and breaks verification for anything but a fully successful, fully-populated
            // callback, so every field is always included with an empty-string fallback.
            $sign_fields = array(
                'merchantAccount',
                'orderReference',
                'amount',
                'currency',
                'authCode',
                'cardPan',
                'transactionStatus',
                'reasonCode',
            );

            $hash = array();
            foreach ($sign_fields as $key) {
                $hash[] = (string) ($data[$key] ?? '');
            }

            $string = implode(';', $hash);
            $my_signature = hash_hmac('md5', $string, $this->secret_key);

            $this->log('Callback signature check', array(
                'sign_string' => $string,
                'expected_signature' => $my_signature,
                'received_signature' => (string) $received_signature,
                'match' => hash_equals($my_signature, (string) $received_signature),
            ));

            if (!hash_equals($my_signature, (string) $received_signature)) {
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
         * Refund an order via WayForPay's REFUND API.
         *
         * Called by LP_Order::refund() after its own eligibility checks pass. On
         * success this must simply return - LearnPress itself marks the order
         * refunded, records who/when/how much, and adds the order note. On any
         * failure this must throw, so LearnPress surfaces the reason and leaves
         * the order status untouched.
         *
         * @param LP_Order $lp_order
         * @param float $amount
         * @param string $note
         * @return void
         * @throws Exception
         */
        public function refund($lp_order, float $amount = 0, string $note = '')
        {
            $order_id = $lp_order->get_id();
            $order_reference = get_post_meta($order_id, '_wayforpay_order_reference', true);

            if (empty($order_reference)) {
                throw new Exception(__('This order has no WayForPay transaction reference to refund.', 'learnpress-wayforpay'));
            }

            if ($amount <= 0) {
                $amount = (float) $lp_order->get_total();
            }
            $amount = round($amount, 2);

            $currency = strtoupper((string) $lp_order->get_currency());
            if (empty($currency)) {
                $currency = strtoupper(learn_press_get_currency());
            }

            $fields = array(
                'transactionType' => 'REFUND',
                'merchantAccount' => $this->merchant_account,
                'orderReference' => $order_reference,
                'amount' => $amount,
                'currency' => $currency,
                'comment' => $note !== '' ? $note : __('Refund issued from LearnPress.', 'learnpress-wayforpay'),
                'apiVersion' => 1,
            );

            // Per WayForPay docs: merchantAccount;orderReference;amount;currency
            $sign_string = implode(
                ';',
                array($fields['merchantAccount'], $fields['orderReference'], $fields['amount'], $fields['currency'])
            );
            $fields['merchantSignature'] = hash_hmac('md5', $sign_string, $this->secret_key);

            $this->log('Sending refund request for order: ' . $order_id . ' - ' . json_encode($fields));

            $response = wp_remote_post(
                'https://api.wayforpay.com/api',
                array(
                    'body' => json_encode($fields),
                    'headers' => array('Content-Type' => 'application/json'),
                    'timeout' => 60,
                )
            );

            if (is_wp_error($response)) {
                $this->log('Refund request failed for order: ' . $order_id . ' - ' . $response->get_error_message());
                throw new Exception($response->get_error_message());
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            $this->log('Refund response for order: ' . $order_id . ' - ' . wp_remote_retrieve_body($response));

            if (empty($body) || !isset($body['transactionStatus'])) {
                throw new Exception(__('Invalid response from WayForPay refund API.', 'learnpress-wayforpay'));
            }

            // Per WayForPay docs: merchantAccount;orderReference;transactionStatus;reasonCode
            $expected_signature = hash_hmac(
                'md5',
                implode(
                    ';',
                    array(
                        $body['merchantAccount'] ?? '',
                        $body['orderReference'] ?? '',
                        $body['transactionStatus'] ?? '',
                        (string) ($body['reasonCode'] ?? ''),
                    )
                ),
                $this->secret_key
            );
            $received_signature = (string) ($body['merchantSignature'] ?? '');

            if ($received_signature !== '' && !hash_equals($expected_signature, $received_signature)) {
                $this->log('Refund response signature mismatch for order: ' . $order_id);
                throw new Exception(__('WayForPay refund response signature is invalid.', 'learnpress-wayforpay'));
            }

            if (!in_array($body['transactionStatus'], array('Refunded', 'Voided'), true)) {
                $reason = $body['reason'] ?? __('Unknown reason', 'learnpress-wayforpay');
                throw new Exception(
                    sprintf(
                        /* translators: 1: WayForPay status, 2: reason */
                        __('WayForPay refund failed: %1$s (%2$s)', 'learnpress-wayforpay'),
                        $body['transactionStatus'],
                        $reason
                    )
                );
            }

            $this->save_transaction_meta($order_id, array(
                'refund_status' => $body['transactionStatus'],
                'refund_amount' => $amount,
                'refund_date' => current_time('mysql'),
            ));

            $this->log('Refund succeeded for order: ' . $order_id . ' - amount: ' . $amount . ' ' . $currency);
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
         * Directory the debug log lives in, created with the same
         * deny-all/silence protection as WordPress's own upload dirs.
         *
         * @return string
         */
        private static function log_dir()
        {
            $dir = WP_CONTENT_DIR . '/wayforpay-logs';
            if (!file_exists($dir)) {
                @mkdir($dir, 0755, true);
                @file_put_contents($dir . '/.htaccess', "Require all denied\n");
                @file_put_contents($dir . '/index.php', "<?php // silence is golden\n");
            }
            return $dir;
        }

        /**
         * Log messages for debugging, to a dedicated per-day file under
         * wp-content/wayforpay-logs/ rather than WordPress's general debug.log,
         * so it stays legible and isn't lost among unrelated log noise.
         *
         * @param string $message
         * @param array $context Optional structured data, JSON-encoded and appended.
         */
        private function log($message, $context = array())
        {
            if (!$this->debug_mode) {
                return;
            }

            $line = '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $message;
            if (!empty($context)) {
                $line .= ' | ' . wp_json_encode($context);
            }

            $file = self::log_dir() . '/wayforpay-' . gmdate('Y-m-d') . '.log';
            @error_log($line . "\n", 3, $file);
        }
    }
}
