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
            '1.0.7',
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

// NEW: Custom form error handler for Elementor forms
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
        
        // Handle backend validation errors (PHP - Israeli ID, Phone)
        // Monitor for AJAX complete on Elementor forms
        $(document).ajaxComplete(function(event, xhr, settings) {
            // Check if this is an Elementor form submission
            if (settings.url && settings.url.indexOf('admin-ajax.php') !== -1 && 
                settings.data && settings.data.indexOf('elementor_pro_forms_send_form') !== -1) {
                
                setTimeout(() => {
                    // Find any forms with errors
                    $('.elementor-form').each(function() {
                        const $form = $(this);
                        if ($form.find('.elementor-error').length > 0) {
                            scrollToFirstError($form);
                        }
                    });
                }, 200);
            }
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
            // Find first error (backend validation)
            const $firstErrorGroup = $form.find('.elementor-field-group.elementor-error').first();
            if ($firstErrorGroup.length) {
                const $firstInput = $firstErrorGroup.find('input, select, textarea').first();
                if ($firstInput.length) {
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