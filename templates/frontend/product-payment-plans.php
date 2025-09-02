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
                            <li><?php _e('Evaluación crediticia aprobatoria', 'wc-installment-payments'); ?></li>
                            <li><?php _e('Documento de identidad vigente', 'wc-installment-payments'); ?></li>
                            <li><?php _e('Comprobante de ingresos', 'wc-installment-payments'); ?></li>
                        </ul>
                    </div>
                    
                    <!-- Action Button -->
                    <div class="wc-installment-plan-actions">
                        <button type="button" 
                                class="wc-installment-select-plan-btn" 
                                data-plan-id="<?php echo esc_attr($plan['id']); ?>"
                                data-plan-name="<?php echo esc_attr($plan['name']); ?>">
                            <?php _e('Seleccionar este Plan', 'wc-installment-payments'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Interactive Calculator -->
    <div class="wc-installment-calculator" id="wc-installment-calculator" style="display: none;">
        <div class="wc-installment-calculator-header">
            <h4><?php _e('Calculadora de Cuotas', 'wc-installment-payments'); ?></h4>
            <button type="button" class="wc-installment-calculator-close" onclick="hideCalculator()">×</button>
        </div>
        
        <div class="wc-installment-calculator-content">
            <div class="wc-installment-calculator-controls">
                <div class="wc-installment-amount-control">
                    <label><?php _e('Monto a financiar:', 'wc-installment-payments'); ?></label>
                    <input type="number" 
                           id="wc-installment-amount" 
                           value="<?php echo esc_attr($product_price); ?>" 
                           min="<?php echo esc_attr(min(array_column($plans, 'min_amount'))); ?>"
                           max="<?php echo esc_attr(max(array_column($plans, 'max_amount'))); ?>"
                           step="1000"
                           onchange="updateCalculations()">
                </div>
                
                <div class="wc-installment-plan-selector">
                    <label><?php _e('Plan de pago:', 'wc-installment-payments'); ?></label>
                    <select id="wc-installment-plan-select" onchange="updateCalculations()">
                        <?php foreach ($plans as $plan): ?>
                            <option value="<?php echo esc_attr($plan['id']); ?>">
                                <?php echo esc_html($plan['name']); ?> (<?php echo $plan['installments_count']; ?> cuotas)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="wc-installment-calculator-results" id="wc-installment-calculator-results">
                <!-- Results will be populated by JavaScript -->
            </div>
        </div>
    </div>

    <!-- Show Calculator Button -->
    <div class="wc-installment-footer">
        <button type="button" 
                class="wc-installment-calculator-btn" 
                onclick="showCalculator()">
            <i class="wc-installment-icon-calculator"></i>
            <?php _e('Calculadora de Cuotas', 'wc-installment-payments'); ?>
        </button>
        
        <div class="wc-installment-help-text">
            <small>
                <?php _e('* Sujeto a aprobación crediticia. Los pagos se procesan automáticamente.', 'wc-installment-payments'); ?>
                <a href="#" onclick="showInstallmentInfo()" class="wc-installment-help-link">
                    <?php _e('Más información', 'wc-installment-payments'); ?>
                </a>
            </small>
        </div>
    </div>

    <!-- Information Modal -->
    <div id="wc-installment-info-modal" class="wc-installment-modal" style="display: none;">
        <div class="wc-installment-modal-overlay" onclick="hideInstallmentInfo()"></div>
        <div class="wc-installment-modal-content">
            <div class="wc-installment-modal-header">
                <h3><?php _e('Información sobre Pagos a Plazos', 'wc-installment-payments'); ?></h3>
                <button type="button" class="wc-installment-modal-close" onclick="hideInstallmentInfo()">×</button>
            </div>
            
            <div class="wc-installment-modal-body">
                <div class="wc-installment-info-section">
                    <h4><?php _e('¿Cómo funciona?', 'wc-installment-payments'); ?></h4>
                    <ol>
                        <li><?php _e('Selecciona el plan de financiamiento que más te convenga', 'wc-installment-payments'); ?></li>
                        <li><?php _e('Completa tu compra normalmente', 'wc-installment-payments'); ?></li>
                        <li><?php _e('Tu solicitud será evaluada automáticamente', 'wc-installment-payments'); ?></li>
                        <li><?php _e('Una vez aprobada, recibirás tu producto y el cronograma de pagos', 'wc-installment-payments'); ?></li>
                        <li><?php _e('Paga cada cuota en las fechas establecidas', 'wc-installment-payments'); ?></li>
                    </ol>
                </div>
                
                <div class="wc-installment-info-section">
                    <h4><?php _e('Métodos de Pago Disponibles', 'wc-installment-payments'); ?></h4>
                    <ul>
                        <li><?php _e('Transferencia bancaria', 'wc-installment-payments'); ?></li>
                        <li><?php _e('Tarjeta de crédito/débito', 'wc-installment-payments'); ?></li>
                        <li><?php _e('PSE (Pagos Seguros en Línea)', 'wc-installment-payments'); ?></li>
                        <li><?php _e('Efecty y otros corresponsales', 'wc-installment-payments'); ?></li>
                    </ul>
                </div>
                
                <div class="wc-installment-info-section">
                    <h4><?php _e('Beneficios', 'wc-installment-payments'); ?></h4>
                    <ul>
                        <li><?php _e('Proceso 100% en línea', 'wc-installment-payments'); ?></li>
                        <li><?php _e('Respuesta inmediata', 'wc-installment-payments'); ?></li>
                        <li><?php _e('Sin papeleos complicados', 'wc-installment-payments'); ?></li>
                        <li><?php _e('Recordatorios automáticos por WhatsApp y email', 'wc-installment-payments'); ?></li>
                        <li><?php _e('Posibilidad de pago anticipado con descuentos', 'wc-installment-payments'); ?></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<script type="text/javascript">
jQuery(document).ready(function($) {
    // Initialize plan selection
    $('.wc-installment-select-plan-btn').on('click', function() {
        var planId = $(this).data('plan-id');
        var planName = $(this).data('plan-name');
        
        // Store selected plan
        $('body').trigger('wc_installment_plan_selected', [planId, planName]);
        
        // Show confirmation
        showPlanSelectionConfirmation(planName);
    });
});

function togglePlanDetails(element) {
    var planElement = $(element).closest('.wc-installment-plan');
    var detailsElement = planElement.find('.wc-installment-plan-details');
    var toggleIcon = planElement.find('.wc-installment-toggle-icon i');
    
    // Close other open plans
    $('.wc-installment-plan').not(planElement).each(function() {
        $(this).find('.wc-installment-plan-details').slideUp();
        $(this).find('.wc-installment-toggle-icon i').removeClass('rotated');
        $(this).removeClass('expanded');
    });
    
    // Toggle current plan
    if (detailsElement.is(':visible')) {
        detailsElement.slideUp();
        toggleIcon.removeClass('rotated');
        planElement.removeClass('expanded');
    } else {
        detailsElement.slideDown();
        toggleIcon.addClass('rotated');
        planElement.addClass('expanded');
    }
}

function showCalculator() {
    $('#wc-installment-calculator').fadeIn();
    updateCalculations();
}

function hideCalculator() {
    $('#wc-installment-calculator').fadeOut();
}

function updateCalculations() {
    var amount = parseFloat($('#wc-installment-amount').val());
    var planId = $('#wc-installment-plan-select').val();
    
    if (!amount || !planId) {
        return;
    }
    
    // Show loading
    $('#wc-installment-calculator-results').html('<div class="wc-installment-loading">Calculando...</div>');
    
    // AJAX call to calculate installments
    $.ajax({
        url: wc_installment_ajax.ajax_url,
        type: 'POST',
        data: {
            action: 'calculate_installments',
            nonce: wc_installment_ajax.nonce,
            amount: amount,
            plan_id: planId
        },
        success: function(response) {
            if (response.success) {
                displayCalculationResults(response.data);
            } else {
                $('#wc-installment-calculator-results').html('<div class="wc-installment-error">' + response.data + '</div>');
            }
        },
        error: function() {
            $('#wc-installment-calculator-results').html('<div class="wc-installment-error">Error al calcular. Intenta nuevamente.</div>');
        }
    });
}

function displayCalculationResults(data) {
    var html = '<div class="wc-installment-calculation-summary">';
    html += '<h5>Resultado del Cálculo</h5>';
    html += '<div class="wc-installment-calc-result-grid">';
    html += '<div class="wc-installment-calc-item">';
    html += '<span class="label">Cuotas:</span>';
    html += '<span class="value">' + data.summary.installment_count + '</span>';
    html += '</div>';
    html += '<div class="wc-installment-calc-item">';
    html += '<span class="label">Pago mensual:</span>';
    html += '<span class="value strong">' + data.summary.formatted_average_installment + '</span>';
    html += '</div>';
    html += '<div class="wc-installment-calc-item">';
    html += '<span class="label">Total a pagar:</span>';
    html += '<span class="value">' + data.summary.formatted_total_amount + '</span>';
    html += '</div>';
    if (data.summary.total_interest > 0) {
        html += '<div class="wc-installment-calc-item">';
        html += '<span class="label">Total intereses:</span>';
        html += '<span class="value interest">' + data.summary.formatted_total_interest + '</span>';
        html += '</div>';
    }
    html += '</div></div>';
    
    $('#wc-installment-calculator-results').html(html);
}

function showInstallmentInfo() {
    $('#wc-installment-info-modal').fadeIn();
}

function hideInstallmentInfo() {
    $('#wc-installment-info-modal').fadeOut();
}

function showPlanSelectionConfirmation(planName) {
    var message = 'Has seleccionado el plan: ' + planName + '\n¿Deseas continuar con la compra?';
    
    if (confirm(message)) {
        // Add to cart with selected plan
        var form = $('form.cart');
        form.append('<input type="hidden" name="wc_installment_plan_id" value="' + planId + '">');
        
        // Trigger add to cart
        form.submit();
    }
}
</script>
