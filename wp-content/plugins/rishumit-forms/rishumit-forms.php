<?php

/**
 * Plugin Name: Rishumit Forms
 * Description: Handles form submissions and validations for Rishumit forms.
 * Version: 1.0.0
 * Author: Your Name
 * Text Domain: rishumit-forms
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Autoload classes
require_once __DIR__ . '/includes/Form.php';

// Initialize the plugin
function rishumit_forms_init()
{
    // Check if the Form class exists before proceeding
    if (!class_exists('\RishumitPlugin\Leads\Form')) {
        error_log('Rishumit Forms: Form class not found.');
        return;
    }

    // Instantiate and register the Form handler
    $formHandler = new \RishumitPlugin\Leads\Form();
    $formHandler->register();
}
add_action('plugins_loaded', 'rishumit_forms_init');

add_action('rishumit_expire_payment_link', 'rishumit_expire_payment_link_callback');

function rishumit_expire_payment_link_callback($id)
{
    delete_option('rishumit_payment_url_' . $id);
}

// AJAX endpoint to get fresh nonce
add_action('wp_ajax_get_payment_nonce', 'get_fresh_payment_nonce');
add_action('wp_ajax_nopriv_get_payment_nonce', 'get_fresh_payment_nonce');

function get_fresh_payment_nonce() {
    wp_send_json_success([
        'nonce' => wp_create_nonce('payment_process_nonce')
    ]);
}

function rishumit_enqueue_payment_assets()
{
    static $assets_loaded = false;
    if ($assets_loaded) {
        return;
    }
    $assets_loaded = true;

    // Only enqueue on pages with forms
    if (is_page() || is_single()) {
        // Get plugin URL
        $plugin_url = plugin_dir_url(__FILE__);

        // Enqueue Apple Pay SDK FIRST
        wp_enqueue_script(
            'apple-pay-sdk',
            'https://meshulam.co.il/_media/js/apple_pay_sdk/sdk.min.js',
            [],
            null,
            false // Load in header
        );

        // Enqueue payment SDK CSS
        wp_enqueue_style(
            'rishumit-payment-sdk',
            $plugin_url . 'assets/css/payment-sdk.css',
            [],
            '1.0.0'
        );

        // Enqueue payment SDK JavaScript
        wp_enqueue_script(
            'rishumit-payment-sdk',
            $plugin_url . 'assets/js/payment-sdk.js',
            ['jquery', 'apple-pay-sdk'], // Add dependency on Apple Pay SDK
            '1.0.20',  // Removed verbose logging, back to clean logs
            true
        );

        // Pass WP_ENVIRONMENT_TYPE straight to JS
        $env = defined('WP_ENVIRONMENT_TYPE') ? WP_ENVIRONMENT_TYPE : 'PRODUCTION';

        wp_add_inline_script(
            'rishumit-payment-sdk',
            "window.WP_ENVIRONMENT_TYPE = '{$env}';",
            'before'
        );

        // Localize script with AJAX data
        wp_localize_script(
            'rishumit-payment-sdk',
            'rishumit_ajax',
            [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('payment_process_nonce'),
                'site_url' => site_url()
            ]
        );
    }
}
add_action('wp_enqueue_scripts', 'rishumit_enqueue_payment_assets');

function rishumit_custom_form_errors() {
    ?>
    <script>
    jQuery(document).ready(function($) {
        let isProcessing = false;
        
        // Handle HTML5 validation errors (frontend)
        document.addEventListener('invalid', function(e) {
            e.preventDefault();
            
            if (isProcessing) return;
            isProcessing = true;
            
            const $form = $(e.target).closest('.elementor-form');
            if ($form.length) {
                setTimeout(() => {
                    showFormErrors($form);
                    isProcessing = false;
                }, 100);
            } else {
                isProcessing = false;
            }
        }, true);
        
        // Handle backend validation errors (Israeli ID, Phone) - IMPROVED
        $(document).on('submit_error', '.elementor-form', function(e, error) {
            console.log('Backend validation error detected');
            const $form = $(this);
            setTimeout(() => {
                scrollToFirstError($form);
            }, 300);
        });
        
        // Backup method: watch for error class changes
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.target.classList.contains('elementor-field-group') && 
                    mutation.target.classList.contains('elementor-error')) {
                    const $form = $(mutation.target).closest('.elementor-form');
                    setTimeout(() => {
                        scrollToFirstError($form);
                    }, 100);
                }
            });
        });
        
        // Observe all form field groups
        $('.elementor-field-group').each(function() {
            observer.observe(this, {
                attributes: true,
                attributeFilter: ['class']
            });
        });
        
        function showFormErrors($form) {
            $form.find('.custom-error').remove();
            let firstError = null;
            
            $form.find('input, select, textarea').each(function() {
                const $input = $(this);
                const $group = $input.closest('.elementor-field-group');
                
                if (!this.checkValidity()) {
                    const hasNativeError = $group.find('.elementor-message-danger:not(.custom-error)').length > 0;
                    
                    if (!hasNativeError) {
                        $group.append('<div class="custom-error elementor-message elementor-message-danger" style="margin-top:4px;font-size:13px;">אנא מלא שדה זה כראוי</div>');
                    }
                    
                    if (!firstError) firstError = $input;
                }
            });
            
            if (firstError) {
                scrollToElement(firstError);
            }
        }
        
        function scrollToFirstError($form) {
            const $firstErrorGroup = $form.find('.elementor-field-group.elementor-error').first();
            if ($firstErrorGroup.length) {
                const $firstInput = $firstErrorGroup.find('input, select, textarea').first();
                if ($firstInput.length) {
                    console.log('Scrolling to error field:', $firstInput.attr('name'));
                    scrollToElement($firstInput);
                }
            }
        }
        
        function scrollToElement($element) {
            $('html, body').animate({
                scrollTop: $element.offset().top - 100
            }, 400);
            $element.focus();
        }
    });
    </script>
    <?php
}
add_action('wp_footer', 'rishumit_custom_form_errors', 999);

function rishumit_populate_otp_hidden_fields() {
    ?>
    <script>
    jQuery(document).ready(function($) {
        // Get URL parameters
        const urlParams = new URLSearchParams(window.location.search);
        const invoice_number = urlParams.get('invoice_number');        

        console.log('URL Params - Invoice number:', invoice_number);

        // Find OTP form hidden fields and populate them
        // The hidden fields should have the field names: transaction_id and request_id
        if (invoice_number) {
            $('input[name="form_fields[invoice_number]"]').val(invoice_number);
            console.log('Set invoice_number hidden field to:', invoice_number);
        }        
    });
    </script>
    <?php
}
add_action('wp_footer', 'rishumit_populate_otp_hidden_fields', 1000);