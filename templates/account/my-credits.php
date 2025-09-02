<?php
/**
 * My Credits Template
 * 
 * Shows customer's credits and payment history
 */

if (!defined('ABSPATH')) {
    exit;
}

$user_id = get_current_user_id();
$credit_manager = new WC_Credit_Manager();
$credits = $credit_manager->get_user_credits($user_id);

// Get filters from URL
$status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
$search_filter = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
?>

<div class="wc-installment-my-credits">
    <!-- Header -->
    <div class="wc-installment-credits-header">
        <h2><?php _e('Mis Créditos', 'wc-installment-payments'); ?></h2>
        <p class="wc-installment-credits-description">
            <?php _e('Aquí puedes ver todos tus créditos activos, historial de pagos y próximos vencimientos.', 'wc-installment-payments'); ?>
        </p>
    </div>

    <!-- Summary Cards -->
    <div class="wc-installment-summary-cards">
        <?php
        $active_credits = array_filter($credits, function($credit) { return $credit['status'] === 'active'; });
        $completed_credits = array_filter($credits, function($credit) { return $credit['status'] === 'completed'; });
        $overdue_credits = array_filter($credits, function($credit) { return $credit['status'] === 'overdue'; });
        
        $total_pending = array_sum(array_column($active_credits, 'pending_amount'));
        $total_paid = array_sum(array_column($credits, 'paid_amount'));
        ?>
        
        <div class="wc-installment-summary-card active">
            <div class="wc-installment-card-icon">
                <i class="wc-installment-icon-clock"></i>
            </div>
            <div class="wc-installment-card-content">
                <h3><?php echo count($active_credits); ?></h3>
                <p><?php _e('Créditos Activos', 'wc-installment-payments'); ?></p>
                <small><?php echo wc_price($total_pending); ?> <?php _e('pendientes', 'wc-installment-payments'); ?></small>
            </div>
        </div>
        
        <div class="wc-installment-summary-card completed">
            <div class="wc-installment-card-icon">
                <i class="wc-installment-icon-check"></i>
            </div>
            <div class="wc-installment-card-content">
                <h3><?php echo count($completed_credits); ?></h3>
                <p><?php _e('Créditos Completados', 'wc-installment-payments'); ?></p>
                <small><?php echo wc_price($total_paid); ?> <?php _e('pagados', 'wc-installment-payments'); ?></small>
            </div>
        </div>
        
        <?php if (count($overdue_credits) > 0): ?>
        <div class="wc-installment-summary-card overdue">
            <div class="wc-installment-card-icon">
                <i class="wc-installment-icon-warning"></i>
            </div>
            <div class="wc-installment-card-content">
                <h3><?php echo count($overdue_credits); ?></h3>
                <p><?php _e('Pagos Vencidos', 'wc-installment-payments'); ?></p>
                <small><?php _e('Requiere atención', 'wc-installment-payments'); ?></small>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Filters and Search -->
    <div class="wc-installment-filters">
        <form class="wc-installment-filter-form" method="get">
            <div class="wc-installment-filter-group">
                <label for="status-filter"><?php _e('Estado:', 'wc-installment-payments'); ?></label>
                <select name="status" id="status-filter">
                    <option value=""><?php _e('Todos los estados', 'wc-installment-payments'); ?></option>
                    <option value="active" <?php selected($status_filter, 'active'); ?>><?php _e('Activo', 'wc-installment-payments'); ?></option>
                    <option value="completed" <?php selected($status_filter, 'completed'); ?>><?php _e('Completado', 'wc-installment-payments'); ?></option>
                    <option value="overdue" <?php selected($status_filter, 'overdue'); ?>><?php _e('Vencido', 'wc-installment-payments'); ?></option>
                    <option value="pending" <?php selected($status_filter, 'pending'); ?>><?php _e('Pendiente', 'wc-installment-payments'); ?></option>
                </select>
            </div>
            
            <div class="wc-installment-filter-group">
                <label for="search-filter"><?php _e('Buscar:', 'wc-installment-payments'); ?></label>
                <input type="text" name="search" id="search-filter" value="<?php echo esc_attr($search_filter); ?>" 
                       placeholder="<?php esc_attr_e('Número de orden, producto...', 'wc-installment-payments'); ?>">
            </div>
            
            <button type="submit" class="wc-installment-filter-btn">
                <?php _e('Filtrar', 'wc-installment-payments'); ?>
            </button>
            
            <?php if ($status_filter || $search_filter): ?>
                <a href="<?php echo esc_url(remove_query_arg(array('status', 'search'))); ?>" class="wc-installment-clear-filters">
                    <?php _e('Limpiar filtros', 'wc-installment-payments'); ?>
                </a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Credits List -->
    <?php if (empty($credits)): ?>
        <div class="wc-installment-no-credits">
            <div class="wc-installment-no-credits-icon">
                <i class="wc-installment-icon-credit-card"></i>
            </div>
            <h3><?php _e('No tienes créditos aún', 'wc-installment-payments'); ?></h3>
            <p><?php _e('Cuando realices compras a plazos, aparecerán aquí.', 'wc-installment-payments'); ?></p>
            <a href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>" class="wc-installment-shop-btn">
                <?php _e('Explorar Productos', 'wc-installment-payments'); ?>
            </a>
        </div>
    <?php else: ?>
        <div class="wc-installment-credits-list">
            <?php foreach ($credits as $credit): ?>
                <?php
                // Apply filters
                if ($status_filter && $credit['status'] !== $status_filter) {
                    continue;
                }
                
                if ($search_filter) {
                    $searchable = strtolower($credit['order_id'] . ' ' . ($credit['metadata']['order_info']['order_number'] ?? ''));
                    if (strpos($searchable, strtolower($search_filter)) === false) {
                        continue;
                    }
                }
                
                $progress_percentage = $credit['total_amount'] > 0 ? 
                    ($credit['paid_amount'] / $credit['total_amount']) * 100 : 0;
                
                $next_installment = null;
                if ($credit['status'] === 'active') {
                    $installments = $credit_manager->get_credit_installments($credit['id'], 'pending');
                    $next_installment = !empty($installments) ? $installments[0] : null;
                }
                ?>
                
                <div class="wc-installment-credit-card <?php echo esc_attr($credit['status']); ?>">
                    <div class="wc-installment-credit-header">
                        <div class="wc-installment-credit-info">
                            <h4>
                                <?php echo esc_html($credit['plan_name']); ?>
                                <span class="wc-installment-credit-id">#<?php echo esc_html($credit['id']); ?></span>
                            </h4>
                            <div class="wc-installment-credit-meta">
                                <?php if (isset($credit['metadata']['order_info']['order_number'])): ?>
                                    <?php printf(__('Orden: %s', 'wc-installment-payments'), esc_html($credit['metadata']['order_info']['order_number'])); ?> | 
                                <?php endif; ?>
                                <?php echo date_i18n(get_option('date_format'), strtotime($credit['created_at'])); ?>
                            </div>
                        </div>
                        
                        <div class="wc-installment-credit-status-container">
                            <span class="wc-installment-credit-status <?php echo esc_attr($credit['status']); ?>">
                                <?php
                                switch ($credit['status']) {
                                    case 'active':
                                        _e('Activo', 'wc-installment-payments');
                                        break;
                                    case 'completed':
                                        _e('Completado', 'wc-installment-payments');
                                        break;
                                    case 'overdue':
                                        _e('Vencido', 'wc-installment-payments');
                                        break;
                                    case 'pending':
                                        _e('Pendiente', 'wc-installment-payments');
                                        break;
                                    default:
                                        echo esc_html(ucfirst($credit['status']));
                                }
                                ?>
                            </span>
                        </div>
                    </div>
                    
                    <!-- Progress Bar -->
                    <div class="wc-installment-credit-progress">
                        <div class="wc-installment-progress-info">
                            <span><?php printf(__('Progreso: %s%%', 'wc-installment-payments'), round($progress_percentage, 1)); ?></span>
                            <span><?php echo esc_html($credit['installments_summary']['paid']); ?>/<?php echo esc_html($credit['installments_summary']['total']); ?> <?php _e('cuotas', 'wc-installment-payments'); ?></span>
                        </div>
                        <div class="wc-installment-progress-bar">
                            <div class="wc-installment-progress-fill" style="width: <?php echo esc_attr($progress_percentage); ?>%"></div>
                        </div>
                    </div>
                    
                    <!-- Amounts -->
                    <div class="wc-installment-credit-amounts">
                        <div class="wc-installment-amount-item">
                            <div class="wc-installment-amount-label"><?php _e('Total', 'wc-installment-payments'); ?></div>
                            <div class="wc-installment-amount-value"><?php echo $credit['formatted_total_amount']; ?></div>
                        </div>
                        <div class="wc-installment-amount-item">
                            <div class="wc-installment-amount-label"><?php _e('Pagado', 'wc-installment-payments'); ?></div>
                            <div class="wc-installment-amount-value paid"><?php echo $credit['formatted_paid_amount']; ?></div>
                        </div>
                        <div class="wc-installment-amount-item">
                            <div class="wc-installment-amount-label"><?php _e('Pendiente', 'wc-installment-payments'); ?></div>
                            <div class="wc-installment-amount-value pending"><?php echo $credit['formatted_pending_amount']; ?></div>
                        </div>
                    </div>
                    
                    <!-- Next Payment Info -->
                    <?php if ($next_installment): ?>
                        <div class="wc-installment-next-payment">
                            <div class="wc-installment-next-payment-info">
                                <h5><?php _e('Próximo Pago', 'wc-installment-payments'); ?></h5>
                                <div class="wc-installment-next-payment-details">
                                    <span class="wc-installment-next-amount"><?php echo $next_installment['formatted_amount']; ?></span>
                                    <span class="wc-installment-next-date">
                                        <?php 
                                        echo $next_installment['formatted_due_date'];
                                        
                                        if ($next_installment['is_overdue']) {
                                            echo ' <span class="overdue-badge">(' . sprintf(__('Vencido hace %d días', 'wc-installment-payments'), $next_installment['days_overdue']) . ')</span>';
                                        } else {
                                            $days_until = floor((strtotime($next_installment['due_date']) - time()) / (60 * 60 * 24));
                                            if ($days_until <= 7) {
                                                echo ' <span class="due-soon-badge">(' . sprintf(_n('En %d día', 'En %d días', $days_until, 'wc-installment-payments'), $days_until) . ')</span>';
                                            }
                                        }
                                        ?>
                                    </span>
                                </div>
                            </div>
                            
                            <?php if ($next_installment['is_overdue']): ?>
                                <div class="wc-installment-overdue-alert">
                                    <i class="wc-installment-icon-warning"></i>
                                    <span><?php _e('Este pago está vencido. Por favor regulariza tu situación.', 'wc-installment-payments'); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Actions -->
                    <div class="wc-installment-credit-actions">
                        <a href="<?php echo esc_url(add_query_arg('credit_id', $credit['id'], wc_get_account_endpoint_url('credit-details'))); ?>" 
                           class="wc-installment-credit-btn primary">
                            <i class="wc-installment-icon-eye"></i>
                            <?php _e('Ver Detalles', 'wc-installment-payments'); ?>
                        </a>
                        
                        <?php if ($credit['status'] === 'active' && $next_installment): ?>
                            <button type="button" 
                                    class="wc-installment-credit-btn success wc-installment-pay-btn" 
                                    data-credit-id="<?php echo esc_attr($credit['id']); ?>"
                                    data-installment-id="<?php echo esc_attr($next_installment['id']); ?>"
                                    data-amount="<?php echo esc_attr($next_installment['amount']); ?>">
                                <i class="wc-installment-icon-credit-card"></i>
                                <?php _e('Pagar Cuota', 'wc-installment-payments'); ?>
                            </button>
                            
                            <button type="button" 
                                    class="wc-installment-credit-btn secondary wc-installment-early-payment-btn" 
                                    data-credit-id="<?php echo esc_attr($credit['id']); ?>">
                                <i class="wc-installment-icon-calculator"></i>
                                <?php _e('Pago Anticipado', 'wc-installment-payments'); ?>
                            </button>
                        <?php endif; ?>
                        
                        <?php if (in_array($credit['status'], ['active', 'overdue'])): ?>
                            <a href="<?php echo esc_url(add_query_arg(array('action' => 'download_schedule', 'credit_id' => $credit['id']), wc_get_account_endpoint_url('my-credits'))); ?>" 
                               class="wc-installment-credit-btn secondary" target="_blank">
                                <i class="wc-installment-icon-download"></i>
                                <?php _e('Descargar Cronograma', 'wc-installment-payments'); ?>
                            </a>
                        <?php endif; ?>
                        
                        <button type="button" 
                                class="wc-installment-credit-btn secondary wc-installment-contact-btn" 
                                data-credit-id="<?php echo esc_attr($credit['id']); ?>">
                            <i class="wc-installment-icon-message"></i>
                            <?php _e('Contactar Soporte', 'wc-installment-payments'); ?>
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Pagination would go here if needed -->
        
    <?php endif; ?>

    <!-- Quick Actions Panel -->
    <div class="wc-installment-quick-actions">
        <h3><?php _e('Acciones Rápidas', 'wc-installment-payments'); ?></h3>
        <div class="wc-installment-quick-actions-grid">
            <a href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>" class="wc-installment-quick-action">
                <i class="wc-installment-icon-shopping"></i>
                <span><?php _e('Nuevas Compras', 'wc-installment-payments'); ?></span>
            </a>
            
            <button type="button" class="wc-installment-quick-action wc-installment-payment-history-btn">
                <i class="wc-installment-icon-history"></i>
                <span><?php _e('Historial Completo', 'wc-installment-payments'); ?></span>
            </button>
            
            <button type="button" class="wc-installment-quick-action wc-installment-payment-methods-btn">
                <i class="wc-installment-icon-payment"></i>
                <span><?php _e('Métodos de Pago', 'wc-installment-payments'); ?></span>
            </button>
            
            <a href="<?php echo esc_url(get_permalink(get_option('woocommerce_myaccount_page_id'))); ?>" class="wc-installment-quick-action">
                <i class="wc-installment-icon-user"></i>
                <span><?php _e('Mi Cuenta', 'wc-installment-payments'); ?></span>
            </a>
        </div>
    </div>
