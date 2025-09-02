<?php
/**
 * Plugin Name: WooCommerce Installment Payments
 * Plugin URI: https://example.com/wc-installment-payments
 * Description: Sistema completo de pagos a plazos para WooCommerce con notificaciones automáticas por WhatsApp y email.
 * Version: 1.0.0
 * Author: Tu Nombre
 * Author URI: https://example.com
 * Text Domain: wc-installment-payments
 * Domain Path: /languages
 * Requires at least: 5.8
 * Tested up to: 6.3
 * WC requires at least: 6.0
 * WC tested up to: 8.0
 * PHP Version: 7.4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Network: false
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WC_INSTALLMENT_VERSION', '1.0.0');
define('WC_INSTALLMENT_PLUGIN_FILE', __FILE__);
define('WC_INSTALLMENT_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('WC_INSTALLMENT_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('WC_INSTALLMENT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WC_INSTALLMENT_PLUGIN_DIR', dirname(__FILE__));

/**
 * Main WC Installment Payments Class
 */
class WC_Installment_Payments {

    /**
     * Plugin instance
     */
    protected static $_instance = null;

    /**
     * Payment Plans Manager
     */
    public $payment_plans = null;

    /**
     * Credit Manager
     */
    public $credit_manager = null;

    /**
     * Notifications Manager
     */
    public $notifications = null;

    /**
     * Database Manager
     */
    public $database_manager = null;

