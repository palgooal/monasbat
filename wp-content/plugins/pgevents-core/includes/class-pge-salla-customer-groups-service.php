<?php
if (!defined('ABSPATH')) exit;

/**
 * Salla Customer Groups API transport.
 *
 * This service is intentionally independent from package activation flows.
 */
class PGE_Salla_Customer_Groups_Service
{
    private const ADD_CUSTOMERS_ENDPOINT = 'https://api.salla.dev/admin/v2/customers/groups/add_customers';
    private const CUSTOMER_DETAILS_ENDPOINT = 'https://api.salla.dev/admin/v2/customers/';

    /**
     * Add one Salla customer to a customer group.
     *
     * @return array|WP_Error
     */
    public function add_customer_to_group($merchant_id, $customer_id, $group_id)
    {
        $merchant_id = $this->positive_integer($merchant_id);
        if ($merchant_id === 0) {
            return new WP_Error('invalid_salla_merchant_id', 'Salla merchant ID must be a positive integer.');
        }

        $customer_id = $this->positive_integer($customer_id);
        if ($customer_id === 0) {
            return new WP_Error('invalid_salla_customer_id', 'Salla customer ID must be a positive integer.');
        }

        $group_id = $this->positive_integer($group_id);
        if ($group_id === 0) {
            return new WP_Error('invalid_salla_group_id', 'Salla customer group ID must be a positive integer.');
        }

        $access_token = $this->access_token($merchant_id);
        if (is_wp_error($access_token)) return $access_token;

        $request_body = wp_json_encode([
            'group_id'  => $group_id,
            'customers' => [$customer_id],
        ]);
        if (!is_string($request_body) || $request_body === '') {
            return new WP_Error('salla_request_encoding_failed', 'Unable to encode the Salla request body.');
        }

        $response = wp_remote_post(self::ADD_CUSTOMERS_ENDPOINT, [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'body'    => $request_body,
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            return new WP_Error(
                'salla_customer_group_transport_error',
                'Salla customer group request failed.',
                ['cause' => $response->get_error_code()]
            );
        }

        $http_status = (int) wp_remote_retrieve_response_code($response);
        if ($http_status < 200 || $http_status >= 300) {
            return new WP_Error(
                'salla_customer_group_http_error',
                'Salla customer group request returned a non-success HTTP status.',
                ['http_status' => $http_status]
            );
        }

        $response_body = wp_remote_retrieve_body($response);
        if (!is_string($response_body) || trim($response_body) === '') {
            return new WP_Error(
                'salla_customer_group_empty_response',
                'Salla customer group request returned an empty response.',
                ['http_status' => $http_status]
            );
        }

        $decoded = json_decode($response_body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error(
                'salla_customer_group_invalid_json',
                'Salla customer group response was not valid JSON.',
                ['http_status' => $http_status]
            );
        }

        if (!is_array($decoded)) {
            return new WP_Error(
                'salla_customer_group_invalid_response',
                'Salla customer group response has an invalid structure.',
                ['http_status' => $http_status]
            );
        }

        $api_status = $http_status;
        if (array_key_exists('status', $decoded)) {
            $api_status = $this->positive_integer($decoded['status']);
            if ($api_status === 0) {
                return new WP_Error(
                    'salla_customer_group_invalid_response',
                    'Salla customer group response has an invalid status.',
                    ['http_status' => $http_status]
                );
            }
        }

        if (($decoded['success'] ?? null) !== true || $api_status < 200 || $api_status >= 300) {
            return new WP_Error(
                'salla_customer_group_api_error',
                'Salla API did not confirm that the customer was added to the group.',
                [
                    'http_status' => $http_status,
                    'api_status'  => $api_status,
                ]
            );
        }

        return [
            'success'     => true,
            'http_status' => $http_status,
            'api_status'  => $api_status,
            'data'        => $decoded['data'] ?? null,
        ];
    }

    /** Read and validate the complete authoritative Customer Details projection. */
    public function get_customer_details($merchant_id, $customer_id)
    {
        $merchant_id = $this->positive_integer($merchant_id);
        $customer_id = $this->positive_integer($customer_id);
        if ($merchant_id === 0 || $customer_id === 0) {
            return new WP_Error('salla_customer_details_invalid_input', 'Salla customer details input is invalid.');
        }

        $access_token = $this->access_token($merchant_id);
        if (is_wp_error($access_token)) return $access_token;

        $response = wp_remote_get(self::CUSTOMER_DETAILS_ENDPOINT . $customer_id, [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Accept'        => 'application/json',
            ],
            'timeout' => 20,
        ]);
        if (is_wp_error($response)) {
            return new WP_Error('salla_customer_details_transport_error', 'Salla customer details request failed.');
        }

        $http_status = (int) wp_remote_retrieve_response_code($response);
        if ($http_status === 404) {
            return ['success' => true, 'exists' => false, 'groups' => [], 'first_name' => null, 'http_status' => 404];
        }
        if ($http_status < 200 || $http_status >= 300) {
            return new WP_Error(
                'salla_customer_details_http_error',
                'Salla customer details returned a non-success HTTP status.',
                ['http_status' => $http_status]
            );
        }
        $body = wp_remote_retrieve_body($response);
        if (!is_string($body) || trim($body) === '') {
            return new WP_Error('salla_customer_details_empty_response', 'Salla customer details returned an empty response.');
        }
        $decoded = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return new WP_Error('salla_customer_details_invalid_json', 'Salla customer details response was not valid JSON.');
        }
        if (($decoded['success'] ?? null) !== true || !is_array($decoded['data'] ?? null) || !array_key_exists('groups', $decoded['data']) || !is_array($decoded['data']['groups'])) {
            return new WP_Error('salla_customer_details_invalid_response', 'Salla customer details response was incomplete.');
        }

