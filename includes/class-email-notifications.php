<?php
/**
 * Email Notifications
 * 
 * Basic email notifications class
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Email_Notifications {

    /**
     * Constructor
     */
    public function __construct() {
        // Constructor logic here
    }

    /**
     * Send notification email
     */
    public function send_notification($to, $subject, $message, $template_data = array()) {
        // Enhanced email headers
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>'
        );

        // Simple template wrapper
        $html_message = $this->wrap_message($message, $template_data);

        return wp_mail($to, $subject, $html_message, $headers);
    }

    /**
     * Wrap message in basic HTML template
     */
    private function wrap_message($message, $template_data = array()) {
        $site_name = get_bloginfo('name');
        $site_url = home_url();

        $html = '<!DOCTYPE html>';
        $html .= '<html><head><meta charset="UTF-8"></head><body>';
        $html .= '<div style="max-width: 600px; margin: 0 auto; font-family: Arial, sans-serif;">';
        $html .= '<div style="background: #2E86AB; color: white; padding: 20px; text-align: center;">';
        $html .= '<h1>' . $site_name . '</h1>';
        $html .= '</div>';
        $html .= '<div style="padding: 20px; background: #f9f9f9;">';
        $html .= wpautop($message);
        $html .= '</div>';
        $html .= '<div style="background: #e9e9e9; padding: 10px; text-align: center; font-size: 12px;">';
        $html .= '<p>Este es un mensaje automático de <a href="' . $site_url . '">' . $site_name . '</a></p>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</body></html>';

        return $html;
    }
}
