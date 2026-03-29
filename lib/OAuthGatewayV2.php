<?php

class OAuthGatewayV2
{
    private $config;
    private $http;
    private $healthRedirectUri;

    public function __construct($config, $http, $healthRedirectUri = null)
    {
        $this->config = $config;
        $this->http = $http;
        $this->healthRedirectUri = $healthRedirectUri ? (string)$healthRedirectUri : (string)$config['health_id']['redirect_uri'];
    }

    public function buildHealthAuthorizeUrl($state)
    {
        $params = array(
            'client_id' => $this->config['health_id']['client_id'],
            'redirect_uri' => $this->healthRedirectUri,
            'response_type' => 'code',
            'state' => $state,
        );

        if (!empty($this->config['health_id']['scope'])) {
            $params['scope'] = $this->config['health_id']['scope'];
        }

        return rtrim($this->config['health_id']['base_url'], '/') .
            $this->config['health_id']['oauth_redirect_endpoint'] .
            '?' . http_build_query($params);
    }

    public function exchangeHealthCode($code)
    {
        $url = rtrim($this->config['health_id']['base_url'], '/') . $this->config['health_id']['token_endpoint'];
        $response = $this->http->post($url, array(
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->healthRedirectUri,
            'client_id' => $this->config['health_id']['client_id'],
            'client_secret' => $this->config['health_id']['client_secret'],
        ), array(
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
        ));

        return $this->normalizeTokenResponse('health', $response);
    }

    public function exchangeProviderToken($healthAccessToken)
    {
        $url = rtrim($this->config['provider_id']['base_url'], '/') . $this->config['provider_id']['token_endpoint'];
        $response = $this->http->post($url, array(
            'client_id' => $this->config['provider_id']['client_id'],
            'secret_key' => $this->config['provider_id']['secret_key'],
            'token_by' => 'Health ID',
            'token' => $healthAccessToken,
        ), array(
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ));

        return $this->normalizeTokenResponse('provider', $response);
    }

    public function fetchProviderProfile($providerAccessToken)
    {
        $url = rtrim($this->config['provider_id']['base_url'], '/') . $this->config['provider_id']['profile_endpoint'];
        $params = isset($this->config['provider_id']['profile_params']) && is_array($this->config['provider_id']['profile_params'])
            ? $this->config['provider_id']['profile_params']
            : array();

        $response = $this->http->get($url, $params, array(
            'Authorization' => 'Bearer ' . $providerAccessToken,
            'client-id' => $this->config['provider_id']['client_id'],
            'secret-key' => $this->config['provider_id']['secret_key'],
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ));

        if (!$response['success']) {
            return array(
                'success' => false,
                'error_code' => 'PROVIDER_PROFILE_REQUEST_FAILED',
                'error_message' => $response['error'],
                'raw' => $response,
            );
        }

        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            return array(
                'success' => false,
                'error_code' => 'PROVIDER_PROFILE_INVALID_JSON',
                'error_message' => 'Provider profile returned invalid JSON',
                'raw' => $response,
            );
        }

        if (isset($decoded['status']) && !in_array((string)$decoded['status'], array('200', 'success'), true)) {
            return array(
                'success' => false,
                'error_code' => 'PROVIDER_PROFILE_ERROR',
                'error_message' => isset($decoded['message']) ? $decoded['message'] : 'Provider profile error',
                'raw' => $decoded,
            );
        }

        $payload = isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : $decoded;
        $organizations = array();
        if (isset($payload['organization']) && is_array($payload['organization'])) {
            $organizations = $payload['organization'];
        } elseif (isset($payload['organizations']) && is_array($payload['organizations'])) {
            $organizations = $payload['organizations'];
        }

        return array(
            'success' => true,
            'profile' => $payload,
            'provider_id' => isset($payload['provider_id']) ? (string)$payload['provider_id'] : '',
            'name_th' => isset($payload['name_th']) ? (string)$payload['name_th'] : '',
            'name_eng' => isset($payload['name_eng']) ? (string)$payload['name_eng'] : '',
            'position_name' => isset($payload['position']) ? (string)$payload['position'] : (isset($payload['position_name']) ? (string)$payload['position_name'] : ''),
            'position_type' => isset($payload['position_type']) ? (string)$payload['position_type'] : '',
            'organizations' => $organizations,
            'raw' => $decoded,
        );
    }

    public function fetchHealthUserInfo($healthAccessToken)
    {
        $url = rtrim($this->config['health_id']['base_url'], '/') . $this->config['health_id']['userinfo_endpoint'];
        $response = $this->http->get($url, array(), array(
            'Authorization' => 'Bearer ' . $healthAccessToken,
            'Accept' => 'application/json',
        ));

        if (!$response['success']) {
            return array(
                'success' => false,
                'error_code' => 'HEALTH_USERINFO_REQUEST_FAILED',
                'error_message' => $response['error'],
                'raw' => $response,
            );
        }

        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            return array(
                'success' => false,
                'error_code' => 'HEALTH_USERINFO_INVALID_JSON',
                'error_message' => 'Health userinfo returned invalid JSON',
                'raw' => $response,
            );
        }

        if (isset($decoded['status']) && !in_array((string)$decoded['status'], array('200', 'success'), true)) {
            return array(
                'success' => false,
                'error_code' => 'HEALTH_USERINFO_API_ERROR',
                'error_message' => isset($decoded['message']) ? $decoded['message'] : 'Health userinfo error',
                'raw' => $decoded,
            );
        }

        $payload = isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : $decoded;

        return array(
            'success' => true,
            'data' => $payload,
            'raw' => $decoded,
        );
    }

    private function normalizeTokenResponse($source, $response)
    {
        $prefix = strtoupper($source);

        if (!$response['success']) {
            return array(
                'success' => false,
                'error_code' => $prefix . '_TOKEN_REQUEST_FAILED',
                'error_message' => $response['error'],
                'raw' => $response,
            );
        }

        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            return array(
                'success' => false,
                'error_code' => $prefix . '_TOKEN_INVALID_JSON',
                'error_message' => ucfirst($source) . ' token response is not valid JSON',
                'raw' => $response,
            );
        }

        if (isset($decoded['status']) && !in_array((string)$decoded['status'], array('200', 'success'), true)) {
            return array(
                'success' => false,
                'error_code' => $prefix . '_TOKEN_API_ERROR',
                'error_message' => isset($decoded['message']) ? $decoded['message'] : ucfirst($source) . ' token error',
                'raw' => $decoded,
            );
        }

        $payload = isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : $decoded;
        if (empty($payload['access_token'])) {
            return array(
                'success' => false,
                'error_code' => $prefix . '_TOKEN_MISSING_ACCESS_TOKEN',
                'error_message' => ucfirst($source) . ' token response did not include access_token',
                'raw' => $decoded,
            );
        }

        return array(
            'success' => true,
            'access_token' => (string)$payload['access_token'],
            'token_type' => isset($payload['token_type']) ? (string)$payload['token_type'] : 'Bearer',
            'expires_in' => isset($payload['expires_in']) ? (int)$payload['expires_in'] : null,
            'account_id' => isset($payload['account_id']) ? (string)$payload['account_id'] : '',
            'raw' => $decoded,
        );
    }
}
