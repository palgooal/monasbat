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

    /** Read authoritative group membership from the documented Customer Details endpoint. */
    public function customer_is_in_group($merchant_id, $customer_id, $group_id)
    {
        $merchant_id = $this->positive_integer($merchant_id);
        $customer_id = $this->positive_integer($customer_id);
        $group_id = $this->positive_integer($group_id);
        if ($merchant_id === 0 || $customer_id === 0 || $group_id === 0) {
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
        if (($decoded['success'] ?? null) !== true || !is_array($decoded['data'] ?? null) || !is_array($decoded['data']['groups'] ?? null)) {
            return new WP_Error('salla_customer_details_invalid_response', 'Salla customer details response was incomplete.');
        }

        $groups = [];
        foreach ($decoded['data']['groups'] as $candidate) {
            $id = $this->positive_integer($candidate);
            if ($id > 0) $groups[] = $id;
        }
        return ['success' => true, 'is_member' => in_array($group_id, $groups, true), 'http_status' => $http_status];
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
