<?php
/**
 * lib/ProviderIdService.php
 * 
 * Service สำหรับ Provider ID API calls
 * 
 * Workflow:
 * 1. exchangeHealthIdTokenForProviderToken() -> Exchange Health ID token เป็น Provider token
 * 2. fetchUserProfile() -> ดึง user profile ข้อมูลครบถ้วน
 */

class ProviderIdService
{
    private $config;
    private $http_client;
    private $logger;
    
    // ============================================================
    // Constructor
    // ============================================================
    public function __construct($oauth_config, $http_client, $logger = null)
    {
        $this->config = $oauth_config;
        $this->http_client = $http_client;
        $this->logger = $logger;
    }
    
    // ============================================================
    // Exchange Health ID Token for Provider Token
    // ============================================================
    /**
     * นำ Health ID access token ไปแลก Provider access token
     * 
     * @param string $health_id_access_token
     * @return array {
     *     'success' => bool,
     *     'access_token' => string|null,
     *     'token_type' => string|null,
     *     'expires_in' => int|null,
     *     'provider_id' => string|null, // จาก response
     *     'error' => string|null,
     *     'error_code' => string|null, // NO_PROVIDER_ID, etc.
     * }
     */
    public function exchangeHealthIdTokenForProviderToken($health_id_access_token)
    {
        $result = array(
            'success' => false,
            'access_token' => null,
            'token_type' => null,
            'expires_in' => null,
            'provider_id' => null,
            'error' => null,
            'error_code' => null,
        );
        
        if (empty($health_id_access_token)) {
            $result['error'] = 'Health ID token is empty';
            $result['error_code'] = 'INVALID_HEALTH_TOKEN';
            return $result;
        }
        
        try {
            $base_url = $this->config['provider_id']['base_url'];
            $endpoint = $this->config['provider_id']['token_endpoint'];
            $url = rtrim($base_url, '/') . '/' . ltrim($endpoint, '/');
            
            // Request body (JSON)
            $post_data = array(
                'client_id' => $this->config['provider_id']['client_id'],
                'secret_key' => $this->config['provider_id']['secret_key'],
                'token_by' => 'Health ID',
                'token' => $health_id_access_token,
            );
            
            $headers = array(
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            );
            
            $this->_log('info', 'Requesting Provider token exchange...');
            $http_result = $this->http_client->post($url, $post_data, $headers);
            
            if (!$http_result['success']) {
                $result['error'] = 'Provider token request failed: ' . $http_result['error'];
                $result['error_code'] = 'PROVIDER_TOKEN_REQUEST_FAILED';
                $this->_log('error', $result['error']);
                return $result;
            }
            
            // Parse response
            $response = json_decode($http_result['body'], true);
            
            if (!is_array($response)) {
                $result['error'] = 'Provider returned invalid JSON';
                $result['error_code'] = 'INVALID_PROVIDER_RESPONSE';
                $this->_log('error', 'Provider returned invalid JSON');
                return $result;
            }
            
            $payload = $response;
            if (isset($response['data']) && is_array($response['data'])) {
                $payload = $response['data'];
            }
            
            // Check for errors in response
            if (isset($response['error']) ||
                (isset($response['status']) && !in_array((string)$response['status'], array('success', '200'), true))) {
                $error_msg = isset($response['error']) ? $response['error'] :
                            (isset($response['message']) ? $response['message'] : 'Unknown error');
                $result['error'] = $error_msg;
                
                // Detect "no provider ID" case
                if (stripos($error_msg, 'not found') !== false || 
                    stripos($error_msg, 'no provider') !== false ||
                    stripos($error_msg, 'provider id') !== false ||
                    stripos($error_msg, 'not provider id') !== false) {
                    $result['error_code'] = 'NO_PROVIDER_ID';
                } else {
                    $result['error_code'] = 'PROVIDER_ERROR';
                }
                
                $this->_log('error', 'Provider error: ' . $error_msg);
                return $result;
            }
            
            // Extract token from response
            if (empty($payload['access_token'])) {
                $result['error'] = 'Provider did not return access token';
                $result['error_code'] = 'NO_ACCESS_TOKEN';
                $this->_log('error', $result['error']);
                return $result;
            }
            
            // SUCCESS!
            $result['success'] = true;
            $result['access_token'] = (string)$payload['access_token'];
            $result['token_type'] = isset($payload['token_type']) ? $payload['token_type'] : 'Bearer';
            $result['expires_in'] = isset($payload['expires_in']) ? (int)$payload['expires_in'] : null;
            
            // Optional: extract provider_id from response
            if (isset($payload['provider_id'])) {
                $result['provider_id'] = (string)$payload['provider_id'];
            } elseif (isset($payload['account_id'])) {
                $result['provider_id'] = (string)$payload['account_id'];
            }
            
            $this->_log('success', 'Provider token exchange successful');
            return $result;
            
        } catch (Exception $e) {
            $result['error'] = 'Exception: ' . $e->getMessage();
            $result['error_code'] = 'EXCEPTION';
            $this->_log('error', 'Exception: ' . $e->getMessage());
            return $result;
        }
    }
    
