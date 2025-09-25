<?php

namespace RishumitPlugin\Leads;

use Exception;

class Form
{
    private $strapiEndpointRequest;
    private $strapiToken;
    private $notifyUrl;
    private bool $isProd = false;
    private ?string $userId   = null;
    private ?string $pageCode = null;

    public function __construct()
    {
        $site_url = get_site_url();
        $host = parse_url($site_url, PHP_URL_HOST) ?: '';

        if (strpos($host, 'local') !== false) {
            $this->isProd = false;
            $this->strapiEndpointRequest = 'http://localhost:1337/api/requests';
            $this->notifyUrl = 'https://a89bf1fe34ae.ngrok-free.app/api/webhooks/create'; // local
        } elseif (strpos($host, 'rishumitstg') !== false || strpos($host, 'azurewebsites.net') !== false) {
            $this->isProd = false;
            $this->strapiEndpointRequest = 'https://be-rishumit.azurewebsites.net/api/requests';
            $this->notifyUrl = 'https://be-rishumit.azurewebsites.net/api/webhooks/create'; // staging
        } elseif (in_array($host, ['rishumit.online', 'rishumit1.wpengine.com'], true)) {
            $this->isProd = true;
            $this->strapiEndpointRequest = 'https://be-rishumit-prod-f9e4fpfjebbdb0bq.israelcentral-01.azurewebsites.net/api/requests';
            $this->notifyUrl = 'https://be-rishumit-prod-f9e4fpfjebbdb0bq.israelcentral-01.azurewebsites.net/api/webhooks/create'; // prod
        } else {
            // unknown host → safer default
            $this->isProd = false;
            $this->strapiEndpointRequest = 'https://be-rishumit.azurewebsites.net/api/requests';
            $this->notifyUrl = 'https://be-rishumit.azurewebsites.net/api/webhooks/create';
        }

        // Load Strapi token from wp-config.php
        if (defined('STRAPI_API_TOKEN')) {
            $this->strapiToken = STRAPI_API_TOKEN;
        } else {
            $this->strapiToken = '';
            error_log('⚠ STRAPI_API_TOKEN not defined in wp-config.php');
        }

        error_log('Env: '.($this->isProd ? 'PROD' : 'NON-PROD').' host='.$host);

        if (defined('USERID') && defined('PAGECODE') && USERID && PAGECODE) {
            $this->userId   = USERID;
            $this->pageCode = PAGECODE;
        } else {
            $this->userId   = '85eaf86f53661afe';
            $this->pageCode = 'de204c9b408d';

            // Only complain in prod
            if ($this->isProd) {
                error_log('❌ Meshulam USERID or PAGECODE missing in wp-config.php');
            }
        }
    }


    public function register()
    {
        // prevent double-registration
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;

        // allow-list of forms your handler SHOULD process
        $allowed = [
            'ESTA',
            'Green Form',
            'Income Tax Exemption',
            'Birth Name Registration',
            'Tax coordination',
            'IDF Certificates',
            'ID appendix',
            'Change Address',
            'Registration Summary',
            'Birth Certificate',
            'Death Certificate',
            'Tabu Service',
        ];

        // Function to check if form should be processed
        $isAllowed = function ($record) use ($allowed) {
            $name = $record->get_form_settings('form_name') ?? '';

            // Log for debugging
            error_log("Form submission detected: '$name'");

            $is_allowed = in_array($name, $allowed, true);
            error_log("Form '$name' is " . ($is_allowed ? 'ALLOWED' : 'SKIPPED'));

            return $is_allowed;
        };

        // remove any previous plain bindings to methods (in case this ran earlier)
        remove_action('elementor_pro/forms/new_record', [$this, 'handleForms'], 10);
        remove_action('elementor_pro/forms/validation', [$this, 'validation'], 10);
        remove_action('elementor_pro/forms/validation/tel', [$this, 'validatePhoneField'], 10);
        remove_action('elementor_pro/forms/validation/number', [$this, 'validateIsraeliID'], 10);

        // reattach via wrappers that skip non-allowed forms
        add_action('elementor_pro/forms/new_record', function ($record, $handler) use ($isAllowed) {
            if (!$isAllowed($record)) {
                error_log("Skipping form processing for: " . ($record->get_form_settings('form_name') ?? 'Unknown'));
                return; // Completely skip - let WordPress handle it normally
            }
            $this->handleForms($record, $handler);
        }, 10, 2);

        add_action('elementor_pro/forms/validation', function ($record, $ajax_handler) use ($isAllowed) {
            if (!$isAllowed($record)) {
                error_log("Skipping form validation for: " . ($record->get_form_settings('form_name') ?? 'Unknown'));
                return; // Completely skip validation
            }
            $this->validation($record, $ajax_handler);
        }, 10, 2);

        add_action('elementor_pro/forms/validation/tel', function ($field, $record, $ajax_handler) use ($isAllowed) {
            if (!$isAllowed($record)) {
                error_log("Skipping phone validation for: " . ($record->get_form_settings('form_name') ?? 'Unknown'));
                return; // Completely skip phone validation
            }
            $this->validatePhoneField($field, $record, $ajax_handler);
        }, 10, 3);

        add_action('elementor_pro/forms/validation/number', function ($field, $record, $ajax_handler) use ($isAllowed) {
            if (!$isAllowed($record)) {
                error_log("Skipping ID validation for: " . ($record->get_form_settings('form_name') ?? 'Unknown'));
                return; // Completely skip ID validation
            }
            $this->validateIsraeliID($field, $record, $ajax_handler);
        }, 10, 3);

        add_action('wp_ajax_create_payment_process', [$this, 'ajaxCreatePaymentProcess']);
        add_action('wp_ajax_nopriv_create_payment_process', [$this, 'ajaxCreatePaymentProcess']);
    }