</div>

<!-- Payment Modal -->
<div id="wc-installment-payment-modal" class="wc-installment-modal" style="display: none;">
    <div class="wc-installment-modal-overlay"></div>
    <div class="wc-installment-modal-content">
        <div class="wc-installment-modal-header">
            <h3><?php _e('Realizar Pago', 'wc-installment-payments'); ?></h3>
            <button type="button" class="wc-installment-modal-close">×</button>
        </div>
        
        <div class="wc-installment-modal-body">
            <div id="wc-installment-payment-content">
                <!-- Content loaded via AJAX -->
            </div>
        </div>
    </div>
</div>

<!-- Early Payment Modal -->
<div id="wc-installment-early-payment-modal" class="wc-installment-modal" style="display: none;">
    <div class="wc-installment-modal-overlay"></div>
    <div class="wc-installment-modal-content">
        <div class="wc-installment-modal-header">
            <h3><?php _e('Pago Anticipado', 'wc-installment-payments'); ?></h3>
            <button type="button" class="wc-installment-modal-close">×</button>
        </div>
        
        <div class="wc-installment-modal-body">
            <div id="wc-installment-early-payment-content">
                <!-- Content loaded via AJAX -->
            </div>
        </div>
    </div>
</div>

<!-- Contact Support Modal -->
<div id="wc-installment-contact-modal" class="wc-installment-modal" style="display: none;">
    <div class="wc-installment-modal-overlay"></div>
    <div class="wc-installment-modal-content">
        <div class="wc-installment-modal-header">
            <h3><?php _e('Contactar Soporte', 'wc-installment-payments'); ?></h3>
            <button type="button" class="wc-installment-modal-close">×</button>
        </div>
        
        <div class="wc-installment-modal-body">
            <form id="wc-installment-contact-form">
                <div class="wc-installment-form-group">
                    <label for="contact-subject"><?php _e('Asunto:', 'wc-installment-payments'); ?></label>
                    <select id="contact-subject" name="subject" required>
                        <option value=""><?php _e('Selecciona un asunto', 'wc-installment-payments'); ?></option>
                        <option value="payment_issue"><?php _e('Problema con pago', 'wc-installment-payments'); ?></option>
                        <option value="date_change"><?php _e('Cambio de fecha de pago', 'wc-installment-payments'); ?></option>
                        <option value="general_inquiry"><?php _e('Consulta general', 'wc-installment-payments'); ?></option>
                        <option value="other"><?php _e('Otro', 'wc-installment-payments'); ?></option>
                    </select>
                </div>
                
                <div class="wc-installment-form-group">
                    <label for="contact-message"><?php _e('Mensaje:', 'wc-installment-payments'); ?></label>
                    <textarea id="contact-message" name="message" rows="5" required 
                              placeholder="<?php esc_attr_e('Describe tu consulta o problema...', 'wc-installment-payments'); ?>"></textarea>
                </div>
                
                <div class="wc-installment-form-group">
                    <label for="contact-phone"><?php _e('Teléfono de contacto (opcional):', 'wc-installment-payments'); ?></label>
                    <input type="tel" id="contact-phone" name="phone" 
                           placeholder="<?php esc_attr_e('+57 300 123 4567', 'wc-installment-payments'); ?>">
                </div>
                
                <div class="wc-installment-form-actions">
                    <button type="button" class="wc-installment-btn secondary" onclick="hideContactModal()">
                        <?php _e('Cancelar', 'wc-installment-payments'); ?>
                    </button>
                    <button type="submit" class="wc-installment-btn primary">
                        <?php _e('Enviar Mensaje', 'wc-installment-payments'); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script type="text/javascript">
