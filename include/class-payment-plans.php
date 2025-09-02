<?php
/**
 * Payment Plans Manager
 * 
 * Handles payment plans creation, management and retrieval
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Payment_Plans {

    /**
     * Table name
     */
    private $table_name;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'wc_payment_plans';
        $this->init();
    }

    /**
     * Initialize
     */
    public function init() {
        add_action('wp_ajax_save_payment_plan', array($this, 'ajax_save_payment_plan'));
        add_action('wp_ajax_delete_payment_plan', array($this, 'ajax_delete_payment_plan'));
        add_action('wp_ajax_toggle_payment_plan', array($this, 'ajax_toggle_payment_plan'));
    }

    /**
     * Create a new payment plan
     */
    public function create_plan($data) {
        global $wpdb;

        $defaults = array(
            'name' => '',
            'installments_count' => 3,
            'interest_rate' => 0.00,
            'min_amount' => 0.00,
            'max_amount' => 999999999.99,
            'active' => 1,
            'priority' => 0,
            'description' => '',
            'conditions' => '',
            'applicable_categories' => '',
            'applicable_products' => ''
        );

        $data = wp_parse_args($data, $defaults);

        // Validate required fields
        if (empty($data['name'])) {
            return new WP_Error('missing_name', __('El nombre del plan es requerido', 'wc-installment-payments'));
        }

        if ($data['installments_count'] < 1) {
            return new WP_Error('invalid_installments', __('El número de cuotas debe ser mayor a 0', 'wc-installment-payments'));
        }

        if ($data['interest_rate'] < 0) {
            return new WP_Error('invalid_interest', __('La tasa de interés no puede ser negativa', 'wc-installment-payments'));
        }

        if ($data['min_amount'] >= $data['max_amount']) {
            return new WP_Error('invalid_amounts', __('El monto mínimo debe ser menor al monto máximo', 'wc-installment-payments'));
        }

        $result = $wpdb->insert(
            $this->table_name,
            array(
                'name' => sanitize_text_field($data['name']),
                'installments_count' => intval($data['installments_count']),
                'interest_rate' => floatval($data['interest_rate']),
                'min_amount' => floatval($data['min_amount']),
                'max_amount' => floatval($data['max_amount']),
                'active' => intval($data['active']),
                'priority' => intval($data['priority']),
                'description' => sanitize_textarea_field($data['description']),
                'conditions' => sanitize_textarea_field($data['conditions']),
                'applicable_categories' => sanitize_text_field($data['applicable_categories']),
                'applicable_products' => sanitize_text_field($data['applicable_products'])
            ),
            array('%s', '%d', '%f', '%f', '%f', '%d', '%d', '%s', '%s', '%s', '%s')
        );

        if ($result === false) {
            return new WP_Error('db_error', __('Error al crear el plan de pago', 'wc-installment-payments'));
        }

        do_action('wc_installment_payment_plan_created', $wpdb->insert_id, $data);

        return $wpdb->insert_id;
    }

    /**
     * Update payment plan
     */
    public function update_plan($plan_id, $data) {
        global $wpdb;

        if (!$this->plan_exists($plan_id)) {
            return new WP_Error('plan_not_found', __('Plan de pago no encontrado', 'wc-installment-payments'));
        }

        // Validate data
        if (isset($data['installments_count']) && $data['installments_count'] < 1) {
            return new WP_Error('invalid_installments', __('El número de cuotas debe ser mayor a 0', 'wc-installment-payments'));
        }

        if (isset($data['interest_rate']) && $data['interest_rate'] < 0) {
            return new WP_Error('invalid_interest', __('La tasa de interés no puede ser negativa', 'wc-installment-payments'));
        }

        if (isset($data['min_amount'], $data['max_amount']) && $data['min_amount'] >= $data['max_amount']) {
            return new WP_Error('invalid_amounts', __('El monto mínimo debe ser menor al monto máximo', 'wc-installment-payments'));
        }

        $update_data = array();
        $update_format = array();

        // Sanitize and prepare data for update
        if (isset($data['name'])) {
            $update_data['name'] = sanitize_text_field($data['name']);
            $update_format[] = '%s';
        }
        if (isset($data['installments_count'])) {
            $update_data['installments_count'] = intval($data['installments_count']);
            $update_format[] = '%d';
        }
        if (isset($data['interest_rate'])) {
            $update_data['interest_rate'] = floatval($data['interest_rate']);
            $update_format[] = '%f';
        }
        if (isset($data['min_amount'])) {
            $update_data['min_amount'] = floatval($data['min_amount']);
            $update_format[] = '%f';
        }
        if (isset($data['max_amount'])) {
            $update_data['max_amount'] = floatval($data['max_amount']);
            $update_format[] = '%f';
        }
        if (isset($data['active'])) {
            $update_data['active'] = intval($data['active']);
            $update_format[] = '%d';
        }
        if (isset($data['priority'])) {
            $update_data['priority'] = intval($data['priority']);
            $update_format[] = '%d';
        }
        if (isset($data['description'])) {
            $update_data['description'] = sanitize_textarea_field($data['description']);
            $update_format[] = '%s';
        }
        if (isset($data['conditions'])) {
            $update_data['conditions'] = sanitize_textarea_field($data['conditions']);
            $update_format[] = '%s';
        }
        if (isset($data['applicable_categories'])) {
            $update_data['applicable_categories'] = sanitize_text_field($data['applicable_categories']);
            $update_format[] = '%s';
        }
        if (isset($data['applicable_products'])) {
            $update_data['applicable_products'] = sanitize_text_field($data['applicable_products']);
            $update_format[] = '%s';
        }

        if (empty($update_data)) {
            return new WP_Error('no_data', __('No hay datos para actualizar', 'wc-installment-payments'));
        }

        $result = $wpdb->update(
            $this->table_name,
            $update_data,
            array('id' => $plan_id),
            $update_format,
            array('%d')
        );

        if ($result === false) {
            return new WP_Error('db_error', __('Error al actualizar el plan de pago', 'wc-installment-payments'));
        }

        do_action('wc_installment_payment_plan_updated', $plan_id, $data);

        return true;
    }

    /**
     * Delete payment plan
     */
    public function delete_plan($plan_id) {
        global $wpdb;

        if (!$this->plan_exists($plan_id)) {
            return new WP_Error('plan_not_found', __('Plan de pago no encontrado', 'wc-installment-payments'));
        }

        // Check if plan is being used
        if ($this->plan_has_credits($plan_id)) {
            return new WP_Error('plan_in_use', __('No se puede eliminar un plan que tiene créditos asociados', 'wc-installment-payments'));
        }

        $result = $wpdb->delete(
            $this->table_name,
            array('id' => $plan_id),
            array('%d')
        );

        if ($result === false) {
            return new WP_Error('db_error', __('Error al eliminar el plan de pago', 'wc-installment-payments'));
        }

        do_action('wc_installment_payment_plan_deleted', $plan_id);

        return true;
    }

    /**
     * Get payment plan by ID
     */
    public function get_plan($plan_id) {
        global $wpdb;

        $plan = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id = %d", $plan_id),
            ARRAY_A
        );

        if ($plan) {
            $plan = $this->format_plan_data($plan);
        }

        return $plan;
    }

    /**
     * Get all payment plans
     */
    public function get_all_plans($active_only = false) {
        global $wpdb;

        $where = $active_only ? "WHERE active = 1" : "";
        
        $plans = $wpdb->get_results(
            "SELECT * FROM {$this->table_name} {$where} ORDER BY priority ASC, installments_count ASC",
            ARRAY_A
        );

        if ($plans) {
            $plans = array_map(array($this, 'format_plan_data'), $plans);
        }

        return $plans ?: array();
    }

    /**
     * Get available payment plans for a product
     */
    public function get_available_plans_for_product($product_id, $amount = null) {
        global $wpdb;

        $product = wc_get_product($product_id);
        if (!$product) {
            return array();
        }

        $price = $amount ?: $product->get_price();
        if (!$price) {
            return array();
        }

        // Get plans that match the price range
        $plans = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} 
                WHERE active = 1 
                AND min_amount <= %f 
                AND max_amount >= %f
                ORDER BY priority ASC, installments_count ASC",
                $price, $price
            ),
            ARRAY_A
        );

        if (!$plans) {
            return array();
        }

        // Filter plans based on product categories and specific products
        $filtered_plans = array();
        $product_categories = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'ids'));

        foreach ($plans as $plan) {
            if ($this->is_plan_applicable_to_product($plan, $product_id, $product_categories)) {
                $filtered_plans[] = $this->format_plan_data($plan);
            }
        }

        return apply_filters('wc_installment_available_plans_for_product', $filtered_plans, $product_id, $price);
    }

    /**
     * Check if plan is applicable to product
     */
    private function is_plan_applicable_to_product($plan, $product_id, $product_categories) {
        // Check specific products
        if (!empty($plan['applicable_products'])) {
            $applicable_products = array_map('trim', explode(',', $plan['applicable_products']));
            if (!in_array($product_id, $applicable_products)) {
                return false;
            }
        }

        // Check categories
        if (!empty($plan['applicable_categories'])) {
            $applicable_categories = array_map('trim', explode(',', $plan['applicable_categories']));
            $applicable_categories = array_map('intval', $applicable_categories);
            
            if (!array_intersect($product_categories, $applicable_categories)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Format plan data
     */
    private function format_plan_data($plan) {
        if (!$plan) {
            return $plan;
        }

        $plan['interest_rate'] = floatval($plan['interest_rate']);
        $plan['min_amount'] = floatval($plan['min_amount']);
        $plan['max_amount'] = floatval($plan['max_amount']);
        $plan['installments_count'] = intval($plan['installments_count']);
        $plan['active'] = intval($plan['active']);
        $plan['priority'] = intval($plan['priority']);

        // Add formatted values for display
        $plan['formatted_min_amount'] = wc_price($plan['min_amount']);
        $plan['formatted_max_amount'] = wc_price($plan['max_amount']);
        $plan['interest_rate_display'] = $plan['interest_rate'] . '%';

        return $plan;
    }

    /**
     * Check if plan exists
     */
    public function plan_exists($plan_id) {
        global $wpdb;

        $count = $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$this->table_name} WHERE id = %d", $plan_id)
        );

        return intval($count) > 0;
    }

    /**
     * Check if plan has associated credits
     */
    public function plan_has_credits($plan_id) {
        global $wpdb;

        $count = $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}wc_credits WHERE plan_id = %d", $plan_id)
        );

        return intval($count) > 0;
    }

    /**
     * Get plan usage statistics
     */
    public function get_plan_stats($plan_id) {
        global $wpdb;

        $stats = array();

        // Total credits
        $stats['total_credits'] = $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}wc_credits WHERE plan_id = %d", $plan_id)
        );

        // Active credits
        $stats['active_credits'] = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}wc_credits WHERE plan_id = %d AND status = 'active'", 
                $plan_id
            )
        );

        // Total amount
        $stats['total_amount'] = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT SUM(total_amount) FROM {$wpdb->prefix}wc_credits WHERE plan_id = %d", 
                $plan_id
            )
        );

        // Completion rate
        $completed = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}wc_credits WHERE plan_id = %d AND status = 'completed'", 
                $plan_id
            )
        );

        $stats['completion_rate'] = $stats['total_credits'] > 0 ? ($completed / $stats['total_credits']) * 100 : 0;

        return $stats;
    }

    /**
     * AJAX: Save payment plan
     */
    public function ajax_save_payment_plan() {
        check_ajax_referer('wc_installment_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Sin permisos suficientes', 'wc-installment-payments'));
        }

        $plan_id = isset($_POST['plan_id']) ? intval($_POST['plan_id']) : 0;
        $data = array(
            'name' => sanitize_text_field($_POST['name'] ?? ''),
            'installments_count' => intval($_POST['installments_count'] ?? 3),
            'interest_rate' => floatval($_POST['interest_rate'] ?? 0),
            'min_amount' => floatval($_POST['min_amount'] ?? 0),
            'max_amount' => floatval($_POST['max_amount'] ?? 999999999.99),
            'active' => intval($_POST['active'] ?? 1),
            'priority' => intval($_POST['priority'] ?? 0),
            'description' => sanitize_textarea_field($_POST['description'] ?? ''),
            'conditions' => sanitize_textarea_field($_POST['conditions'] ?? ''),
            'applicable_categories' => sanitize_text_field($_POST['applicable_categories'] ?? ''),
            'applicable_products' => sanitize_text_field($_POST['applicable_products'] ?? '')
        );

        if ($plan_id > 0) {
            $result = $this->update_plan($plan_id, $data);
        } else {
            $result = $this->create_plan($data);
        }

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } else {
            wp_send_json_success(array(
                'message' => __('Plan guardado exitosamente', 'wc-installment-payments'),
                'plan_id' => $plan_id > 0 ? $plan_id : $result
            ));
        }
    }

    /**
     * AJAX: Delete payment plan
     */
    public function ajax_delete_payment_plan() {
        check_ajax_referer('wc_installment_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Sin permisos suficientes', 'wc-installment-payments'));
        }

        $plan_id = intval($_POST['plan_id']);
        $result = $this->delete_plan($plan_id);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } else {
            wp_send_json_success(__('Plan eliminado exitosamente', 'wc-installment-payments'));
        }
    }

    /**
     * AJAX: Toggle payment plan active status
     */
    public function ajax_toggle_payment_plan() {
        check_ajax_referer('wc_installment_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Sin permisos suficientes', 'wc-installment-payments'));
        }

        $plan_id = intval($_POST['plan_id']);
        $active = intval($_POST['active']);

        $result = $this->update_plan($plan_id, array('active' => $active));

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } else {
            wp_send_json_success(array(
                'message' => __('Estado actualizado exitosamente', 'wc-installment-payments'),
                'active' => $active
            ));
        }
    }
}
