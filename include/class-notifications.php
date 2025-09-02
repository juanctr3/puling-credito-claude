<?php
/**
 * Notifications Manager
 * 
 * Handles all notification sending (WhatsApp, Email, SMS)
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Installment_Notifications {

    /**
     * Notifications table
     */
    private $notifications_table;

    /**
     * WhatsApp API instance
     */
    private $whatsapp_api;

    /**
     * Email notifications instance
     */
    private $email_notifications;

    /**
     * Credit manager instance
     */
    private $credit_manager;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        
        $this->notifications_table = $wpdb->prefix . 'wc_installment_notifications';
        $this->whatsapp_api = new WC_WhatsApp_API();
        $this->email_notifications = new WC_Email_Notifications();
        
        $this->init();
    }

    /**
     * Initialize
     */
    public function init() {
        // Schedule events
        add_action('init', array($this, 'schedule_events'));
        add_action('wc_installment_process_notifications', array($this, 'process_scheduled_notifications'));
        add_action('wc_installment_send_reminders', array($this, 'send_payment_reminders'));
        
        // Webhook handler
        add_action('woocommerce_api_wc_installment_whatsapp_webhook', array($this, 'handle_whatsapp_webhook'));
        
        // AJAX actions
        add_action('wp_ajax_send_test_notification', array($this, 'ajax_send_test_notification'));
        add_action('wp_ajax_resend_notification', array($this, 'ajax_resend_notification'));
    }

    /**
     * Schedule recurring events
     */
    public function schedule_events() {
        if (!wp_next_scheduled('wc_installment_process_notifications')) {
            wp_schedule_event(time(), 'hourly', 'wc_installment_process_notifications');
        }
        
        if (!wp_next_scheduled('wc_installment_send_reminders')) {
            wp_schedule_event(time(), 'daily', 'wc_installment_send_reminders');
        }
    }

    /**
     * Send credit notification
     */
    public function send_credit_notification($credit_id, $type, $custom_data = array()) {
        $credit = $this->get_credit_data($credit_id);
        if (!$credit) {
            return new WP_Error('credit_not_found', __('Crédito no encontrado', 'wc-installment-payments'));
        }

        $template_data = array_merge($this->get_template_data($credit), $custom_data);
        
        switch ($type) {
            case 'approved':
                return $this->send_credit_approved_notification($credit, $template_data);
            case 'rejected':
                return $this->send_credit_rejected_notification($credit, $template_data);
            case 'completed':
                return $this->send_credit_completed_notification($credit, $template_data);
            default:
                return new WP_Error('invalid_type', __('Tipo de notificación inválido', 'wc-installment-payments'));
        }
    }

    /**
     * Send payment reminder
     */
    public function send_payment_reminder($credit_id, $installment_id, $days_ahead) {
        $credit = $this->get_credit_data($credit_id);
        $installment = $this->get_installment_data($installment_id);
        
        if (!$credit || !$installment) {
            return new WP_Error('data_not_found', __('Datos no encontrados', 'wc-installment-payments'));
        }

        $template_data = $this->get_template_data($credit, $installment);
        $template_data['days_ahead'] = $days_ahead;
        $template_data['reminder_type'] = $this->get_reminder_type($days_ahead);

        $results = array();

        // Send WhatsApp notification
        if (get_option('wc_installment_whatsapp_notifications', 'yes') === 'yes') {
            $whatsapp_result = $this->send_whatsapp_notification(
                $credit,
                'payment_reminder',
                $template_data,
                $installment_id
            );
            $results['whatsapp'] = $whatsapp_result;
        }

        // Send email notification
        if (get_option('wc_installment_email_notifications', 'yes') === 'yes') {
            $email_result = $this->send_email_notification(
                $credit,
                'payment_reminder',
                $template_data,
                $installment_id
            );
            $results['email'] = $email_result;
        }

        return $results;
    }

    /**
     * Send overdue notification
     */
    public function send_overdue_notification($credit_id, $installment_id) {
        $credit = $this->get_credit_data($credit_id);
        $installment = $this->get_installment_data($installment_id);
        
        if (!$credit || !$installment) {
            return new WP_Error('data_not_found', __('Datos no encontrados', 'wc-installment-payments'));
        }

        $days_overdue = $this->calculate_days_overdue($installment['due_date']);
        
        $template_data = $this->get_template_data($credit, $installment);
        $template_data['days_overdue'] = $days_overdue;
        
        // Calculate late fee
        $calculator = new WC_Interest_Calculator();
        $late_fee = $calculator->calculate_late_fee($installment['amount'], $days_overdue);
        $template_data['late_fee'] = $late_fee;
        $template_data['formatted_late_fee'] = wc_price($late_fee);
        $template_data['total_due'] = $installment['amount'] + $late_fee;
        $template_data['formatted_total_due'] = wc_price($installment['amount'] + $late_fee);

        $results = array();

        // Send WhatsApp notification
        if (get_option('wc_installment_whatsapp_notifications', 'yes') === 'yes') {
            $whatsapp_result = $this->send_whatsapp_notification(
                $credit,
                'payment_overdue',
                $template_data,
                $installment_id
            );
            $results['whatsapp'] = $whatsapp_result;
        }

        // Send email notification
        if (get_option('wc_installment_email_notifications', 'yes') === 'yes') {
            $email_result = $this->send_email_notification(
                $credit,
                'payment_overdue',
                $template_data,
                $installment_id
            );
            $results['email'] = $email_result;
        }

        return $results;
    }

    /**
     * Send payment confirmation
     */
    public function send_payment_confirmation($credit_id, $installment_id, $amount) {
        $credit = $this->get_credit_data($credit_id);
        $installment = $this->get_installment_data($installment_id);
        
        if (!$credit || !$installment) {
            return new WP_Error('data_not_found', __('Datos no encontrados', 'wc-installment-payments'));
        }

        $template_data = $this->get_template_data($credit, $installment);
        $template_data['payment_amount'] = $amount;
        $template_data['formatted_payment_amount'] = wc_price($amount);
        $template_data['payment_date'] = current_time(get_option('date_format'));

        $results = array();

        // Send WhatsApp notification
        if (get_option('wc_installment_whatsapp_notifications', 'yes') === 'yes') {
            $whatsapp_result = $this->send_whatsapp_notification(
                $credit,
                'payment_confirmed',
                $template_data,
                $installment_id
            );
            $results['whatsapp'] = $whatsapp_result;
        }

        // Send email notification
        if (get_option('wc_installment_email_notifications', 'yes') === 'yes') {
            $email_result = $this->send_email_notification(
                $credit,
                'payment_confirmed',
                $template_data,
                $installment_id
            );
            $results['email'] = $email_result;
        }

        return $results;
    }

    /**
     * Send WhatsApp notification
     */
    private function send_whatsapp_notification($credit, $template_type, $template_data, $installment_id = null) {
        if (!$this->whatsapp_api->is_configured()) {
            return new WP_Error('whatsapp_not_configured', __('WhatsApp no configurado', 'wc-installment-payments'));
        }

        $phone = $this->get_customer_phone($credit);
        if (!$phone) {
            return new WP_Error('no_phone', __('Número de teléfono no disponible', 'wc-installment-payments'));
        }

        $message = $this->get_message_template($template_type, 'whatsapp', $template_data);
        if (is_wp_error($message)) {
            return $message;
        }

        // Log notification attempt
        $notification_id = $this->log_notification(
            $credit['id'],
            $installment_id,
            $template_type,
            'whatsapp',
            $phone,
            null,
            $message,
            $template_type
        );

        // Send via WhatsApp API
        $result = $this->whatsapp_api->send_text_message($phone, $message);

        // Update notification status
        $this->update_notification_status($notification_id, $result);

        return $result;
    }

    /**
     * Send email notification
     */
    private function send_email_notification($credit, $template_type, $template_data, $installment_id = null) {
        $email = $this->get_customer_email($credit);
        if (!$email) {
            return new WP_Error('no_email', __('Email no disponible', 'wc-installment-payments'));
        }

        $subject = $this->get_email_subject($template_type, $template_data);
        $message = $this->get_message_template($template_type, 'email', $template_data);
        
        if (is_wp_error($message)) {
            return $message;
        }

        // Log notification attempt
        $notification_id = $this->log_notification(
            $credit['id'],
            $installment_id,
            $template_type,
            'email',
            $email,
            $subject,
            $message,
            $template_type
        );

        // Send email
        $result = $this->email_notifications->send_notification(
            $email,
            $subject,
            $message,
            $template_data
        );

        // Update notification status
        $this->update_notification_status($notification_id, $result);

        return $result;
    }

    /**
     * Get message template
     */
    private function get_message_template($template_type, $channel, $template_data) {
        $templates = $this->get_notification_templates();
        
        if (!isset($templates[$template_type][$channel])) {
            return new WP_Error('template_not_found', __('Plantilla de mensaje no encontrada', 'wc-installment-payments'));
        }

        $template = $templates[$template_type][$channel];
        
        // Replace placeholders with actual data
        foreach ($template_data as $key => $value) {
            $template = str_replace('{{' . $key . '}}', $value, $template);
        }

        return $template;
    }

    /**
     * Get notification templates
     */
    private function get_notification_templates() {
        $templates = get_option('wc_installment_notification_templates', array());
        
        // Default templates if none configured
        if (empty($templates)) {
            $templates = array(
                'credit_approved' => array(
                    'whatsapp' => "¡Hola {{customer_name}}! Tu crédito por {{total_amount}} ha sido aprobado. Tu próximo pago de {{next_installment_amount}} vence el {{next_due_date}}. ¡Gracias por confiar en nosotros!",
                    'email' => "Estimado/a {{customer_name}},\n\nNos complace informarte que tu solicitud de crédito por {{total_amount}} ha sido aprobada.\n\nDetalles del crédito:\n- Monto total: {{total_amount}}\n- Cuotas: {{installments_count}}\n- Próximo pago: {{next_installment_amount}} el {{next_due_date}}\n\nPuedes revisar los detalles completos en tu cuenta.\n\nSaludos,\nEquipo de Créditos"
                ),
                'payment_reminder' => array(
                    'whatsapp' => "Hola {{customer_name}}, te recordamos que tienes un pago pendiente de {{installment_amount}} que vence {{reminder_message}}. Puedes pagar a través de nuestros canales disponibles.",
                    'email' => "Estimado/a {{customer_name}},\n\nTe recordamos que tienes un pago pendiente:\n\n- Cuota #{{installment_number}}\n- Monto: {{installment_amount}}\n- Fecha de vencimiento: {{due_date}}\n\n{{reminder_message}}\n\nPuedes realizar el pago a través de nuestros canales disponibles.\n\nSaludos,\nEquipo de Créditos"
                ),
                'payment_overdue' => array(
                    'whatsapp' => "{{customer_name}}, tu pago de {{installment_amount}} está vencido desde hace {{days_overdue}} días. Total a pagar incluyendo mora: {{total_due}}. Por favor contacta para regularizar tu situación.",
                    'email' => "Estimado/a {{customer_name}},\n\nTu pago está vencido:\n\n- Cuota #{{installment_number}}: {{installment_amount}}\n- Días de atraso: {{days_overdue}}\n- Mora: {{formatted_late_fee}}\n- Total a pagar: {{total_due}}\n\nPor favor contacta con nosotros para regularizar tu situación.\n\nSaludos,\nEquipo de Créditos"
                ),
                'payment_confirmed' => array(
                    'whatsapp' => "¡Perfecto {{customer_name}}! Confirmamos el pago de {{formatted_payment_amount}} recibido el {{payment_date}}. Gracias por mantener al día tu crédito.",
                    'email' => "Estimado/a {{customer_name}},\n\nConfirmamos la recepción de tu pago:\n\n- Monto pagado: {{formatted_payment_amount}}\n- Fecha de pago: {{payment_date}}\n- Cuota #{{installment_number}}\n\nGracias por mantener al día tu crédito.\n\nSaludos,\nEquipo de Créditos"
                )
            );
        }

        return apply_filters('wc_installment_notification_templates', $templates);
    }

    /**
     * Get email subject
     */
    private function get_email_subject($template_type, $template_data) {
        $subjects = array(
            'credit_approved' => __('Crédito Aprobado - {{order_number}}', 'wc-installment-payments'),
            'payment_reminder' => __('Recordatorio de Pago - Cuota #{{installment_number}}', 'wc-installment-payments'),
            'payment_overdue' => __('Pago Vencido - Acción Requerida', 'wc-installment-payments'),
            'payment_confirmed' => __('Pago Confirmado - Cuota #{{installment_number}}', 'wc-installment-payments')
        );

        $subject = isset($subjects[$template_type]) ? $subjects[$template_type] : __('Notificación de Crédito', 'wc-installment-payments');

        // Replace placeholders
        foreach ($template_data as $key => $value) {
            $subject = str_replace('{{' . $key . '}}', $value, $subject);
        }

        return $subject;
    }

    /**
     * Get template data for notifications
     */
    private function get_template_data($credit, $installment = null) {
        $metadata = isset($credit['metadata']) ? $credit['metadata'] : array();
        
        $data = array(
            'credit_id' => $credit['id'],
            'customer_name' => isset($metadata['customer_info']['name']) ? $metadata['customer_info']['name'] : 'Cliente',
            'customer_email' => isset($metadata['customer_info']['email']) ? $metadata['customer_info']['email'] : '',
            'customer_phone' => isset($metadata['customer_info']['phone']) ? $metadata['customer_info']['phone'] : '',
            'order_number' => isset($metadata['order_info']['order_number']) ? $metadata['order_info']['order_number'] : $credit['order_id'],
            'total_amount' => wc_price($credit['total_amount']),
            'paid_amount' => wc_price($credit['paid_amount']),
            'pending_amount' => wc_price($credit['pending_amount']),
            'installments_count' => isset($credit['installments_count']) ? $credit['installments_count'] : 0,
            'plan_name' => isset($credit['plan_name']) ? $credit['plan_name'] : '',
            'interest_rate' => isset($credit['interest_rate']) ? $credit['interest_rate'] . '%' : '0%',
            'site_name' => get_bloginfo('name'),
            'site_url' => home_url(),
            'current_date' => current_time(get_option('date_format'))
        );

        // Add installment specific data
        if ($installment) {
            $data['installment_number'] = $installment['installment_number'];
            $data['installment_amount'] = wc_price($installment['amount']);
            $data['due_date'] = date_i18n(get_option('date_format'), strtotime($installment['due_date']));
            $data['principal_amount'] = wc_price($installment['principal_amount']);
            $data['interest_amount'] = wc_price($installment['interest_amount']);
        }

        // Add next installment info if available
        if (!$installment) {
            $next_installment = $this->get_next_installment($credit['id']);
            if ($next_installment) {
                $data['next_installment_number'] = $next_installment['installment_number'];
                $data['next_installment_amount'] = wc_price($next_installment['amount']);
                $data['next_due_date'] = date_i18n(get_option('date_format'), strtotime($next_installment['due_date']));
            }
        }

        return apply_filters('wc_installment_notification_template_data', $data, $credit, $installment);
    }

    /**
     * Get next pending installment
     */
    private function get_next_installment($credit_id) {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wc_installments 
                WHERE credit_id = %d AND status = 'pending' 
                ORDER BY due_date ASC LIMIT 1",
                $credit_id
            ),
            ARRAY_A
        );
    }

    /**
     * Get credit data
     */
    private function get_credit_data($credit_id) {
        global $wpdb;

        $credit = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT c.*, p.name as plan_name, p.installments_count, p.interest_rate
                FROM {$wpdb->prefix}wc_credits c
                LEFT JOIN {$wpdb->prefix}wc_payment_plans p ON c.plan_id = p.id
                WHERE c.id = %d",
                $credit_id
            ),
            ARRAY_A
        );

        if ($credit && $credit['metadata']) {
            $credit['metadata'] = json_decode($credit['metadata'], true);
        }

        return $credit;
    }

    /**
     * Get installment data
     */
    private function get_installment_data($installment_id) {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}wc_installments WHERE id = %d", $installment_id),
            ARRAY_A
        );
    }

    /**
     * Get customer phone
     */
    private function get_customer_phone($credit) {
        $metadata = isset($credit['metadata']) ? $credit['metadata'] : array();
        
        if (isset($metadata['customer_info']['phone']) && !empty($metadata['customer_info']['phone'])) {
            return $metadata['customer_info']['phone'];
        }

        // Fallback to user meta
        $user_phone = get_user_meta($credit['user_id'], 'billing_phone', true);
        return $user_phone ?: null;
    }

    /**
     * Get customer email
     */
    private function get_customer_email($credit) {
        $metadata = isset($credit['metadata']) ? $credit['metadata'] : array();
        
        if (isset($metadata['customer_info']['email']) && !empty($metadata['customer_info']['email'])) {
            return $metadata['customer_info']['email'];
        }

        // Fallback to user email
        $user = get_user_by('id', $credit['user_id']);
        return $user ? $user->user_email : null;
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
     * Get reminder type message
     */
    private function get_reminder_type($days_ahead) {
        if ($days_ahead >= 7) {
            return __('en una semana', 'wc-installment-payments');
        } elseif ($days_ahead >= 3) {
            return __('en 3 días', 'wc-installment-payments');
        } elseif ($days_ahead >= 1) {
            return __('mañana', 'wc-installment-payments');
        } else {
            return __('hoy', 'wc-installment-payments');
        }
    }

    /**
     * Log notification
     */
    private function log_notification($credit_id, $installment_id, $type, $channel, $recipient, $subject, $message, $template_used) {
        global $wpdb;

        $result = $wpdb->insert(
            $this->notifications_table,
            array(
                'credit_id' => $credit_id,
                'installment_id' => $installment_id,
                'type' => $type,
                'channel' => $channel,
                'recipient' => $recipient,
                'subject' => $subject,
                'message' => $message,
                'template_used' => $template_used,
                'status' => 'pending'
            ),
            array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Update notification status
     */
    private function update_notification_status($notification_id, $result) {
        global $wpdb;

        if (is_wp_error($result)) {
            $wpdb->update(
                $this->notifications_table,
                array(
                    'status' => 'failed',
                    'error_message' => $result->get_error_message(),
                    'retry_count' => 1
                ),
                array('id' => $notification_id),
                array('%s', '%s', '%d'),
                array('%d')
            );
        } else {
            $response_data = is_array($result) ? json_encode($result) : $result;
            
            $wpdb->update(
                $this->notifications_table,
                array(
                    'status' => 'sent',
                    'response_data' => $response_data
                ),
                array('id' => $notification_id),
                array('%s', '%s'),
                array('%d')
            );
        }
    }

    /**
     * Process scheduled notifications
     */
    public function process_scheduled_notifications() {
        global $wpdb;

        $current_time = current_time('mysql');

        // Get pending scheduled notifications
        $notifications = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->notifications_table} 
                WHERE status = 'pending' 
                AND (scheduled_at IS NULL OR scheduled_at <= %s)
                AND retry_count < 3
                ORDER BY scheduled_at ASC
                LIMIT 50",
                $current_time
            ),
            ARRAY_A
        );

        foreach ($notifications as $notification) {
            $this->process_notification($notification);
        }
    }

    /**
     * Process individual notification
     */
    private function process_notification($notification) {
        switch ($notification['channel']) {
            case 'whatsapp':
                $result = $this->whatsapp_api->send_text_message(
                    $notification['recipient'],
                    $notification['message']
                );
                break;
                
            case 'email':
                $result = wp_mail(
                    $notification['recipient'],
                    $notification['subject'],
                    $notification['message'],
                    array('Content-Type: text/html; charset=UTF-8')
                );
                break;
                
            default:
                $result = new WP_Error('invalid_channel', __('Canal de notificación inválido', 'wc-installment-payments'));
        }

        $this->update_notification_status($notification['id'], $result);
    }

    /**
     * Send payment reminders for today
     */
    public function send_payment_reminders() {
        $reminder_days = explode(',', get_option('wc_installment_payment_reminder_days', '7,3,1'));
        
        foreach ($reminder_days as $days) {
            $this->send_reminders_for_days(intval(trim($days)));
        }
    }

    /**
     * Send reminders for specific days ahead
     */
    private function send_reminders_for_days($days_ahead) {
        global $wpdb;

        $reminder_date = date('Y-m-d', strtotime(current_time('Y-m-d') . " +{$days_ahead} days"));

        $installments = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT i.*, c.user_id, c.id as credit_id
                FROM {$wpdb->prefix}wc_installments i
                JOIN {$wpdb->prefix}wc_credits c ON i.credit_id = c.id
                WHERE i.status = 'pending' 
                AND i.due_date = %s 
                AND c.status = 'active'",
                $reminder_date
            ),
            ARRAY_A
        );

        foreach ($installments as $installment) {
            // Check if reminder was already sent today
            $already_sent = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->notifications_table}
                    WHERE credit_id = %d 
                    AND installment_id = %d 
                    AND type = 'reminder'
                    AND DATE(sent_at) = %s
                    AND status = 'sent'",
                    $installment['credit_id'],
                    $installment['id'],
                    current_time('Y-m-d')
                )
            );

            if ($already_sent == 0) {
                $this->send_payment_reminder($installment['credit_id'], $installment['id'], $days_ahead);
            }
        }
    }

    /**
     * Resend failed notification
     */
    public function resend_notification($notification_id) {
        global $wpdb;

        $notification = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->notifications_table} WHERE id = %d", $notification_id),
            ARRAY_A
        );

        if (!$notification) {
            return new WP_Error('notification_not_found', __('Notificación no encontrada', 'wc-installment-payments'));
        }

        if ($notification['retry_count'] >= 3) {
            return new WP_Error('max_retries', __('Se alcanzó el máximo número de intentos', 'wc-installment-payments'));
        }

        // Reset status and increment retry count
        $wpdb->update(
            $this->notifications_table,
            array(
                'status' => 'pending',
                'retry_count' => $notification['retry_count'] + 1,
                'error_message' => null
            ),
            array('id' => $notification_id),
            array('%s', '%d', '%s'),
            array('%d')
        );

        // Process the notification
        $this->process_notification($notification);

        return true;
    }

    /**
     * Get notification statistics
     */
    public function get_notification_stats($date_from = null, $date_to = null) {
        global $wpdb;

        $where = "WHERE 1=1";
        $params = array();

        if ($date_from) {
            $where .= " AND DATE(sent_at) >= %s";
            $params[] = $date_from;
        }

        if ($date_to) {
            $where .= " AND DATE(sent_at) <= %s";
            $params[] = $date_to;
        }

        $stats = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT 
                    channel,
                    status,
                    COUNT(*) as count
                FROM {$this->notifications_table} 
                {$where}
                GROUP BY channel, status",
                $params
            ),
            ARRAY_A
        );

        $formatted_stats = array(
            'whatsapp' => array('sent' => 0, 'failed' => 0, 'pending' => 0),
            'email' => array('sent' => 0, 'failed' => 0, 'pending' => 0),
            'sms' => array('sent' => 0, 'failed' => 0, 'pending' => 0)
        );

        foreach ($stats as $stat) {
            if (isset($formatted_stats[$stat['channel']])) {
                $formatted_stats[$stat['channel']][$stat['status']] = intval($stat['count']);
            }
        }

        return $formatted_stats;
    }

    /**
     * Handle WhatsApp webhook
     */
    public function handle_whatsapp_webhook() {
        $raw_data = file_get_contents('php://input');
        $data = json_decode($raw_data, true);

        if (!$data) {
            wp_die(__('Datos inválidos', 'wc-installment-payments'), '', 400);
        }

        $result = $this->whatsapp_api->process_webhook($data);

        if (is_wp_error($result)) {
            wp_die($result->get_error_message(), '', 400);
        }

        wp_die('OK', '', 200);
    }

    /**
     * AJAX: Send test notification
     */
    public function ajax_send_test_notification() {
        check_ajax_referer('wc_installment_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Sin permisos suficientes', 'wc-installment-payments'));
        }

        $channel = sanitize_text_field($_POST['channel']);
        $recipient = sanitize_text_field($_POST['recipient']);
        $message = sanitize_textarea_field($_POST['message']);

        if ($channel === 'whatsapp') {
            $result = $this->whatsapp_api->send_text_message($recipient, $message);
        } elseif ($channel === 'email') {
            $result = wp_mail($recipient, __('Mensaje de Prueba', 'wc-installment-payments'), $message);
        } else {
            wp_send_json_error(__('Canal no válido', 'wc-installment-payments'));
            return;
        }

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } else {
            wp_send_json_success(__('Mensaje de prueba enviado', 'wc-installment-payments'));
        }
    }

    /**
     * AJAX: Resend notification
     */
    public function ajax_resend_notification() {
        check_ajax_referer('wc_installment_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Sin permisos suficientes', 'wc-installment-payments'));
        }

        $notification_id = intval($_POST['notification_id']);
        $result = $this->resend_notification($notification_id);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } else {
            wp_send_json_success(__('Notificación reenviada', 'wc-installment-payments'));
        }
    }

    /**
     * Send credit approved notification
     */
    private function send_credit_approved_notification($credit, $template_data) {
        $results = array();

        // Send WhatsApp notification
        if (get_option('wc_installment_whatsapp_notifications', 'yes') === 'yes') {
            $whatsapp_result = $this->send_whatsapp_notification($credit, 'credit_approved', $template_data);
            $results['whatsapp'] = $whatsapp_result;
        }

        // Send email notification
        if (get_option('wc_installment_email_notifications', 'yes') === 'yes') {
            $email_result = $this->send_email_notification($credit, 'credit_approved', $template_data);
            $results['email'] = $email_result;
        }

        return $results;
    }

    /**
     * Send credit completed notification
     */
    private function send_credit_completed_notification($credit, $template_data) {
        $results = array();

        // Send WhatsApp notification
        if (get_option('wc_installment_whatsapp_notifications', 'yes') === 'yes') {
            $whatsapp_result = $this->send_whatsapp_notification($credit, 'credit_completed', $template_data);
            $results['whatsapp'] = $whatsapp_result;
        }

        // Send email notification
        if (get_option('wc_installment_email_notifications', 'yes') === 'yes') {
            $email_result = $this->send_email_notification($credit, 'credit_completed', $template_data);
            $results['email'] = $email_result;
        }

        return $results;
    }

    /**
     * Get failed notifications
     */
    public function get_failed_notifications($limit = 50) {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT n.*, c.user_id, CONCAT(c.id, ' - ', u.display_name) as credit_info
                FROM {$this->notifications_table} n
                JOIN {$wpdb->prefix}wc_credits c ON n.credit_id = c.id
                JOIN {$wpdb->users} u ON c.user_id = u.ID
                WHERE n.status = 'failed'
                ORDER BY n.sent_at DESC
                LIMIT %d",
                $limit
            ),
            ARRAY_A
        );
    }
}
