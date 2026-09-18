<?php
/**
 * Plugin Name: LearnPress - WayForPay Payment
 * Plugin URI: https://github.com/artgana/learnpress-wayforpay
 * Description: WayForPay payment gateway for LearnPress, with coupon-aware checkout totals and refund support.
 * Author: artgana
 * Version: 4.3.1
 * Text Domain: learnpress-wayforpay
 * Require_LP_Version: 4.0.0
 * Requires at least: 6.3
 * Requires PHP: 7.4
 * License: GNU General Public License v2.0
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
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
        add_action('plugins_loaded', array($this, 'load_textdomain'));
    }

    /**
     * Load textdomain.
     */
    public function load_textdomain()
    {
        load_plugin_textdomain('learnpress-wayforpay', false, dirname(plugin_basename(__FILE__)) . '/languages');
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

		$is_submit = isset($_GET['lp-wayforpay-submit']) && !empty($_GET['lp-wayforpay-submit']);
		$is_callback = isset($_GET['lp-wayforpay-callback']);
		$is_client_log = isset($_GET['lp-wayforpay-client-log']);

		if (!$is_submit && !$is_callback && !$is_client_log) {
			return;
		}

		$gateway = new LP_Gateway_WayForPay();

		// The client-log beacon is debug-only telemetry from the redirect page's
		// own JS (see process_wayforpay_submit()) - it doesn't need the "request
		// reached WordPress at all" logging below, since the whole point of it is
		// answering a question upstream-WAF logging can't: whether the browser's
		// auto-submit actually fired/completed after this point.
		if ($is_client_log) {
			$gateway->handle_client_log();
			exit;
		}

		// Logged immediately, before any parsing/nonce/signature checks, so that
		// with debug mode on we can tell whether a request reached WordPress at
		// all - if this line never appears for an attempted payment, something
		// upstream (WAF/CDN/hosting firewall) is dropping it, not this plugin.
		$gateway->log_incoming_request($is_submit ? 'submit' : 'callback');

		// Handle Submit
		if ($is_submit) {
			$order_id = absint($_GET['lp-wayforpay-submit']);
			$nonce = sanitize_text_field($_GET['nonce'] ?? '');

			if ($order_id <= 0) {
				wp_die(__('Invalid order ID', 'learnpress-wayforpay'), 'Error', array('response' => 400));
			}

			if (empty($nonce)) {
				wp_die(__('Missing security token', 'learnpress-wayforpay'), 'Security Error', array('response' => 403));
			}

			$gateway->process_wayforpay_submit($order_id, $nonce);
			exit;
		}

		// Handle Callback
		if ($is_callback) {
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
