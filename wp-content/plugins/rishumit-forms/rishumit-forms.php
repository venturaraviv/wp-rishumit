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
            '1.0.32',  // User updates
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
        let invoice_number = urlParams.get('invoice_number');

        // If invoice_number not in URL, check sessionStorage (for back/forward navigation)
        if (!invoice_number && sessionStorage.getItem('rishumit_invoice_number')) {
            invoice_number = sessionStorage.getItem('rishumit_invoice_number');
            console.log('Retrieved invoice_number from sessionStorage:', invoice_number);

            // Update URL to include invoice_number parameter
            const url = new URL(window.location);
            url.searchParams.set('invoice_number', invoice_number);
            window.history.replaceState({}, '', url);
            console.log('Updated URL with invoice_number:', url.toString());
        }

        // Find OTP form hidden fields and populate them
        if (invoice_number) {
            // Try to find field by id attribute first
            let $field = $('#form-field-invoice_number');
            if ($field.length === 0) {
                // Try by name attribute as fallback
                $field = $('input[name="form_fields[invoice_number]"]');
            }

            if ($field.length > 0) {
                $field.val(invoice_number);
                console.log('Populated invoice_number field:', invoice_number);
            } else {
                console.warn('Could not find invoice_number field in form');
            }
        } else {
            console.warn('No invoice_number found in URL or sessionStorage');
        }
    });
    </script>
    <?php
}
add_action('wp_footer', 'rishumit_populate_otp_hidden_fields', 1000);

// Helper function to validate phone and invoice number from REST request
function validate_green_otp_params(WP_REST_Request $request) {
  error_log('Request params: ' . print_r($request->get_params(), true));

  $phone = preg_replace('/\D+/', '', (string) $request->get_param('phone'));
  $invoice_number = (string) $request->get_param('invoice_number');

  error_log('Extracted phone: ' . $phone);
  error_log('Extracted invoice_number: ' . $invoice_number);

  // Validate phone: 10 digits, starts with 05
  if (!preg_match('/^05\d{8}$/', $phone)) {
    error_log('❌ Phone validation failed: ' . $phone);
    return [
      'error' => new WP_REST_Response(['ok' => false, 'message' => 'Invalid phone'], 400)
    ];
  }

  // // Validate invoice_number is provided
  // if (empty($invoice_number)) {
  //   error_log('❌ Invoice number is empty');
  //   return [
  //     'error' => new WP_REST_Response(['ok' => false, 'message' => 'Invoice number required'], 400)
  //   ];
  // }

  // Return validated data
  return [
    'phone' => $phone,
    'invoice_number' => $invoice_number
  ];
}

// Helper function to get Strapi webhook endpoint based on environment
function get_strapi_webhook_endpoint($webhook_name) {
  $host = $_SERVER['HTTP_HOST'] ?? '';

  if (strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false) {
    // Local
    $base_url = 'http://localhost:1337';
  } elseif (strpos($host, 'rishumitstg') !== false || strpos($host, 'azurewebsites.net') !== false || $host === 'staging-p.rishumit.online') {
    // Staging
    $base_url = 'https://be-rishumit.azurewebsites.net';
  } elseif (in_array($host, ['rishumit.online'], true)) {
    // Production
    $base_url = 'https://be-rishumit-prod-f9e4fpfjebbdb0bq.israelcentral-01.azurewebsites.net';
  } else {
    // Default to production
    $base_url = 'https://be-rishumit-prod-f9e4fpfjebbdb0bq.israelcentral-01.azurewebsites.net';
  }

  return $base_url . '/api/webhooks/' . $webhook_name;
}

// Helper function to call Strapi webhook with error handling
function call_strapi_webhook($webhook_endpoint, $payload, $error_context = 'Webhook') {
  error_log("Calling {$error_context} webhook: {$webhook_endpoint}");
  error_log('Payload: ' . json_encode($payload));

  // Build headers
  $headers = [
    'Content-Type' => 'application/json',
    'Referer' => get_site_url()
  ];

  // Add auth token if available
  if (defined('STRAPI_API_TOKEN') && !empty(STRAPI_API_TOKEN)) {
    $headers['Authorization'] = 'Bearer ' . STRAPI_API_TOKEN;
  }

  // Call Strapi webhook
  $response = wp_remote_post($webhook_endpoint, [
    'method'  => 'POST',
    'headers' => $headers,
    'body'    => json_encode($payload),
    'timeout' => 30,
    'sslverify' => true,
  ]);

  // Handle errors
  if (is_wp_error($response)) {
    $error_message = $response->get_error_message();
    error_log("❌ Error calling {$error_context} webhook: {$error_message}");
    return [
      'error' => new WP_REST_Response(['ok' => false, 'message' => 'Webhook error: ' . $error_message], 500)
    ];
  }

  $response_code = wp_remote_retrieve_response_code($response);
  $response_body = wp_remote_retrieve_body($response);

  error_log('Webhook response code: ' . $response_code);
  error_log('Webhook response body: ' . $response_body);

  // Check response status
  if ($response_code !== 200) {
    error_log("❌ Webhook returned non-200 status: {$response_code}");
    return [
      'error' => new WP_REST_Response(['ok' => false, 'message' => 'תקלה תקשורת באתר משרד התחבורה, נא צור קשר עם שירות הלקוחות'], $response_code)
    ];
  }

  // Parse response
  $response_data = json_decode($response_body, true);
  if (!isset($response_data['success']) || !$response_data['success']) {
    error_log('❌ Webhook returned success=false');
    return [
      'error' => new WP_REST_Response(['ok' => false, 'message' => $error_context . ' failed'], 500)
    ];
  }

  return ['success' => true];
}

