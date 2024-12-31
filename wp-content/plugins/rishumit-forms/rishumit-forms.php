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
function rishumit_forms_init() {
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