    public function ajaxCreatePaymentProcess()
    {
        try {
            if (!wp_verify_nonce($_POST['nonce'], 'payment_process_nonce')) {
                wp_die('Security check failed');
            }

            $payment_id = intval($_POST['payment_id']);

            // Get payment data from transient
            $payment_data = get_transient('payment_data_' . $payment_id);

            if (!$payment_data) {
                wp_send_json(['success' => false, 'message' => 'Payment data expired']);
                return;
            }

            $result = $this->createPaymentProcess(
                $payment_data['full_name'],
                $payment_data['phone'],
                $payment_data['email'],
                $payment_data['form_name'],
                $payment_data['strapi_id']
            );

            // Add the Strapi ID to the response
            if ($result['success']) {
                $result['strapiId'] = $payment_data['strapi_id'];
            }

            // Clean up transient
            // delete_transient('payment_data_' . $payment_id);

            wp_send_json($result);
        } catch (Exception $e) {
            wp_send_json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function validatePhoneField($field, $record, $ajax_handler)
    {
        // Get the phone value
        $phone_value = $field['value'] ?? '';

        // Strip all non-numeric characters
        $phone_value = preg_replace('/[^0-9]/', '', $phone_value);

        // Validate the phone number with Israeli format
        if (empty($phone_value)) {
            $ajax_handler->add_error($field['id'], __("מספר טלפון נדרש.", "rishumit-plugin"));
        } elseif (strlen($phone_value) !== 10 || substr($phone_value, 0, 2) !== '05') {
            $ajax_handler->add_error($field['id'], __("אנא הזן מספר טלפון נייד ישראלי תקין (10 ספרות המתחילות ב-05).", "rishumit-plugin"));
        }
    }

    public function validateIsraeliID($field, $record, $ajax_handler)
    {
        if ($field['id'] !== 'ssn') {
            return;
        }

        $id_value = preg_replace('/\D/', '', $field['value'] ?? '');
        $id_value = str_pad($id_value, 9, '0', STR_PAD_LEFT);

        if (empty($id_value)) {
            $ajax_handler->add_error($field['id'], __("מספר תעודת זהות נדרש.", "rishumit-plugin"));
        } elseif (strlen($id_value) !== 9 || !$this->isValidIsraeliID($id_value)) {
            $ajax_handler->add_error($field['id'], __("אנא הזן מספר תעודת זהות ישראלית תקינה.", "rishumit-plugin"));
        }
    }


    private function isValidIsraeliID($id)
    {
        $id = str_pad($id, 9, '0', STR_PAD_LEFT);

        if (!preg_match('/^\d{9}$/', $id)) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $digit = (int) $id[$i];
            $calc = $digit * (($i % 2) + 1);
            if ($calc > 9) {
                $calc -= 9;
            }
            $sum += $calc;
        }

        return ($sum % 10 === 0);
    }




    public function validation($record, $ajax_handler)
    {
        // 1. Check if record and ajax_handler exist
        if (!$record || !$ajax_handler) {
            error_log('Invalid form record or ajax handler');
            return;
        }

        // 2. Get form settings properly
        $formName = $record->get_form_settings('form_name') ?? 'Unknown Form';

        // Only validate basic fields if they exist
        // Email validation
        if ($this->fieldExists($record, 'email')) {
            $this->checkEmail($record, 'email', $ajax_handler);
        }

        // First name validation
        if ($this->fieldExists($record, 'name')) {
            $this->checkName($record, 'first_name', $ajax_handler, 1, 50);
        }

        // Last name validation
        if ($this->fieldExists($record, 'fam')) {
            $this->checkName($record, 'last_name', $ajax_handler, 1, 50);
        }

        // Phone validation
        if ($this->fieldExists($record, 'phone')) {
            $this->checkPhoneNumber($record, 'phone', $ajax_handler, 7, 15);
        }

        // ID number validation
        if ($this->fieldExists($record, 'ssn')) {
            $field = $record->get_field(['id' => 'ssn'])['ssn'];
            $this->validateIsraeliID($field, $record, $ajax_handler);
        }
    }

    /**
     * Check if a field exists in the form record
     */
    private function fieldExists($record, $fieldId)
    {
        $fieldInfo = $record->get_field(['id' => $fieldId]);
        return isset($fieldInfo[$fieldId]);
    }

    /**
     * Sanitize keys by removing colons at the end
     */
    private function sanitizeKey($key)
    {
        // Remove colon at the end of the key
        if (substr($key, -1) === ':') {
            return substr($key, 0, -1);
        }
        return $key;
    }

    public function handleForms($record, $handler)
    {
        try {
            // Retrieve the form name
            $form_name = $record->get_form_settings('form_name') ?? 'Unnamed Form';

            // Retrieve submitted fields
            $fields = $record->get('fields');

            // Log all fields for debugging purposes
            error_log('Form Name: ' . $form_name);

            // Initialize children array
            $children = [];

            // Check the 'nosaf' field for address change
            $change_address_field = isset($fields['nosaf']) ? $fields['nosaf']['value'] : '';

            if (strpos($change_address_field, 'ילדים') !== false || strpos($change_address_field, 'בן/ת זוג') !== false) {
                $children = $this->extractChildren($fields);
            }

            // Extract user, request, and spouse data
            $user = $this->extractUserData($fields, $form_name);
            $request = $this->extractRequestData($fields, $form_name);

            if (!empty($children)) {
                $request['children'] = $children;
            }

            $spouse = $this->extractSpouseData($fields);
            if (!empty($spouse)) {
                $request['spouse'] = $spouse;
            }

            // Filter out empty fields while keeping numerical zeros
            $request = array_filter($request, function ($value) {
                return $value !== '' && ($value || $value === 0 || $value === "0");
            });

            // Append created_by with user email
            $user_email = isset($fields['email']['value']) ? $fields['email']['value'] : '';
            $request['created_by_client_id'] = $user_email;

            // Rename 'חתימת המבקש/ת' to 'חתימה'
            foreach ($request as $key => $value) {
                if (preg_match('/חתימת המבקש.*:/u', $key)) {
                    $request['חתימה'] = $value;
                    unset($request[$key]);
                }
            }

            // Wrap request data inside request_json
            $payload = [
                'data' => [
                    'user' => $user,
                    'request_json' => $request,
                ]
            ];

            // Send to Strapi and get response
            $strapi_response = $this->sendToStrapi($this->strapiEndpointRequest, $payload);

            if (!$strapi_response['success']) {
                // If Strapi reported an error
                error_log('Strapi error: ' . $strapi_response['message']);
                $handler->add_error_message(__("השליחה נכשלה: " . $strapi_response['message'], "rishumit-plugin"));
                return false;
            }

            // Replace the existing redirect code in handleForms with this:

            // Get the ID from the Strapi response
            $response_id = 0;
            // First check if ID is in the root of the response (from your example)
            if (isset($strapi_response['data']) && isset($strapi_response['data']['id'])) {
                $response_id = $strapi_response['data']['id'];
            }
            // Alternatively check the nested data structure (common in Strapi responses)
            elseif (isset($strapi_response['data']) && isset($strapi_response['data']['data']) && isset($strapi_response['data']['data']['id'])) {
                $response_id = $strapi_response['data']['data']['id'];
            }

            if ($response_id > 0) {
                $user_phone = '';
                if (isset($user['phone'])) {
                    $user_phone = $user['phone'];
                } elseif (isset($user['טלפון'])) {
                    $user_phone = $user['טלפון'];
                } elseif (isset($fields['phone']['value'])) {
                    $user_phone = $fields['phone']['value'];
                }

                // Store payment data for frontend
                $payment_data = [
                    'strapi_id' => $response_id,
                    'full_name' => $user['שם פרטי'] . ' ' . ($user['שם משפחה'] ?? ''),
                    'phone' => $user_phone,
                    'email' => $user['email'] ?? '',
                    'form_name' => $form_name,
                    'amount' => $this->getAmountByForm($form_name)
                ];

                // Store in session/transient for frontend to access
                set_transient('payment_data_' . $response_id, $payment_data, 300); // 5 minutes

                $handler->add_success_message(__("הטופס נשלח בהצלחה. מכין תשלום...", "rishumit-plugin"));

                if (method_exists($handler, 'add_response_data')) {
                    $handler->add_response_data('show_payment', true);
                    $handler->add_response_data('payment_id', $response_id);
                    $handler->add_response_data('success', true);
                }

                return true;
            } else {
                // Standard success message if no ID was obtained
                error_log("NO RESPONSE ID - Response ID was: " . var_export($response_id, true));

                $handler->add_success_message(__("הטופס נשלח בהצלחה.", "rishumit-plugin"));
            }
            error_log("ABOUT TO RETURN TRUE FROM END OF METHOD");

            return true;

        } catch (Exception $e) {
            // If any unexpected error occurs, log it and show a generic error
            error_log('Exception in handleForms: ' . $e->getMessage());
            error_log('Exception trace: ' . $e->getTraceAsString());
            $handler->add_error_message(__("אירעה שגיאה לא צפויה. אנא נסה שוב מאוחר יותר.", "rishumit-plugin"));
            return false;
        }
    }

    private function extractUserData($fields, $form_name)
    {
        // Initialize the base user data array
        $user_data = [];

        // Define the fields that should be included in the user data
        $userFields = [
            'name' => 'first_name',
            'fam' => 'last_name',
            'ssn' => 'ID number',
            'email' => 'email',
            'phone' => 'phone',
            'father' => 'father_name',
            'mother' => 'mother_name',
            'day' => 'birth_day',
            'year' => 'birth_year',
            'month' => 'birth_month',
            'status' => 'marital_status',
            'ishi' => 'marital status',
            'dob' => 'date_of_birth',
            'sex' => 'gender',
            'ir' => 'city_of_residence',
            'st' => 'street',
            'bait' => 'house_number',
            'dira' => 'apartment_number',
            'PO' => 'PO Number',
            'country' => 'birth_country',
            'nationality' => 'nationality',
            'city' => 'birth_city',
            'grandpa' => 'grandpa'
        ];

        // Loop through the fields and map them to user data
        foreach ($fields as $field_key => $field) {
            // Skip non-relevant fields
            if (isset($field['type']) && ($field['type'] === 'html' || $field['type'] === 'step')) {
                continue;
            }

            // Check if field title exists in the userFields mapping
            if (isset($userFields[$field_key])) {
                // Use ID as fallback if title is empty
                $field_title = !empty($field['title']) ? $field['title'] : $field['id'];

                // Sanitize the field title to remove trailing colons
                $field_title = $this->sanitizeKey($field_title);

                // For ID appendix form, append "למשלוח" to specific field titles
                if ($form_name === 'ID appendix' || $form_name === 'ספח ת.ז') {
                    $appendDelivery = [
                        'מספר הבית',
                        'מספר הדירה',
                        'הישוב',
                        'הרחוב'
                    ];

                    if (in_array($field_title, $appendDelivery)) {
                        $field_title .= ' למשלוח';
                    }
                }

                // Map field to its appropriate user data key
                $originalValue = isset($field['value']) ? strval($field['value']) : '';
                $user_data[$field_title] = $this->preserveIsraeliIDFormat($originalValue, $field_key, $field_title);

                if ($form_name !== 'Tabu Service') {
                    if ($field_key == 'phone') {
                        $user_data['phone'] = isset($field['value']) ? strval($field['value']) : '';
                    } elseif ($field_key == 'email') {
                        $user_data['email'] = isset($field['value']) ? strval($field['value']) : '';
                    }
                }
            }
        }

        return $user_data;
    }

    private function extractRequestData($fields, $form_name)
    {
        $request_data = [
            'requested_at' => current_time('mysql'),
            'request_source' => 'Website Form',
            'form_name' => $form_name
        ];

        // Apply employer details grouping only for the "Tax coordination" form
        if ($form_name === 'Tax coordination') {
            $request_data['employer_details'] = $this->extractEmployerDetails($fields);
        }

        // Apply Green Form specific processing
        if ($form_name === 'Green Form') {
            // Add green_radio and license_number at the beginning
            if (isset($fields['green_radio']['value']) && !empty($fields['green_radio']['value'])) {
                $request_data['green_radio'] = $fields['green_radio']['value'];
            }
            if (isset($fields['license_number']['value']) && !empty($fields['license_number']['value'])) {
                $request_data['license_number'] = $fields['license_number']['value'];
            }

            // Check which of the 3 license options is chosen and add it
            $license_options = ['issue_license_category', 'add_license_category', 'add_driving_permit'];

            foreach ($license_options as $option) {
                if (isset($fields[$option]['value']) && !empty($fields[$option]['value'])) {
                    $request_data[$option] = $fields[$option]['value'];
                    break; // Only one should be selected, so break after finding it
                }
            }
        }

        // Get non-employer fields
        $additional_fields = $this->extractNonEmployerFields($fields);

        // Transform field names for "registration summary" form
        if (strcasecmp($form_name, 'Registration Summary') === 0) {
            if (isset($additional_fields['שם משפחה'])) {
                $additional_fields['שם משפחה של הנבדק'] = $additional_fields['שם משפחה'];
                unset($additional_fields['שם משפחה']);
            }
            if (isset($additional_fields['שם פרטי'])) {
                $additional_fields['שם פרטי של הנבדק'] = $additional_fields['שם פרטי'];
                unset($additional_fields['שם פרטי']);
            }
        }

        // Merge all fields
        $request_data = array_merge($request_data, $additional_fields);

        return $request_data;
    }

    private function extractNonEmployerFields($fields)
    {
        $non_employer_data = [];

        // Define fields that belong to user data and should be excluded
        $user_data_fields = [
            'name', 'fam', 'ssn', 'email', 'phone', 'father', 'mother',
            'day', 'year', 'month', 'status', 'ishi', 'dob', 'sex',
            'ir', 'st', 'bait', 'dira', 'country', 'nationality', 'city', 'grandpa'
        ];

        foreach ($fields as $field_key => $field) {
            // Skip non-relevant field types and fields part of user data
            if (
                (isset($field['type']) && ($field['type'] === 'html' || $field['type'] === 'step')) ||
                in_array($field_key, $user_data_fields) ||
                preg_match('/^emp(\d+)_/', $field_key) || preg_match('/^child_\d+_/', $field_key) || preg_match('/^spouse_/', $field_key)
                // Exclude employer, child fields and spouse fields
            ) {
                continue;
            }

            // Use title as the key if available, fallback to id
            $key = !empty($field['title']) ? $field['title'] : $field['id'];

            // Sanitize the key to remove trailing colons
            $key = $this->sanitizeKey($key);

            // Add the field to non-employer data if it has a value
            if (isset($field['value'])) {
                $originalValue = $field['value'];
                $non_employer_data[$key] = $this->preserveIsraeliIDFormat($originalValue, $field_key, $key);
            }
        }

        return $non_employer_data;
    }

    private function extractEmployerDetails($fields)
    {
        $employers = []; // Temporary array to hold each employer's details

        foreach ($fields as $field_key => $field) {
            // Skip non-relevant field types
            if (isset($field['type']) && ($field['type'] === 'html' || $field['type'] === 'step')) {
                continue;
            }

            // Detect employer-related fields by their prefixes (e.g., emp1_, emp2_)
            if (preg_match('/^emp(\d+)_/', $field_key, $matches)) {
                $employer_index = (int)$matches[1]; // Extract employer index (e.g., 1, 2, 3)

                // Initialize the employer object if it doesn't exist
                if (!isset($employers[$employer_index])) {
                    $employers[$employer_index] = [];
                }

                // Get the field ID and sanitize it if needed
                $field_id = str_replace("emp{$employer_index}_", '', $field_key);
                $field_id = $this->sanitizeKey($field_id);

                // Add the field's id and value to the employer object
                if (isset($field['value'])) {
                    $employers[$employer_index][$field_id] = $field['value'];
                }
            }
        }

        // Return the employers as an indexed array for JSON consistency
        return array_values($employers);
    }

    private function sendToStrapi($endpoint, $data)
    {
        try {
            // Ensure data is correctly formatted
            $json_data = json_encode($data);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log('JSON encoding error: ' . json_last_error_msg());
                return ['success' => false, 'message' => 'Data formatting error: ' . json_last_error_msg()];
            }

            // Log the request details
            error_log('Sending to Strapi: ' . $endpoint);

            // Build headers array first
            $headers = [
                'Content-Type'  => 'application/json',
                'Referer' => get_site_url()
            ];

            // Only add auth header if token exists
            if (!empty($this->strapiToken)) {
                $headers['Authorization'] = 'Bearer ' . $this->strapiToken;
            }

            $response = wp_remote_post($endpoint, [
                'method'  => 'POST',
                'headers' => $headers,
                'body'    => $json_data,
                'timeout' => 45, // Increase timeout for potential slow responses
                'sslverify' => true,
            ]);

            if (is_wp_error($response)) {
                $error_message = $response->get_error_message();
                error_log('Error sending to Strapi: ' . $error_message);
                return ['success' => false, 'message' => 'Connection error: ' . $error_message];
            }

            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);

            // Check for unsuccessful HTTP codes
            if ($response_code < 200 || $response_code >= 300) {
                error_log("Strapi returned error code: $response_code");

                // Try to get a more detailed error message from the response body
                $error_details = json_decode($response_body, true);
                $error_message = '';

                if (json_last_error() === JSON_ERROR_NONE && isset($error_details['error'])) {
                    if (is_array($error_details['error'])) {
                        $error_message = isset($error_details['error']['message'])
                            ? $error_details['error']['message']
                            : "Unknown error";
                    } else {
                        $error_message = $error_details['error'];
                    }
                } else {
                    $error_message = "Server error (HTTP $response_code)";
                }

                return ['success' => false, 'message' => $error_message];
            }

            // Check if response is valid JSON first
            $decoded_response = json_decode($response_body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                // If not valid JSON but status code is 200, it might be a text message
                if ($response_code == 200 && !empty($response_body)) {
                    error_log("Non-JSON response from Strapi: " . $response_body);
                    return [
                        'success' => false,
                        'message' => $response_body // Use the response text as error message
                    ];
                } else {
                    error_log("Invalid JSON from Strapi: " . json_last_error_msg());
                    return ['success' => false, 'message' => 'Invalid server response'];
                }
            }

            // If we reach here, everything was successful
            return [
                'success' => true,
                'data' => $decoded_response,
                'message' => 'Form submitted successfully'
            ];

        } catch (Exception $e) {
            error_log('Exception in sendToStrapi: ' . $e->getMessage());
            error_log('Exception trace: ' . $e->getTraceAsString());
            return ['success' => false, 'message' => 'Server error: ' . $e->getMessage()];
        }
    }

    private function extractSpouseData($fields)
    {
        $spouse = [];

        // Retrieve the value of the field using its id 'nosaf'
        $address_change_needed = isset($fields['nosaf']) ? $fields['nosaf']['value'] : '';

        // If the value contains "ילדים" or "ילדים ובן/ת זוג", extract the children and spouse data
        if (strpos($address_change_needed, 'ילדים') !== false || strpos($address_change_needed, 'בן/ת זוג') !== false) {
            // Extract spouse data
            $spouse['first_name'] = isset($fields['spouse_first_name']['value']) ? $fields['spouse_first_name']['value'] : '';
            $spouse['last_name'] = isset($fields['spouse_last_name']['value']) ? $fields['spouse_last_name']['value'] : '';
            $spouse['id_number'] = isset($fields['spouse_id']['value']) ? $fields['spouse_id']['value'] : '';
            $spouse['father_name'] = isset($fields['spouse_father_name']['value']) ? $fields['spouse_father_name']['value'] : '';
            $spouse['mother_name'] = isset($fields['spouse_mother_name']['value']) ? $fields['spouse_mother_name']['value'] : '';
            $spouse['birth_year'] = isset($fields['spouse_birth_year']['value']) ? $fields['spouse_birth_year']['value'] : '';

            // If the spouse data is complete (all fields filled), return it
            if (!empty($spouse['first_name']) && !empty($spouse['last_name']) && !empty($spouse['id_number'])) {
                return $spouse;
            }
        }

        // Return the spouse data (empty if not present)
        return [];
    }

    private function extractChildren($fields)
    {
        $children = [];  // This will store all the children data

        // Check the number of children from the 'child' field
        $num_children = isset($fields['child']['value']) ? (int)$fields['child']['value'] : 0;

        // If no children, return an empty array
        if ($num_children == 0) {
            error_log("No children data available.");
            return $children;
        }

        // Loop through the number of children and collect data
        for ($i = 1; $i <= $num_children; $i++) {
            // Construct the field names dynamically based on child number
            $child_prefix = "child_{$i}"; // Dynamic child identifier (e.g., "child_1", "child_2")

            $first_name_field = $child_prefix . '_first_name';
            $last_name_field = $child_prefix . '_last_name';
            $id_field = $child_prefix . '_id';
            $father_name_field = $child_prefix . '_father_name';
            $mother_name_field = $child_prefix . '_mother_name';
            $birth_year_field = $child_prefix . '_birth_year';

            // Collect data for each child if available, using proper array access
            $first_name = isset($fields[$first_name_field]['value']) ? $fields[$first_name_field]['value'] : '';
            $last_name = isset($fields[$last_name_field]['value']) ? $fields[$last_name_field]['value'] : '';
            $id = isset($fields[$id_field]['value']) ? $fields[$id_field]['value'] : '';
            $father_name = isset($fields[$father_name_field]['value']) ? $fields[$father_name_field]['value'] : '';
            $mother_name = isset($fields[$mother_name_field]['value']) ? $fields[$mother_name_field]['value'] : '';
            $birth_year = isset($fields[$birth_year_field]['value']) ? $fields[$birth_year_field]['value'] : '';

            // Add the child to the array if all required data is available
            if ($first_name && $last_name && $id) {
                $children[] = [
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'id' => $id,
                    'father_name' => $father_name,
                    'mother_name' => $mother_name,
                    'birth_year' => $birth_year,
                ];
            } else {
                // Log that some required fields are missing for this child
                error_log("Skipping child $i due to missing required data.");
            }
        }

        return $children;
    }

    private function checkPhoneNumber($record, $fieldName, $ajax_handler, $min_len = 1, $max_len = 50)
    {
        // Adjust the fieldName if necessary for field mapping
        $actualFieldName = $fieldName; // In this case, phone is the actual field name

        // Get the field information, using isset to ensure the key exists
        $fieldInfo = $record->get_field(['id' => $actualFieldName]);

        // Check if the field exists
        if (!isset($fieldInfo[$actualFieldName])) {
            // Just log and continue - don't add error
            error_log("Phone field not found: $actualFieldName");
            return;
        }

        // Log the phone value for debugging
        $phone_value = isset($fieldInfo[$actualFieldName]['value']) ? $fieldInfo[$actualFieldName]['value'] : 'EMPTY';

        // Accept almost any phone input for now to debug
        // Only validate if it's completely empty
        if (empty($fieldInfo[$actualFieldName]['value']) && isset($fieldInfo[$actualFieldName]['required']) && $fieldInfo[$actualFieldName]['required']) {
            $ajax_handler->add_error($fieldName, __("Phone number is required.", "rishumit-plugin"));
        }
    }

    private function checkName($record, $fieldName, $ajax_handler, $min_len = 1, $max_len = 100)
    {
        // Correct field IDs mapping
        $fieldMap = [
            'first_name' => 'name',
            'last_name' => 'fam',
        ];

        if (!isset($fieldMap[$fieldName])) {
            error_log("Field map not found for: $fieldName");
            return;
        }

        $actualFieldName = $fieldMap[$fieldName];
        $fieldInfo = $record->get_field(['id' => $actualFieldName]);

        if (!isset($fieldInfo[$actualFieldName])) {
            error_log("Field not found: $actualFieldName");
            return;
        }

        // Log the field value for debugging
        $name_value = isset($fieldInfo[$actualFieldName]['value']) ? $fieldInfo[$actualFieldName]['value'] : 'EMPTY';

        // Accept virtually any input for now to debug
        // Only validate if it's completely empty
        if (empty($fieldInfo[$actualFieldName]['value']) && isset($fieldInfo[$actualFieldName]['required']) && $fieldInfo[$actualFieldName]['required']) {
            $ajax_handler->add_error($actualFieldName, __("שם נדרש.", "rishumit-plugin"));
        }
    }

    private function checkEmail($record, $fieldName, $ajax_handler)
    {
        // Get the field information, using isset to ensure the key exists
        $fieldInfo = $record->get_field(['id' => $fieldName]);

        // Check if the field is set and has a value before accessing it
        if (!isset($fieldInfo[$fieldName])) {
            error_log("Email field not found: $fieldName");
            return;
        }

        $field = isset($fieldInfo[$fieldName]['raw_value']) ? $fieldInfo[$fieldName]['raw_value'] : '';

        // If the field value is not empty and is not a valid email, add an error
        if (!empty($field) && !filter_var($field, FILTER_VALIDATE_EMAIL)) {
            $ajax_handler->add_error($fieldName, __("כתובת אימייל לא תקינה.", "rishumit-plugin"));
        }
    }

    private function createPaymentProcess($full_name, $phone, $email, $form_name, $strapi_id)
{
    $endpoint = $this->isProd
        ? 'https://meshulam.co.il/api/light/server/1.0/createPaymentProcess'
        : 'https://sandbox.meshulam.co.il/api/light/server/1.0/createPaymentProcess';

    if (!empty($phone)) {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($phone) == 9 && substr($phone, 0, 1) != '0') {
            $phone = '0' . $phone;
        }
        if (strlen($phone) < 9 || strlen($phone) > 12) {
            error_log("Phone number had invalid length after formatting: $phone. Using fallback.");
            $phone = '0500000000';
        }
    } else {
        error_log("Phone was empty. Using fallback.");
        $phone = '0500000000';
    }

    if (empty(trim($full_name)) || strlen(trim($full_name)) < 3) {
        error_log("Name invalid for Meshulam payment: '$full_name'");
        $full_name = "Customer " . $strapi_id;
    }
    if (strlen($full_name) > 50) {
        $full_name = substr($full_name, 0, 47) . '...';
    }

    // Get conversion_id based on form name
    $conversion_id = $this->getConversionIdByForm($form_name);

    $params = [
        'userId' => $this->userId,
        'pageCode' => $this->pageCode,
        'sum' => $this->getAmountByForm($form_name),
        'successUrl' => site_url('/thank-you?conversion_id=' . $conversion_id . '&id=' . $strapi_id . '&form=' . urlencode($form_name)),
        'cancelUrl' => site_url('/payment-cancelled?id=' . $strapi_id),
        'notifyUrl' => $this->notifyUrl,
        'description' => 'Form: ' . $form_name . ' / ID: ' . $strapi_id,
        'pageField[fullName]' => trim($full_name),
        'pageField[phone]' => preg_replace('/[^0-9]/', '', $phone),
        'pageField[email]' => $email,
        'cField1' => $strapi_id,
        'paymentNum' => 1,
        'id' => $strapi_id,
    ];

    $response = wp_remote_post($endpoint, [
        'method' => 'POST',
        'body' => $params,
        'timeout' => 45,
    ]);

    if (is_wp_error($response)) {
        error_log("Meshulam error: " . $response->get_error_message());
        return ['success' => false, 'message' => $response->get_error_message()];
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    error_log("Decoded Meshulam response: " . print_r($body, true));

    if (!isset($body['status']) || $body['status'] !== 1) {
        error_log("Meshulam payment creation failed: " .
            (isset($body['err']['message']) ? $body['err']['message'] : 'Unknown error'));
        return ['success' => false, 'message' => 'Payment process creation failed'];
    }

    return [
        'success' => true,
        'authCode' => $body['data']['authCode'] ?? null,
        'processId' => $body['data']['processId'] ?? null,
        'processToken' => $body['data']['processToken'] ?? null,
        'successUrl' => $params['successUrl'] 
    ];
}