    /**
     * Main instance
     */
    public static function instance() {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Constructor
     */
    public function __construct() {
        $this->init_hooks();
        $this->includes();
        $this->init();
    }

    /**
     * Hook into actions and filters
     */
    private function init_hooks() {
        add_action('init', array($this, 'init'), 0);
        add_action('plugins_loaded', array($this, 'plugins_loaded'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // WooCommerce hooks
        add_action('woocommerce_loaded', array($this, 'woocommerce_loaded'));
        add_filter('woocommerce_payment_gateways', array($this, 'add_gateway_class'));
        
        // Product page hooks
        add_action('woocommerce_single_product_summary', array($this, 'display_payment_plans'), 25);
        
        // My Account hooks
        add_filter('woocommerce_account_menu_items', array($this, 'add_my_account_menu_items'));
        add_action('woocommerce_account_my-credits_endpoint', array($this, 'my_credits_endpoint'));
        
        // Admin hooks
        add_action('admin_menu', array($this, 'admin_menu'));
        
        // AJAX hooks
        add_action('wp_ajax_calculate_installments', array($this, 'ajax_calculate_installments'));
        add_action('wp_ajax_nopriv_calculate_installments', array($this, 'ajax_calculate_installments'));
        
        // Activation and deactivation
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }

    /**
     * Include required core files
     */
    public function includes() {
        // Core classes
        include_once WC_INSTALLMENT_PLUGIN_PATH . 'includes/class-database-manager.php';
        include_once WC_INSTALLMENT_PLUGIN_PATH . 'includes/class-payment-plans.php';
        include_once WC_INSTALLMENT_PLUGIN_PATH . 'includes/class-credit-manager.php';
        include_once WC_INSTALLMENT_PLUGIN_PATH . 'includes/class-notifications.php';
        include_once WC_INSTALLMENT_PLUGIN_PATH . 'includes/class-whatsapp-api.php';
        include_once WC_INSTALLMENT_PLUGIN_PATH . 'includes/class-email-notifications.php';
        include_once WC_INSTALLMENT_PLUGIN_PATH . 'includes/class-interest-calculator.php';
        include_once WC_INSTALLMENT_PLUGIN_PATH . 'includes/class-installment-gateway.php';
        include_once WC_INSTALLMENT_PLUGIN_PATH . 'includes/class-admin-settings.php';
        include_once WC_INSTALLMENT_PLUGIN_PATH . 'includes/class-customer-account.php';
        
        // Helpers
        include_once WC_INSTALLMENT_PLUGIN_PATH . 'lib/helpers/class-currency-helper.php';
        include_once WC_INSTALLMENT_PLUGIN_PATH . 'lib/helpers/class-date-helper.php';
        include_once WC_INSTALLMENT_PLUGIN_PATH . 'lib/helpers/class-validation-helper.php';
        
        // Admin only
        if (is_admin()) {
            include_once WC_INSTALLMENT_PLUGIN_PATH . 'includes/admin/class-admin-credits.php';
            include_once WC_INSTALLMENT_PLUGIN_PATH . 'includes/admin/class-admin-payment-plans.php';
        }
    }

    /**
     * Init when WordPress initialises
     */
    public function init() {
        // Before init action
        do_action('before_wc_installment_init');

        // Set up localisation
        $this->load_plugin_textdomain();

        // Init action
        do_action('wc_installment_init');
    }

    /**
     * When WP has loaded all plugins, trigger the `wc_installment_loaded` hook
     */
    public function plugins_loaded() {
        do_action('wc_installment_loaded');
    }

    /**
     * Load Localisation files
     */
    public function load_plugin_textdomain() {
        load_plugin_textdomain('wc-installment-payments', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    }

    /**
     * Initialize when WooCommerce is loaded
     */
    public function woocommerce_loaded() {
        $this->database_manager = new WC_Installment_Database_Manager();
        $this->payment_plans = new WC_Payment_Plans();
        $this->credit_manager = new WC_Credit_Manager();
        $this->notifications = new WC_Installment_Notifications();
        
        // Add rewrite endpoints for My Account
        add_rewrite_endpoint('my-credits', EP_ROOT | EP_PAGES);
        add_rewrite_endpoint('credit-details', EP_ROOT | EP_PAGES);
    }

    /**
     * Add payment gateway to WooCommerce
     */
    public function add_gateway_class($gateways) {
        $gateways[] = 'WC_Installment_Gateway';
        return $gateways;
    }

    /**
     * Display payment plans on product page
     */
    public function display_payment_plans() {
        global $product;
        
        if (!$product || !$this->payment_plans) {
            return;
        }

        $plans = $this->payment_plans->get_available_plans_for_product($product->get_id());
        
        if (empty($plans)) {
            return;
        }

        wc_get_template(
            'frontend/product-payment-plans.php',
            array(
                'plans' => $plans,
                'product' => $product
            ),
            'wc-installment-payments/',
            WC_INSTALLMENT_PLUGIN_PATH . 'templates/'
        );
    }

    /**
     * Add menu items to My Account
     */
    public function add_my_account_menu_items($items) {
        $logout = $items['customer-logout'];
        unset($items['customer-logout']);
        
        $items['my-credits'] = __('Mis Créditos', 'wc-installment-payments');
        $items['customer-logout'] = $logout;
        
        return $items;
    }

    /**
     * My Credits endpoint content
     */
    public function my_credits_endpoint() {
        wc_get_template(
            'account/my-credits.php',
            array(),
            'wc-installment-payments/',
            WC_INSTALLMENT_PLUGIN_PATH . 'templates/'
        );
    }

    /**
     * Add admin menu
     */
    public function admin_menu() {
        add_menu_page(
            __('Pagos a Plazos', 'wc-installment-payments'),
            __('Pagos a Plazos', 'wc-installment-payments'),
            'manage_woocommerce',
            'wc-installment-payments',
            array($this, 'admin_page'),
            'dashicons-money-alt',
            56
        );

        add_submenu_page(
            'wc-installment-payments',
            __('Configuración', 'wc-installment-payments'),
            __('Configuración', 'wc-installment-payments'),
            'manage_woocommerce',
            'wc-installment-settings',
            array($this, 'admin_settings_page')
        );

        add_submenu_page(
            'wc-installment-payments',
            __('Planes de Pago', 'wc-installment-payments'),
            __('Planes de Pago', 'wc-installment-payments'),
            'manage_woocommerce',
            'wc-installment-plans',
            array($this, 'admin_plans_page')
        );

        add_submenu_page(
            'wc-installment-payments',
            __('Créditos', 'wc-installment-payments'),
            __('Créditos', 'wc-installment-payments'),
            'manage_woocommerce',
            'wc-installment-credits',
            array($this, 'admin_credits_page')
        );
    }

    /**
     * Main admin page
     */
    public function admin_page() {
        include WC_INSTALLMENT_PLUGIN_PATH . 'templates/admin/dashboard.php';
    }

    /**
     * Admin settings page
     */
    public function admin_settings_page() {
        include WC_INSTALLMENT_PLUGIN_PATH . 'templates/admin/settings-page.php';
    }

    /**
     * Admin plans page
     */
    public function admin_plans_page() {
        include WC_INSTALLMENT_PLUGIN_PATH . 'templates/admin/payment-plans-config.php';
    }

    /**
     * Admin credits page
     */
    public function admin_credits_page() {
        include WC_INSTALLMENT_PLUGIN_PATH . 'templates/admin/credits-list.php';
    }

    /**
     * AJAX calculate installments
     */
    public function ajax_calculate_installments() {
        check_ajax_referer('wc_installment_nonce', 'nonce');

        $product_id = intval($_POST['product_id']);
        $plan_id = intval($_POST['plan_id']);
        $amount = floatval($_POST['amount']);

        $calculator = new WC_Interest_Calculator();
        $installments = $calculator->calculate_installments($amount, $plan_id);

        wp_send_json_success($installments);
    }

    /**
     * Enqueue frontend scripts and styles
     */
    public function enqueue_frontend_scripts() {
        wp_enqueue_style('wc-installment-frontend', WC_INSTALLMENT_PLUGIN_URL . 'assets/css/frontend.css', array(), WC_INSTALLMENT_VERSION);
        wp_enqueue_style('wc-installment-responsive', WC_INSTALLMENT_PLUGIN_URL . 'assets/css/responsive.css', array(), WC_INSTALLMENT_VERSION);

        wp_enqueue_script('wc-installment-frontend', WC_INSTALLMENT_PLUGIN_URL . 'assets/js/frontend.js', array('jquery'), WC_INSTALLMENT_VERSION, true);
        wp_enqueue_script('wc-installment-calculator', WC_INSTALLMENT_PLUGIN_URL . 'assets/js/payment-calculator.js', array('jquery'), WC_INSTALLMENT_VERSION, true);

        wp_localize_script('wc-installment-frontend', 'wc_installment_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wc_installment_nonce'),
            'currency_symbol' => get_woocommerce_currency_symbol(),
            'currency_position' => get_option('woocommerce_currency_pos'),
            'thousand_separator' => wc_get_price_thousand_separator(),
            'decimal_separator' => wc_get_price_decimal_separator(),
            'decimals' => wc_get_price_decimals()
        ));
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'wc-installment') === false) {
            return;
        }

        wp_enqueue_style('wc-installment-admin', WC_INSTALLMENT_PLUGIN_URL . 'assets/css/admin.css', array(), WC_INSTALLMENT_VERSION);
        wp_enqueue_script('wc-installment-admin', WC_INSTALLMENT_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), WC_INSTALLMENT_VERSION, true);