jQuery(document).ready(function($) {
    // Payment button
    $('.wc-installment-pay-btn').on('click', function() {
        var creditId = $(this).data('credit-id');
        var installmentId = $(this).data('installment-id');
        var amount = $(this).data('amount');
        
        showPaymentModal(creditId, installmentId, amount);
    });
    
    // Early payment button
    $('.wc-installment-early-payment-btn').on('click', function() {
        var creditId = $(this).data('credit-id');
        showEarlyPaymentModal(creditId);
    });
    
    // Contact button
    $('.wc-installment-contact-btn').on('click', function() {
        var creditId = $(this).data('credit-id');
        showContactModal(creditId);
    });
    
    // Modal close buttons
    $('.wc-installment-modal-close, .wc-installment-modal-overlay').on('click', function() {
        $(this).closest('.wc-installment-modal').fadeOut();
        $('body').removeClass('wc-installment-modal-open');
    });
    
    // Contact form submission
    $('#wc-installment-contact-form').on('submit', function(e) {
        e.preventDefault();
        submitContactForm();
    });
    
    // Auto-refresh credits every 5 minutes
    setInterval(function() {
        refreshCreditStatuses();
    }, 300000); // 5 minutes
});

function showPaymentModal(creditId, installmentId, amount) {
    $('#wc-installment-payment-content').html('<div class="loading">Cargando...</div>');
    $('#wc-installment-payment-modal').fadeIn();
    $('body').addClass('wc-installment-modal-open');
    
    // Load payment form via AJAX
    jQuery.ajax({
        url: wc_installment_ajax.ajax_url,
        type: 'POST',
        data: {
            action: 'get_payment_form',
            nonce: wc_installment_ajax.nonce,
            credit_id: creditId,
            installment_id: installmentId,
            amount: amount
        },
        success: function(response) {
            if (response.success) {
                $('#wc-installment-payment-content').html(response.data);
                initializePaymentForm();
            } else {
                $('#wc-installment-payment-content').html('<div class="error">' + response.data + '</div>');
            }
        },
        error: function() {
            $('#wc-installment-payment-content').html('<div class="error">Error al cargar el formulario de pago.</div>');
        }
    });
}

