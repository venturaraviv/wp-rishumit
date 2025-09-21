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

// Add custom validation messages in Hebrew
function rishumit_custom_validation_messages()
{
    // Only load on frontend
    if (is_admin()) {
        return;
    }

    // Register and enqueue the script with no source file (we'll use inline script)
    wp_register_script('rishumit-validation-messages', false);

    // Add inline script with the Hebrew validation message
    $script = '
    document.addEventListener("DOMContentLoaded", function() {
        var formElements = document.querySelectorAll("input, select, textarea");
        for (var i = 0; i < formElements.length; i++) {
            formElements[i].oninvalid = function(e) {
                e.target.setCustomValidity("");
                if (!e.target.validity.valid) {
                    e.target.setCustomValidity("אנא מלא שדה זה");
                }
            };
            formElements[i].oninput = function(e) {
                e.target.setCustomValidity("");
            };
        }
    });
    ';

    wp_add_inline_script('rishumit-validation-messages', $script);
    wp_enqueue_script('rishumit-validation-messages', '', array(), '1.0', true);
}
add_action('wp_enqueue_scripts', 'rishumit_custom_validation_messages');

function rishumit_enqueue_payment_assets()
{
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
            '1.0.3',
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