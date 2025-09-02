/**
 * WC Installment Payments - Frontend JavaScript
 */

(function($) {
    'use strict';

    // Global variables
    let selectedPlanId = null;
    let selectedPlanName = null;
    let productPrice = 0;

    /**
     * Initialize the plugin
     */
    function init() {
        // Get product price from the page
        getProductPrice();
        
        // Bind events
        bindEvents();
        
        // Initialize plan interactions
        initializePlans();
        
        // Setup accessibility
        setupAccessibility();
        
        console.log('WC Installment Payments initialized');
    }

    /**
     * Get product price from various possible locations
     */
    function getProductPrice() {
        // Try to get price from different possible selectors
        const priceSelectors = [
            '.woocommerce-Price-amount bdi',
            '.price .woocommerce-Price-amount bdi',
            '.summary .price .amount',
            '.single_variation_wrap .woocommerce-Price-amount bdi',
            '[data-price]'
        ];

        for (let selector of priceSelectors) {
            const priceElement = $(selector).first();
            if (priceElement.length) {
                let priceText = priceElement.text() || priceElement.attr('data-price');
                if (priceText) {
                    // Remove currency symbols and formatting
                    priceText = priceText.replace(/[^\d.,]/g, '');
                    priceText = priceText.replace(',', '');
                    productPrice = parseFloat(priceText);
                    if (!isNaN(productPrice) && productPrice > 0) {
                        break;
                    }
                }
            }
        }

        // If still no price found, try to extract from variation data
        if (productPrice <= 0) {
            const variationData = $('form.variations_form').data('product_variations');
            if (variationData && variationData.length > 0) {
                productPrice = parseFloat(variationData[0].display_price);
            }
        }

        console.log('Product price detected:', productPrice);
    }

    /**
     * Bind all events
     */
    function bindEvents() {
        // Plan selection
        $(document).on('click', '.wc-installment-select-plan-btn', handlePlanSelection);
        
        // Plan details toggle
        $(document).on('click', '.wc-installment-plan-header', handlePlanToggle);
        
        // Calculator
        $(document).on('click', '.wc-installment-calculator-btn', showCalculator);
        $(document).on('click', '.wc-installment-calculator-close', hideCalculator);
        $(document).on('change', '#wc-installment-amount, #wc-installment-plan-select', updateCalculations);
        
        // Modal
        $(document).on('click', '.wc-installment-help-link', showInstallmentInfo);
        $(document).on('click', '.wc-installment-modal-close, .wc-installment-modal-overlay', hideInstallmentInfo);
        
        // Keyboard navigation
        $(document).on('keydown', '.wc-installment-plan-header', handleKeyboardNavigation);
        
        // Variation changes (for variable products)
        $('form.variations_form').on('show_variation', function(event, variation) {
            productPrice = parseFloat(variation.display_price);
            updatePlanAvailability();
        });
        
        // Add to cart integration
        $('form.cart').on('submit', handleAddToCart);
        
        // Custom events
        $(document.body).on('wc_installment_plan_selected', handleCustomPlanSelection);
    }

    /**
     * Initialize plan interactions
     */
    function initializePlans() {
        // Add ARIA attributes for accessibility
        $('.wc-installment-plan-header').attr({
            'role': 'button',
            'tabindex': '0',
            'aria-expanded': 'false'
        });

        // Set initial state
        $('.wc-installment-plan-details').hide();
        
        // Highlight recommended plan
        const $recommendedPlan = $('.wc-installment-plan.recommended').first();
        if ($recommendedPlan.length) {
            $recommendedPlan.addClass('highlighted');
            setTimeout(() => {
                $recommendedPlan.removeClass('highlighted');
            }, 3000);
        }
    }

    /**
     * Setup accessibility features
     */
    function setupAccessibility() {
        // Add screen reader descriptions
        $('.wc-installment-plan').each(function() {
            const $plan = $(this);
            const planName = $plan.find('h4').text();
            const installments = $plan.data('installments');
            const interestRate = $plan.data('interest-rate');
            
            const description = `Plan ${planName}, ${installments} cuotas, tasa de interés ${interestRate}%`;
            $plan.find('.wc-installment-plan-header').attr('aria-label', description);
        });

        // Add focus indicators
        $('.wc-installment-plan-header').on('focus', function() {
            $(this).addClass('focused');
        }).on('blur', function() {
            $(this).removeClass('focused');
        });
    }

    /**
     * Handle plan selection
     */
    function handlePlanSelection(e) {
        e.preventDefault();
        e.stopPropagation();
        
        const $button = $(this);
        selectedPlanId = $button.data('plan-id');
        selectedPlanName = $button.data('plan-name');
        
        // Visual feedback
        showPlanSelectionFeedback($button);
        
        // Trigger custom event
        $(document.body).trigger('wc_installment_plan_selected', [selectedPlanId, selectedPlanName]);
        
        // Show confirmation
        showPlanSelectionConfirmation();
    }

    /**
     * Show plan selection feedback
     */
    function showPlanSelectionFeedback($button) {
        // Remove previous selections
        $('.wc-installment-select-plan-btn').removeClass('selected');
        $('.wc-installment-plan').removeClass('selected');
        
        // Mark current selection
        $button.addClass('selected');
        $button.closest('.wc-installment-plan').addClass('selected');
        
        // Change button text temporarily
        const originalText = $button.text();
        $button.text('✓ Seleccionado');
        
        setTimeout(() => {
            $button.text(originalText);
        }, 2000);
    }

    /**
     * Show plan selection confirmation
     */
    function showPlanSelectionConfirmation() {
        const message = `Has seleccionado el plan: ${selectedPlanName}\n\n¿Deseas continuar con la compra?`;
        
        if (confirm(message)) {
            // Scroll to add to cart button
            const $addToCartButton = $('.single_add_to_cart_button');
            if ($addToCartButton.length) {
                $('html, body').animate({
                    scrollTop: $addToCartButton.offset().top - 100
                }, 500);
                
                // Highlight the button
                $addToCartButton.addClass('highlighted');
                setTimeout(() => {
                    $addToCartButton.removeClass('highlighted');
                }, 3000);
            }
        }
    }

    /**
     * Handle plan toggle
     */
    function handlePlanToggle(e) {
        // Don't toggle if clicking on the select button
        if ($(e.target).hasClass('wc-installment-select-plan-btn') || 
            $(e.target).closest('.wc-installment-select-plan-btn').length) {
            return;
        }
        
        const $planHeader = $(this);
        const $plan = $planHeader.closest('.wc-installment-plan');
        const $details = $plan.find('.wc-installment-plan-details');
        const $toggleIcon = $plan.find('.wc-installment-toggle-icon i');
        const isExpanded = $details.is(':visible');
        
        // Close other plans
        $('.wc-installment-plan').not($plan).each(function() {
            $(this).find('.wc-installment-plan-details').slideUp(300);
            $(this).find('.wc-installment-toggle-icon i').removeClass('rotated');
            $(this).removeClass('expanded');
            $(this).find('.wc-installment-plan-header').attr('aria-expanded', 'false');
        });
        
        // Toggle current plan
        if (isExpanded) {
            $details.slideUp(300);
            $toggleIcon.removeClass('rotated');
            $plan.removeClass('expanded');
            $planHeader.attr('aria-expanded', 'false');
        } else {
            $details.slideDown(300);
            $toggleIcon.addClass('rotated');
            $plan.addClass('expanded');
            $planHeader.attr('aria-expanded', 'true');
            
            // Track plan view
            trackPlanView($plan.data('plan-id'));
        }
    }

    /**
     * Handle keyboard navigation
     */
    function handleKeyboardNavigation(e) {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            $(this).trigger('click');
        }
    }

    /**
     * Show calculator
     */
    function showCalculator() {
        const $calculator = $('#wc-installment-calculator');
        
        // Set initial values
        $('#wc-installment-amount').val(productPrice);
        
        // Show modal
        $calculator.fadeIn(300);
        
        // Focus on amount input
        setTimeout(() => {
            $('#wc-installment-amount').focus();
        }, 300);
        
        // Update calculations
        updateCalculations();
        
        // Prevent body scroll
        $('body').addClass('wc-installment-modal-open');
    }

    /**
     * Hide calculator
     */
    function hideCalculator() {
        $('#wc-installment-calculator').fadeOut(300);
        $('body').removeClass('wc-installment-modal-open');
    }

    /**
     * Update calculations
     */
    function updateCalculations() {
        const amount = parseFloat($('#wc-installment-amount').val());
        const planId = $('#wc-installment-plan-select').val();
        
        if (!amount || !planId) {
            return;
        }

        // Validate amount
        const selectedOption = $(`#wc-installment-plan-select option[value="${planId}"]`);
        if (!selectedOption.length) {
            return;
        }

        // Show loading
        $('#wc-installment-calculator-results').html(getLoadingHTML());
        
        // AJAX call
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
                    showCalculationError(response.data);
                }
            },
            error: function(xhr, status, error) {
                showCalculationError('Error de conexión. Por favor intenta nuevamente.');
                console.error('AJAX Error:', error);
            }
        });
    }

    /**
     * Display calculation results
     */
    function displayCalculationResults(data) {
        let html = '<div class="wc-installment-calculation-summary">';
        html += '<h5>Resultado del Cálculo</h5>';
        html += '<div class="wc-installment-calc-result-grid">';
        
        html += '<div class="wc-installment-calc-item">';
        html += '<span class="label">Número de cuotas:</span>';
        html += `<span class="value">${data.summary.installment_count}</span>`;
        html += '</div>';
        
        html += '<div class="wc-installment-calc-item">';
        html += '<span class="label">Pago mensual:</span>';
        html += `<span class="value strong">${data.summary.formatted_average_installment}</span>`;
        html += '</div>';
        
        html += '<div class="wc-installment-calc-item">';
        html += '<span class="label">Total a pagar:</span>';
        html += `<span class="value">${data.summary.formatted_total_amount}</span>`;
        html += '</div>';
        
        if (data.summary.total_interest > 0) {
            html += '<div class="wc-installment-calc-item">';
            html += '<span class="label">Total intereses:</span>';
            html += `<span class="value interest">${data.summary.formatted_total_interest}</span>`;
            html += '</div>';
            
            html += '<div class="wc-installment-calc-item">';
            html += '<span class="label">Costo adicional:</span>';
            html += `<span class="value interest">${data.summary.formatted_savings_vs_cash}</span>`;
            html += '</div>';
        } else {
            html += '<div class="wc-installment-calc-item no-interest">';
            html += '<span class="label">Beneficio:</span>';
            html += '<span class="value benefit">¡Sin intereses!</span>';
            html += '</div>';
        }
        
        html += '</div>';
        
        // Add installment schedule
        if (data.installments && data.installments.length > 0) {
            html += '<div class="wc-installment-calc-schedule">';
            html += '<h6>Cronograma de Pagos</h6>';
            html += '<div class="wc-installment-calc-schedule-list">';
            
            data.installments.forEach(function(installment, index) {
                if (index < 5) { // Show only first 5 installments to avoid clutter
                    html += '<div class="wc-installment-calc-schedule-item">';
                    html += `<span class="number">${installment.number}</span>`;
                    html += `<span class="amount">${installment.formatted_amount}</span>`;
                    html += `<span class="date">${installment.formatted_due_date}</span>`;
                    html += '</div>';
                }
            });
            
            if (data.installments.length > 5) {
                html += `<div class="wc-installment-calc-more">... y ${data.installments.length - 5} cuotas más</div>`;
            }
            
            html += '</div></div>';
        }
        
        html += '</div>';
        
        $('#wc-installment-calculator-results').html(html);
    }

    /**
     * Show calculation error
     */
    function showCalculationError(message) {
        const html = `<div class="wc-installment-error">${message}</div>`;
        $('#wc-installment-calculator-results').html(html);
    }

    /**
     * Get loading HTML
     */
    function getLoadingHTML() {
        return '<div class="wc-installment-loading"><div class="spinner"></div>Calculando...</div>';
    }

    /**
     * Show installment info modal
     */
    function showInstallmentInfo(e) {
        if (e) e.preventDefault();
        $('#wc-installment-info-modal').fadeIn(300);
        $('body').addClass('wc-installment-modal-open');
        
        // Focus on close button for accessibility
        setTimeout(() => {
            $('#wc-installment-info-modal .wc-installment-modal-close').focus();
        }, 300);
    }

    /**
     * Hide installment info modal
     */
    function hideInstallmentInfo() {
        $('#wc-installment-info-modal').fadeOut(300);
        $('body').removeClass('wc-installment-modal-open');
    }

    /**
     * Update plan availability for variable products
     */
    function updatePlanAvailability() {
        $('.wc-installment-plan').each(function() {
            const $plan = $(this);
            const planId = $plan.data('plan-id');
            
            // Check if plan is available for current price
            checkPlanAvailability(planId, productPrice, $plan);
        });
    }

    /**
     * Check if plan is available for given price
     */
    function checkPlanAvailability(planId, price, $plan) {
        // This would typically be an AJAX call to check server-side
        // For now, we'll do basic client-side validation if we have the data
        
        const minAmount = $plan.data('min-amount');
        const maxAmount = $plan.data('max-amount');
        
        if (minAmount && maxAmount) {
            const isAvailable = price >= minAmount && price <= maxAmount;
            
            if (isAvailable) {
                $plan.removeClass('unavailable').addClass('available');
                $plan.find('.wc-installment-select-plan-btn').prop('disabled', false);
            } else {
                $plan.removeClass('available').addClass('unavailable');
                $plan.find('.wc-installment-select-plan-btn').prop('disabled', true);
                
                // Add unavailable message
                if (!$plan.find('.wc-installment-unavailable-msg').length) {
                    const msg = price < minAmount ? 
                        `Monto mínimo: ${formatCurrency(minAmount)}` : 
                        `Monto máximo: ${formatCurrency(maxAmount)}`;
                    $plan.find('.wc-installment-plan-summary').append(
                        `<div class="wc-installment-unavailable-msg">${msg}</div>`
                    );
                }
            }
        }
    }

    /**
     * Handle add to cart with selected plan
     */
    function handleAddToCart(e) {
        if (selectedPlanId) {
            // Add hidden input with selected plan
            const $form = $(this);
            
            // Remove any existing plan inputs
            $form.find('input[name="wc_installment_plan_id"]').remove();
            
            // Add selected plan
            $form.append(`<input type="hidden" name="wc_installment_plan_id" value="${selectedPlanId}">`);
            
            // Add plan name for reference
            $form.append(`<input type="hidden" name="wc_installment_plan_name" value="${selectedPlanName}">`);
            
            console.log('Adding to cart with installment plan:', selectedPlanId);
        }
    }

    /**
     * Handle custom plan selection event
     */
    function handleCustomPlanSelection(event, planId, planName) {
        selectedPlanId = planId;
        selectedPlanName = planName;
        
        console.log('Plan selected via custom event:', planId, planName);
        
        // Update UI accordingly
        updatePlanSelectionUI();
    }

    /**
     * Update plan selection UI
     */
    function updatePlanSelectionUI() {
        // Remove all selected states
        $('.wc-installment-plan').removeClass('selected');
        $('.wc-installment-select-plan-btn').removeClass('selected');
        
        // Add selected state to chosen plan
        $(`.wc-installment-plan[data-plan-id="${selectedPlanId}"]`).addClass('selected');
        $(`.wc-installment-select-plan-btn[data-plan-id="${selectedPlanId}"]`).addClass('selected');
        
        // Show selection indicator
        showSelectionNotification();
    }

    /**
     * Show selection notification
     */
    function showSelectionNotification() {
        // Create or update notification
        let $notification = $('.wc-installment-selection-notification');
        
        if (!$notification.length) {
            $notification = $('<div class="wc-installment-selection-notification"></div>');
            $('.wc-installment-payment-plans').prepend($notification);
        }
        
        $notification.html(`
            <div class="wc-installment-notification-content">
                <i class="wc-installment-icon-check"></i>
                <span>Plan seleccionado: <strong>${selectedPlanName}</strong></span>
                <button type="button" class="wc-installment-clear-selection">×</button>
            </div>
        `).addClass('show');
        
        // Bind clear selection
        $notification.find('.wc-installment-clear-selection').on('click', clearPlanSelection);
    }

    /**
     * Clear plan selection
     */
    function clearPlanSelection() {
        selectedPlanId = null;
        selectedPlanName = null;
        
        $('.wc-installment-plan').removeClass('selected');
        $('.wc-installment-select-plan-btn').removeClass('selected');
        $('.wc-installment-selection-notification').removeClass('show');
        
        // Remove hidden inputs
        $('form.cart input[name="wc_installment_plan_id"]').remove();
        $('form.cart input[name="wc_installment_plan_name"]').remove();
    }

    /**
     * Track plan view (for analytics)
     */
    function trackPlanView(planId) {
        if (typeof gtag !== 'undefined') {
            gtag('event', 'view_installment_plan', {
                'custom_parameter_1': planId,
                'custom_parameter_2': productPrice
            });
        }
        
        // Custom tracking event
        $(document.body).trigger('wc_installment_plan_viewed', [planId]);
    }

    /**
     * Format currency using WooCommerce settings
     */
    function formatCurrency(amount) {
        const symbol = wc_installment_ajax.currency_symbol;
        const position = wc_installment_ajax.currency_position;
        const thousandSep = wc_installment_ajax.thousand_separator;
        const decimalSep = wc_installment_ajax.decimal_separator;
        const decimals = parseInt(wc_installment_ajax.decimals);
        
        // Format number
        const formattedNumber = Number(amount).toLocaleString('es-CO', {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals
        });
        
        // Apply currency position
        switch (position) {
            case 'left':
                return symbol + formattedNumber;
            case 'right':
                return formattedNumber + symbol;
            case 'left_space':
                return symbol + ' ' + formattedNumber;
            case 'right_space':
                return formattedNumber + ' ' + symbol;
            default:
                return symbol + formattedNumber;
        }
    }

    /**
     * Handle escape key for modals
     */
    function handleEscapeKey(e) {
        if (e.key === 'Escape') {
            if ($('#wc-installment-calculator').is(':visible')) {
                hideCalculator();
            }
            if ($('#wc-installment-info-modal').is(':visible')) {
                hideInstallmentInfo();
            }
        }
    }

    /**
     * Validate form before submission
     */
    function validateInstallmentForm() {
        if (selectedPlanId) {
            // Additional validation can be added here
            return true;
        }
        return true; // Allow normal purchases without installments
    }

    /**
     * Handle checkout integration
     */
    function setupCheckoutIntegration() {
        // This will be expanded when creating the checkout integration
        $(document.body).on('updated_checkout', function() {
            // Update installment information in checkout
            updateCheckoutInstallmentInfo();
        });
    }

    /**
     * Update checkout installment info
     */
    function updateCheckoutInstallmentInfo() {
        if (selectedPlanId) {
            // Add installment information to checkout
            const $checkoutForm = $('form.checkout');
            if ($checkoutForm.length) {
                // This would typically show installment details in checkout
                console.log('Updating checkout with installment plan:', selectedPlanId);
            }
        }
    }

    /**
     * Initialize responsive behavior
     */
    function initResponsive() {
        let resizeTimer;
        
        $(window).on('resize', function() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function() {
                // Adjust UI for different screen sizes
                adjustForScreenSize();
            }, 250);
        });
        
        // Initial adjustment
        adjustForScreenSize();
    }

    /**
     * Adjust UI for current screen size
     */
    function adjustForScreenSize() {
        const windowWidth = $(window).width();
        
        if (windowWidth < 768) {
            // Mobile adjustments
            $('.wc-installment-plan-summary').addClass('mobile');
            $('.wc-installment-schedule-list').addClass('mobile');
        } else {
            // Desktop adjustments
            $('.wc-installment-plan-summary').removeClass('mobile');
            $('.wc-installment-schedule-list').removeClass('mobile');
        }
    }

    /**
     * Setup error handling
     */
    function setupErrorHandling() {
        // Global AJAX error handler for installment requests
        $(document).ajaxError(function(event, xhr, settings, thrownError) {
            if (settings.data && settings.data.indexOf('wc_installment') !== -1) {
                console.error('WC Installment AJAX Error:', thrownError);
                showErrorNotification('Ocurrió un error. Por favor intenta nuevamente.');
            }
        });
    }

    /**
     * Show error notification
     */
    function showErrorNotification(message) {
        const $notification = $(`
            <div class="wc-installment-error-notification">
                <span>${message}</span>
                <button type="button" class="close">×</button>
            </div>
        `);
        
        $('.wc-installment-payment-plans').prepend($notification);
        
        $notification.find('.close').on('click', function() {
            $notification.remove();
        });
        
        // Auto-hide after 5 seconds
        setTimeout(() => {
            $notification.fadeOut(300, function() {
                $(this).remove();
            });
        }, 5000);
    }

    /**
     * Public API
     */
    window.WCInstallmentPayments = {
        selectPlan: function(planId, planName) {
            selectedPlanId = planId;
            selectedPlanName = planName;
            updatePlanSelectionUI();
            return true;
        },
        
        clearSelection: clearPlanSelection,
        
        getSelectedPlan: function() {
            return {
                id: selectedPlanId,
                name: selectedPlanName
            };
        },
        
        showCalculator: showCalculator,
        hideCalculator: hideCalculator,
        
        updateCalculations: updateCalculations,
        
        getCurrentPrice: function() {
            return productPrice;
        }
    };

    // Global functions for template compatibility
    window.togglePlanDetails = function(element) {
        $(element).trigger('click');
    };

    window.showCalculator = showCalculator;
    window.hideCalculator = hideCalculator;
    window.updateCalculations = updateCalculations;
    window.showInstallmentInfo = showInstallmentInfo;
    window.hideInstallmentInfo = hideInstallmentInfo;

    // Initialize when document is ready
    $(document).ready(function() {
        init();
        initResponsive();
        setupErrorHandling();
        setupCheckoutIntegration();
        
        // Bind global keyboard events
        $(document).on('keydown', handleEscapeKey);
        
        // Prevent body scroll when modal is open
        $(document).on('keydown', function(e) {
            if ($('body').hasClass('wc-installment-modal-open') && 
                (e.key === 'ArrowUp' || e.key === 'ArrowDown')) {
                // Allow arrow keys in modals but prevent page scroll
                const $target = $(e.target);
                if (!$target.is('input, select, textarea')) {
                    e.preventDefault();
                }
            }
        });
    });

})(jQuery);
