<?php
/**
 * Plugin Name: Rishumit Forms
 * Description: Handles form submissions and validations for Rishumit forms.
 * Version: 1.0
 * Author: Your Name
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Autoload classes
require_once __DIR__ . '/includes/Form.php';

// Initialize the plugin
function rishumit_forms_init() {
    $formHandler = new \RishumitPlugin\Leads\Form();
    $formHandler->register();
}
add_action('plugins_loaded', 'rishumit_forms_init');




// /**
//  * Debug Elementor Pro Form Data
//  */

// // Hook into Elementor Pro form validation to capture and log JSON data.
// add_action('elementor_pro/forms/validation', 'log_form_data_during_validation', 10, 2);
// add_action('elementor_pro/forms/new_record', 'log_form_data_during_submission', 10, 2);

// /**
//  * Log form data during validation and add JSON to error message for debugging.
//  *
//  * @param \ElementorPro\Modules\Forms\Classes\Record $record The form record object.
//  * @param \ElementorPro\Modules\Forms\Classes\Ajax_Handler $ajax_handler The AJAX handler.
//  */
// function log_form_data_during_validation($record, $ajax_handler) {
//     // Get all submitted fields and form name
//     $fields = $record->get('fields');
//     $form_name = $record->get_form_settings('form_name');

//     // Convert form data to JSON
//     $json_data = json_encode($fields, JSON_PRETTY_PRINT);

//     // Log the JSON data to debug.log
//     error_log("Form Validation - Form Name: $form_name");
//     error_log("Validation Data (JSON): $json_data");

//     // Add the JSON data to the AJAX error response (temporary for debugging)
//     $ajax_handler->add_error('', 'Debug JSON: ' . $json_data);
// }

// /**
//  * Log form data during submission to capture all fields in JSON format.
//  *
//  * @param \ElementorPro\Modules\Forms\Classes\Record $record The form record object.
//  * @param \ElementorPro\Modules\Forms\Classes\Ajax_Handler $handler The AJAX handler.
//  */
// function log_form_data_during_submission($record, $handler) {
//     // Get all submitted fields and form name
//     $fields = $record->get('fields');
//     $form_name = $record->get_form_settings('form_name');

//     // Convert form data to JSON
//     $json_data = json_encode($fields, JSON_PRETTY_PRINT);

//     // Log the JSON data to debug.log
//     error_log("Form Submission - Form Name: $form_name");
//     error_log("Submitted Data (JSON): $json_data");

//     // Stop default operations for testing
//     $handler->add_error('', 'Form submission disabled. Debug JSON: ' . $json_data);
// }

// // Enable WordPress debugging if not already set in wp-config.php
// if (!defined('WP_DEBUG')) {
//     define('WP_DEBUG', true);
// }
// if (!defined('WP_DEBUG_LOG')) {
//     define('WP_DEBUG_LOG', true);
// }
// if (!defined('WP_DEBUG_DISPLAY')) {
//     define('WP_DEBUG_DISPLAY', false);
// }
