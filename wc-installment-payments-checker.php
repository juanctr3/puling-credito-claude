<?php
/**
 * WooCommerce Installment Payments - System Checker
 * 
 * Diagnostic tool to identify common issues
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Installment_System_Checker {
    
    /**
     * Run all diagnostic checks
     */
    public static function run_diagnostics() {
        $results = array(
            'environment' => self::check_environment(),
            'database' => self::check_database(),
            'files' => self::check_files(),
            'classes' => self::check_classes(),
            'dependencies' => self::check_dependencies(),
            'configuration' => self::check_configuration()
        );
        
        return $results;
    }
    
    /**
     * Check environment requirements
     */
    private static function check_environment() {
        $checks = array();
        
        // PHP Version
        $checks['php_version'] = array(
            'required' => '7.4',
            'current' => PHP_VERSION,
            'status' => version_compare(PHP_VERSION, '7.4', '>='),
            'message' => version_compare(PHP_VERSION, '7.4', '>=') ? 
                'PHP version is compatible' : 'PHP 7.4 or higher required'
        );
        
        // WordPress Version
        $checks['wp_version'] = array(
            'required' => '5.8',
            'current' => get_bloginfo('version'),
            'status' => version_compare(get_bloginfo('version'), '5.8', '>='),
            'message' => version_compare(get_bloginfo('version'), '5.8', '>=') ? 
                'WordPress version is compatible' : 'WordPress 5.8 or higher required'
        );
        
        // WooCommerce
        $wc_active = class_exists('WooCommerce');
        $wc_version = $wc_active ? WC()->version : 'Not installed';
        $checks['woocommerce'] = array(
            'required' => '6.0',
            'current' => $wc_version,
            'status' => $wc_active && version_compare($wc_version, '6.0', '>='),
            'message' => $wc_active ? 
                ($wc_version >= '6.0' ? 'WooCommerce version is compatible' : 'WooCommerce 6.0 or higher required') :
                'WooCommerce is not active'
        );
        
        // Memory limit
        $memory_limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
        $required_memory = 128 * 1024 * 1024; // 128MB
        $checks['memory_limit'] = array(
            'required' => '128M',
            'current' => ini_get('memory_limit'),
            'status' => $memory_limit >= $required_memory,
            'message' => $memory_limit >= $required_memory ? 
                'Memory limit is sufficient' : 'At least 128MB memory required'
        );
        
        return $checks;
    }
    
    /**
     * Check database requirements
     */
    private static function check_database() {
        global $wpdb;
        $checks = array();
        
        // Database connection
        $checks['connection'] = array(
            'status' => $wpdb->last_error === '',
            'message' => $wpdb->last_error === '' ? 'Database connection OK' : 'Database connection error: ' . $wpdb->last_error
        );
        
        // Required tables
        $required_tables = array(
            'wc_payment_plans',
            'wc_credits', 
            'wc_installments',
            'wc_installment_notifications',
            'wc_installment_settings',
            'wc_customer_credit_history'
        );
        
        foreach ($required_tables as $table) {
            $full_table_name = $wpdb->prefix . $table;
            $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $full_table_name)) === $full_table_name;
            
            $checks['table_' . $table] = array(
                'status' => $table_exists,
                'message' => $table_exists ? "Table {$table} exists" : "Table {$table} is missing"
            );
        }
        
        return $checks;
    }
    
    /**
     * Check required files
     */
    private static function check_files() {
        $checks = array();
        
        $required_files = array(
            'includes/class-database-manager.php',
            'includes/class-payment-plans.php',
            'includes/class-interest-calculator.php',
            'includes/class-credit-manager.php',
            'includes/class-whatsapp-api.php',
            'includes/class-notifications.php',
            'includes/class-email-notifications.php',
            'assets/css/frontend.css',
            'assets/js/frontend.js',
            'templates/frontend/product-payment-plans.php',
            'templates/account/my-credits.php'
        );
        
        foreach ($required_files as $file) {
            $file_path = WC_INSTALLMENT_PLUGIN_PATH . $file;
            $file_exists = file_exists($file_path);
            
            $checks['file_' . str_replace(array('/', '-', '.'), '_', $file)] = array(
                'status' => $file_exists,
                'message' => $file_exists ? "File {$file} exists" : "File {$file} is missing",
                'path' => $file_path
            );
        }
        
        return $checks;
    }
    
    /**
     * Check required classes
     */
    private static function check_classes() {
        $checks = array();
        
        $required_classes = array(
            'WC_Installment_Database_Manager',
            'WC_Payment_Plans',
            'WC_Interest_Calculator',
            'WC_Credit_Manager',
            'WC_WhatsApp_API',
            'WC_Installment_Notifications',
            'WC_Email_Notifications'
        );
        
        foreach ($required_classes as $class) {
            $class_exists = class_exists($class);
            
            $checks['class_' . strtolower($class)] = array(
                'status' => $class_exists,
                'message' => $class_exists ? "Class {$class} is available" : "Class {$class} is missing"
            );
        }
        
        return $checks;
    }
    
    /**
     * Check dependencies
     */
    private static function check_dependencies() {
        $checks = array();
        
        // cURL for API calls
        $checks['curl'] = array(
            'status' => function_exists('curl_init'),
            'message' => function_exists('curl_init') ? 'cURL is available' : 'cURL extension is required'
        );
        
        // JSON support
        $checks['json'] = array(
            'status' => function_exists('json_encode'),
            'message' => function_exists('json_encode') ? 'JSON support is available' : 'JSON extension is required'
        );
        
        // GD or Imagick for image processing
        $gd_available = extension_loaded('gd');
        $imagick_available = extension_loaded('imagick');
        $checks['image_processing'] = array(
            'status' => $gd_available || $imagick_available,
            'message' => ($gd_available || $imagick_available) ? 
                'Image processing is available' : 'GD or Imagick extension recommended'
        );
        
        return $checks;
    }
    
    /**
     * Check plugin configuration
     */
    private static function check_configuration() {
        $checks = array();
        
        // WhatsApp API configuration
        $whatsapp_secret = get_option('wc_installment_whatsapp_api_secret');
        $whatsapp_account = get_option('wc_installment_whatsapp_account');
        
        $checks['whatsapp_config'] = array(
            'status' => !empty($whatsapp_secret) && !empty($whatsapp_account),
            'message' => (!empty($whatsapp_secret) && !empty($whatsapp_account)) ? 
                'WhatsApp API is configured' : 'WhatsApp API requires configuration'
        );
        
        // Email configuration
        $checks['email_config'] = array(
            'status' => !empty(get_option('admin_email')),
            'message' => !empty(get_option('admin_email')) ? 
                'Email configuration is OK' : 'Admin email is not configured'
        );
        
        // Currency configuration
        $checks['currency_config'] = array(
            'status' => function_exists('get_woocommerce_currency'),
            'message' => function_exists('get_woocommerce_currency') ? 
                'Currency configuration is OK' : 'WooCommerce currency not configured'
        );
        
        return $checks;
    }
    
    /**
     * Display diagnostic results
     */
    public static function display_diagnostics() {
        if (!current_user_can('manage_options')) {
            wp_die(__('No tienes permisos para ver esta página.'));
        }
        
        $results = self::run_diagnostics();
        
        echo '<div class="wrap">';
        echo '<h1>WooCommerce Installment Payments - Diagnóstico del Sistema</h1>';
        
        foreach ($results as $category => $checks) {
            echo '<h2>' . ucfirst(str_replace('_', ' ', $category)) . '</h2>';
            echo '<table class="widefat">';
            echo '<thead><tr><th>Check</th><th>Status</th><th>Message</th><th>Details</th></tr></thead>';
            echo '<tbody>';
            
            foreach ($checks as $check_name => $check_data) {
                $status_icon = $check_data['status'] ? '✅' : '❌';
                $status_class = $check_data['status'] ? 'success' : 'error';
                
                echo '<tr>';
                echo '<td>' . str_replace('_', ' ', $check_name) . '</td>';
                echo '<td><span class="' . $status_class . '">' . $status_icon . '</span></td>';
                echo '<td>' . $check_data['message'] . '</td>';
                echo '<td>';
                
                if (isset($check_data['required'])) {
                    echo 'Required: ' . $check_data['required'] . '<br>';
                }
                if (isset($check_data['current'])) {
                    echo 'Current: ' . $check_data['current'] . '<br>';
                }
                if (isset($check_data['path'])) {
                    echo 'Path: ' . $check_data['path'];
                }
                
                echo '</td>';
                echo '</tr>';
            }
            
            echo '</tbody>';
            echo '</table>';
            echo '<br>';
        }
        
        // Quick fix suggestions
        echo '<h2>Sugerencias de Solución</h2>';
        echo '<div class="notice notice-info">';
        echo '<p><strong>Errores comunes y soluciones:</strong></p>';
        echo '<ul>';
        echo '<li><strong>Clases faltantes:</strong> Verificar que todos los archivos estén subidos correctamente</li>';
        echo '<li><strong>Tablas faltantes:</strong> Desactivar y reactivar el plugin</li>';
        echo '<li><strong>Errores de permisos:</strong> Verificar permisos de archivos y carpetas</li>';
        echo '<li><strong>Memoria insuficiente:</strong> Aumentar memory_limit en php.ini</li>';
        echo '<li><strong>WooCommerce no activo:</strong> Instalar y activar WooCommerce</li>';
        echo '</ul>';
        echo '</div>';
        
        echo '</div>';
        
        // Add some basic styling
        echo '<style>
            .success { color: green; font-weight: bold; }
            .error { color: red; font-weight: bold; }
            .widefat th { background: #f1f1f1; }
            .widefat td { padding: 8px; }
        </style>';
    }
    
    /**
     * Add diagnostics to admin menu
     */
    public static function add_diagnostics_menu() {
        if (is_admin() && current_user_can('manage_options')) {
            add_submenu_page(
                'wc-installment-payments',
                'Diagnóstico del Sistema',
                'Diagnóstico',
                'manage_options',
                'wc-installment-diagnostics',
                array(__CLASS__, 'display_diagnostics')
            );
        }
    }
}

// Initialize diagnostics if in debug mode
if (defined('WP_DEBUG') && WP_DEBUG) {
    add_action('admin_menu', array('WC_Installment_System_Checker', 'add_diagnostics_menu'), 999);
}
