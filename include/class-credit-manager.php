<?php
/**
 * Credit Manager
 * 
 * Handles credit creation, management and payment processing
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Credit_Manager {

    /**
     * Credits table name
     */
    private $credits_table;

    /**
     * Installments table name
     */
    private $installments_table;

    /**
     * History table name
     */
    private $history_table;

    /**
     * Interest calculator
     */
    private $calculator;

    /**
     * Notifications manager
     */
    private $notifications;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        
        $this->credits_table = $wpdb->prefix . 'wc_credits';
        $this->installments_table = $wpdb->prefix . 'wc_installments';
        $this->history_table = $wpdb->prefix . 'wc_customer_credit_history';
        
        $this->calculator = new WC_Interest_Calculator();
        
        $this->init();
    }

    /**
     * Initialize
     */
    public function init() {
        // AJAX actions
        add_action('wp_ajax_approve_credit', array($this, 'ajax_approve_credit'));
        add_action('wp_ajax_reject_credit', array($this, 'ajax_reject_credit'));
        add_action('wp_ajax_process_payment', array($this, 'ajax_process_payment'));
        add_action('wp_ajax_calculate_early_payment', array($this, 'ajax_calculate_early_payment'));
        
        // Scheduled events
        add_action('wc_installment_daily_tasks', array($this, 'process_daily_tasks'));
        add_action('wc_installment_check_overdue_payments', array($this, 'check_overdue_payments'));
        
        // Payment hooks
        add_action('woocommerce_order_status_completed', array($this, 'maybe_create_credit_from_order'));
        add_action('woocommerce_payment_complete', array($this, 'process_installment_payment'));
    }

    /**
     * Create a new credit
     */
    public function create_credit($order_id, $plan_id, $user_id = null) {
        global $wpdb;

        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('invalid_order', __('Orden no válida', 'wc-installment-payments'));
        }

        $user_id = $user_id ?: $order->get_user_id();
        if (!$user_id) {
            return new WP_Error('invalid_user', __('Usuario no válido', 'wc-installment-payments'));
        }

        // Get payment plan
        $payment_plans = new WC_Payment_Plans();
        $plan = $payment_plans->get_plan($plan_id);
        
        if (!$plan) {
            return new WP_Error('invalid_plan', __('Plan de pago no válido', 'wc-installment-payments'));
        }

        $total_amount = $order->get_total();

        // Validate amount against plan limits
        if ($total_amount < $plan['min_amount'] || $total_amount > $plan['max_amount']) {
            return new WP_Error('amount_out_of_range', __('El monto está fuera del rango permitido para este plan', 'wc-installment-payments'));
        }

        // Check if credit already exists for this order
        $existing_credit = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$this->credits_table} WHERE order_id = %d", $order_id)
        );

        if ($existing_credit) {
            return new WP_Error('credit_exists', __('Ya existe un crédito para esta orden', 'wc-installment-payments'));
        }

        // Start transaction
        $wpdb->query('START TRANSACTION');

        try {
            // Create credit record
            $credit_data = array(
                'user_id' => $user_id,
                'order_id' => $order_id,
                'plan_id' => $plan_id,
                'total_amount' => $total_amount,
                'paid_amount' => 0,
                'pending_amount' => $total_amount,
                'status' => 'pending',
                'metadata' => json_encode(array(
                    'customer_info' => array(
                        'name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                        'email' => $order->get_billing_email(),
                        'phone' => $order->get_billing_phone()
                    ),
                    'order_info' => array(
                        'order_number' => $order->get_order_number(),
                        'order_date' => $order->get_date_created()->format('Y-m-d H:i:s'),
                        'products' => $this->get_order_products_info($order)
                    )
                ))
            );

            $result = $wpdb->insert($this->credits_table, $credit_data, array(
                '%d', '%d', '%d', '%f', '%f', '%f', '%s', '%s'
            ));

            if ($result === false) {
                throw new Exception(__('Error al crear el crédito', 'wc-installment-payments'));
            }

            $credit_id = $wpdb->insert_id;

            // Calculate and create installments
            $installments_calculation = $this->calculator->calculate_installments($total_amount, $plan_id);
            
            if (is_wp_error($installments_calculation)) {
                throw new Exception($installments_calculation->get_error_message());
            }

            $this->create_installments($credit_id, $installments_calculation['installments']);

            // Log history
            $this->add_history_record($credit_id, $user_id, 'created', null, null, 'pending', __('Crédito creado', 'wc-installment-payments'));

            $wpdb->query('COMMIT');

            // Fire action
            do_action('wc_installment_credit_created', $credit_id, $order_id, $user_id);

            return $credit_id;

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('credit_creation_failed', $e->getMessage());
        }
    }

    /**
     * Create installments for a credit
     */
    private function create_installments($credit_id, $installments_data) {
        global $wpdb;

        foreach ($installments_data as $installment) {
            $installment_data = array(
                'credit_id' => $credit_id,
                'installment_number' => $installment['number'],
                'amount' => $installment['amount'],
                'interest_amount' => $installment['interest'] ?? 0,
                'principal_amount' => $installment['principal'],
                'due_date' => $installment['due_date'],
                'status' => 'pending'
            );

            $result = $wpdb->insert($this->installments_table, $installment_data, array(
                '%d', '%d', '%f', '%f', '%f', '%s', '%s'
            ));

            if ($result === false) {
                throw new Exception(__('Error al crear las cuotas', 'wc-installment-payments'));
            }
        }
    }

    /**
     * Approve credit
     */
    public function approve_credit($credit_id, $admin_user_id = null) {
        global $wpdb;

        $credit = $this->get_credit($credit_id);
        if (!$credit) {
            return new WP_Error('credit_not_found', __('Crédito no encontrado', 'wc-installment-payments'));
        }

        if ($credit['status'] !== 'pending') {
            return new WP_Error('invalid_status', __('Solo se pueden aprobar créditos pendientes', 'wc-installment-payments'));
        }

        // Calculate next payment date
        $next_payment_date = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT due_date FROM {$this->installments_table} 
                WHERE credit_id = %d AND status = 'pending' 
                ORDER BY due_date ASC LIMIT 1",
                $credit_id
            )
        );

        $result = $wpdb->update(
            $this->credits_table,
            array(
                'status' => 'active',
                'approval_date' => current_time('mysql'),
                'next_payment_date' => $next_payment_date
            ),
            array('id' => $credit_id),
            array('%s', '%s', '%s'),
            array('%d')
        );

        if ($result === false) {
            return new WP_Error('update_failed', __('Error al aprobar el crédito', 'wc-installment-payments'));
        }

        // Log history
        $this->add_history_record(
            $credit_id, 
            $credit['user_id'], 
            'approved', 
            null, 
            'pending', 
            'active', 
            __('Crédito aprobado', 'wc-installment-payments'),
            $admin_user_id
        );

        // Send approval notification
        $this->send_credit_notification($credit_id, 'approved');

        do_action('wc_installment_credit_approved', $credit_id, $admin_user_id);

        return true;
    }

    /**
     * Reject credit
     */
    public function reject_credit($credit_id, $reason = '', $admin_user_id = null) {
        global $wpdb;

        $credit = $this->get_credit($credit_id);
        if (!$credit) {
            return new WP_Error('credit_not_found', __('Crédito no encontrado', 'wc-installment-payments'));
        }

        if ($credit['status'] !== 'pending') {
            return new WP_Error('invalid_status', __('Solo se pueden rechazar créditos pendientes', 'wc-installment-payments'));
        }

        $result = $wpdb->update(
            $this->credits_table,
            array('status' => 'cancelled'),
            array('id' => $credit_id),
            array('%s'),
            array('%d')
        );

        if ($result === false) {
            return new WP_Error('update_failed', __('Error al rechazar el crédito', 'wc-installment-payments'));
        }

        // Cancel all pending installments
        $wpdb->update(
            $this->installments_table,
            array('status' => 'cancelled'),
            array('credit_id' => $credit_id, 'status' => 'pending'),
            array('%s'),
            array('%d', '%s')
        );

        // Log history
        $notes = $reason ? sprintf(__('Crédito rechazado. Razón: %s', 'wc-installment-payments'), $reason) : __('Crédito rechazado', 'wc-installment-payments');
        
        $this->add_history_record(
            $credit_id, 
            $credit['user_id'], 
            'cancelled', 
            null, 
            'pending', 
            'cancelled', 
            $notes,
            $admin_user_id
        );

        do_action('wc_installment_credit_rejected', $credit_id, $reason, $admin_user_id);

        return true;
    }

    /**
     * Process installment payment
     */
    public function process_payment($installment_id, $amount, $payment_method = '', $transaction_id = '') {
        global $wpdb;

        $installment = $this->get_installment($installment_id);
        if (!$installment) {
            return new WP_Error('installment_not_found', __('Cuota no encontrada', 'wc-installment-payments'));
        }

        if ($installment['status'] === 'paid') {
            return new WP_Error('already_paid', __('Esta cuota ya fue pagada', 'wc-installment-payments'));
        }

        $credit = $this->get_credit($installment['credit_id']);
        if (!$credit || $credit['status'] !== 'active') {
            return new WP_Error('invalid_credit', __('Crédito no válido o inactivo', 'wc-installment-payments'));
        }

        // Calculate late fee if applicable
        $late_fee = 0;
        if ($installment['due_date'] < current_time('Y-m-d')) {
            $days_overdue = $this->calculate_days_overdue($installment['due_date']);
            $late_fee = $this->calculator->calculate_late_fee($installment['amount'], $days_overdue);
        }

        $total_required = $installment['amount'] + $late_fee;

        if ($amount < $total_required) {
            return new WP_Error('insufficient_amount', 
                sprintf(__('Monto insuficiente. Se requiere %s (incluye mora de %s)', 'wc-installment-payments'), 
                    wc_price($total_required), wc_price($late_fee))
            );
        }

        $wpdb->query('START TRANSACTION');

        try {
            // Update installment
            $result = $wpdb->update(
                $this->installments_table,
                array(
                    'status' => 'paid',
                    'paid_date' => current_time('mysql'),
                    'payment_amount' => $amount,
                    'payment_method' => $payment_method,
                    'transaction_id' => $transaction_id,
                    'late_fee' => $late_fee
                ),
                array('id' => $installment_id),
                array('%s', '%s', '%f', '%s', '%s', '%f'),
                array('%d')
            );

            if ($result === false) {
                throw new Exception(__('Error al actualizar la cuota', 'wc-installment-payments'));
            }

            // Update credit amounts
            $new_paid_amount = $credit['paid_amount'] + $amount;
            $new_pending_amount = $credit['pending_amount'] - $installment['amount'];

            // Check if credit is completed
            $remaining_installments = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->installments_table} 
                    WHERE credit_id = %d AND status = 'pending'",
                    $credit['id']
                )
            );

            $new_status = $remaining_installments > 0 ? 'active' : 'completed';
            $completion_date = $new_status === 'completed' ? current_time('mysql') : null;

            // Get next payment date
            $next_payment_date = null;
            if ($remaining_installments > 0) {
                $next_payment_date = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT due_date FROM {$this->installments_table} 
                        WHERE credit_id = %d AND status = 'pending' 
                        ORDER BY due_date ASC LIMIT 1",
                        $credit['id']
                    )
                );
            }

            // Update credit
            $credit_update_data = array(
                'paid_amount' => $new_paid_amount,
                'pending_amount' => $new_pending_amount,
                'status' => $new_status,
                'next_payment_date' => $next_payment_date,
                'completion_date' => $completion_date
            );

            $result = $wpdb->update(
                $this->credits_table,
                $credit_update_data,
                array('id' => $credit['id']),
                array('%f', '%f', '%s', '%s', '%s'),
                array('%d')
            );

            if ($result === false) {
                throw new Exception(__('Error al actualizar el crédito', 'wc-installment-payments'));
            }

            // Log history
            $notes = sprintf(
                __('Pago procesado. Cuota #%d. Monto: %s. Método: %s', 'wc-installment-payments'),
                $installment['installment_number'],
                wc_price($amount),
                $payment_method
            );

            if ($late_fee > 0) {
                $notes .= sprintf(__(' (Mora: %s)', 'wc-installment-payments'), wc_price($late_fee));
            }

            $this->add_history_record(
                $credit['id'],
                $credit['user_id'],
                'payment_made',
                $amount,
                $credit['status'],
                $new_status,
                $notes
            );

            $wpdb->query('COMMIT');

            // Send payment confirmation
            $this->send_payment_confirmation($credit['id'], $installment_id, $amount);

            // If completed, send completion notification
            if ($new_status === 'completed') {
                $this->send_credit_notification($credit['id'], 'completed');
                do_action('wc_installment_credit_completed', $credit['id']);
            }

            do_action('wc_installment_payment_processed', $credit['id'], $installment_id, $amount);

            return array(
                'success' => true,
                'payment_amount' => $amount,
                'late_fee' => $late_fee,
                'credit_status' => $new_status,
                'remaining_installments' => $remaining_installments
            );

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('payment_failed', $e->getMessage());
        }
    }

    /**
     * Get credit by ID
     */
    public function get_credit($credit_id) {
        global $wpdb;

        $credit = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->credits_table} WHERE id = %d", $credit_id),
            ARRAY_A
        );

        if ($credit && $credit['metadata']) {
            $credit['metadata'] = json_decode($credit['metadata'], true);
        }

        return $credit;
    }

    /**
     * Get installment by ID
     */
    public function get_installment($installment_id) {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->installments_table} WHERE id = %d", $installment_id),
            ARRAY_A
        );
    }

    /**
     * Get user credits
     */
    public function get_user_credits($user_id, $status = null, $limit = null, $offset = 0) {
        global $wpdb;

        $where = $wpdb->prepare("WHERE c.user_id = %d", $user_id);
        
        if ($status) {
            $where .= $wpdb->prepare(" AND c.status = %s", $status);
        }

        $limit_clause = $limit ? $wpdb->prepare("LIMIT %d, %d", $offset, $limit) : '';

        $credits = $wpdb->get_results(
            "SELECT c.*, p.name as plan_name, p.installments_count, p.interest_rate
            FROM {$this->credits_table} c
            LEFT JOIN {$wpdb->prefix}wc_payment_plans p ON c.plan_id = p.id
            {$where}
            ORDER BY c.created_at DESC
            {$limit_clause}",
            ARRAY_A
        );

        // Add formatted data and installments info
        foreach ($credits as &$credit) {
            $credit['formatted_total_amount'] = wc_price($credit['total_amount']);
            $credit['formatted_paid_amount'] = wc_price($credit['paid_amount']);
            $credit['formatted_pending_amount'] = wc_price($credit['pending_amount']);
            
            // Get installments summary
            $credit['installments_summary'] = $this->get_credit_installments_summary($credit['id']);
            
            if ($credit['metadata']) {
                $credit['metadata'] = json_decode($credit['metadata'], true);
            }
        }

        return $credits;
    }

    /**
     * Get credit installments
     */
    public function get_credit_installments($credit_id, $status = null) {
        global $wpdb;

        $where = $wpdb->prepare("WHERE credit_id = %d", $credit_id);
        
        if ($status) {
            $where .= $wpdb->prepare(" AND status = %s", $status);
        }

        $installments = $wpdb->get_results(
            "SELECT * FROM {$this->installments_table} {$where} ORDER BY installment_number ASC",
            ARRAY_A
        );

        // Add formatted data
        foreach ($installments as &$installment) {
            $installment['formatted_amount'] = wc_price($installment['amount']);
            $installment['formatted_due_date'] = date_i18n(get_option('date_format'), strtotime($installment['due_date']));
            $installment['is_overdue'] = $installment['status'] === 'pending' && $installment['due_date'] < current_time('Y-m-d');
            $installment['days_overdue'] = $installment['is_overdue'] ? $this->calculate_days_overdue($installment['due_date']) : 0;
            
            if ($installment['late_fee'] > 0) {
                $installment['formatted_late_fee'] = wc_price($installment['late_fee']);
            }
        }

        return $installments;
    }

    /**
     * Get installments summary for a credit
     */
    private function get_credit_installments_summary($credit_id) {
        global $wpdb;

        $summary = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) as overdue
                FROM {$this->installments_table} 
                WHERE credit_id = %d",
                $credit_id
            ),
            ARRAY_A
        );

        return $summary;
    }

    /**
     * Check overdue payments
     */
    public function check_overdue_payments() {
        global $wpdb;

        $current_date = current_time('Y-m-d');

        // Get overdue installments
        $overdue_installments = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT i.*, c.user_id, c.status as credit_status
                FROM {$this->installments_table} i
                JOIN {$this->credits_table} c ON i.credit_id = c.id
                WHERE i.status = 'pending' 
                AND i.due_date < %s 
                AND c.status = 'active'",
                $current_date
            ),
            ARRAY_A
        );

        foreach ($overdue_installments as $installment) {
            // Update installment status to overdue
            $wpdb->update(
                $this->installments_table,
                array('status' => 'overdue'),
                array('id' => $installment['id']),
                array('%s'),
                array('%d')
            );

            // Update credit status if needed
            $wpdb->update(
                $this->credits_table,
                array('status' => 'overdue'),
                array('id' => $installment['credit_id']),
                array('%s'),
                array('%d')
            );

            // Send overdue notification
            $this->send_overdue_notification($installment['credit_id'], $installment['id']);

            // Log history
            $days_overdue = $this->calculate_days_overdue($installment['due_date']);
            $this->add_history_record(
                $installment['credit_id'],
                $installment['user_id'],
                'overdue',
                null,
                'active',
                'overdue',
                sprintf(__('Cuota #%d vencida hace %d días', 'wc-installment-payments'), $installment['installment_number'], $days_overdue)
            );
        }

        do_action('wc_installment_overdue_payments_processed', $overdue_installments);
    }

    /**
     * Process daily tasks
     */
    public function process_daily_tasks() {
        // Check overdue payments
        $this->check_overdue_payments();

        // Send payment reminders
        $this->send_payment_reminders();

        // Process scheduled notifications
        $this->process_scheduled_notifications();
    }

    /**
     * Send payment reminders
     */
    public function send_payment_reminders() {
        global $wpdb;

        $reminder_days = explode(',', get_option('wc_installment_payment_reminder_days', '7,3,1'));
        $current_date = current_time('Y-m-d');

        foreach ($reminder_days as $days) {
            $days = intval(trim($days));
            $reminder_date = date('Y-m-d', strtotime($current_date . " +{$days} days"));

            $installments = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT i.*, c.user_id
                    FROM {$this->installments_table} i
                    JOIN {$this->credits_table} c ON i.credit_id = c.id
                    WHERE i.status = 'pending' 
                    AND i.due_date = %s 
                    AND c.status = 'active'",
                    $reminder_date
                ),
                ARRAY_A
            );

            foreach ($installments as $installment) {
                $this->send_payment_reminder($installment['credit_id'], $installment['id'], $days);
            }
        }
    }

    /**
     * Calculate days overdue
     */
    private function calculate_days_overdue($due_date) {
        $current_date = new DateTime(current_time('Y-m-d'));
        $due_date_obj = new DateTime($due_date);
        $diff = $current_date->diff($due_date_obj);
        
        return $diff->days;
    }

    /**
     * Add history record
     */
    private function add_history_record($credit_id, $user_id, $action, $amount = null, $previous_status = null, $new_status = null, $notes = '', $admin_user_id = null) {
        global $wpdb;

        $wpdb->insert(
            $this->history_table,
            array(
                'user_id' => $user_id,
                'credit_id' => $credit_id,
                'action' => $action,
                'amount' => $amount,
                'previous_status' => $previous_status,
                'new_status' => $new_status,
                'notes' => $notes,
                'admin_user_id' => $admin_user_id,
                'ip_address' => $this->get_client_ip(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
            ),
            array('%d', '%d', '%s', '%f', '%s', '%s', '%s', '%d', '%s', '%s')
        );
    }

    /**
     * Get client IP address
     */
    private function get_client_ip() {
        $ip_keys = array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR');
        
        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '';
    }

    /**
     * Get order products info
     */
    private function get_order_products_info($order) {
        $products = array();
        
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $products[] = array(
                'name' => $item->get_name(),
                'quantity' => $item->get_quantity(),
                'price' => $item->get_total(),
                'sku' => $product ? $product->get_sku() : ''
            );
        }
        
        return $products;
    }

    /**
     * Send credit notification
     */
    private function send_credit_notification($credit_id, $type) {
        if (class_exists('WC_Installment_Notifications')) {
            $notifications = new WC_Installment_Notifications();
            $notifications->send_credit_notification($credit_id, $type);
        }
    }

    /**
     * Send payment confirmation
     */
    private function send_payment_confirmation($credit_id, $installment_id, $amount) {
        if (class_exists('WC_Installment_Notifications')) {
            $notifications = new WC_Installment_Notifications();
            $notifications->send_payment_confirmation($credit_id, $installment_id, $amount);
        }
    }

    /**
     * Send overdue notification
     */
    private function send_overdue_notification($credit_id, $installment_id) {
        if (class_exists('WC_Installment_Notifications')) {
            $notifications = new WC_Installment_Notifications();
            $notifications->send_overdue_notification($credit_id, $installment_id);
        }
    }

    /**
     * Send payment reminder
     */
    private function send_payment_reminder($credit_id, $installment_id, $days_ahead) {
        if (class_exists('WC_Installment_Notifications')) {
            $notifications = new WC_Installment_Notifications();
            $notifications->send_payment_reminder($credit_id, $installment_id, $days_ahead);
        }
    }

    /**
     * Process scheduled notifications
     */
    private function process_scheduled_notifications() {
        if (class_exists('WC_Installment_Notifications')) {
            $notifications = new WC_Installment_Notifications();
            $notifications->process_scheduled_notifications();
        }
    }

    /**
     * AJAX: Approve credit
     */
    public function ajax_approve_credit() {
        check_ajax_referer('wc_installment_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Sin permisos suficientes', 'wc-installment-payments'));
        }

        $credit_id = intval($_POST['credit_id']);
        $result = $this->approve_credit($credit_id, get_current_user_id());

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } else {
            wp_send_json_success(__('Crédito aprobado exitosamente', 'wc-installment-payments'));
        }
    }

    /**
     * AJAX: Reject credit
     */
    public function ajax_reject_credit() {
        check_ajax_referer('wc_installment_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Sin permisos suficientes', 'wc-installment-payments'));
        }

        $credit_id = intval($_POST['credit_id']);
        $reason = sanitize_textarea_field($_POST['reason'] ?? '');
        
        $result = $this->reject_credit($credit_id, $reason, get_current_user_id());

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } else {
            wp_send_json_success(__('Crédito rechazado exitosamente', 'wc-installment-payments'));
        }
    }

    /**
     * AJAX: Process payment
     */
    public function ajax_process_payment() {
        check_ajax_referer('wc_installment_nonce', 'nonce');

        $installment_id = intval($_POST['installment_id']);
        $amount = floatval($_POST['amount']);
        $payment_method = sanitize_text_field($_POST['payment_method'] ?? '');
        $transaction_id = sanitize_text_field($_POST['transaction_id'] ?? '');

        $result = $this->process_payment($installment_id, $amount, $payment_method, $transaction_id);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } else {
            wp_send_json_success($result);
        }
    }

    /**
     * AJAX: Calculate early payment
     */
    public function ajax_calculate_early_payment() {
        check_ajax_referer('wc_installment_nonce', 'nonce');

        $credit_id = intval($_POST['credit_id']);
        $result = $this->calculator->calculate_early_payment($credit_id);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } else {
            wp_send_json_success($result);
        }
    }
}