add_action('rest_api_init', function () {
  // Send initial OTP
  register_rest_route('green/v1', '/send-otp', [
    'methods'  => 'POST',
    'callback' => 'green_send_otp',
    'permission_callback' => '__return_true',
  ]);

  // Resend OTP (new code)
  register_rest_route('green/v1', '/send-new-otp', [
    'methods'  => 'POST',
    'callback' => 'green_send_new_otp',
    'permission_callback' => '__return_true',
  ]);

  // Request voice call
  register_rest_route('green/v1', '/ask-voice-call', [
    'methods'  => 'POST',
    'callback' => 'green_ask_voice_call',
    'permission_callback' => '__return_true',
  ]);

  // Log Meshulam response
  register_rest_route('payment/v1', '/log-meshulam-response', [
    'methods'  => 'POST',
    'callback' => 'log_meshulam_response',
    'permission_callback' => '__return_true',
  ]);
});

function green_send_otp(WP_REST_Request $request) {
  error_log('🔍 green_send_otp called');

  // Validate parameters
  $validated = validate_green_otp_params($request);
  if (isset($validated['error'])) {
    return $validated['error'];
  }

  $phone = $validated['phone'];
  $invoice_number = $validated['invoice_number'];

  // Get webhook endpoint
  $webhook_endpoint = get_strapi_webhook_endpoint('setPhoneForOTP');

  // Prepare payload
  $payload = [
    'invoice_number' => $invoice_number,
    'phone_number' => $phone
  ];

  // Call webhook
  $result = call_strapi_webhook($webhook_endpoint, $payload, 'setPhoneForOTP');
  if (isset($result['error'])) {
    return $result['error'];
  }

  error_log('✅ Phone number set for OTP successfully');

  // Return success (do NOT return otp)
  return new WP_REST_Response(['ok' => true], 200);
}

function green_send_new_otp(WP_REST_Request $request) {
  error_log('🔍 green_send_new_otp called');

  // Validate parameters
  $validated = validate_green_otp_params($request);
  if (isset($validated['error'])) {
    return $validated['error'];
  }

  $phone = $validated['phone'];
  $invoice_number = $validated['invoice_number'];

  // Get webhook endpoint
  $webhook_endpoint = get_strapi_webhook_endpoint('sendNewOtp');

  // Prepare payload
  $payload = [
    'invoice_number' => $invoice_number,
    'phone' => $phone
  ];

  // Call webhook
  $result = call_strapi_webhook($webhook_endpoint, $payload, 'sendNewOtp');
  if (isset($result['error'])) {
    return $result['error'];
  }

  error_log('✅ New OTP sent successfully');

  // Return success
  return new WP_REST_Response(['ok' => true], 200);
}

function green_ask_voice_call(WP_REST_Request $request) {  
  error_log('🔍 green_ask_voice_call called');

  // Validate parameters
  $validated = validate_green_otp_params($request);
  if (isset($validated['error'])) {
    return $validated['error'];
  }

  $phone = $validated['phone'];
  $invoice_number = $validated['invoice_number'];

  // Get webhook endpoint
  $webhook_endpoint = get_strapi_webhook_endpoint('doVoiceCallOtp');

  // Prepare payload
  $payload = [
    'invoice_number' => $invoice_number,
    'phone' => $phone
  ];

  // Call webhook
  $result = call_strapi_webhook($webhook_endpoint, $payload, 'doVoiceCallOtp');
  if (isset($result['error'])) {
    return $result['error'];
  }

  error_log('✅ Voice call requested successfully');

  // Return success
  return new WP_REST_Response(['ok' => true], 200);
}

function log_meshulam_response(WP_REST_Request $request) {
  error_log('💳 MESHULAM RESPONSE RECEIVED');

  // Get the response data
  $data = $request->get_json_params();

  // Extract meshulam response, context, and redirect URL
  $meshulam_response = $data['meshulam_response'] ?? $data;
  $context = $data['context'] ?? 'N/A';
  $redirect_url = $data['redirect_url'] ?? 'N/A';

  // Log the full response
  error_log('══════════════════════════════════════════════════════════');
  error_log('💳 Meshulam Payment Response:');
  error_log('📍 Context/Trigger: ' . $context);
  error_log(json_encode($meshulam_response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  error_log('');
  error_log('🔀 Redirect URL: ' . $redirect_url);
  error_log('══════════════════════════════════════════════════════════');

  // Return success
  return new WP_REST_Response(['ok' => true, 'message' => 'Response logged'], 200);
}