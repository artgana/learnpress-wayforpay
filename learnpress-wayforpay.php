<?php
/**
 * Plugin Name: LearnPress - WayForPay Payment
 * Plugin URI: https://github.com/artgana/learnpress-wayforpay
 * Description: WayForPay payment gateway for LearnPress.
 * Author: artgana
 * Version: 4.0.0
 * Text Domain: learnpress-wayforpay
 * Require_LP_Version: 4.0.0
 * Requires at least: 6.3
 * Requires PHP: 7.4
 *
 * @package learnpress-wayforpay
 */

defined('ABSPATH') || exit;

const LP_ADDON_WAYFORPAY_FILE = __FILE__;

/**
 * Class LP_Addon_WayForPay_Preload
 */
class LP_Addon_WayForPay_Preload
{

    /**
     * @var array
     */
    public static $addon_info = array();

    /**
     * @var LP_Addon_WayForPay_Preload
     */
    protected static $instance;

    /**
     * Instance pattern.
     *
     * @return LP_Addon_WayForPay_Preload
     */
    public static function instance()
    {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    protected function __construct()
    {
        $can_load = true;

        define('LP_ADDON_WAYFORPAY_BASENAME', plugin_basename(LP_ADDON_WAYFORPAY_FILE));
        define('LP_ADDON_WAYFORPAY_PATH', plugin_dir_path(LP_ADDON_WAYFORPAY_FILE));
        define('LP_ADDON_WAYFORPAY_URL', plugin_dir_url(LP_ADDON_WAYFORPAY_FILE));

        include_once ABSPATH . 'wp-admin/includes/plugin.php';
        self::$addon_info = get_file_data(
            LP_ADDON_WAYFORPAY_FILE,
            array(
                'Name' => 'Plugin Name',
                'Require_LP_Version' => 'Require_LP_Version',
                'Version' => 'Version',
            )
        );

        define('LP_ADDON_WAYFORPAY_VER', self::$addon_info['Version']);
        define('LP_ADDON_WAYFORPAY_REQUIRE_VER', self::$addon_info['Require_LP_Version']);

        // Check LP activated.
        if (!is_plugin_active('learnpress/learnpress.php')) {
            $can_load = false;
        } elseif (version_compare(LP_ADDON_WAYFORPAY_REQUIRE_VER, get_option('learnpress_version', '3.0.0'), '>')) {
            $can_load = false;
        }

        if (!$can_load) {
            add_action('admin_notices', array($this, 'show_note_errors_require_lp'));
            deactivate_plugins(LP_ADDON_WAYFORPAY_BASENAME);
            if (isset($_GET['activate'])) {
                unset($_GET['activate']);
            }
            return;
        }

        // Load Addon
        add_action('learn-press/ready', array($this, 'load'));
    }

    /**
     * Load logic.
     */
    public function load()
    {
        // Load actual gateway logic
        if (!class_exists('LP_Gateway_Abstract')) {
            return;
        }
        require_once LP_ADDON_WAYFORPAY_PATH . 'inc/class-lp-gateway-wayforpay.php';

        // Hook to methods
        add_filter('learn-press/payment-methods', array($this, 'add_gateway'));
        add_action('template_redirect', array($this, 'handle_request'));
    }

    /**
     * Add Gateway to LearnPress.
     *
     * @param array $methods
     * @return array
     */
    public function add_gateway($methods)
    {
        $methods['wayforpay'] = 'LP_Gateway_WayForPay';
        return $methods;
    }

    /**
     * Handle Requests (Submit / Callback).
     */
    public function handle_request()
    {
        if (!class_exists('LP_Gateway_WayForPay')) {
            return;
        }

        // Handle Submit
        if (isset($_GET['lp-wayforpay-submit']) && !empty($_GET['lp-wayforpay-submit'])) {
            $order_id = absint($_GET['lp-wayforpay-submit']);
            $gateway = new LP_Gateway_WayForPay();
            $gateway->process_wayforpay_submit($order_id);
            exit;
        }

        // Handle Callback
        if (isset($_GET['lp-wayforpay-callback'])) {
            $gateway = new LP_Gateway_WayForPay();
            $gateway->handle_wayforpay_callback();
            exit;
        }
    }

    /**
     * Show error notice.
     */
    public function show_note_errors_require_lp()
    { ?>
        <div class="notice notice-error">
            <p><?php echo sprintf('%s requires LearnPress version %s or later.', '<strong>' . self::$addon_info['Name'] . '</strong>', '<strong>' . self::$addon_info['Require_LP_Version'] . '</strong>'); ?></p>
        </div>
        <?php
    }
}

LP_Addon_WayForPay_Preload::instance();
