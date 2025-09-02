<?php
/**
 * Interest Calculator
 * 
 * Handles all interest calculations for installment payments
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Interest_Calculator {

    /**
     * Payment plans instance
     */
    private $payment_plans;

    /**
     * Constructor
     */
    public function __construct() {
        // Initialize payment plans only when needed
        $this->payment_plans = null;
    }

    /**
     * Get payment plans instance (lazy loading)
     */
    private function get_payment_plans() {
        if ($this->payment_plans === null && class_exists('WC_Payment_Plans')) {
            $this->payment_plans = new WC_Payment_Plans();
        }
        return $this->payment_plans;
    }

    /**
     * Calculate installments for a given amount and plan
     */
    public function calculate_installments($amount, $plan_id, $start_date = null) {
        $payment_plans = $this->get_payment_plans();
        if (!$payment_plans) {
            return new WP_Error('payment_plans_unavailable', __('Sistema de planes de pago no disponible', 'wc-installment-payments'));
        }

        $plan = $payment_plans->get_plan($plan_id);
        
        if (!$plan) {
            return new WP_Error('invalid_plan', __('Plan de pago no válido', 'wc-installment-payments'));
        }

        if ($amount < $plan['min_amount'] || $amount > $plan['max_amount']) {
            return new WP_Error('amount_out_of_range', __('El monto está fuera del rango permitido para este plan', 'wc-installment-payments'));
        }

        $start_date = $start_date ?: current_time('Y-m-d');
        $installments = array();

        // Calculate based on interest type
        if ($plan['interest_rate'] <= 0) {
            // No interest - simple division
            $installments = $this->calculate_simple_installments($amount, $plan, $start_date);
        } else {
            // With interest - compound calculation
            $installments = $this->calculate_compound_installments($amount, $plan, $start_date);
        }

        // Add summary information
        $summary = $this->calculate_summary($installments, $amount);
        
        return array(
            'plan' => $plan,
            'installments' => $installments,
            'summary' => $summary
        );
    }

    /**
     * Calculate simple installments (no interest)
     */
    private function calculate_simple_installments($amount, $plan, $start_date) {
        $installments = array();
        $installment_amount = round($amount / $plan['installments_count'], 2);
        $remaining = $amount;

        for ($i = 1; $i <= $plan['installments_count']; $i++) {
            $due_date = $this->calculate_due_date($start_date, $i);
            
            // Adjust last installment for rounding differences
            if ($i == $plan['installments_count']) {
                $installment_amount = $remaining;
            }

            $installments[] = array(
                'number' => $i,
                'amount' => $installment_amount,
                'principal' => $installment_amount,
                'interest' => 0.00,
                'due_date' => $due_date,
                'formatted_amount' => $this->format_price($installment_amount),
                'formatted_principal' => $this->format_price($installment_amount),
                'formatted_interest' => $this->format_price(0),
                'formatted_due_date' => $this->format_date($due_date)
            );

            $remaining -= $installment_amount;
        }

        return $installments;
    }

    /**
     * Calculate compound installments (with interest)
     */
    private function calculate_compound_installments($amount, $plan, $start_date) {
        $installments = array();
        $monthly_rate = $this->get_monthly_interest_rate($plan['interest_rate']);
        
        // Calculate fixed payment amount using PMT formula
        $pmt = $this->calculate_pmt($amount, $monthly_rate, $plan['installments_count']);
        
        $remaining_balance = $amount;

        for ($i = 1; $i <= $plan['installments_count']; $i++) {
            $due_date = $this->calculate_due_date($start_date, $i);
            
            // Interest for this period
            $interest_payment = round($remaining_balance * $monthly_rate, 2);
            
            // Principal payment
            $principal_payment = round($pmt - $interest_payment, 2);
            
            // Adjust last payment for rounding differences
            if ($i == $plan['installments_count']) {
                $principal_payment = $remaining_balance;
                $pmt = $principal_payment + $interest_payment;
            }

            $installments[] = array(
                'number' => $i,
                'amount' => round($pmt, 2),
                'principal' => $principal_payment,
                'interest' => $interest_payment,
                'remaining_balance' => max(0, $remaining_balance - $principal_payment),
                'due_date' => $due_date,
                'formatted_amount' => $this->format_price($pmt),
                'formatted_principal' => $this->format_price($principal_payment),
                'formatted_interest' => $this->format_price($interest_payment),
                'formatted_due_date' => $this->format_date($due_date)
            );

            $remaining_balance -= $principal_payment;
        }

        return $installments;
    }

    /**
     * Calculate PMT (Payment) using the formula
     * PMT = PV * (r * (1 + r)^n) / ((1 + r)^n - 1)
     */
    private function calculate_pmt($present_value, $rate, $periods) {
        if ($rate == 0) {
            return $present_value / $periods;
        }

        $factor = pow(1 + $rate, $periods);
        return ($present_value * $rate * $factor) / ($factor - 1);
    }

    /**
     * Get monthly interest rate from annual rate
     */
    private function get_monthly_interest_rate($annual_rate) {
        return $annual_rate / 100 / 12; // Convert percentage to decimal and divide by 12
    }

    /**
     * Calculate due date for installment
     */
    private function calculate_due_date($start_date, $installment_number) {
        return date('Y-m-d', strtotime($start_date . " +{$installment_number} month"));
    }

    /**
     * Calculate summary information
     */
    private function calculate_summary($installments, $original_amount) {
        $total_amount = array_sum(array_column($installments, 'amount'));
        $total_interest = array_sum(array_column($installments, 'interest'));
        $average_installment = $total_amount / count($installments);

        return array(
            'original_amount' => $original_amount,
            'total_amount' => $total_amount,
            'total_interest' => $total_interest,
            'average_installment' => $average_installment,
            'installment_count' => count($installments),
            'formatted_original_amount' => $this->format_price($original_amount),
            'formatted_total_amount' => $this->format_price($total_amount),
            'formatted_total_interest' => $this->format_price($total_interest),
            'formatted_average_installment' => $this->format_price($average_installment),
            'savings_vs_cash' => $total_interest > 0 ? -$total_interest : 0,
            'formatted_savings_vs_cash' => $total_interest > 0 ? 
                '-' . $this->format_price($total_interest) : 
                $this->format_price(0)
        );
    }

    /**
     * Calculate late fee
     */
    public function calculate_late_fee($amount, $days_overdue) {
        $late_fee_percentage = floatval(get_option('wc_installment_late_fee_percentage', 2.5));
        $max_late_fee = floatval(get_option('wc_installment_max_late_fee', 50000));
        $grace_period = intval(get_option('wc_installment_grace_period_days', 5));

        // No late fee during grace period
        if ($days_overdue <= $grace_period) {
            return 0;
        }

        // Calculate late fee as percentage of overdue amount
        $late_fee = ($amount * $late_fee_percentage / 100) * ($days_overdue - $grace_period);
        
        // Apply maximum late fee limit
        return min($late_fee, $max_late_fee);
    }

    /**
     * Calculate early payment discount
     */
    public function calculate_early_payment($credit_id) {
        global $wpdb;

        // Get credit information
        $credit = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}wc_credits WHERE id = %d", $credit_id),
            ARRAY_A
        );

        if (!$credit) {
            return new WP_Error('credit_not_found', __('Crédito no encontrado', 'wc-installment-payments'));
        }

        // Get pending installments
        $pending_installments = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wc_installments 
                WHERE credit_id = %d AND status = 'pending' 
                ORDER BY due_date ASC",
                $credit_id
            ),
            ARRAY_A
        );

        if (empty($pending_installments)) {
            return new WP_Error('no_pending_installments', __('No hay cuotas pendientes', 'wc-installment-payments'));
        }

        $total_pending = array_sum(array_column($pending_installments, 'amount'));
        $total_interest_remaining = array_sum(array_column($pending_installments, 'interest_amount'));
        
        // Early payment discount (e.g., 50% of remaining interest)
        $discount_percentage = floatval(get_option('wc_installment_early_payment_discount', 50));
        $discount_amount = $total_interest_remaining * ($discount_percentage / 100);
        
        $amount_to_pay = $total_pending - $discount_amount;
        $savings = $discount_amount;

        return array(
            'credit_id' => $credit_id,
            'total_pending' => $total_pending,
            'discount_amount' => $discount_amount,
            'amount_to_pay' => $amount_to_pay,
            'savings' => $savings,
            'formatted_total_pending' => $this->format_price($total_pending),
            'formatted_discount_amount' => $this->format_price($discount_amount),
            'formatted_amount_to_pay' => $this->format_price($amount_to_pay),
            'formatted_savings' => $this->format_price($savings),
            'pending_installments' => $pending_installments
        );
    }

    /**
     * Format price using WooCommerce format
     */
    private function format_price($amount) {
        if (function_exists('wc_price')) {
            return wc_price($amount);
        }
        
        // Fallback formatting
        return ' . number_format($amount, 2, '.', ',');
    }

    /**
     * Format date
     */
    private function format_date($date) {
        if (function_exists('date_i18n')) {
            return date_i18n(get_option('date_format'), strtotime($date));
        }
        
        return date('d/m/Y', strtotime($date));
    }

    /**
     * Get payment plan by ID (fallback method)
     */
    public function get_plan_by_id($plan_id) {
        global $wpdb;
        
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}wc_payment_plans WHERE id = %d", $plan_id),
            ARRAY_A
        );
    }

    /**
     * Validate calculation inputs
     */
    public function validate_calculation_inputs($amount, $plan_id) {
        if (!is_numeric($amount) || $amount <= 0) {
            return new WP_Error('invalid_amount', __('Monto inválido', 'wc-installment-payments'));
        }

        if (!is_numeric($plan_id) || $plan_id <= 0) {
            return new WP_Error('invalid_plan', __('Plan inválido', 'wc-installment-payments'));
        }

        return true;
    }

    /**
     * Get available payment methods for installments
     */
    public function get_payment_methods() {
        return array(
            'bank_transfer' => __('Transferencia Bancaria', 'wc-installment-payments'),
            'pse' => __('PSE', 'wc-installment-payments'),
            'credit_card' => __('Tarjeta de Crédito', 'wc-installment-payments'),
            'debit_card' => __('Tarjeta Débito', 'wc-installment-payments'),
            'efecty' => __('Efecty', 'wc-installment-payments')
        );
    }

    /**
     * Calculate affordability based on income
     */
    public function calculate_affordability($monthly_income, $monthly_expenses, $requested_installment) {
        $available_income = $monthly_income - $monthly_expenses;
        $affordability_ratio = $available_income > 0 ? ($requested_installment / $available_income) : 1;
        
        return array(
            'available_income' => $available_income,
            'affordability_ratio' => $affordability_ratio,
            'is_affordable' => $affordability_ratio <= 0.3, // 30% rule
            'max_affordable_installment' => $available_income * 0.3
        );
    }

    /**
     * Calculate credit score impact
     */
    public function calculate_credit_score_requirements($amount, $installments_count) {
        // Simple credit score requirements based on amount and term
        if ($amount <= 100000) {
            $min_score = 600;
        } elseif ($amount <= 500000) {
            $min_score = 650;
        } elseif ($amount <= 1000000) {
            $min_score = 700;
        } else {
            $min_score = 750;
        }

        // Adjust for longer terms
        if ($installments_count > 12) {
            $min_score += 25;
        } elseif ($installments_count > 6) {
            $min_score += 10;
        }

        return array(
            'minimum_score' => $min_score,
            'recommended_score' => $min_score + 50,
            'requirements' => $this->get_credit_requirements($min_score)
        );
    }

    /**
     * Get credit requirements based on score
     */
    private function get_credit_requirements($min_score) {
        $requirements = array(
            __('Documento de identidad vigente', 'wc-installment-payments'),
            __('Comprobante de ingresos reciente', 'wc-installment-payments'),
            __('Referencias comerciales', 'wc-installment-payments')
        );

        if ($min_score >= 700) {
            $requirements[] = __('Experiencia crediticia demostrable', 'wc-installment-payments');
            $requirements[] = __('Estabilidad laboral mínima 6 meses', 'wc-installment-payments');
        }

        if ($min_score >= 750) {
            $requirements[] = __('Ingresos demostrables superiores a 2 SMMLV', 'wc-installment-payments');
            $requirements[] = __('Referencias bancarias', 'wc-installment-payments');
        }

        return $requirements;
    }

    /**
     * Generate payment schedule PDF data
     */
    public function generate_payment_schedule_data($installments, $credit_info) {
        $schedule_data = array(
            'credit_info' => $credit_info,
            'installments' => $installments,
            'summary' => array(
                'total_installments' => count($installments),
                'total_amount' => array_sum(array_column($installments, 'amount')),
                'total_interest' => array_sum(array_column($installments, 'interest')),
                'first_payment_date' => $installments[0]['due_date'] ?? null,
                'last_payment_date' => end($installments)['due_date'] ?? null
            ),
            'terms_and_conditions' => $this->get_terms_and_conditions(),
            'generated_at' => current_time('mysql')
        );

        return $schedule_data;
    }

    /**
     * Get terms and conditions for payments
     */
    private function get_terms_and_conditions() {
        return array(
            __('Los pagos deben realizarse en las fechas establecidas', 'wc-installment-payments'),
            __('Pagos tardíos generan intereses moratorios', 'wc-installment-payments'),
            __('Se permite pago anticipado con descuento en intereses', 'wc-installment-payments'),
            __('La mora superior a 30 días puede afectar el historial crediticio', 'wc-installment-payments'),
            __('Se enviaran recordatorios automáticos por WhatsApp y email', 'wc-installment-payments')
        );
    }

    /**
     * Calculate risk assessment
     */
    public function calculate_risk_assessment($customer_data, $amount, $term) {
        $risk_factors = array();
        $risk_score = 0;

        // Amount risk
        if ($amount > 1000000) {
            $risk_factors[] = 'high_amount';
            $risk_score += 20;
        }

        // Term risk
        if ($term > 12) {
            $risk_factors[] = 'long_term';
            $risk_score += 15;
        }

        // Customer history risk
        if (isset($customer_data['previous_defaults']) && $customer_data['previous_defaults'] > 0) {
            $risk_factors[] = 'previous_defaults';
            $risk_score += 30;
        }

        // Income stability
        if (isset($customer_data['employment_months']) && $customer_data['employment_months'] < 6) {
            $risk_factors[] = 'employment_instability';
            $risk_score += 25;
        }

        $risk_level = $this->get_risk_level($risk_score);

        return array(
            'risk_score' => $risk_score,
            'risk_level' => $risk_level,
            'risk_factors' => $risk_factors,
            'approval_recommendation' => $risk_score <= 30,
            'required_guarantees' => $this->get_required_guarantees($risk_level)
        );
    }

    /**
     * Get risk level based on score
     */
    private function get_risk_level($score) {
        if ($score <= 15) return 'low';
        if ($score <= 30) return 'medium';
        if ($score <= 50) return 'high';
        return 'very_high';
    }

    /**
     * Get required guarantees based on risk level
     */
    private function get_required_guarantees($risk_level) {
        $guarantees = array();

        switch ($risk_level) {
            case 'low':
                $guarantees = array(__('Ninguna garantía adicional requerida', 'wc-installment-payments'));
                break;
            case 'medium':
                $guarantees = array(__('Referencias comerciales verificables', 'wc-installment-payments'));
                break;
            case 'high':
                $guarantees = array(
                    __('Referencias comerciales verificables', 'wc-installment-payments'),
                    __('Codeudor con ingresos demostrables', 'wc-installment-payments')
                );
                break;
            case 'very_high':
                $guarantees = array(
                    __('Codeudor con ingresos demostrables', 'wc-installment-payments'),
                    __('Garantía real o prendaria', 'wc-installment-payments'),
                    __('Seguro de vida', 'wc-installment-payments')
                );
                break;
        }

        return $guarantees;
    }
}