        wp_localize_script('wc-installment-admin', 'wc_installment_admin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wc_installment_admin_nonce')
        ));
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Check if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(__('Este plugin requiere WooCommerce para funcionar.', 'wc-installment-payments'));
        }

        // Create database tables
        $this->database_manager = new WC_Installment_Database_Manager();
        $this->database_manager->create_tables();

        // Create default payment plans
        $this->create_default_payment_plans();

        // Flush rewrite rules
        flush_rewrite_rules();

        // Set default options
        $this->set_default_options();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        flush_rewrite_rules();
    }

    /**
     * Create default payment plans
     */
    private function create_default_payment_plans() {
        $default_plans = array(
            array(
                'name' => '3 Cuotas sin interés',
                'installments_count' => 3,
                'interest_rate' => 0.00,
                'min_amount' => 50000,
                'max_amount' => 500000,
                'active' => 1
            ),
            array(
                'name' => '6 Cuotas - 5% anual',
                'installments_count' => 6,
                'interest_rate' => 5.00,
                'min_amount' => 100000,
                'max_amount' => 1000000,
                'active' => 1
            ),
            array(
                'name' => '12 Cuotas - 8% anual',
                'installments_count' => 12,
                'interest_rate' => 8.00,
                'min_amount' => 200000,
                'max_amount' => 2000000,
                'active' => 1
            )
        );

        foreach ($default_plans as $plan) {
            $this->payment_plans->create_plan($plan);
        }
    }

    /**
     * Set default options
     */
    private function set_default_options() {
        $default_options = array(
            'wc_installment_whatsapp_api_endpoint' => 'https://whatsapp.smsenlinea.com/api/send/whatsapp',
            'wc_installment_whatsapp_api_secret' => '',
            'wc_installment_whatsapp_account' => '',
            'wc_installment_email_notifications' => 'yes',
            'wc_installment_whatsapp_notifications' => 'yes',
            'wc_installment_payment_reminder_days' => '7,3,1',
            'wc_installment_overdue_reminder_days' => '1,7,15',
            'wc_installment_currency' => get_woocommerce_currency()
        );

        foreach ($default_options as $option => $value) {
            add_option($option, $value);
        }
    }

    /**
     * Get the plugin url
     */
    public function plugin_url() {
        return untrailingslashit(plugins_url('/', __FILE__));
    }

    /**
     * Get the plugin path
     */
    public function plugin_path() {
        return untrailingslashit(plugin_dir_path(__FILE__));
    }

    /**
     * Get Ajax URL
     */
    public function ajax_url() {
        return admin_url('admin-ajax.php', 'relative');
    }
}

/**
 * Main instance of WC_Installment_Payments
 */
function WC_Installment_Payments() {
    return WC_Installment_Payments::instance();
}

// Initialize the plugin
WC_Installment_Payments();
