<?php
/**
 * WhatsApp API Integration
 * 
 * Handles WhatsApp messaging through SMS en Línea API
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_WhatsApp_API {

    /**
     * API Configuration
     */
    private $api_config;

    /**
     * Constructor
     */
    public function __construct() {
        $this->api_config = array(
            'endpoint' => get_option('wc_installment_whatsapp_api_endpoint', 'https://whatsapp.smsenlinea.com/api/send/whatsapp'),
            'secret' => get_option('wc_installment_whatsapp_api_secret', ''),
            'account' => get_option('wc_installment_whatsapp_account', ''),
            'timeout' => 30
        );
    }

    /**
     * Send text message
     */
    public function send_text_message($phone, $message, $priority = 'normal') {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', __('WhatsApp API no configurada', 'wc-installment-payments'));
        }

        $phone = $this->format_phone_number($phone);
        if (!$phone) {
            return new WP_Error('invalid_phone', __('Número de teléfono no válido', 'wc-installment-payments'));
        }

        $data = array(
            'secret' => $this->api_config['secret'],
            'account' => $this->api_config['account'],
            'recipient' => $phone,
            'message' => $this->sanitize_message($message),
            'type' => 'text',
            'priority' => $priority
        );

        return $this->make_api_request($data);
    }

    /**
     * Send media message (image, document, etc.)
     */
    public function send_media_message($phone, $media_url, $caption = '', $media_type = 'image') {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', __('WhatsApp API no configurada', 'wc-installment-payments'));
        }

        $phone = $this->format_phone_number($phone);
        if (!$phone) {
            return new WP_Error('invalid_phone', __('Número de teléfono no válido', 'wc-installment-payments'));
        }

        $data = array(
            'secret' => $this->api_config['secret'],
            'account' => $this->api_config['account'],
            'recipient' => $phone,
            'type' => 'media',
            'media_type' => $media_type,
            'media_url' => $media_url,
            'caption' => $this->sanitize_message($caption)
        );

        return $this->make_api_request($data);
    }

    /**
     * Send template message
     */
    public function send_template_message($phone, $template_name, $variables = array()) {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', __('WhatsApp API no configurada', 'wc-installment-payments'));
        }

        $phone = $this->format_phone_number($phone);
        if (!$phone) {
            return new WP_Error('invalid_phone', __('Número de teléfono no válido', 'wc-installment-payments'));
        }

        $data = array(
            'secret' => $this->api_config['secret'],
            'account' => $this->api_config['account'],
            'recipient' => $phone,
            'type' => 'template',
            'template_name' => $template_name,
            'variables' => $variables
        );

        return $this->make_api_request($data);
    }

    /**
     * Send document
     */
    public function send_document($phone, $document_url, $filename, $caption = '') {
        return $this->send_media_message($phone, $document_url, $caption, 'document');
    }

    /**
     * Make API request
     */
    private function make_api_request($data) {
        $response = wp_remote_post($this->api_config['endpoint'], array(
            'timeout' => $this->api_config['timeout'],
            'headers' => array(
                'Content-Type' => 'application/json',
                'User-Agent' => 'WC-Installment-Payments/' . WC_INSTALLMENT_VERSION
            ),
            'body' => json_encode($data)
        ));

        if (is_wp_error($response)) {
            $this->log_error('API Request Failed', $response->get_error_message(), $data);
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        // Log the request and response
        $this->log_api_call($data, $response_code, $response_body);

        if ($response_code !== 200) {
            $error_message = $this->parse_error_message($response_body);
            return new WP_Error('api_error', $error_message, array(
                'response_code' => $response_code,
                'response_body' => $response_body
            ));
        }

        $parsed_response = json_decode($response_body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('json_error', __('Error al parsear respuesta JSON', 'wc-installment-payments'));
        }

        return $parsed_response;
    }

    /**
     * Format phone number for WhatsApp
     */
    private function format_phone_number($phone) {
        // Remove all non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        if (empty($phone)) {
            return false;
        }

        // Add country code if not present (assuming Colombia +57)
        if (strlen($phone) === 10 && substr($phone, 0, 1) === '3') {
            $phone = '57' . $phone;
        } elseif (strlen($phone) === 13 && substr($phone, 0, 3) === '575') {
            // Already has country code
        } else {
            // Try to detect and format other patterns
            if (strlen($phone) < 10) {
                return false; // Too short
            }
        }

        return $phone;
    }

    /**
     * Sanitize message content
     */
    private function sanitize_message($message) {
        // Remove HTML tags
        $message = wp_strip_all_tags($message);
        
        // Convert HTML entities
        $message = html_entity_decode($message, ENT_QUOTES, 'UTF-8');
        
        // Limit message length (WhatsApp limit is 4096 characters)
        if (strlen($message) > 4000) {
            $message = substr($message, 0, 3997) . '...';
        }
        
        return $message;
    }

    /**
     * Parse error message from API response
     */
    private function parse_error_message($response_body) {
        $parsed = json_decode($response_body, true);
        
        if (isset($parsed['error'])) {
            return $parsed['error'];
        }
        
        if (isset($parsed['message'])) {
            return $parsed['message'];
        }
        
        return __('Error desconocido en la API de WhatsApp', 'wc-installment-payments');
    }

    /**
     * Check if API is properly configured
     */
    public function is_configured() {
        return !empty($this->api_config['secret']) && 
               !empty($this->api_config['account']) && 
               !empty($this->api_config['endpoint']);
    }

    /**
     * Test API connection
     */
    public function test_connection() {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', __('WhatsApp API no configurada', 'wc-installment-payments'));
        }

        // Send a simple test message to the configured account
        $test_data = array(
            'secret' => $this->api_config['secret'],
            'account' => $this->api_config['account'],
            'action' => 'test_connection'
        );

        $response = wp_remote_post($this->api_config['endpoint'] . '/test', array(
            'timeout' => 15,
            'headers' => array(
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($test_data)
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code === 200) {
            return true;
        } else {
            $response_body = wp_remote_retrieve_body($response);
            return new WP_Error('connection_failed', $this->parse_error_message($response_body));
        }
    }

    /**
     * Get message status
     */
    public function get_message_status($message_id) {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', __('WhatsApp API no configurada', 'wc-installment-payments'));
        }

        $data = array(
            'secret' => $this->api_config['secret'],
            'account' => $this->api_config['account'],
            'message_id' => $message_id
        );

        $response = wp_remote_get(
            add_query_arg($data, $this->api_config['endpoint'] . '/status'),
            array('timeout' => 15)
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($response_code !== 200) {
            return new WP_Error('status_error', $this->parse_error_message($response_body));
        }

        return json_decode($response_body, true);
    }

    /**
     * Log API calls for debugging
     */
    private function log_api_call($request_data, $response_code, $response_body) {
        if (!WP_DEBUG || !get_option('wc_installment_log_api_calls', false)) {
            return;
        }

        $log_entry = array(
            'timestamp' => current_time('mysql'),
            'request' => array(
                'endpoint' => $this->api_config['endpoint'],
                'data' => $this->sanitize_log_data($request_data)
            ),
            'response' => array(
                'code' => $response_code,
                'body' => $response_body
            )
        );

        error_log('WC Installment WhatsApp API Call: ' . json_encode($log_entry));
    }

    /**
     * Sanitize sensitive data for logging
     */
    private function sanitize_log_data($data) {
        $sanitized = $data;
        
        // Hide sensitive information
        if (isset($sanitized['secret'])) {
            $sanitized['secret'] = '***HIDDEN***';
        }
        
        if (isset($sanitized['recipient'])) {
            $phone = $sanitized['recipient'];
            $sanitized['recipient'] = substr($phone, 0, 3) . '***' . substr($phone, -2);
        }
        
        return $sanitized;
    }

    /**
     * Log errors
     */
    private function log_error($context, $message, $data = null) {
        $log_entry = array(
            'timestamp' => current_time('mysql'),
            'context' => $context,
            'message' => $message,
            'data' => $data ? $this->sanitize_log_data($data) : null
        );

        error_log('WC Installment WhatsApp Error: ' . json_encode($log_entry));
    }

    /**
     * Get account information
     */
    public function get_account_info() {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', __('WhatsApp API no configurada', 'wc-installment-payments'));
        }

        $data = array(
            'secret' => $this->api_config['secret'],
            'account' => $this->api_config['account']
        );

        $response = wp_remote_get(
            add_query_arg($data, $this->api_config['endpoint'] . '/account'),
            array('timeout' => 15)
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($response_code !== 200) {
            return new WP_Error('account_error', $this->parse_error_message($response_body));
        }

        return json_decode($response_body, true);
    }

    /**
     * Get message templates
     */
    public function get_templates() {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', __('WhatsApp API no configurada', 'wc-installment-payments'));
        }

        $data = array(
            'secret' => $this->api_config['secret'],
            'account' => $this->api_config['account']
        );

        $response = wp_remote_get(
            add_query_arg($data, $this->api_config['endpoint'] . '/templates'),
            array('timeout' => 15)
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($response_code !== 200) {
            return new WP_Error('templates_error', $this->parse_error_message($response_body));
        }

        return json_decode($response_body, true);
    }

    /**
     * Validate phone number format
     */
    public function validate_phone($phone) {
        $formatted_phone = $this->format_phone_number($phone);
        
        if (!$formatted_phone) {
            return array(
                'valid' => false,
                'message' => __('Número de teléfono no válido', 'wc-installment-payments')
            );
        }

        // Additional validations
        if (strlen($formatted_phone) < 10 || strlen($formatted_phone) > 15) {
            return array(
                'valid' => false,
                'message' => __('El número de teléfono debe tener entre 10 y 15 dígitos', 'wc-installment-payments')
            );
        }

        return array(
            'valid' => true,
            'formatted' => $formatted_phone,
            'display' => $this->format_phone_for_display($formatted_phone)
        );
    }

    /**
     * Format phone for display
     */
    private function format_phone_for_display($phone) {
        // For Colombian numbers
        if (substr($phone, 0, 2) === '57' && strlen($phone) === 12) {
            return '+57 ' . substr($phone, 2, 3) . ' ' . substr($phone, 5, 3) . ' ' . substr($phone, 8, 4);
        }
        
        // Generic international format
        return '+' . substr($phone, 0, 2) . ' ' . substr($phone, 2);
    }

    /**
     * Schedule message for later delivery
     */
    public function schedule_message($phone, $message, $schedule_time, $message_type = 'text') {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', __('WhatsApp API no configurada', 'wc-installment-payments'));
        }

        $phone = $this->format_phone_number($phone);
        if (!$phone) {
            return new WP_Error('invalid_phone', __('Número de teléfono no válido', 'wc-installment-payments'));
        }

        $data = array(
            'secret' => $this->api_config['secret'],
            'account' => $this->api_config['account'],
            'recipient' => $phone,
            'message' => $this->sanitize_message($message),
            'type' => $message_type,
            'schedule_time' => date('Y-m-d H:i:s', strtotime($schedule_time))
        );

        return $this->make_api_request($data);
    }

    /**
     * Cancel scheduled message
     */
    public function cancel_scheduled_message($message_id) {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', __('WhatsApp API no configurada', 'wc-installment-payments'));
        }

        $data = array(
            'secret' => $this->api_config['secret'],
            'account' => $this->api_config['account'],
            'message_id' => $message_id,
            'action' => 'cancel'
        );

        $response = wp_remote_post($this->api_config['endpoint'] . '/cancel', array(
            'timeout' => 15,
            'headers' => array(
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($data)
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code === 200) {
            return true;
        } else {
            $response_body = wp_remote_retrieve_body($response);
            return new WP_Error('cancel_failed', $this->parse_error_message($response_body));
        }
    }

    /**
     * Get delivery statistics
     */
    public function get_delivery_stats($date_from = null, $date_to = null) {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', __('WhatsApp API no configurada', 'wc-installment-payments'));
        }

        $data = array(
            'secret' => $this->api_config['secret'],
            'account' => $this->api_config['account']
        );

        if ($date_from) {
            $data['date_from'] = date('Y-m-d', strtotime($date_from));
        }

        if ($date_to) {
            $data['date_to'] = date('Y-m-d', strtotime($date_to));
        }

        $response = wp_remote_get(
            add_query_arg($data, $this->api_config['endpoint'] . '/stats'),
            array('timeout' => 15)
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($response_code !== 200) {
            return new WP_Error('stats_error', $this->parse_error_message($response_body));
        }

        return json_decode($response_body, true);
    }

    /**
     * Send bulk messages
     */
    public function send_bulk_messages($recipients, $message, $delay_between = 1) {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', __('WhatsApp API no configurada', 'wc-installment-payments'));
        }

        $results = array();
        $success_count = 0;
        $error_count = 0;

        foreach ($recipients as $phone) {
            $result = $this->send_text_message($phone, $message);
            
            $results[] = array(
                'phone' => $phone,
                'success' => !is_wp_error($result),
                'response' => $result
            );

            if (is_wp_error($result)) {
                $error_count++;
            } else {
                $success_count++;
            }

            // Add delay between messages to avoid rate limiting
            if ($delay_between > 0 && count($recipients) > 1) {
                sleep($delay_between);
            }
        }

        return array(
            'total_sent' => count($recipients),
            'success_count' => $success_count,
            'error_count' => $error_count,
            'results' => $results
        );
    }

    /**
     * Get webhook URL for status updates
     */
    public function get_webhook_url() {
        return add_query_arg(
            array(
                'wc-api' => 'wc_installment_whatsapp_webhook'
            ),
            home_url('/')
        );
    }

    /**
     * Process webhook callback
     */
    public function process_webhook($data) {
        // Validate webhook data
        if (!isset($data['message_id']) || !isset($data['status'])) {
            return new WP_Error('invalid_webhook', __('Datos de webhook inválidos', 'wc-installment-payments'));
        }

        // Update notification status in database
        global $wpdb;
        
        $wpdb->update(
            $wpdb->prefix . 'wc_installment_notifications',
            array(
                'status' => sanitize_text_field($data['status']),
                'response_data' => json_encode($data)
            ),
            array('id' => intval($data['message_id'])),
            array('%s', '%s'),
            array('%d')
        );

        do_action('wc_installment_whatsapp_status_updated', $data);

        return true;
    }

    /**
     * Get rate limits information
     */
    public function get_rate_limits() {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', __('WhatsApp API no configurada', 'wc-installment-payments'));
        }

        $data = array(
            'secret' => $this->api_config['secret'],
            'account' => $this->api_config['account']
        );

        $response = wp_remote_get(
            add_query_arg($data, $this->api_config['endpoint'] . '/limits'),
            array('timeout' => 15)
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($response_code !== 200) {
            return new WP_Error('limits_error', $this->parse_error_message($response_body));
        }

        return json_decode($response_body, true);
    }

    /**
     * Update configuration
     */
    public function update_config($config) {
        if (isset($config['endpoint'])) {
            $this->api_config['endpoint'] = $config['endpoint'];
            update_option('wc_installment_whatsapp_api_endpoint', $config['endpoint']);
        }

        if (isset($config['secret'])) {
            $this->api_config['secret'] = $config['secret'];
            update_option('wc_installment_whatsapp_api_secret', $config['secret']);
        }

        if (isset($config['account'])) {
            $this->api_config['account'] = $config['account'];
            update_option('wc_installment_whatsapp_account', $config['account']);
        }

        if (isset($config['timeout'])) {
            $this->api_config['timeout'] = intval($config['timeout']);
        }
    }

    /**
     * Get configuration for display
     */
    public function get_config() {
        return array(
            'endpoint' => $this->api_config['endpoint'],
            'account' => $this->api_config['account'],
            'is_configured' => $this->is_configured(),
            'timeout' => $this->api_config['timeout']
        );
    }
}
