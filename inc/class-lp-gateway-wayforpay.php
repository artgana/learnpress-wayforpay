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
		            'type' => 'sectionend',
	            ),
            );
        }

        /**
         * Payment form on checkout.
         */
        public function get_payment_form()
        {
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
                return array(
                    'result' => 'fail',
                    'message' => __('Order not found', 'learnpress-wayforpay'),
                );
            }

            // Return redirect to our intermediate submit handler
            return array(
                'result' => 'success',
                'redirect' => add_query_arg('lp-wayforpay-submit', $order_id, home_url('/')),
            );
        }

        /**
         * Handle the intermediate page generation using POST method to WayForPay.
         *
         * @param int $order_id
         */
        public function process_wayforpay_submit($order_id)
        {
            $order = learn_press_get_order($order_id);
            if (!$order) {
                wp_die(__('Invalid Order', 'learnpress-wayforpay'));
            }

            $order_no = $order->get_order_number();
            // Use local time for order date calculation
            $date = strtotime($order->get_order_date());
            $amount = $order->get_total();
            $currency = learn_press_get_currency();

            // Handle UAH specifically if needed (WayForPay uses UAH)
            if ($currency === 'uah' || $currency === 'ГРН') {
                $currency = 'UAH';
            }

            $productNames = array();
            $productPrices = array();
            $productCounts = array();

            // Fallback to simple order description to avoid item structure issues
            $productNames[] = sprintf(__('Order %s', 'learnpress-wayforpay'), $order_no);
            $productCounts[] = 1;
            $productPrices[] = $amount;

            // Callback URLs
            // returnUrl: Where user is redirected after payment (Success page)
            $return_url = $this->get_return_url($order);
            // serviceUrl: Server-to-server callback
            $service_url = add_query_arg('lp-wayforpay-callback', '1', home_url('/'));

            $fields = array(
                'merchantAccount' => $this->merchant_account,
                'merchantAuthType' => 'SimpleSignature',
                'merchantDomainName' => $_SERVER['SERVER_NAME'],
                'orderReference' => $order_id . '_lp_' . time(), // Unique ref
                'orderDate' => $date,
                'amount' => $amount,
                'currency' => $currency,
                'orderTimeout' => 49000,
                'productName' => $productNames,
                'productPrice' => $productPrices,
                'productCount' => $productCounts,
                'clientFirstName' => '',
                'clientLastName' => '',
                'clientEmail' => $order->get_user_email(),
                'clientPhone' => '',
                'language' => 'AUTO',
                'returnUrl' => $return_url,
                'serviceUrl' => $service_url,
            );

            // Generate Signature
            $fields['merchantSignature'] = $this->generate_signature($fields);

            // Render Form
            ?>
            <!DOCTYPE html>
	        <html <?php language_attributes(); ?>>

            <head>
                <title>
                    <?php _e('Redirecting to Payment...', 'learnpress-wayforpay'); ?>
                </title>
                <style>
                    body {
                        font-family: sans-serif;
                        text-align: center;
                        padding: 50px;
                    }

                    .loader {
                        border: 5px solid #f3f3f3;
                        border-top: 5px solid #3498db;
                        border-radius: 50%;
                        width: 50px;
                        height: 50px;
                        animation: spin 2s linear infinite;
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
                </style>
            </head>

            <body>
                <div class="loader"></div>
                <h3>
                    <?php _e('Please wait, redirecting to payment gateway...', 'learnpress-wayforpay'); ?>
                </h3>
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
                </form>
                <script type="text/javascript">
                    setTimeout(function () {
                        document.getElementById('wayforpay_form').submit();
                    }, 1000);
                </script>
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
                wp_die('No data', 'WayForPay');
            }

            // Validate Signature
            $received_signature = $data['merchantSignature'] ?? '';
            $check_fields = array('merchantAccount', 'orderReference', 'amount', 'currency', 'authCode', 'cardPan', 'transactionStatus', 'reasonCode');

            $hash = array();
            foreach ($check_fields as $key) {
                if (isset($data[$key])) {
                    $hash[] = $data[$key];
                }
            }
            $string = implode(';', $hash);
            $my_signature = hash_hmac('md5', $string, $this->secret_key);

            if ($received_signature !== $my_signature) {
                $this->response_to_gateway($data['orderReference'], 'accept'); // Signature mismatch, but maybe just log it. 
                wp_die('Invalid Signature');
            }

            // Get Order ID
            $parts = explode('_', $data['orderReference']);
            $order_id = $parts[0]; // Assuming format ORDERID_lp_TIME
            $order = learn_press_get_order($order_id);

            if (!$order) {
                wp_die('Order not found');
            }

            if ($data['transactionStatus'] === 'Approved') {
                // Payment Success
                $order->payment_complete($data['orderReference']);
                $order->update_status('completed'); // Payment received via WayForPay
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

            $sign_string = implode(';', $response);
            $response['signature'] = hash_hmac('md5', $sign_string, $this->secret_key);

            echo json_encode($response);
            exit;
        }
    }
}
