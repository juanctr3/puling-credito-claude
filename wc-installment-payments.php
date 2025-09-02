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

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('admin_notices', 'wc_installment_payments_woocommerce_missing_notice');
    return;
}

function wc_installment_payments_woocommerce_missing_notice() {
    ?>
    <div class="notice notice-error">
        <p><?php _e('WooCommerce Installment Payments requiere que WooCommerce esté activo.', 'wc-installment-payments'); ?></p>
    </div>
    <?php
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
final class WC_Installment_Payments {

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
    }

    /**
     * Prevent cloning
     */
    public function __clone() {
        _doing_it_wrong(__FUNCTION__, __('Nope'), '1.0.0');
    }

    /**
     * Prevent unserializing
     */
    public function __wakeup() {
        _doing_it_wrong(__FUNCTION__, __('Nope'), '1.0.0');
    }

    /**
     * Hook into actions and filters
     */
    private function init_hooks() {
        add_action('plugins_loaded', array($this, 'init'), 10);
        add_action('init', array($this, 'init_endpoints'));
        
        // Activation and deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }

    /**
     * Initialize the plugin
     */
    public function init() {
        // Check if WooCommerce is loaded
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }

        // Load plugin textdomain
        $this->load_plugin_textdomain();

        // Include required files
        $this->includes();

        // Initialize components
        $this->init_components();

        // Hook into WordPress and WooCommerce
        $this->init_wordpress_hooks();
        $this->init_woocommerce_hooks();

        // Trigger init action
        do_action('wc_installment_payments_loaded');
    }

    /**
     * Include required files
     */
    private function includes() {
        $includes = array(
            'includes/class-database-manager.php',
            'includes/class-payment-plans.php',
            'includes/class-interest-calculator.php',
            'includes/class-whatsapp-api.php',
            'includes/class-email-notifications.php',
            'includes/class-credit-manager.php',
            'includes/class-notifications.php'
        );

        foreach ($includes as $file) {
            $file_path = WC_INSTALLMENT_PLUGIN_PATH . $file;
            if (file_exists($file_path)) {
                include_once $file_path;
            }
        }
    }

    /**
     * Initialize components
     */
    private function init_components() {
        try {
            // Initialize database manager first
            if (class_exists('WC_Installment_Database_Manager')) {
                $this->database_manager = new WC_Installment_Database_Manager();
            }
            
            // Initialize payment plans
            if (class_exists('WC_Payment_Plans')) {
                $this->payment_plans = new WC_Payment_Plans();
            }
            
            // Initialize credit manager
            if (class_exists('WC_Credit_Manager')) {
                $this->credit_manager = new WC_Credit_Manager();
            }
            
            // Initialize notifications
            if (class_exists('WC_Installment_Notifications')) {
                $this->notifications = new WC_Installment_Notifications();
            }
        } catch (Exception $e) {
            error_log('WC Installment Payments - Error initializing components: ' . $e->getMessage());
            add_action('admin_notices', array($this, 'initialization_error_notice'));
        }
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_wordpress_hooks() {
        // Frontend scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));
        
        // Admin scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // Admin menu
        add_action('admin_menu', array($this, 'admin_menu'));
        
        // AJAX hooks
        add_action('wp_ajax_calculate_installments', array($this, 'ajax_calculate_installments'));
        add_action('wp_ajax_nopriv_calculate_installments', array($this, 'ajax_calculate_installments'));
    }

    /**
     * Initialize WooCommerce hooks
     */
    private function init_woocommerce_hooks() {
        // Product page hooks
        add_action('woocommerce_single_product_summary', array($this, 'display_payment_plans'), 25);
        
        // My Account hooks
        add_filter('woocommerce_account_menu_items', array($this, 'add_my_account_menu_items'));
        add_action('woocommerce_account_my-credits_endpoint', array($this, 'my_credits_endpoint'));
        
        // Payment gateway
        add_filter('woocommerce_payment_gateways', array($this, 'add_gateway_class'));
    }

    /**
     * Initialize endpoints
     */
    public function init_endpoints() {
        // Add rewrite endpoints for My Account
        add_rewrite_endpoint('my-credits', EP_ROOT | EP_PAGES);
        add_rewrite_endpoint('credit-details', EP_ROOT | EP_PAGES);
    }

    /**
     * Load plugin textdomain
     */
    public function load_plugin_textdomain() {
        load_plugin_textdomain(
            'wc-installment-payments',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages/'
        );
    }

    /**
     * Display payment plans on product page
     */
    public function display_payment_plans() {
        global $product;
        
        if (!$product || !$this->payment_plans) {
            return;
        }

        try {
            $plans = $this->payment_plans->get_available_plans_for_product($product->get_id());
            
            if (empty($plans)) {
                return;
            }

            $template_file = WC_INSTALLMENT_PLUGIN_PATH . 'templates/frontend/product-payment-plans.php';
            if (file_exists($template_file)) {
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
        } catch (Exception $e) {
            error_log('Error displaying payment plans: ' . $e->getMessage());
        }
    }

    /**
     * Add My Account menu items
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
        $template_file = WC_INSTALLMENT_PLUGIN_PATH . 'templates/account/my-credits.php';
        if (file_exists($template_file)) {
            include $template_file;
        }
    }

    /**
     * Add payment gateway
     */
    public function add_gateway_class($gateways) {
        if (class_exists('WC_Installment_Gateway')) {
            $gateways[] = 'WC_Installment_Gateway';
        }
        return $gateways;
    }

    /**
     * Admin menu
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
     * Admin pages
     */
    public function admin_page() {
        echo '<div class="wrap">';
        echo '<h1>' . __('Pagos a Plazos - Dashboard', 'wc-installment-payments') . '</h1>';
        echo '<p>' . __('Panel de administración de pagos a plazos', 'wc-installment-payments') . '</p>';
        echo '</div>';
    }

    public function admin_settings_page() {
        echo '<div class="wrap">';
        echo '<h1>' . __('Configuración - Pagos a Plazos', 'wc-installment-payments') . '</h1>';
        echo '<p>' . __('Configuración del sistema de pagos a plazos', 'wc-installment-payments') . '</p>';
        echo '</div>';
    }

    public function admin_plans_page() {
        echo '<div class="wrap">';
        echo '<h1>' . __('Planes de Pago', 'wc-installment-payments') . '</h1>';
        echo '<p>' . __('Administración de planes de financiamiento', 'wc-installment-payments') . '</p>';
        echo '</div>';
    }

    public function admin_credits_page() {
        echo '<div class="wrap">';
        echo '<h1>' . __('Créditos', 'wc-installment-payments') . '</h1>';
        echo '<p>' . __('Administración de créditos otorgados', 'wc-installment-payments') . '</p>';
        echo '</div>';
    }

    /**
     * Enqueue frontend scripts
     */
    public function enqueue_frontend_scripts() {
        $css_file = WC_INSTALLMENT_PLUGIN_URL . 'assets/css/frontend.css';
        $js_file = WC_INSTALLMENT_PLUGIN_URL . 'assets/js/frontend.js';
        
        if (file_exists(WC_INSTALLMENT_PLUGIN_PATH . 'assets/css/frontend.css')) {
            wp_enqueue_style('wc-installment-frontend', $css_file, array(), WC_INSTALLMENT_VERSION);
        }

        if (file_exists(WC_INSTALLMENT_PLUGIN_PATH . 'assets/js/frontend.js')) {
            wp_enqueue_script('wc-installment-frontend', $js_file, array('jquery'), WC_INSTALLMENT_VERSION, true);
            
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
    }

    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'wc-installment') === false) {
            return;
        }

        $css_file = WC_INSTALLMENT_PLUGIN_URL . 'assets/css/admin.css';
        $js_file = WC_INSTALLMENT_PLUGIN_URL . 'assets/js/admin.js';
        
        if (file_exists(WC_INSTALLMENT_PLUGIN_PATH . 'assets/css/admin.css')) {
            wp_enqueue_style('wc-installment-admin', $css_file, array(), WC_INSTALLMENT_VERSION);
        }

        if (file_exists(WC_INSTALLMENT_PLUGIN_PATH . 'assets/js/admin.js')) {
            wp_enqueue_script('wc-installment-admin', $js_file, array('jquery'), WC_INSTALLMENT_VERSION, true);
            
            wp_localize_script('wc-installment-admin', 'wc_installment_admin', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wc_installment_admin_nonce')
            ));
        }
    }

    /**
     * AJAX calculate installments
     */
    public function ajax_calculate_installments() {
        check_ajax_referer('wc_installment_nonce', 'nonce');

        $amount = floatval($_POST['amount'] ?? 0);
        $plan_id = intval($_POST['plan_id'] ?? 0);

        if (!$amount || !$plan_id) {
            wp_send_json_error(__('Datos inválidos', 'wc-installment-payments'));
        }

        try {
            if (class_exists('WC_Interest_Calculator')) {
                $calculator = new WC_Interest_Calculator();
                $result = $calculator->calculate_installments($amount, $plan_id);

                if (is_wp_error($result)) {
                    wp_send_json_error($result->get_error_message());
                } else {
                    wp_send_json_success($result);
                }
            } else {
                wp_send_json_error(__('Calculadora no disponible', 'wc-installment-payments'));
            }
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * Plugin activation
     */
    public function activate() {
        try {
            // Create database tables
            if (class_exists('WC_Installment_Database_Manager')) {
                $database_manager = new WC_Installment_Database_Manager();
                $database_manager->create_tables();
            }

            // Create default payment plans
            $this->create_default_payment_plans();

            // Set default options
            $this->set_default_options();

            // Flush rewrite rules
            flush_rewrite_rules();

        } catch (Exception $e) {
            error_log('WC Installment Payments activation error: ' . $e->getMessage());
            // Don't stop activation, just log the error
        }
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
        if (!class_exists('WC_Payment_Plans')) {
            return;
        }

        try {
            $payment_plans = new WC_Payment_Plans();
            
            // Check if plans already exist
            $existing_plans = $payment_plans->get_all_plans();
            if (!empty($existing_plans)) {
                return; // Plans already exist
            }
            
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
                $payment_plans->create_plan($plan);
            }
        } catch (Exception $e) {
            error_log('Error creating default payment plans: ' . $e->getMessage());
        }
    }

    /**
     * Set default options
     */
    private function set_default_options() {
        $default_options = array(
            'wc_installment_whatsapp_api_endpoint' => 'https://whatsapp.smsenlinea.com/api/send/whatsapp',
            'wc_installment_email_notifications' => 'yes',
            'wc_installment_whatsapp_notifications' => 'yes',
            'wc_installment_payment_reminder_days' => '7,3,1'
        );

        foreach ($default_options as $option => $value) {
            if (!get_option($option)) {
                add_option($option, $value);
            }
        }
    }

    /**
     * Notice functions
     */
    public function woocommerce_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p><?php _e('WooCommerce Installment Payments requiere que WooCommerce esté activo.', 'wc-installment-payments'); ?></p>
        </div>
        <?php
    }

    public function initialization_error_notice() {
        ?>
        <div class="notice notice-warning">
            <p><?php _e('Hubo un error al inicializar algunos componentes de WooCommerce Installment Payments. Revisa el log de errores.', 'wc-installment-payments'); ?></p>
        </div>
        <?php
    }
}

/**
 * Main instance
 */
function WC_Installment_Payments() {
    return WC_Installment_Payments::instance();
}

// Initialize the plugin
WC_Installment_Payments();
