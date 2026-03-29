<?php
/**
 * lib/HealthIdService.php
 * 
 * Service สำหรับ Health ID OAuth workflow
 * 
 * Flow:
 * 1. generateAuthUrl() -> ใช้ redirect ผู้ใช้ไป Health ID login page
 * 2. exchangeCodeForToken() -> Exchange authorization code เป็น access token
 */

class HealthIdService
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
    // Generate OAuth Redirect URL
    // ============================================================
    /**
     * สร้าง URL สำหรับ redirect ไปยัง Health ID login page
     * 
     * @param string $state - random string สำหรับ CSRF protection
     * @param array $extra_params - optional parameters ที่ต้องการส่งเพิ่มเติม
     * @return string URL ที่จะ redirect
     */
    public function generateAuthUrl($state, $extra_params = array())
    {
        $params = array(
            'client_id' => $this->config['health_id']['client_id'],
            'redirect_uri' => $this->config['health_id']['redirect_uri'],
            'response_type' => 'code',
            'state' => $state,
            'scope' => isset($this->config['health_id']['scope']) ? 
                       $this->config['health_id']['scope'] : 'openid profile',
        );
        
        // Merge with extra params
        if (is_array($extra_params)) {
            $params = array_merge($params, $extra_params);
        }
        
        // Build URL
        $base_url = $this->config['health_id']['base_url'];
        $endpoint = $this->config['health_id']['oauth_redirect_endpoint'];
        $url = rtrim($base_url, '/') . '/' . ltrim($endpoint, '/');
        
        // Append query params
        $query = $this->_buildQuery($params);
        return $url . '?' . $query;
    }
    
    // ============================================================
    // Exchange Authorization Code for Access Token
    // ============================================================
    /**
     * Exchange authorization code (จาก Health ID) เป็น access token
     * 
     * @param string $code - authorization code จาก Health ID callback
     * @param string $state - state ที่ส่งไปก่อนหน้า (สำหรับ verify)
     * @return array {
     *     'success' => bool,
     *     'access_token' => string|null,
     *     'token_type' => string|null, // เช่น "Bearer"
     *     'expires_in' => int|null,
     *     'refresh_token' => string|null,
     *     'error' => string|null,
     *     'error_description' => string|null,
     * }
     */
    public function exchangeCodeForToken($code, $state = null)
    {
        $result = array(
            'success' => false,
            'access_token' => null,
            'token_type' => null,
            'expires_in' => null,
            'refresh_token' => null,
            'error' => null,
            'error_description' => null,
        );
        
        // Validate inputs
        if (empty($code)) {
            $result['error'] = 'INVALID_CODE';
            $result['error_description'] = 'Authorization code is empty';
            $this->_log('error', 'exchangeCodeForToken: invalid code');
            return $result;
        }
        
        try {
            // Prepare token request
            $base_url = $this->config['health_id']['base_url'];
            $endpoint = $this->config['health_id']['token_endpoint'];
            $url = rtrim($base_url, '/') . '/' . ltrim($endpoint, '/');
            
            $post_data = array(
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->config['health_id']['redirect_uri'],
                'client_id' => $this->config['health_id']['client_id'],
                'client_secret' => $this->config['health_id']['client_secret'],
            );
            
            $headers = array(
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept' => 'application/json',
            );
            
            // Make request
            $http_result = $this->http_client->post($url, $post_data, $headers);
            
            if (!$http_result['success']) {
                $result['error'] = 'HEALTH_ID_REQUEST_FAILED';
                $result['error_description'] = $http_result['error'];
                $this->_log('error', 'Health ID token request failed: ' . $http_result['error']);
                return $result;
            }
            
            // Parse response
            $response = json_decode($http_result['body'], true);
            
            if (!is_array($response)) {
                $result['error'] = 'INVALID_RESPONSE';
                $result['error_description'] = 'Health ID returned invalid JSON';
                $this->_log('error', 'Health ID returned invalid JSON: ' . substr($http_result['body'], 0, 100));
                return $result;
            }
            
            // Extract payload from common response envelopes
            $payload = $response;
            if (isset($response['data']) && is_array($response['data'])) {
                $payload = $response['data'];
            }
            
            // Check for error in response
            if (isset($response['error'])) {
                $result['error'] = $response['error'];
                $result['error_description'] = isset($response['error_description']) ?
                                                $response['error_description'] :
                                                (isset($response['message']) ? $response['message'] : null);
                $this->_log('error', 'Health ID error: ' . $result['error']);
                return $result;
            }
            
            if (isset($response['status']) && !in_array((string)$response['status'], array('success', '200'), true)) {
                $result['error'] = 'HEALTH_ID_API_ERROR';
                $result['error_description'] = isset($response['message']) ? $response['message'] : 'Health ID returned an error';
                $this->_log('error', 'Health ID API error: ' . $result['error_description']);
                return $result;
            }
            
            // Extract token
            if (empty($payload['access_token'])) {
                $result['error'] = 'NO_ACCESS_TOKEN';
                $result['error_description'] = 'Health ID did not return access token';
                $this->_log('error', 'Health ID did not return access token');
                return $result;
            }
            
            // SUCCESS!
            $result['success'] = true;
            $result['access_token'] = (string)$payload['access_token'];
            $result['token_type'] = isset($payload['token_type']) ? $payload['token_type'] : 'Bearer';
            $result['expires_in'] = isset($payload['expires_in']) ? (int)$payload['expires_in'] : null;
            $result['refresh_token'] = isset($payload['refresh_token']) ? $payload['refresh_token'] : null;
            
            $this->_log('success', 'Health ID token exchange successful');
            return $result;
            
        } catch (Exception $e) {
            $result['error'] = 'EXCEPTION';
            $result['error_description'] = $e->getMessage();
            $this->_log('error', 'Exception in exchangeCodeForToken: ' . $e->getMessage());
            return $result;
        }
    }
    
    // ============================================================
    // Fetch User Info
    // ============================================================
    /**
     * ดึงข้อมูล user จาก Health ID (optional)
     * 
     * @param string $access_token
     * @return array {
     *     'success' => bool,
     *     'data' => array,
     *     'error' => string|null,
     * }
     */
    public function getUserInfo($access_token)
    {
        $result = array(
            'success' => false,
            'data' => null,
            'error' => null,
        );
        
        if (empty($access_token)) {
            $result['error'] = 'INVALID_ACCESS_TOKEN';
            return $result;
        }
        
        try {
            $base_url = $this->config['health_id']['base_url'];
            $endpoint = $this->config['health_id']['userinfo_endpoint'];
            $url = rtrim($base_url, '/') . '/' . ltrim($endpoint, '/');
            
            $headers = array(
                'Authorization' => 'Bearer ' . $access_token,
                'Accept' => 'application/json',
            );
            
            $http_result = $this->http_client->get($url, array(), $headers);
            
            if (!$http_result['success']) {
                $result['error'] = 'USERINFO_REQUEST_FAILED';
                return $result;
            }
            
            $data = json_decode($http_result['body'], true);
            
            if (!is_array($data)) {
                $result['error'] = 'INVALID_RESPONSE';
                return $result;
            }
            
            $result['success'] = true;
            $result['data'] = $data;
            return $result;
            
        } catch (Exception $e) {
            $result['error'] = 'EXCEPTION: ' . $e->getMessage();
            return $result;
        }
    }
    
    // ============================================================
    // PRIVATE: Helpers
    // ============================================================
    
    private function _buildQuery($params)
    {
        $pairs = array();
        foreach ($params as $key => $value) {
            $key = urlencode((string)$key);
            $pairs[] = $key . '=' . urlencode((string)$value);
        }
        return implode('&', $pairs);
    }
    
    private function _log($level, $message)
    {
        if (is_callable($this->logger)) {
            call_user_func($this->logger, $level, '[HealthID] ' . $message);
        } else {
            error_log('[HealthID] ' . $message);
        }
    }
}
