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

        // Print all fields to the debug.log
        error_log('All Form Fields: ' . print_r($fields, true));
    
        // Remove fields with the type "step" (or any other types you don't care about)
        $fields = array_filter($fields, function($field) {
            return $field['type'] !== 'step';  // Exclude "step" fields
        });
    
        // Log all fields data for debugging
        $user = $this->extractUserData($fields, $form_name);
        $request = $this->extractRequestData($fields, $form_name);
    
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
        // // Common fields for all forms
        // $user_data = [
        //     'first_name' => $fields['name']['value'] ?? '',
        //     'last_name' => $fields['fam']['value'] ?? '',
        //     'email' => $fields['email']['value'] ?? '',
        //     'phone' => $fields['phone']['value'] ?? '',
        //     'ssn' => $fields['ssn']['value'] ?? '',
        // ];

       // Initialize the base user data array
        $user_data = [];

        // Keep 'name' (first_name) and 'fam' (last_name) as explicit fields
        $user_data['first_name'] = $fields['name']['value'] ?? '';
        $user_data['last_name'] = $fields['fam']['value'] ?? '';

        // Loop through the fields and generate dynamic keys from titles or fallback to field IDs
        foreach ($fields as $field_key => $field) {
            // Skip HTML fields or any other non-relevant field types
            if ($field['type'] === 'html') {
                continue;
            }

            // Skip 'name' and 'fam' since we've already handled them above
            if ($field_key === 'name' || $field_key === 'fam') {
                continue;
            }

            // Check if the field has a title and its value is set
            if (isset($field['title']) && !empty($field['title'])) {
                // Decode the title from Unicode to the actual character
                $decoded_title = json_decode('"' . $field['title'] . '"');
            } else {
                // If no title exists, use the field's ID as the key or any other fallback string
                $decoded_title = $field_key;  // Using the field's key (e.g., field_12345) as a fallback
            }

            // Add the field value to the user_data array with the decoded title or fallback as the key
            $user_data[$decoded_title] = $field['value'] ?? '';  // Default to empty string if no value
        }


        // Extract user data dynamically based on the form
        switch ($form_name) {
            case 'ESTA':
                return array_merge($user_data, [
                    'address' => [
                        'street' => $fields['field_7b0669b']['value'] ?? '',
                        'house_number' => $fields['field_2454023']['value'] ?? '',
                        'apartment_number' => $fields['field_7b0669b']['value'] ?? '', // Assuming this field for apartment
                        'city' => $fields['field_2454023']['value'] ?? '', // Assuming this field for city
                    ],
                    'marital_status' => $fields['field_2217507']['value'] ?? '', // Assuming this for marital status
                    'date_of_birth' => [
                        'year' => $fields['field_0b6639f']['value'] ?? '', // Assuming this field for year of birth
                        'month' => $fields['field_0b6639f']['value'] ?? '', // Same for month
                        'day' => $fields['field_0b6639f']['value'] ?? '', // Same for day
                    ],
                ]);            
            case 'Green Form':
                return array_merge($user_data, [
                    'medical_declaration' => [
                        'driving_license_category' => $fields['field_788fccf']['value'] ?? '',
                        'medical_conditions' => [
                            'loss_of_consciousness' => $fields['field_3deb024']['value'] ?? 'No',
                            'epilepsy' => $fields['field_af77912']['value'] ?? 'No',
                            'stroke' => $fields['field_a9a2fe2']['value'] ?? 'No',
                        ],
                        'acceptance' => $fields['field_c1b93e9']['value'] ?? '',
                    ],
                ]);
            case 'Income Tax Exemption':
                return array_merge($user_data, [
                    'address' => [
                        'street' => $fields['street']['value'] ?? '',
                        'house_number' => $fields['app']['value'] ?? '',
                        'apartment_number' => '',
                        'city' => $fields['city']['value'] ?? '',
                    ],
                    'marital_status' => $fields['status']['value'] ?? '',
                    'date_of_birth' => $fields['birth']['value'] ?? '',
                    'income_tax_exemption' => [
                        'applicant_type' => $fields['field_8815ed6']['value'] ?? '',
                        'pension_funds' => $fields['field_9910572']['value'] ?? '',
                        'fund_component' => $fields['field_7674580']['value'] ?? '',
                        'additional_exemption_reason' => $fields['field_6581701']['value'] ?? '',
                    ],
                ]);
            case 'Birth Name Registration':
                return array_merge($user_data, [
                    'address' => [
                        'city' => $fields['ir']['value'] ?? '',
                        'street' => $fields['st']['value'] ?? '',
                        'house_number' => $fields['bait']['value'] ?? '',
                        'apartment_number' => $fields['dira']['value'] ?? '',
                    ],
                    'marital_status' => $fields['ishi']['value'] ?? '',
                    'newborn' => [
                        'first_name' => $fields['nameniv']['value'] ?? '',
                        'ssn' => $fields['idniv']['value'] ?? '',
                        'gender' => $fields['min']['value'] ?? '',
                        'birth_date' => [
                            'year' => $fields['yy']['value'] ?? '',
                            'month' => $fields['ho']['value'] ?? '',
                            'day' => $fields['yom']['value'] ?? '',
                        ],
                        'birth_place' => [
                            'hospital' => $fields['hos']['value'] ?? '',
                            'city' => $fields['hosa']['value'] ?? '',
                        ],
                    ],
                ]);
            case 'Tax coordination':
                return array_merge($user_data, [
                    'tax_details' => [
                        'coordination_for' => $fields['YYY']['value'] ?? '',
                        'income_sources' => $fields['makor']['value'] ?? '',
                        'received_one_time_amount' => $fields['field_a194741']['value'] ?? '',
                        'children_under_19' => $fields['field_e443730']['value'] ?? '',
                        'comments' => $fields['field_3d43be7']['value'] ?? '',
                        'employer_details' => [
                            'employer_1' => [
                                'deduction_file_number' => $fields['field_6721318']['value'] ?? '',
                                'last_gross_salary' => $fields['field_f1d8b8c']['value'] ?? '',
                                'expected_work_months' => $fields['field_d19ba80']['value'] ?? '',
                            ],
                        ],
                    ],
                ]);
            case 'IDF Certificates':
                return array_merge($user_data, [
                    'id_card_issue_date' => $fields['msg']['value'] ?? '',
                    'date_of_birth' => $fields['field_163993f']['value'] ?? '',
                    'signature' => $fields['hatima']['value'] ?? '',
                ]);
            case 'ספח ת.ז':
                return array_merge($user_data, [
                    'request_type' => $fields['field_25e2490']['value'] ?? '',
                    'parents' => [
                        'mother_name' => $fields['em']['value'] ?? '',
                        'father_name' => $fields['av']['value'] ?? '',
                        'grandfather_name' => $fields['sav']['value'] ?? '',
                    ],
                    'birth_date' => [
                        'year' => $fields['yy']['value'] ?? '',
                        'month' => $fields['mm']['value'] ?? '',
                        'day' => $fields['dd']['value'] ?? '',
                    ],
                    'birth_country' => $fields['eretz']['value'] ?? '',
                    'nationality' => $fields['leum']['value'] ?? '',
                    'address' => [
                        'city' => $fields['city']['value'] ?? '',
                        'street' => $fields['st']['value'] ?? '',
                        'house_number' => $fields['fjs']['value'] ?? '',
                        'apartment_number' => $fields['asfg']['value'] ?? '',
                    ],
                    'signature' => $fields['hatima']['value'] ?? '',
                ]);
            case 'Change Address':
                return array_merge($user_data, [
                    'date_of_birth' => [
                        'year' => $fields['yy']['value'] ?? '',
                        'month' => $fields['ho']['value'] ?? '',
                        'day' => $fields['yom']['value'] ?? '',
                    ],
                    'marital_status' => $fields['ishi']['value'] ?? '',
                    'father_name' => $fields['av']['value'] ?? '',
                    'mother_name' => $fields['field_d8cbe25']['value'] ?? '',
                    'current_address' => [
                        'city' => $fields['ir']['value'] ?? '',
                        'street' => $fields['st']['value'] ?? '',
                        'house_number' => $fields['bait']['value'] ?? '',
                        'apartment_number' => $fields['dira']['value'] ?? '',
                        'po_box' => $fields['td']['value'] ?? '',
                    ],
                    'previous_city' => $fields['eretz']['value'] ?? '',
                    'change_for_family' => $fields['nosaf']['value'] ?? '',
                    'partner' => [
                        'first_name' => $fields['as']['value'] ?? '',
                        'last_name' => $fields['aa']['value'] ?? '',
                        'ssn' => $fields['cc']['value'] ?? '',
                        'father_name' => $fields['gg']['value'] ?? '',
                        'mother_name' => $fields['xz']['value'] ?? '',
                        'birth_year' => $fields['fg']['value'] ?? '',
                    ],
                    'children' => $this->extractChildren($fields),
                    'id_card_attachment' => $fields['t6']['value'] ?? '',
                    'signature' => $fields['field_59dfe8c']['value'] ?? '',
                ]); 
            case 'Registration Summary':
                return array_merge($user_data, [
                    'request_details' => [
                        'for_whom' => $fields['field_8815ed6']['value'] ?? '',
                        'child' => [
                            'first_name' => $fields['field_60b47b6']['value'] ?? '',
                            'last_name' => $fields['field_5e6fb1e']['value'] ?? '',
                            'gender' => $fields['field_0af7f74']['value'] ?? '',
                            'birth_date' => $fields['field_163993f']['value'] ?? '',
                            'birth_place' => $fields['field_584278c']['value'] ?? '',
                            'nationality' => $fields['field_a7e16af']['value'] ?? '',
                            'mother_name' => $fields['field_068c6c3']['value'] ?? '',
                            'father_name' => $fields['field_23c85ea']['value'] ?? '',
                            'ssn' => $fields['field_2f427bf']['value'] ?? '',
                            'marital_status' => $fields['field_f8337d4']['value'] ?? '',
                        ],
                    ],
                ]);      
            case 'Birth Certificate':
                return array_merge($user_data, [
                    'address' => [
                        'city' => $fields['ir']['value'] ?? '',
                        'street' => $fields['st']['value'] ?? '',
                        'house_number' => $fields['bait']['value'] ?? '',
                        'apartment_number' => $fields['dira']['value'] ?? '',
                    ],
                    'marital_status' => $fields['ishi']['value'] ?? '',
                    'applicant_details' => [
                        'first_name' => $fields['nameniv']['value'] ?? '',
                        'last_name' => $fields['mishname']['value'] ?? '',
                        'gender' => $fields['min']['value'] ?? '',
                        'ssn' => $fields['idniv']['value'] ?? '',
                        'birth_date' => [
                            'year' => $fields['yy']['value'] ?? '',
                            'month' => $fields['ho']['value'] ?? '',
                            'day' => $fields['yom']['value'] ?? '',
                        ],
                        'birth_place' => [
                            'hospital' => $fields['hos']['value'] ?? '',
                            'city' => $fields['hosa']['value'] ?? '',
                        ],
                        'nationality' => $fields['leum']['value'] ?? '',
                        'mother_name' => $fields['imniv']['value'] ?? '',
                        'father_name' => $fields['avniv']['value'] ?? '',
                        'maternal_grandfather_name' => $fields['savniv']['value'] ?? '',
                        'mother_maiden_name' => $fields['past']['value'] ?? '',
                    ],
                ]);     
            case 'Death Certificate':
                return array_merge($user_data, [
                    'relationship_to_deceased' => $fields['kirva']['value'] ?? '',
                    'deceased' => [
                        'first_name' => $fields['field_8815ed6']['value'] ?? '',
                        'last_name' => $fields['field_2877bb3']['value'] ?? '',
                        'ssn' => $fields['field_671e356']['value'] ?? '',
                        'official_death_date' => $fields['field_163993f']['value'] ?? '',
                        'death_place' => [
                            'hospital' => $fields['field_a8a319f']['value'] ?? '',
                            'city' => $fields['field_ab570a5']['value'] ?? '',
                        ],
                    ],
                ]);                                                   
            default:
                return $user_data;
        }
    }

    private function extractRequestData($fields, $form_name)
    {
        return [
            'requested_at' => current_time('mysql'),
            'request_source' => 'Website Form',
            'form_name' => $form_name,
            'meta' => [
                'submission_date' => $fields['fdate']['value'] ?? current_time('mysql'),
            ],
        ];
    }

    private function getFieldLabel($field_key) {
        $labels = [
            'min' => 'Gender',
            'name' => 'First Name',
            'fam' => 'Last Name',
            'ssn' => 'SSN',
            'field_386a19c' => 'Business Type',
            'field_ca406db' => 'Occupation Details',
            'field_2454023' => 'Country of Birth',
            'field_71d245b' => 'Phone Number',
            'field_ec09dca' => 'Email',
            'field_5795418' => 'Additional Info',
            'field_6dd1f98' => 'Passport Info',
            'field_f866239' => 'Credit Card Number',
            'field_397b026' => 'Credit Card Expiry',
            'field_204f3a8' => 'CVV',
            // Add more field mappings as needed
        ];
    
        return $labels[$field_key] ?? $field_key; // If no label found, return the field key
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

    private function extractChildren($fields)
    {
        $children = [];

        // Check the number of children from the 'child' field
        $num_children = isset($fields['child']) ? (int)$fields['child']['value'] : 0;

        // If there are no children, return an empty array
        if ($num_children == 0) {
            return $children;
        }

        // Loop through and process each child
        for ($i = 1; $i <= $num_children; $i++) {
            // Construct the field names for each child
            $first_name_field = "hb"; // First name field (for child $i)
            $last_name_field = "jy"; // Last name field
            $ssn_field = "xb"; // SSN field
            $father_name_field = "tv"; // Father’s name field
            $mother_name_field = "bd"; // Mother’s name field
            $birth_year_field = "xa"; // Birth year field

            // Collect data for each child if available
            $first_name = isset($fields[$first_name_field]) ? $fields[$first_name_field]['value'] : '';
            $last_name = isset($fields[$last_name_field]) ? $fields[$last_name_field]['value'] : '';
            $ssn = isset($fields[$ssn_field]) ? $fields[$ssn_field]['value'] : '';
            $father_name = isset($fields[$father_name_field]) ? $fields[$father_name_field]['value'] : '';
            $mother_name = isset($fields[$mother_name_field]) ? $fields[$mother_name_field]['value'] : '';
            $birth_year = isset($fields[$birth_year_field]) ? $fields[$birth_year_field]['value'] : '';

            // Add the child to the array if all required data is available
            if ($first_name && $last_name && $ssn) {
                $children[] = [
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'ssn' => $ssn,
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
