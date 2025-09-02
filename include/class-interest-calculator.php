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
        $this->payment_plans = new WC_Payment_Plans();
    }

    /**
     * Calculate installments for a given amount and plan
     */
    public function calculate_installments($amount, $plan_id, $start_date = null) {
        $plan = $this->payment_plans->get_plan($plan_id);
        
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
                'formatted_amount' => wc_price($installment_amount),
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
        $total_payment = round($pmt * $plan['installments_count'], 2);

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
                'formatted_amount' => wc_price($pmt),
                'formatted_principal' => wc_price($principal_payment),
                'formatted_interest' => wc_price($interest_payment),
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
