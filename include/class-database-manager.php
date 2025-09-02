<?php
/**
 * Database Manager
 * 
 * Handles all database operations for the installment payments plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Installment_Database_Manager {

    /**
     * Database version
     */
    const DB_VERSION = '1.0.0';

    /**
     * Constructor
     */
    public function __construct() {
        $this->init();
    }

    /**
     * Initialize database manager
     */
    public function init() {
        add_action('init', array($this, 'check_version'));
    }

    /**
     * Check if database needs update
     */
    public function check_version() {
        if (get_option('wc_installment_db_version') !== self::DB_VERSION) {
            $this->create_tables();
            update_option('wc_installment_db_version', self::DB_VERSION);
        }
    }

    /**
     * Create all plugin tables
     */
    public function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // Payment Plans Table
        $sql_plans = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wc_payment_plans (
            id int(11) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            installments_count int(11) NOT NULL,
            interest_rate decimal(5,2) NOT NULL DEFAULT 0.00,
            min_amount decimal(15,2) NOT NULL DEFAULT 0.00,
            max_amount decimal(15,2) NOT NULL DEFAULT 999999999.99,
            active tinyint(1) NOT NULL DEFAULT 1,
            priority int(11) NOT NULL DEFAULT 0,
            description text,
            conditions text,
            applicable_categories text,
            applicable_products text,
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY active (active),
            KEY installments_count (installments_count),
            KEY priority (priority)
        ) $charset_collate;";

        // Credits Table
        $sql_credits = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wc_credits (
            id int(11) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            order_id bigint(20) unsigned NOT NULL,
            plan_id int(11) NOT NULL,
            total_amount decimal(15,2) NOT NULL,
            paid_amount decimal(15,2) NOT NULL DEFAULT 0.00,
            pending_amount decimal(15,2) NOT NULL,
            status enum('pending','active','completed','cancelled','overdue') NOT NULL DEFAULT 'pending',
            approval_date datetime NULL,
            completion_date datetime NULL,
            next_payment_date date NULL,
            late_fees decimal(15,2) NOT NULL DEFAULT 0.00,
            notes text,
            metadata longtext,
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY order_id (order_id),
            KEY plan_id (plan_id),
            KEY status (status),
            KEY next_payment_date (next_payment_date),
            FOREIGN KEY (plan_id) REFERENCES {$wpdb->prefix}wc_payment_plans(id) ON DELETE CASCADE
        ) $charset_collate;";

        // Installments Table
        $sql_installments = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wc_installments (
            id int(11) NOT NULL AUTO_INCREMENT,
            credit_id int(11) NOT NULL,
            installment_number int(11) NOT NULL,
            amount decimal(15,2) NOT NULL,
            interest_amount decimal(15,2) NOT NULL DEFAULT 0.00,
            principal_amount decimal(15,2) NOT NULL,
            due_date date NOT NULL,
            paid_date datetime NULL,
            payment_amount decimal(15,2) DEFAULT NULL,
            status enum('pending','paid','overdue','cancelled') NOT NULL DEFAULT 'pending',
            payment_method varchar(50) DEFAULT NULL,
            transaction_id varchar(255) DEFAULT NULL,
            late_fee decimal(15,2) NOT NULL DEFAULT 0.00,
            notes text,
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY credit_id (credit_id),
            KEY installment_number (installment_number),
            KEY due_date (due_date),
            KEY status (status),
            UNIQUE KEY unique_credit_installment (credit_id, installment_number),
            FOREIGN KEY (credit_id) REFERENCES {$wpdb->prefix}wc_credits(id) ON DELETE CASCADE
        ) $charset_collate;";

        // Notifications Log Table
        $sql_notifications = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wc_installment_notifications (
            id int(11) NOT NULL AUTO_INCREMENT,
            credit_id int(11) NOT NULL,
            installment_id int(11) NULL,
            type enum('reminder','overdue','payment_confirmed','credit_approved','credit_completed') NOT NULL,
            channel enum('email','whatsapp','sms') NOT NULL,
            recipient varchar(255) NOT NULL,
            subject varchar(500) DEFAULT NULL,
            message text NOT NULL,
            template_used varchar(100) DEFAULT NULL,
            sent_at timestamp DEFAULT CURRENT_TIMESTAMP,
            status enum('pending','sent','failed','delivered','read') NOT NULL DEFAULT 'pending',
            response_data longtext,
            error_message text,
            retry_count int(11) NOT NULL DEFAULT 0,
            scheduled_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY credit_id (credit_id),
            KEY installment_id (installment_id),
            KEY type (type),
            KEY channel (channel),
            KEY status (status),
            KEY sent_at (sent_at),
            KEY scheduled_at (scheduled_at),
            FOREIGN KEY (credit_id) REFERENCES {$wpdb->prefix}wc_credits(id) ON DELETE CASCADE,
            FOREIGN KEY (installment_id) REFERENCES {$wpdb->prefix}wc_installments(id) ON DELETE SET NULL
        ) $charset_collate;";

        // Settings Table
        $sql_settings = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wc_installment_settings (
            id int(11) NOT NULL AUTO_INCREMENT,
            setting_key varchar(255) NOT NULL,
            setting_value longtext,
            autoload enum('yes','no') NOT NULL DEFAULT 'yes',
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY setting_key (setting_key)
        ) $charset_collate;";

        // Customer Credit History Table
        $sql_credit_history = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wc_customer_credit_history (
            id int(11) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            credit_id int(11) NOT NULL,
            action enum('created','approved','payment_made','overdue','completed','cancelled') NOT NULL,
            amount decimal(15,2) DEFAULT NULL,
            previous_status varchar(50) DEFAULT NULL,
            new_status varchar(50) DEFAULT NULL,
            notes text,
            admin_user_id bigint(20) unsigned NULL,
            ip_address varchar(45) DEFAULT NULL,
            user_agent text,
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY credit_id (credit_id),
            KEY action (action),
            KEY created_at (created_at),
            FOREIGN KEY (credit_id) REFERENCES {$wpdb->prefix}wc_credits(id) ON DELETE CASCADE
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        dbDelta($sql_plans);
        dbDelta($sql_credits);
        dbDelta($sql_installments);
        dbDelta($sql_notifications);
        dbDelta($sql_settings);
        dbDelta($sql_credit_history);

        // Create indexes for better performance
        $this->create_additional_indexes();
        
        // Insert default data
        $this->insert_default_data();
    }

    /**
     * Create additional indexes for performance
     */
    private function create_additional_indexes() {
        global $wpdb;

        $indexes = array(
            // Composite indexes for common queries
            "CREATE INDEX idx_credits_user_status ON {$wpdb->prefix}wc_credits(user_id, status)",
            "CREATE INDEX idx_installments_due_status ON {$wpdb->prefix}wc_installments(due_date, status)",
            "CREATE INDEX idx_notifications_scheduled_status ON {$wpdb->prefix}wc_installment_notifications(scheduled_at, status)",
            "CREATE INDEX idx_history_user_action ON {$wpdb->prefix}wc_customer_credit_history(user_id, action)",
        );

        foreach ($indexes as $index) {
            $wpdb->query($index);
        }
    }

    /**
     * Insert default data
     */
    private function insert_default_data() {
        global $wpdb;

        // Insert default settings
        $default_settings = array(
            'payment_reminder_days' => '7,3,1',
            'overdue_reminder_days' => '1,7,15,30',
            'late_fee_percentage' => '2.5',
            'max_late_fee_amount' => '50000',
            'grace_period_days' => '5',
            'auto_cancel_after_days' => '60',
            'min_credit_amount' => '50000',
            'max_credit_amount' => '5000000',
            'whatsapp_enabled' => 'yes',
            'email_enabled' => 'yes',
            'sms_enabled' => 'no'
        );

        foreach ($default_settings as $key => $value) {
            $wpdb->replace(
                $wpdb->prefix . 'wc_installment_settings',
                array(
                    'setting_key' => $key,
                    'setting_value' => $value,
                    'autoload' => 'yes'
                ),
                array('%s', '%s', '%s')
            );
        }
    }

    /**
     * Drop all plugin tables (used during uninstall)
     */
    public static function drop_tables() {
        global $wpdb;

        $tables = array(
            $wpdb->prefix . 'wc_customer_credit_history',
            $wpdb->prefix . 'wc_installment_notifications',
            $wpdb->prefix . 'wc_installments',
            $wpdb->prefix . 'wc_credits',
            $wpdb->prefix . 'wc_payment_plans',
            $wpdb->prefix . 'wc_installment_settings'
        );

        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS $table");
        }
    }

    /**
     * Get table name with prefix
     */
    public static function get_table_name($table) {
        global $wpdb;
        return $wpdb->prefix . 'wc_' . $table;
    }

    /**
     * Clean up orphaned records
     */
    public function cleanup_orphaned_records() {
        global $wpdb;

        // Clean up installments without credits
        $wpdb->query("
            DELETE i FROM {$wpdb->prefix}wc_installments i 
            LEFT JOIN {$wpdb->prefix}wc_credits c ON i.credit_id = c.id 
            WHERE c.id IS NULL
        ");

        // Clean up notifications without credits
        $wpdb->query("
            DELETE n FROM {$wpdb->prefix}wc_installment_notifications n 
            LEFT JOIN {$wpdb->prefix}wc_credits c ON n.credit_id = c.id 
            WHERE c.id IS NULL
        ");

        // Clean up history without credits
        $wpdb->query("
            DELETE h FROM {$wpdb->prefix}wc_customer_credit_history h 
            LEFT JOIN {$wpdb->prefix}wc_credits c ON h.credit_id = c.id 
            WHERE c.id IS NULL
        ");
    }

    /**
     * Get database statistics
     */
    public function get_db_stats() {
        global $wpdb;

        $stats = array();

        // Count records in each table
        $tables = array(
            'payment_plans' => 'wc_payment_plans',
            'credits' => 'wc_credits',
            'installments' => 'wc_installments',
            'notifications' => 'wc_installment_notifications',
            'settings' => 'wc_installment_settings',
            'credit_history' => 'wc_customer_credit_history'
        );

        foreach ($tables as $key => $table) {
            $count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}$table");
            $stats[$key] = intval($count);
        }

        // Additional statistics
        $stats['active_credits'] = $wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->prefix}wc_credits 
            WHERE status = 'active'
        ");

        $stats['overdue_installments'] = $wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->prefix}wc_installments 
            WHERE status = 'overdue'
        ");

        $stats['total_amount_pending'] = $wpdb->get_var("
            SELECT SUM(pending_amount) FROM {$wpdb->prefix}wc_credits 
            WHERE status IN ('active', 'overdue')
        ");

        return $stats;
    }

    /**
     * Optimize database tables
     */
    public function optimize_tables() {
        global $wpdb;

        $tables = array(
            'wc_payment_plans',
            'wc_credits',
            'wc_installments',
            'wc_installment_notifications',
            'wc_installment_settings',
            'wc_customer_credit_history'
        );

        foreach ($tables as $table) {
            $wpdb->query("OPTIMIZE TABLE {$wpdb->prefix}$table");
        }
    }

    /**
     * Backup essential data
     */
    public function backup_data() {
        global $wpdb;

        $backup_data = array();
        
        // Backup payment plans
        $backup_data['payment_plans'] = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}wc_payment_plans",
            ARRAY_A
        );

        // Backup active credits
        $backup_data['active_credits'] = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}wc_credits WHERE status IN ('active', 'pending')",
            ARRAY_A
        );

        // Backup settings
        $backup_data['settings'] = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}wc_installment_settings",
            ARRAY_A
        );

        return $backup_data;
    }

    /**
     * Restore data from backup
     */
    public function restore_data($backup_data) {
        global $wpdb;

        if (!is_array($backup_data)) {
            return false;
        }

        $wpdb->query('START TRANSACTION');

        try {
            // Restore payment plans
            if (isset($backup_data['payment_plans'])) {
                foreach ($backup_data['payment_plans'] as $plan) {
                    $wpdb->insert($wpdb->prefix . 'wc_payment_plans', $plan);
                }
            }

            // Restore settings
            if (isset($backup_data['settings'])) {
                foreach ($backup_data['settings'] as $setting) {
                    $wpdb->replace($wpdb->prefix . 'wc_installment_settings', $setting);
                }
            }

            $wpdb->query('COMMIT');
            return true;
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            return false;
        }
    }
}
