<?php

namespace RishumitPlugin\Leads;

use Exception;

class Form
{
    private $strapiEndpointUser;
    private $strapiEndpointRequest;

    public function __construct()
    {
        // Strapi endpoints
        $this->strapiEndpointUser = getenv('STRAPI_ENDPOINT_USER');
        $this->strapiEndpointRequest = getenv('STRAPI_ENDPOINT_REQUEST');
    }

    public function register()
    {
        // Register hooks for form validation and submission
        add_action('elementor_pro/forms/new_record', [$this, 'handleForms'], 10, 2);
        add_action('elementor_pro/forms/validation', [$this, 'validation'], 10, 2);
    }

    public function validation($record, $ajax_handler)
    {
        // Prevent emails from being sent during testing
        remove_action('elementor_pro/forms/new_record', 'ElementorPro\Modules\Forms\Actions\Email\Action::send_email');

        // Retrieve the form name
        $formName = $record->get_form_settings('form_name');

        // Perform validation logic
        $this->checkEmail($record, 'email', $ajax_handler);
        $this->checkName($record, 'first_name', $ajax_handler, 2, 40);
        $this->checkName($record, 'last_name', $ajax_handler, 2, 40);
        $this->checkPhoneNumber($record, 'phone', $ajax_handler);
    }