    // ============================================================
    // Fetch User Profile
    // ============================================================
    /**
     * ดึง user profile จาก Provider ID API
     * 
     * @param string $provider_access_token
     * @param array $optional_params - moph_center_token, moph_idp_permission, position_type
     * @return array {
     *     'success' => bool,
     *     'data' => array, // Full profile data
     *     'provider_id' => string|null,
     *     'name_th' => string|null,
     *     'name_eng' => string|null,
     *     'position_name' => string|null,
     *     'position_type' => string|null,
     *     'organizations' => array, // Array of org objects
     *     'error' => string|null,
     *     'error_code' => string|null,
     * }
     */
    public function fetchUserProfile($provider_access_token, $optional_params = array())
    {
        $result = array(
            'success' => false,
            'data' => null,
            'provider_id' => null,
            'name_th' => null,
            'name_eng' => null,
            'position_name' => null,
            'position_type' => null,
            'organizations' => array(),
            'error' => null,
            'error_code' => null,
        );
        
        if (empty($provider_access_token)) {
            $result['error'] = 'Provider token is empty';
            $result['error_code'] = 'INVALID_PROVIDER_TOKEN';
            return $result;
        }
        
        try {
            $base_url = $this->config['provider_id']['base_url'];
            $endpoint = $this->config['provider_id']['profile_endpoint'];
            $url = rtrim($base_url, '/') . '/' . ltrim($endpoint, '/');
            
            // Query params (optional)
            $query_params = array();
            if (isset($this->config['provider_id']['profile_params'])) {
                $query_params = $this->config['provider_id']['profile_params'];
            }
            
            // Override with passed params
            if (is_array($optional_params)) {
                $query_params = array_merge($query_params, $optional_params);
            }
            
            // Headers
            $headers = array(
                'Authorization' => 'Bearer ' . $provider_access_token,
                'client-id' => $this->config['provider_id']['client_id'],
                'secret-key' => $this->config['provider_id']['secret_key'],
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            );
            
            $this->_log('info', 'Fetching user profile from Provider...');
            $http_result = $this->http_client->get($url, $query_params, $headers);
            
            if (!$http_result['success']) {
                $result['error'] = 'Profile request failed: ' . $http_result['error'];
                $result['error_code'] = 'PROFILE_REQUEST_FAILED';
                $this->_log('error', $result['error']);
                return $result;
            }
            
            // Parse response
            $response = json_decode($http_result['body'], true);
            
            if (!is_array($response)) {
                $result['error'] = 'Provider returned invalid JSON';
                $result['error_code'] = 'INVALID_PROFILE_RESPONSE';
                return $result;
            }
            
            $payload = $response;
            if (isset($response['data']) && is_array($response['data'])) {
                $payload = $response['data'];
            }
            
            // Check for errors
            if (isset($response['error']) ||
                (isset($response['status']) && !in_array((string)$response['status'], array('success', '200'), true))) {
                $error_msg = isset($response['error']) ? $response['error'] :
                            (isset($response['message']) ? $response['message'] : 'Unknown error');
                $result['error'] = $error_msg;
                $result['error_code'] = 'PROVIDER_PROFILE_ERROR';
                return $result;
            }
            
            // SUCCESS! Parse profile data
            $result['success'] = true;
            $result['data'] = $payload;
            
            // Extract important fields
            // Structure may vary, try common patterns
            if (isset($payload['provider_id'])) {
                $result['provider_id'] = (string)$payload['provider_id'];
            } elseif (isset($payload['account_id'])) {
                $result['provider_id'] = (string)$payload['account_id'];
            }
            
            if (isset($payload['name_th'])) {
                $result['name_th'] = (string)$payload['name_th'];
            }
            
            if (isset($payload['name_eng'])) {
                $result['name_eng'] = (string)$payload['name_eng'];
            }
            
            if (isset($payload['position_name'])) {
                $result['position_name'] = (string)$payload['position_name'];
            }
            
            if (isset($payload['position_type'])) {
                $result['position_type'] = (string)$payload['position_type'];
            }
            
            // Extract organizations (typically in array)
            if (isset($payload['organizations']) && is_array($payload['organizations'])) {
                $result['organizations'] = $payload['organizations'];
            } elseif (isset($payload['org']) && is_array($payload['org'])) {
                $result['organizations'] = $payload['org'];
            }
            
            $this->_log('success', 'User profile fetched successfully. Provider ID: ' . $result['provider_id']);
            return $result;
            
        } catch (Exception $e) {
            $result['error'] = 'Exception: ' . $e->getMessage();
            $result['error_code'] = 'EXCEPTION';
            return $result;
        }
    }
    
    // ============================================================
    // PRIVATE: Helpers
    // ============================================================
    
    private function _log($level, $message)
    {
        if (is_callable($this->logger)) {
            call_user_func($this->logger, $level, '[ProviderID] ' . $message);
        } else {
            error_log('[ProviderID] ' . $message);
        }
    }
}
