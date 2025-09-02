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
        
        if (data.summary.