    public function handleForms($record, $handler)
{
    // Retrieve the form name
    $form_name = $record->get_form_settings('form_name') ?? 'Unnamed Form';
    
    // Retrieve submitted fields
    $fields = $record->get('fields');

    // Log all fields for debugging purposes (optional)
    error_log('Filtered Form Fields: ' . print_r($fields, true));

    // Check if the field for address change request for children or spouse is relevant
    // Check the 'nosaf' field for address change
    $change_address_field = isset($fields['nosaf']) ? $fields['nosaf']['value'] : '';

    // Log the value of the 'nosaf' field
    error_log('Value of address change (nosaf) field: ' . $change_address_field);

    // If the field contains "ילדים" or "ילדים ובן/ת זוג", call extractChildren
    $children = [];
    if (strpos($change_address_field, 'ילדים') !== false || strpos($change_address_field, 'בן/ת זוג') !== false) {
        $children = $this->extractChildren($fields);
        if (!empty($children)) {
            // Log children data if relevant
            error_log('Children Data: ' . print_r($children, true));
        }
    }

    // Automatically process user data
    $user = $this->extractUserData($fields, $form_name);
    $request = $this->extractRequestData($fields, $form_name);

    // If children are found, add them to the user data
    if (!empty($children)) {
        $user['children'] = $children;  // Add the children array to the user data
    }

    // Extract spouse data (if relevant)
    $spouse = $this->extractSpouseData($fields);

    // If spouse data exists, add it to the user object
    if (!empty($spouse)) {
        $user['spouse'] = $spouse;
    }

    // Decode any Unicode characters
    $json_user = json_encode($user, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $json_request = json_encode($request, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
    // Log the user and request data to debug.log in JSON format
    error_log('User Data: ' . $json_user);
    error_log('Request Data: ' . $json_request);

    // Optional: Stop form submission for testing
    wp_die('Form submission stopped for testing purposes');
    
    // For production, send data to Strapi (uncomment when ready for production)
    // if ($this->strapiEndpointUser && $this->strapiEndpointRequest) {
    //     $this->sendToStrapi($this->strapiEndpointUser, $user);
    //     $this->sendToStrapi($this->strapiEndpointRequest, $request);
    // } else {
    //     // Log data locally if Strapi is not configured
    //     error_log("Strapi endpoints are not configured. User Data: " . json_encode($user));
    //     error_log("Request Data: " . json_encode($request));
    // }
}



    private function extractUserData($fields, $form_name)
    {
        // Initialize the base user data array
        $user_data = [];

        // Loop through the fields and generate dynamic keys from titles or fallback to field IDs
        foreach ($fields as $field_key => $field) {
            // Skip HTML fields or any other non-relevant field types
            if ($field['type'] === 'html' || $field['type'] === 'step') {
                continue;
            }

            // Sanitize the title by removing any quotation marks if it exists
            if (isset($field['title']) && !empty($field['title'])) {
                $field['title'] = str_replace('"', '', $field['title']);
            }

            // Use sanitized title or fallback to field key
            $decoded_title = $field['title'] ?? $field_key;

            // Add the field value to the user data array
            $user_data[$decoded_title] = $field['value'] ?? '';  // Default to empty string if no value
        }

        // Return the user data
        return $user_data;
    }

    private function extractRequestData($fields, $form_name)
    {
        return [
            'requested_at' => current_time('mysql'),
            'request_source' => 'Website Form',
            'form_name' => $form_name
        ];
    }

    private function sendToStrapi($endpoint, $data)
    {
        $response = wp_remote_post($endpoint, [
            'method' => 'POST',
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($data),
        ]);

        if (is_wp_error($response)) {
            error_log('Error sending to Strapi: ' . $response->get_error_message());
        }
    }

    private function checkPhoneNumber($record, $fieldName, $ajax_handler, $min_len = 9, $max_len = 10)
    {
        // Get the field information, using isset to ensure the key exists
        $fieldInfo = $record->get_field(['id' => $fieldName]);

        // Check if the field is set and has a value before accessing it
        $field = isset($fieldInfo[$fieldName]) ? $fieldInfo[$fieldName]['value'] : '';

        // If the field is not empty, check if it's numeric and within the length range
        if (!empty($field) && (!is_numeric($field) || strlen($field) < $min_len || strlen($field) > $max_len)) {
            $ajax_handler->add_error($fieldName, __("Invalid phone number.", "rishumit-plugin"));
        }
    }

    private function checkEmail($record, $fieldName, $ajax_handler)
    {
        // Get the field information, using isset to ensure the key exists
        $fieldInfo = $record->get_field(['id' => $fieldName]);

        // Check if the field is set and has a value before accessing it
        $field = isset($fieldInfo[$fieldName]) ? $fieldInfo[$fieldName]['raw_value'] : '';

        // If the field value is not empty and is not a valid email, add an error
        if (!empty($field) && !filter_var($field, FILTER_VALIDATE_EMAIL)) {
            $ajax_handler->add_error($fieldName, __("Invalid email address.", "rishumit-plugin"));
        }
    }

    private function checkName($record, $fieldName, $ajax_handler, $min_len = 2, $max_len = 40)
    {
        // Correct field IDs mapping
        $fieldMap = [
            'first_name' => 'name',
            'last_name' => 'fam',
        ];

        if (!isset($fieldMap[$fieldName])) {
            $ajax_handler->add_error($fieldName, __("Invalid field name.", "rishumit-plugin"));
            return;
        }

        $actualFieldName = $fieldMap[$fieldName];
        $fieldInfo = $record->get_field(['id' => $actualFieldName]);

        if (!isset($fieldInfo[$actualFieldName]) || !isset($fieldInfo[$actualFieldName]['value'])) {
            $ajax_handler->add_error($fieldName, __("Field not found or empty.", "rishumit-plugin"));
            return;
        }

        $field = $fieldInfo[$actualFieldName]['value'];

        if (preg_match('/[^a-zA-Zא-ת ]/', $field) || mb_strlen($field) < $min_len || mb_strlen($field) > $max_len) {
            $ajax_handler->add_error($fieldName, __("Invalid name format.", "rishumit-plugin"));
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
            $spouse['first_name'] = isset($fields['spouse_first_name']) ? $fields['spouse_first_name']['value'] : '';
            $spouse['last_name'] = isset($fields['spouse_last_name']) ? $fields['spouse_last_name']['value'] : '';
            $spouse['id_number'] = isset($fields['spouse_id']) ? $fields['spouse_id']['value'] : '';
            $spouse['father_name'] = isset($fields['spouse_father_name']) ? $fields['spouse_father_name']['value'] : '';
            $spouse['mother_name'] = isset($fields['spouse_mother_name']) ? $fields['spouse_mother_name']['value'] : '';
            $spouse['birth_year'] = isset($fields['spouse_birth_year']) ? $fields['spouse_birth_year']['value'] : '';

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
    $num_children = isset($fields['child']) ? (int)$fields['child']['value'] : 0;

    // If no children, return an empty array
    if ($num_children == 0) {
        error_log("No children data available.");
        return $children;
    }

    // Loop through the number of children and collect data
    for ($i = 1; $i <= $num_children; $i++) {
        // Construct the field names dynamically based on child number (e.g., 'child_1_first_name', 'child_2_last_name', etc.)
        $child_prefix = "child_{$i}"; // Dynamic child identifier (e.g., "child_1", "child_2")

        $first_name_field = $child_prefix . '_first_name';
        $last_name_field = $child_prefix . '_last_name';
        $id_field = $child_prefix . '_id';
        $father_name_field = $child_prefix . '_father_name';
        $mother_name_field = $child_prefix . '_mother_name';
        $birth_year_field = $child_prefix . '_birth_year';

        // Collect data for each child if available
        $first_name = isset($fields[$first_name_field]) ? $fields[$first_name_field]['value'] : '';
        $last_name = isset($fields[$last_name_field]) ? $fields[$last_name_field]['value'] : '';
        $id = isset($fields[$id_field]) ? $fields[$id_field]['value'] : '';
        $father_name = isset($fields[$father_name_field]) ? $fields[$father_name_field]['value'] : '';
        $mother_name = isset($fields[$mother_name_field]) ? $fields[$mother_name_field]['value'] : '';
        $birth_year = isset($fields[$birth_year_field]) ? $fields[$birth_year_field]['value'] : '';

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

    





}
