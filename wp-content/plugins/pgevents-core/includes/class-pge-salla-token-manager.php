<?php
if (!defined('ABSPATH')) exit;

/**
 * Resolves a usable Salla access token and refreshes it when necessary.
 *
 * Refreshes are serialized per merchant because Salla refresh tokens rotate
 * and must not be reused by concurrent requests.
 */
class PGE_Salla_Token_Manager
{
    private const TOKEN_ENDPOINT = 'https://accounts.salla.sa/oauth2/token';
    private const REFRESH_SAFETY_WINDOW_SECONDS = 300;
    private const LOCK_TIMEOUT_SECONDS = 5;
    private const LOCK_NAME_PREFIX = 'pge_salla_token_refresh_';

    /**
     * Return a comfortably valid access token, refreshing it when required.
     *
     * @return string|WP_Error
     */
    public static function get_valid_access_token($merchant_id)
    {
        $merchant_id = self::positive_integer($merchant_id);
        if ($merchant_id === 0) {
            return new WP_Error(
                'invalid_salla_merchant_id',
                'Salla merchant ID must be a positive integer.'
            );
        }

        $option_name = 'pge_salla_tokens_' . $merchant_id;
        $token_data = get_option($option_name, null);
        $validated = self::validate_stored_token($token_data);
        if (is_wp_error($validated)) {
            return $validated;
        }

        $now = self::current_timestamp();
        if (self::is_comfortably_valid($validated, $now)) {
            return $validated['access_token'];
        }

        global $wpdb;
        if (!is_object($wpdb)
            || !method_exists($wpdb, 'prepare')
            || !method_exists($wpdb, 'get_var')
            || !method_exists($wpdb, 'query')) {
            return new WP_Error(
                'salla_token_lock_unavailable',
                'The Salla token refresh lock is unavailable.'
            );
        }

        $lock_name = self::LOCK_NAME_PREFIX . $merchant_id;
        $got_lock = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT GET_LOCK(%s, %d)',
                $lock_name,
                self::LOCK_TIMEOUT_SECONDS
            )
        );

        if ((int) $got_lock !== 1) {
            return new WP_Error(
                'salla_token_refresh_lock_failed',
                'Unable to acquire the Salla token refresh lock.',
                ['merchant_id' => $merchant_id]
            );
        }

        try {
            // The option is authoritative. Another request may have refreshed
            // and rotated the token while this request waited for the lock.
            $token_data = get_option($option_name, null);
            $validated = self::validate_stored_token($token_data);
            if (is_wp_error($validated)) {
                return $validated;
            }

            $now = self::current_timestamp();
            if (self::is_comfortably_valid($validated, $now)) {
                return $validated['access_token'];
            }

            $refresh_token = self::non_empty_string($token_data['refresh_token'] ?? null);
            if ($refresh_token === '') {
                return new WP_Error(
                    'salla_refresh_token_missing',
                    'Salla refresh token is missing.'
                );
            }

            $client_id = self::credential('PGE_SALLA_CLIENT_ID', 'pge_salla_client_id');
            if ($client_id === '') {
                return new WP_Error(
                    'salla_client_id_missing',
                    'Salla client ID is missing.'
                );
            }

            $client_secret = self::credential('PGE_SALLA_CLIENT_SECRET', 'pge_salla_client_secret');
            if ($client_secret === '') {
                return new WP_Error(
                    'salla_client_secret_missing',
                    'Salla client secret is missing.'
                );
            }

            $response = wp_remote_post(self::TOKEN_ENDPOINT, [
                'headers' => [
                    'Accept'       => 'application/json',
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body' => [
                    'grant_type'    => 'refresh_token',
                    'client_id'     => $client_id,
                    'client_secret' => $client_secret,
                    'refresh_token' => $refresh_token,
                ],
                'timeout' => 20,
            ]);

            if (is_wp_error($response)) {
                return new WP_Error(
                    'salla_token_refresh_transport_error',
                    'The Salla token refresh request failed.'
                );
            }

            $http_status = (int) wp_remote_retrieve_response_code($response);
            if ($http_status < 200 || $http_status >= 300) {
                return new WP_Error(
                    'salla_token_refresh_http_error',
                    'The Salla token refresh request returned a non-success HTTP status.',
                    ['http_status' => $http_status]
                );
            }

            $response_body = wp_remote_retrieve_body($response);
            if (!is_string($response_body) || trim($response_body) === '') {
                return new WP_Error(
                    'salla_token_refresh_empty_response',
                    'The Salla token refresh request returned an empty response.'
                );
            }

            $decoded = json_decode($response_body, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                return new WP_Error(
                    'salla_token_refresh_invalid_json',
                    'The Salla token refresh response was not valid JSON.'
                );
            }

            $replacement = self::validate_refresh_response($decoded, $refresh_token, $now);
            if (is_wp_error($replacement)) {
                return $replacement;
            }

            $replacement['updated_at'] = current_time('mysql');
            if (!update_option($option_name, $replacement, false)) {
                return new WP_Error(
                    'salla_token_persistence_failed',
                    'Unable to persist the refreshed Salla token set.',
                    ['merchant_id' => $merchant_id]
                );
            }

            return $replacement['access_token'];
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }
    }

    /**
     * Validate the fields needed to decide whether the stored access token
     * can be used. Refresh-only fields are validated inside the lock.
     *
     * @return array|WP_Error
     */
    private static function validate_stored_token($token_data)
    {
        if ($token_data === null || $token_data === false) {
            return new WP_Error(
                'salla_tokens_missing',
                'Salla token data was not found for this merchant.'
            );
        }

        if (!is_array($token_data)) {
            return new WP_Error(
                'salla_token_data_invalid',
                'Stored Salla token data is invalid.'
            );
        }

        $access_token = self::non_empty_string($token_data['access_token'] ?? null);
        $expires = self::positive_integer($token_data['expires'] ?? null);
        if ($access_token === '' || $expires === 0) {
            return new WP_Error(
                'salla_token_data_invalid',
                'Stored Salla token data is incomplete or invalid.'
            );
        }

        return [
            'access_token' => $access_token,
            'expires'      => $expires,
        ];
    }

    private static function is_comfortably_valid(array $token_data, $now)
    {
        return $token_data['expires'] > ($now + self::REFRESH_SAFETY_WINDOW_SECONDS);
    }

    /**
     * @return array|WP_Error
     */
    private static function validate_refresh_response(array $data, $previous_refresh_token, $now)
    {
        $access_token = self::non_empty_string($data['access_token'] ?? null);
        $refresh_token = self::non_empty_string($data['refresh_token'] ?? null);
        $expires = self::positive_integer($data['expires'] ?? null);
        $scope = self::non_empty_string($data['scope'] ?? null);
        $token_type = self::non_empty_string($data['token_type'] ?? null);

        if ($access_token === ''
            || $refresh_token === ''
            || $refresh_token === $previous_refresh_token
            || $expires <= $now
            || $scope === ''
            || $token_type === '') {
            return new WP_Error(
                'salla_token_refresh_invalid_response',
                'The Salla token refresh response was incomplete or invalid.'
            );
        }

        return [
            'access_token'  => $access_token,
            'refresh_token' => $refresh_token,
            'expires'       => $expires,
            'scope'         => $scope,
            'token_type'    => $token_type,
        ];
    }

    private static function credential($constant_name, $option_name)
    {
        $value = defined($constant_name)
            ? constant($constant_name)
            : get_option($option_name, '');

        return self::non_empty_string($value);
    }

    private static function current_timestamp()
    {
        return (int) current_time('timestamp', true);
    }

    private static function non_empty_string($value)
    {
        return is_string($value) ? trim($value) : '';
    }

    private static function positive_integer($value)
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