function showEarlyPaymentModal(creditId) {
    $('#wc-installment-early-payment-content').html('<div class="loading">Calculando...</div>');
    $('#wc-installment-early-payment-modal').fadeIn();
    $('body').addClass('wc-installment-modal-open');
    
    // Calculate early payment via AJAX
    jQuery.ajax({
        url: wc_installment_ajax.ajax_url,
        type: 'POST',
        data: {
            action: 'calculate_early_payment',
            nonce: wc_installment_ajax.nonce,
            credit_id: creditId
        },
        success: function(response) {
            if (response.success) {
                displayEarlyPaymentOptions(response.data);
            } else {
                $('#wc-installment-early-payment-content').html('<div class="error">' + response.data + '</div>');
            }
        },
        error: function() {
            $('#wc-installment-early-payment-content').html('<div class="error">Error al calcular el pago anticipado.</div>');
        }
    });
}

function displayEarlyPaymentOptions(data) {
    var html = '<div class="wc-installment-early-payment-summary">';
    html += '<h4>Opciones de Pago Anticipado</h4>';
    
    html += '<div class="wc-installment-early-payment-option">';
    html += '<h5>Pago Total Anticipado</h5>';
    html += '<div class="wc-installment-early-payment-breakdown">';
    html += '<div class="breakdown-item">';
    html += '<span>Total pendiente:</span>';
    html += '<span>' + data.formatted_total_pending + '</span>';
    html += '</div>';
    html += '<div class="breakdown-item discount">';
    html += '<span>Descuento por pago anticipado:</span>';
    html += '<span>-' + data.formatted_discount_amount + '</span>';
    html += '</div>';
    html += '<div class="breakdown-item total">';
    html += '<span><strong>Total a pagar:</strong></span>';
    html += '<span><strong>' + data.formatted_amount_to_pay + '</strong></span>';
    html += '</div>';
    html += '<div class="breakdown-item savings">';
    html += '<span>Ahorro total:</span>';
    html += '<span>' + data.formatted_savings + '</span>';
    html += '</div>';
    html += '</div>';
    
    html += '<button type="button" class="wc-installment-btn primary" onclick="processEarlyPayment(' + data.credit_id + ', ' + data.amount_to_pay + ')">';
    html += 'Proceder con Pago Anticipado';
    html += '</button>';
    html += '</div>';
    
    html += '</div>';
    
    $('#wc-installment-early-payment-content').html(html);
}

