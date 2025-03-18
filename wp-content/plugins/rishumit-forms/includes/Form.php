<?php

namespace RishumitPlugin\Leads;

use Exception;

class Form
{
   private $strapiEndpointUser;
   private $strapiEndpointRequest;
   private $strapiToken;

   public function __construct()
   {
        $this->strapiEndpointRequest = 'https://be-rishumit-prod-f9e4fpfjebbdb0bq.israelcentral-01.azurewebsites.net/api/requests';
        // dev https://be-rishumit.azurewebsites.net/api/requests
        $this->strapiToken = '479e3212fb5013aa56e0ca849364a719c02516eb7ed9ac4670729a8bae2c7b8c0005c1c6d7e775f8b60ea66942ea74f8113a5fc4e367d2add23df62e44cc716bc5b7f6eaf91c96e4fcd5da5aa92424b1c241093cc5365153fd6aa8f05c320b47382329f4087aec412df04e414cd4cdcc7fd63c83a9f092de1cbaf0cc7dbaa1df';
   }

   public function register()
   {
       // Register hooks for form validation and submission
       add_action('elementor_pro/forms/new_record', [$this, 'handleForms'], 10, 2);
       add_action('elementor_pro/forms/validation', [$this, 'validation'], 10, 2);
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
           error_log('Filtered Form Fields: ' . print_r($fields, true));

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

           // Log complete payload for debugging
           error_log('Complete payload: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

           // Use debug function before sending to Strapi
           $this->debugFormSubmission($payload, null, $fields);

           // Send to Strapi and get response
           $strapi_response = $this->sendToStrapi($this->strapiEndpointRequest, $payload);
           
           // Use debug function with the response
           $this->debugFormSubmission($payload, $strapi_response, $fields);

           if (!$strapi_response['success']) {
               // If Strapi reported an error
               error_log('Strapi error: ' . $strapi_response['message']);
               $handler->add_error_message(__('Submission failed: ' . $strapi_response['message'], 'rishumit-plugin'));
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
        else if (isset($strapi_response['data']) && isset($strapi_response['data']['data']) && isset($strapi_response['data']['data']['id'])) {
            $response_id = $strapi_response['data']['data']['id'];
        }

        // IMPORTANT: Success handling with redirect
        if ($response_id > 0) {
            // Get the form-specific payment URL
            $redirect_url = $this->getPaymentUrl($form_name, $response_id);
            
            // Log the redirect URL for debugging
            error_log("Redirecting to payment URL: $redirect_url");
            
            // Use Elementor's native redirect mechanism which is the proper way for AJAX forms
            if (method_exists($handler, 'add_response_data')) {
                $handler->add_response_data('redirect_url', $redirect_url);
                $handler->add_response_data('redirect_to', $redirect_url); // Try both possible parameter names
            }
            
            // Add a success message with a note about redirection
            $handler->add_success_message(__('Form submitted successfully. Redirecting to payment page...', 'rishumit-plugin'));
            
            // Add a custom redirect script with a hook that runs late in the process
            add_action('elementor_pro/forms/after_send', function() use ($redirect_url) {
                // Output redirect JavaScript that will execute after form submission
                add_action('wp_footer', function() use ($redirect_url) {
                    ?>
                    <script type="text/javascript">
                    // Track if redirect has been triggered
                    var redirectTriggered = false;
                    
                    // Function to handle redirection
                    function handleRedirect() {
                        if (!redirectTriggered) {
                            redirectTriggered = true;
                            console.log("Redirecting to payment page...");
                            window.location.href = "<?php echo $redirect_url; ?>";
                        }
                    }
                    
                    // Add event listener for form submissions
                    document.addEventListener('elementor/submit/success', function() {
                        setTimeout(handleRedirect, 1500);
                    });
                    
                    // Backup redirect after 3 seconds
                    setTimeout(handleRedirect, 3000);
                    </script>
                    <?php
                }, 99);
            });
        } else {
            // Standard success message if no ID was obtained
            $handler->add_success_message(__('Form submitted successfully.', 'rishumit-plugin'));
        }
           
           return true;
           
       } catch (Exception $e) {
           // If any unexpected error occurs, log it and show a generic error
           error_log('Exception in handleForms: ' . $e->getMessage());
           error_log('Exception trace: ' . $e->getTraceAsString());
           $handler->add_error_message(__('An unexpected error occurred. Please try again later.', 'rishumit-plugin'));
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

               // Map field to its appropriate user data key
               $user_data[$field_title] = isset($field['value']) ? strval($field['value']) : '';
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

       // Get non-employer fields
       $additional_fields = $this->extractNonEmployerFields($fields);

       // Transform field names for "registration summary" form
       if ($form_name === 'registration summary') {
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
               preg_match('/^emp(\d+)_/', $field_key) // Exclude employer-related fields
           ) {
               continue;
           }

           // Use title as the key if available, fallback to id
           $key = !empty($field['title']) ? $field['title'] : $field['id'];
           
           // Sanitize the key to remove trailing colons
           $key = $this->sanitizeKey($key);

           // Add the field to non-employer data if it has a value
           if (isset($field['value'])) {
               $non_employer_data[$key] = $field['value'];
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
           error_log('Request data: ' . $json_data);

           $response = wp_remote_post($endpoint, [
               'method'  => 'POST',
               'headers' => [
                   'Content-Type'  => 'application/json',
                   'Authorization' => 'Bearer ' . $this->strapiToken,
                   'Referer' => get_site_url()
               ],
               'body'    => $json_data,
               'timeout' => 45, // Increase timeout for potential slow responses
               'sslverify' => false // Try disabling SSL verification if HTTPS issues occur
           ]);

           if (is_wp_error($response)) {
               $error_message = $response->get_error_message();
               error_log('Error sending to Strapi: ' . $error_message);
               return ['success' => false, 'message' => 'Connection error: ' . $error_message];
           }

           $response_code = wp_remote_retrieve_response_code($response);
           $response_body = wp_remote_retrieve_body($response);

           // Log the response status
           error_log("Strapi response code: $response_code");
           error_log("Strapi response body: $response_body");

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
       error_log("Phone validation - value: $phone_value");
       
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
       error_log("Name validation for $fieldName (actual: $actualFieldName) - value: $name_value");
       
       // Accept virtually any input for now to debug
       // Only validate if it's completely empty
       if (empty($fieldInfo[$actualFieldName]['value']) && isset($fieldInfo[$actualFieldName]['required']) && $fieldInfo[$actualFieldName]['required']) {
           $ajax_handler->add_error($actualFieldName, __("Name is required.", "rishumit-plugin"));
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
           $ajax_handler->add_error($fieldName, __("Invalid email address.", "rishumit-plugin"));
       }
   }
   /**
    * Add this function to your Form class to debug the form submission
    */
    private function debugFormSubmission($payload, $strapi_response, $fields)
    {
        // Create a debug log file in the wp-content directory
        $upload_dir = wp_upload_dir();
        $log_file = $upload_dir['basedir'] . '/form_debug_' . date('Y-m-d') . '.log';
        
        // Start logging
        $log = "\n\n===== FORM SUBMISSION DEBUG " . current_time('mysql') . " =====\n";
        
        // Log the raw fields
        $log .= "=== RAW FORM FIELDS ===\n";
        $log .= print_r($fields, true);
        
        // Log the processed payload
        $log .= "\n=== PROCESSED PAYLOAD ===\n";
        $log .= json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
        // Log the Strapi response
        $log .= "\n=== STRAPI RESPONSE ===\n";
        if (is_array($strapi_response)) {
            $log .= "Success: " . ($strapi_response['success'] ? 'true' : 'false') . "\n";
            $log .= "Message: " . ($strapi_response['message'] ?? 'No message') . "\n";
            
            if (isset($strapi_response['data'])) {
                $log .= "Data: " . json_encode($strapi_response['data'], JSON_PRETTY_PRINT) . "\n";
            }
        } else {
            $log .= "Non-array response: " . print_r($strapi_response, true) . "\n";
        }
        
        // Test Strapi connection directly
        $log .= "\n=== TESTING STRAPI CONNECTION ===\n";
        $test_response = wp_remote_get($this->strapiEndpointRequest, [
            'headers' => [
                'Authorization' => 'Bearer 479e3212fb5013aa56e0ca849364a719c02516eb7ed9ac4670729a8bae2c7b8c0005c1c6d7e775f8b60ea66942ea74f8113a5fc4e367d2add23df62e44cc716bc5b7f6eaf91c96e4fcd5da5aa92424b1c241093cc5365153fd6aa8f05c320b47382329f4087aec412df04e414cd4cdcc7fd63c83a9f092de1cbaf0cc7dbaa1df',
            ],
            'timeout' => 30
        ]);
        
        if (is_wp_error($test_response)) {
            $log .= "Connection test failed: " . $test_response->get_error_message() . "\n";
        } else {
            $log .= "Connection test response code: " . wp_remote_retrieve_response_code($test_response) . "\n";
            $log .= "Connection test headers: " . print_r(wp_remote_retrieve_headers($test_response), true) . "\n";
        }
        
        // Write to log file
        file_put_contents($log_file, $log, FILE_APPEND);
        
        // Also send an admin email with the debug info
        $admin_email = get_option('admin_email');
        wp_mail(
            $admin_email,
            'Form Submission Debug Report',
            "Form submission debug information has been logged to: $log_file\n\nThe key issues may be in the Strapi response section."
        );
    }

    /**
     * Gets the appropriate payment URL based on the form name
     * 
     * @param string $form_name The name of the form submitted
     * @param int $response_id The ID from Strapi response
     * @return string The payment URL
     */
    private function getPaymentUrl($form_name, $response_id) {
        // Define the mapping between form names and their payment URLs
        $payment_urls = [
            'ESTA' => 'https://meshulam.co.il/s/8970f3af-fb24-e275-dd1e-037e7692c428',
            'Green Form' => 'https://meshulam.co.il/s/04e8e085-b32e-30cc-0136-d61b25b411f8',
            'Income Tax Exemption' => 'https://meshulam.co.il/s/e8277735-1eae-1f8b-a101-602248f45909',
            'Birth Name Registration' => 'https://meshulam.co.il/s/535db637-bf99-c5cd-ab1c-7ce14fab8512',
            'Tax coordination' => 'https://meshulam.co.il/s/c697ab1b-3e6f-af97-1536-06d6cb104c52',
            'IDF Certificates' => 'https://meshulam.co.il/quick_payment?b=5e07431f82ad1513a07a6661f0180258',
            'ID appendix' => 'https://meshulam.co.il/s/b11b43e4-645e-4d9c-86fb-e232a5b892af',
            'Change Address' => 'https://meshulam.co.il/s/2ede72e5-2957-ec05-a9ad-92ce9a45b459',
            'Registration Summary' => 'https://meshulam.co.il/s/10e0a10a-925b-2d5c-aab8-35a55fdc9593',
            'Birth Certificate' => 'https://meshulam.co.il/s/9459aa72-a5f8-aac0-5505-14c54871aa4f',
            'Death Certificate' => 'https://meshulam.co.il/s/7b0d477d-70d4-859d-c62b-f10b14a1daa3'
        ];

        // Log the form name for debugging
        error_log("Getting payment URL for form: $form_name");

        // Check if we have a specific URL for this form
        if (isset($payment_urls[$form_name])) {
            $base_url = $payment_urls[$form_name];
            
            // Check if the URL already contains query parameters
            if (strpos($base_url, '?') !== false) {
                // URL already has query parameters, append the ID
                return $base_url . '&id=' . $response_id;
            } else {
                // URL doesn't have query parameters, add them
                return $base_url . '?id=' . $response_id;
            }
        }

        // Fallback to the default payment URL if form name not found
        error_log("No specific payment URL found for: $form_name, using default");
        return 'https://meshulam.co.il/quick_payment?b=933da63c75d1f89aabe220cd09470043&id=' . $response_id;
    }
}