private function getConversionIdByForm($form_name)
{
    $conversion_ids = [
        'ESTA' => 'visa',
        'Green Form' => 'driver',
        'Birth Name Registration' => 'baby',
        'Change Address' => 'shinuy',
        'Tax coordination' => 'coordination',
        'Registration Summary' => 'info',
        'IDF Certificates' => 'military',
        'Birth Certificate' => 'leida',
        'Death Certificate' => 'death',
        'ID appendix' => 'appendix',
        'Tabu Service' => 'nesach',
        'Income Tax Exemption' => 'tax' // Added a reasonable default for this one
    ];

    return $conversion_ids[$form_name] ?? 'general';
}


    private function getAmountByForm($form_name)
    {
        $amounts = [
        'ESTA' => 299,
        'Green Form' => 189,
        'Income Tax Exemption' => 239,
        'Birth Name Registration' => 189,
        'Tax coordination' => 229,
        'IDF Certificates' => 159,
        'ID appendix' => 189,
        'Change Address' => 189,
        'Registration Summary' => 189,
        'Birth Certificate' => 189,
        'Death Certificate' => 189,
        'Tabu Service' => 189
    ];

        return $amounts[$form_name] ?? 159;
    }

    private function preserveIsraeliIDFormat($value, $fieldKey, $fieldTitle) 
{
    // List of field keys and titles that should be treated as Israeli IDs
    $id_field_indicators = [
        // Field keys (English)
        'ssn', 'id', 'id_number', 'teudat_zehut', 'tz',
        // Hebrew indicators (will match partial strings)
        'תעודת זהות', 'ת.ז', 'מספר זהות', 'זהות', 'תז',
        // Child and spouse patterns
        'child_', 'spouse_id'
    ];
    
    // Check if this field represents an Israeli ID
    $isIDField = false;
    
    // Check both field key and title
    $searchStrings = [$fieldKey, $fieldTitle];
    
    foreach ($searchStrings as $searchString) {
        foreach ($id_field_indicators as $indicator) {
            if (strpos($searchString, $indicator) !== false) {
                $isIDField = true;
                break 2; // Break out of both loops
            }
        }
    }
    
    // If it's an ID field and exactly 8 digits, add leading zero
    if ($isIDField && !empty($value)) {
        // Remove all non-digits
        $cleanId = preg_replace('/\D/', '', $value);
        
        // Only pad if it's exactly 8 digits
        if (strlen($cleanId) === 8) {
            return '0' . $cleanId;
        }
        
        // Return the cleaned ID as-is for other lengths
        return $cleanId;
    }
    
    return $value;
}


}