function processEarlyPayment(creditId, amount) {
    // This would redirect to payment gateway or show payment form
    alert('Funcionalidad de pago en desarrollo. Monto: ' + amount);
}

function showContactModal(creditId) {
    $('#wc-installment-contact-modal').fadeIn();
    $('#wc-installment-contact-form').data('credit-id', creditId);
    $('body').addClass('wc-installment-modal-open');
}

function hideContactModal() {
    $('#wc-installment-contact-modal').fadeOut();
    $('body').removeClass('wc-installment-modal-open');
}

function submitContactForm() {
    var $form = $('#wc-installment-contact-form');
    var creditId = $form.data('credit-id');
    var formData = {
        action: 'submit_support_request',
        nonce: wc_installment_ajax.nonce,
        credit_id: creditId,
        subject: $('#contact-subject').val(),
        message: $('#contact-message').val(),
        phone: $('#contact-phone').val()
    };
    
    $form.find('button[type="submit"]').prop('disabled', true).text('Enviando...');
    
    jQuery.ajax({
        url: wc_installment_ajax.ajax_url,
        type: 'POST',
        data: formData,
        success: function(response) {
            if (response.success) {
                alert('Mensaje enviado correctamente. Te contactaremos pronto.');
                hideContactModal();
                $form[0].reset();
            } else {
                alert('Error al enviar el mensaje: ' + response.data);
            }
        },
        error: function() {
            alert('Error de conexión. Por favor intenta nuevamente.');
        },
        complete: function() {
            $form.find('button[type="submit"]').prop('disabled', false).text('Enviar Mensaje');
        }
    });
}

function initializePaymentForm() {
    // Initialize payment form functionality
    // This would typically integrate with payment gateways
}

function refreshCreditStatuses() {
    // Refresh credit statuses without full page reload
    jQuery.ajax({
        url: wc_installment_ajax.ajax_url,
        type: 'POST',
        data: {
            action: 'refresh_credit_statuses',
            nonce: wc_installment_ajax.nonce
        },
        success: function(response) {
            if (response.success && response.data.updated > 0) {
                // Reload page if there were updates
                location.reload();
            }
        }
    });
}
</script>
