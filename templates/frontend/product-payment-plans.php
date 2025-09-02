<?php
/**
 * Product Payment Plans Template
 * 
 * Shows available payment plans on product page
 */

if (!defined('ABSPATH')) {
    exit;
}

if (empty($plans)) {
    return;
}

$calculator = new WC_Interest_Calculator();
$product_price = $product->get_price();
?>

<div class="wc-installment-payment-plans" id="wc-installment-payment-plans">
    <div class="wc-installment-header">
        <h3 class="wc-installment-title">
            <i class="wc-installment-icon-credit-card"></i>
            <?php _e('Planes de Financiamiento Disponibles', 'wc-installment-payments'); ?>
        </h3>
        <p class="wc-installment-subtitle">
            <?php _e('Compra ahora y paga en cómodas cuotas', 'wc-installment-payments'); ?>
        </p>
    </div>

    <div class="wc-installment-plans-container">
        <?php foreach ($plans as $index => $plan): 
            $calculation = $calculator->calculate_installments($product_price, $plan['id']);
            if (is_wp_error($calculation)) continue;
            
            $installments = $calculation['installments'];
            $summary = $calculation['summary'];
            $is_recommended = $index === 0; // First plan as recommended
        ?>
        
        <div class="wc-installment-plan <?php echo $is_recommended ? 'recommended' : ''; ?>" 
             data-plan-id="<?php echo esc_attr($plan['id']); ?>"
             data-installments="<?php echo esc_attr($plan['installments_count']); ?>"
             data-interest-rate="<?php echo esc_attr($plan['interest_rate']); ?>">
            
            <?php if ($is_recommended): ?>
                <div class="wc-installment-recommended-badge">
                    <?php _e('Recomendado', 'wc-installment-payments'); ?>
                </div>
            <?php endif; ?>
            
            <div class="wc-installment-plan-header" onclick="togglePlanDetails(this)">
                <div class="wc-installment-plan-main">
                    <div class="wc-installment-plan-title">
                        <h4><?php echo esc_html($plan['name']); ?></h4>
                        <?php if ($plan['interest_rate'] == 0): ?>
                            <span class="wc-installment-no-interest"><?php _e('Sin interés', 'wc-installment-payments'); ?></span>
                        <?php else: ?>
                            <span class="wc-installment-interest-rate"><?php echo $plan['interest_rate']; ?>% <?php _e('anual', 'wc-installment-payments'); ?></span>
                        <?php endif; ?>
                    </div>
                    
                    <div class="wc-installment-plan-summary">
                        <div class="wc-installment-monthly-payment">
                            <span class="wc-installment-label"><?php echo $plan['installments_count']; ?> <?php _e('cuotas de', 'wc-installment-payments'); ?></span>
                            <span class="wc-installment-amount"><?php echo wc_price($summary['average_installment']); ?></span>
                        </div>
                        
                        <?php if ($summary['total_interest'] > 0): ?>
                            <div class="wc-installment-total">
                                <span class="wc-installment-label"><?php _e('Total:', 'wc-installment-payments'); ?></span>
                                <span class="wc-installment-total-amount"><?php echo $summary['formatted_total_amount']; ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="wc-installment-toggle-icon">
                    <i class="wc-installment-icon-chevron-down"></i>
                </div>
            </div>
            
            <div class="wc-installment-plan-details" style="display: none;">
                <div class="wc-installment-details-content">
                    
                    <!-- Payment Schedule -->
                    <div class="wc-installment-schedule">
                        <h5><?php _e('Cronograma de Pagos', 'wc-installment-payments'); ?></h5>
                        <div class="wc-installment-schedule-list">
                            <?php foreach ($installments as $installment): ?>
                                <div class="wc-installment-schedule-item">
                                    <div class="wc-installment-schedule-number">
                                        <?php echo $installment['number']; ?>
                                    </div>
                                    <div class="wc-installment-schedule-info">
                                        <div class="wc-installment-schedule-amount">
                                            <?php echo $installment['formatted_amount']; ?>
                                        </div>
                                        <div class="wc-installment-schedule-date">
                                            <?php echo $installment['formatted_due_date']; ?>
                                        </div>
                                        <?php if ($installment['interest'] > 0): ?>
                                            <div class="wc-installment-schedule-breakdown">
                                                <?php printf(
                                                    __('Capital: %s | Interés: %s', 'wc-installment-payments'),
                                                    $installment['formatted_principal'],
                                                    $installment['formatted_interest']
                                                ); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- Summary Information -->
                    <div class="wc-installment-summary">
                        <h5><?php _e('Resumen del Financiamiento', 'wc-installment-payments'); ?></h5>
                        <div class="wc-installment-summary-grid">
                            <div class="wc-installment-summary-item">
                                <span class="wc-installment-summary-label"><?php _e('Precio Contado:', 'wc-installment-payments'); ?></span>
                                <span class="wc-installment-summary-value"><?php echo $summary['formatted_original_amount']; ?></span>
                            </div>
                            
                            <div class="wc-installment-summary-item">
                                <span class="wc-installment-summary-label"><?php _e('Total Financiado:', 'wc-installment-payments'); ?></span>
                                <span class="wc-installment-summary-value"><?php echo $summary['formatted_total_amount']; ?></span>
                            </div>
                            
                            <?php if ($summary['total_interest'] > 0): ?>
                                <div class="wc-installment-summary-item">
                                    <span class="wc-installment-summary-label"><?php _e('Total Intereses:', 'wc-installment-payments'); ?></span>
                                    <span class="wc-installment-summary-value interest-cost"><?php echo $summary['formatted_total_interest']; ?></span>
                                </div>
                                
                                <div class="wc-installment-summary-item">
                                    <span class="wc-installment-summary-label"><?php _e('Costo Adicional:', 'wc-installment-payments'); ?></span>
                                    <span class="wc-installment-summary-value additional-cost">
                                        <?php echo wc_price($summary['total_interest']); ?>
                                        (<?php echo round(($summary['total_interest'] / $summary['original_amount']) * 100, 2); ?>%)
                                    </span>
                                </div>
                            <?php else: ?>
                                <div class="wc-installment-summary-item no-interest">
                                    <span class="wc-installment-summary-label"><?php _e('Beneficio:', 'wc-installment-payments'); ?></span>
                                    <span class="wc-installment-summary-value benefit">
                                        <?php _e('Sin intereses - El mismo precio!', 'wc-installment-payments'); ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Terms and Conditions -->
                    <?php if (!empty($plan['conditions'])): ?>
                        <div class="wc-installment-conditions">
                            <h5><?php _e('Términos y Condiciones', 'wc-installment-payments'); ?></h5>
                            <div class="wc-installment-conditions-content">
                                <?php echo wp_kses_post($plan['conditions']); ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Requirements -->
                    <div class="wc-installment-requirements">
                        <h5><?php _e('Requisitos', 'wc-installment-payments'); ?></h5>
                        <ul class="wc-installment-requirements-list">
                            <li><?php printf(__('Monto mínimo: %s', 'wc-installment-payments'), $plan['formatted_min_amount']); ?></li>
                            <li><?php printf(__('Monto máximo: %s', 'wc-installment-payments'), $plan['formatted_max_amount']); ?></li>
                            <li><?php _e('Evaluación crediticia aprobatoria', '