        $groups = [];
        foreach ($decoded['data']['groups'] as $candidate) {
            $value = is_array($candidate) && array_key_exists('id', $candidate) ? $candidate['id'] : $candidate;
            $id = $this->positive_integer($value);
            if ($id === 0) return new WP_Error('salla_customer_details_malformed_groups', 'Salla customer groups response was malformed.');
            $groups[$id] = $id;
        }
        $groups = array_values($groups);
        sort($groups, SORT_NUMERIC);
        $first_name = $decoded['data']['first_name'] ?? null;
        if (!is_string($first_name) || trim($first_name) === '') {
            return new WP_Error('salla_customer_details_missing_update_field', 'Salla customer details did not include a usable first name.');
        }
        return ['success' => true, 'exists' => true, 'groups' => $groups, 'first_name' => $first_name, 'http_status' => $http_status];
    }

    /** Read authoritative group membership from Customer Details. */
    public function customer_is_in_group($merchant_id, $customer_id, $group_id)
    {
        $group_id = $this->positive_integer($group_id);
        if ($group_id === 0) return new WP_Error('salla_customer_details_invalid_input', 'Salla customer details input is invalid.');
        $details = $this->get_customer_details($merchant_id, $customer_id);
        if (is_wp_error($details)) return $details;
        return [
            'success' => true,
            'is_member' => !empty($details['exists']) && in_array($group_id, $details['groups'], true),
            'http_status' => (int) $details['http_status'],
        ];
    }

    /** Replace the complete customer group list while preserving current first_name. */
    public function update_customer_groups($merchant_id, $customer_id, $first_name, array $groups)
    {
        if (!PGE_Salla_Not_Member_Removal_Feature::enabled()) {
            return new WP_Error('salla_not_member_removal_disabled', 'Salla customer-group removal is disabled.');
        }
        $merchant_id = $this->positive_integer($merchant_id);
        $customer_id = $this->positive_integer($customer_id);
        if (!$merchant_id || !$customer_id) return new WP_Error('salla_customer_update_invalid_input', 'Salla customer update input is invalid.');
        if (!is_string($first_name) || trim($first_name) === '') return new WP_Error('salla_customer_details_missing_update_field', 'A current first name is required.');
        $normalized = [];
        foreach ($groups as $candidate) {
            $id = $this->positive_integer($candidate);
            if (!$id) return new WP_Error('salla_customer_update_invalid_groups', 'Salla customer groups are invalid.');
            $normalized[$id] = (string) $id;
        }
        $normalized = array_values($normalized);
        $access_token = $this->access_token($merchant_id);
        if (is_wp_error($access_token)) return $access_token;
        $body = wp_json_encode(['first_name' => $first_name, 'groups' => $normalized]);
        if (!is_string($body)) return new WP_Error('salla_request_encoding_failed', 'Unable to encode the Salla request body.');
        // This is the sole production destructive removal boundary.
        if (!PGE_Salla_Not_Member_Removal_Feature::enabled()) {
            return new WP_Error('salla_not_member_removal_disabled', 'Salla customer-group removal is disabled.');
        }
        $response = wp_remote_request(self::CUSTOMER_DETAILS_ENDPOINT . $customer_id, [
            'method' => 'PUT',
            'headers' => ['Authorization' => 'Bearer ' . $access_token, 'Content-Type' => 'application/json', 'Accept' => 'application/json'],
            'body' => $body,
            'timeout' => 20,
        ]);
        if (is_wp_error($response)) return new WP_Error('salla_customer_update_transport_error', 'Salla customer update request failed.');
        $http_status = (int) wp_remote_retrieve_response_code($response);
        if ($http_status === 401) return new WP_Error('salla_customer_update_unauthorized', 'Salla customer update was unauthorized.', ['http_status' => 401]);
        if ($http_status < 200 || $http_status >= 300) return new WP_Error('salla_customer_update_http_error', 'Salla customer update returned a non-success HTTP status.', ['http_status' => $http_status]);
        $response_body = wp_remote_retrieve_body($response);
        if (!is_string($response_body) || trim($response_body) === '') return new WP_Error('salla_customer_update_ambiguous_response', 'Salla customer update returned an ambiguous success response.', ['http_status' => $http_status]);
        $decoded = json_decode($response_body, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded) || ($decoded['success'] ?? null) !== true) {
            return new WP_Error('salla_customer_update_ambiguous_response', 'Salla customer update returned an ambiguous success response.', ['http_status' => $http_status]);
        }
        return ['success' => true, 'http_status' => $http_status];
    }

    /** @return string|WP_Error */
    private function access_token($merchant_id)
    {
        if (!class_exists('PGE_Salla_Token_Manager')) {
            return new WP_Error('salla_token_manager_unavailable', 'Salla token manager is unavailable.');
        }
        $token = PGE_Salla_Token_Manager::get_valid_access_token($merchant_id);
        if (is_wp_error($token)) {
            $safe_codes = [
                'invalid_salla_merchant_id', 'salla_tokens_missing', 'salla_token_data_invalid',
                'salla_token_lock_unavailable', 'salla_token_refresh_lock_failed',
                'salla_refresh_token_missing', 'salla_client_id_missing', 'salla_client_secret_missing',
                'salla_token_refresh_transport_error', 'salla_token_refresh_http_error',
                'salla_token_refresh_empty_response', 'salla_token_refresh_invalid_json',
                'salla_token_refresh_invalid_response', 'salla_token_persistence_failed',
            ];
            $code = $token->get_error_code();
            return new WP_Error(in_array($code, $safe_codes, true) ? $code : 'salla_token_acquisition_failed', 'Unable to obtain a valid Salla access token.');
        }
        if (!is_string($token)) return new WP_Error('salla_token_data_invalid', 'Salla access token is invalid.');
        $token = trim($token);
        if ($token === '') return new WP_Error('salla_access_token_missing', 'Salla access token is missing.');
        if (preg_match('/[\x00-\x20\x7f]/', $token)) return new WP_Error('salla_token_data_invalid', 'Salla access token is invalid.');
        return $token;
    }

    private function positive_integer($value)
    {
        if (!is_scalar($value) || is_bool($value)) {
            return 0;
        }

        $value = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        return $value === false ? 0 : (int) $value;
    }
}
