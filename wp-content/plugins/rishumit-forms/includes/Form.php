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
       // $this->strapiEndpointUser = getenv('STRAPI_ENDPOINT_USER');
       $this->strapiEndpointRequest = defined('STRAPI_ENDPOINT_REQUEST')
       ? STRAPI_ENDPOINT_REQUEST
       : 'http://localhost:1337/api/requests'; // Fallback for local development
   }


   public function register()
   {
       // Register hooks for form validation and submission
       add_action('elementor_pro/forms/new_record', [$this, 'handleForms'], 10, 2);
       add_action('elementor_pro/forms/validation', [$this, 'validation'], 10, 2);
   }


   public function validation($record, $ajax_handler)
   {
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
      
       // Prevent emails from being sent during testing
       remove_action('elementor_pro/forms/new_record', 'ElementorPro\Modules\Forms\Actions\Email\Action::send_email');
  
       // Retrieve the form name
       $form_name = $record->get_form_settings('form_name') ?? 'Unnamed Form';
      
       // Retrieve submitted fields
       $fields = $record->get('fields');


       // Prepare data for background processing
       $submission_data = [
           'fields' => $fields,
           'form_name' => $form_name
       ];


       // Immediately show success to the user
       $handler->add_response_data('message', __('Form submitted successfully. We will process your request shortly.', 'rishumit-plugin'));


       // Schedule the task (runs within 1 minute)
       wp_schedule_single_event(time() + 60, 'rishumit_process_form_submission', [$submission_data]);
  
       // Log all fields for debugging purposes (optional)
       error_log('Filtered Form Fields: ' . print_r($fields, true));
  
       // Initialize children array
       $children = [];
  
       // Check if the field for address change request for children or spouse is relevant
       // Check the 'nosaf' field for address change
       $change_address_field = isset($fields['nosaf']) ? $fields['nosaf']['value'] : '';
      
       // If the field contains "ילדים" or "ילדים ובן/ת זוג", extract the children data
       if (strpos($change_address_field, 'ילדים') !== false || strpos($change_address_field, 'בן/ת זוג') !== false) {
           $children = $this->extractChildren($fields);
       }
  
       // Automatically process user data (excluding children)
       $user = $this->extractUserData($fields, $form_name);
       $request = $this->extractRequestData($fields, $form_name);


       // Append created_by with user email
       $user_email = $fields['email']['value'] ?? '';  // Retrieve user's email
       $request['created_by_client_id'] = $user_email;           // Append it to the request object
  
       // Add children to user data only if children exist
       if (!empty($children)) {
           $request['children'] = $children;  // Add the children array to the user data
       }
  
       // Extract spouse data (if relevant)
       $spouse = $this->extractSpouseData($fields);
       if (!empty($spouse)) {
           $request['spouse'] = $spouse;
       }


       // Rename 'חתימת המבקש/ת' to 'חתימה' in the request object dynamically
       foreach ($request as $key => $value) {
           if (preg_match('/חתימת המבקש.*:/u', $key)) {  // Match keys like 'חתימת המבקש/ת:' or similar
               $request['חתימה'] = $value;  // Create the new key with the same value
               unset($request[$key]);        // Remove the old key
           }
       }


       // Wrap request data inside request_json
       $payload = [
           'data' => [
               'user' => $user,
               'request_json' => $request,  // Wrap request object inside request_json
           ]
       ];
  
       // Decode any Unicode characters
       $json_user = json_encode($user, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
       $json_request = json_encode($request, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
      
       // Log the user and request data to debug.log in JSON format
       error_log('User Data: ' . $json_user);
       error_log('Request Data: ' . $json_request);       
      
       // Send the combined payload to Strapi
       $this->sendToStrapi($this->strapiEndpointRequest, $payload);
      


       // Optional: Stop form submission for testing
       wp_die('Form submission stopped for testing purposes');
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
           if ($field['type'] === 'html' || $field['type'] === 'step') {
               continue;
           }


           // Check if field title exists in the userFields mapping
           if (isset($userFields[$field_key])) {
               // Use ID as fallback if title is empty
               $field_title = !empty($field['title']) ? $field['title'] : $field['id'];


               // Map field to its appropriate user data key
               $user_data[$field_title] = $field['value'] ?? '';  // Default to empty string if no value
           }
       }


       // Return the user data
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


       // Add all other non-employer fields to the request data
       $request_data = array_merge($request_data, $this->extractNonEmployerFields($fields));


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
               $field['type'] === 'html' ||
               $field['type'] === 'step' ||
               in_array($field_key, $user_data_fields) ||
               preg_match('/^emp(\d+)_/', $field_key) // Exclude employer-related fields
           ) {
               continue;
           }


           // Use title as the key if available, fallback to id
           $key = !empty($field['title']) ? $field['title'] : $field['id'];


           // Add the field to non-employer data
           $non_employer_data[$key] = $field['value'] ?? ''; // Default to empty string if no value
       }


       return $non_employer_data;
   }




   private function extractEmployerDetails($fields)
   {
       $employers = []; // Temporary array to hold each employer's details


       foreach ($fields as $field_key => $field) {
           // Skip non-relevant field types
           if ($field['type'] === 'html' || $field['type'] === 'step') {
               continue;
           }


           // Detect employer-related fields by their prefixes (e.g., emp1_, emp2_)
           if (preg_match('/^emp(\d+)_/', $field_key, $matches)) {
               $employer_index = (int)$matches[1]; // Extract employer index (e.g., 1, 2, 3)


               // Initialize the employer object if it doesn't exist
               if (!isset($employers[$employer_index])) {
                   $employers[$employer_index] = [];
               }


               // Add the field's id and value to the employer object
               $employers[$employer_index][$field['id']] = $field['value'] ?? '';
           }
       }


       // Return the employers as an indexed array for JSON consistency
       return array_values($employers);
   }


   private function sendToStrapi($endpoint, $data)
   {
       $response = wp_remote_post($endpoint, [
           'method'  => 'POST',
           'headers' => [
               'Content-Type' => 'application/json',
               'Authorization' => 'Bearer 479e3212fb5013aa56e0ca849364a719c02516eb7ed9ac4670729a8bae2c7b8c0005c1c6d7e775f8b60ea66942ea74f8113a5fc4e367d2add23df62e44cc716bc5b7f6eaf91c96e4fcd5da5aa92424b1c241093cc5365153fd6aa8f05c320b47382329f4087aec412df04e414cd4cdcc7fd63c83a9f092de1cbaf0cc7dbaa1df'
           ],
           'body'    => json_encode($data),
       ]);

       if (is_wp_error($response)) {
           error_log('Error sending to Strapi: ' . $response->get_error_message());
       } else {
           error_log('Response from Strapi: ' . wp_remote_retrieve_body($response));
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



