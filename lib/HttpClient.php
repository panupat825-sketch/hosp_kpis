<?php
/**
 * lib/HttpClient.php
 * 
 * Helper class สำหรับ cURL HTTP requests
 * เหมาะสำหรับ PHP 5.6+ (ไม่ใช้ http_build_query ที่เกิน PHP 5.6)
 * 
 * วิธีใช้:
 *   $http = new HttpClient();
 *   $result = $http->post('https://example.com/api', array('key' => 'value'));
 *   if ($result['success']) {
 *       echo $result['body'];
 *   } else {
 *       echo $result['error'];
 *   }
 */

class HttpClient
{
    // ============================================================
    // Config
    // ============================================================
    private $timeout = 30; // seconds
    private $verify_ssl = true;
    private $follow_redirects = true;
    private $max_redirects = 5;
    
    // Logging
    private $logger_callback = null;
    private $mask_tokens = true;
    
    // ============================================================
    // Constructor
    // ============================================================
    public function __construct($options = array())
    {
        if (is_array($options)) {
            if (isset($options['timeout'])) {
                $this->timeout = (int)$options['timeout'];
            }
            if (isset($options['verify_ssl']) && is_bool($options['verify_ssl'])) {
                $this->verify_ssl = $options['verify_ssl'];
            }
            if (isset($options['logger'])) {
                $this->logger_callback = $options['logger'];
            }
            if (isset($options['mask_tokens']) && is_bool($options['mask_tokens'])) {
                $this->mask_tokens = $options['mask_tokens'];
            }
        }
    }
    
    // ============================================================
    // POST Request
    // ============================================================
    public function post($url, $data = array(), $headers = array())
    {
        $options = array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $this->_encode_body($data, $headers),
        );
        
        return $this->_request('POST', $url, $options, $headers);
    }
    
    // ============================================================
    // GET Request
    // ============================================================
    public function get($url, $query_params = array(), $headers = array())
    {
        if (!empty($query_params) && is_array($query_params)) {
            $q = $this->_build_query($query_params);
            if (empty($q)) {
                // ความพยายามสร้าง query string ไม่สำเร็จ
            } else {
                $url .= (strpos($url, '?') === false ? '?' : '&') . $q;
            }
        }
        
        return $this->_request('GET', $url, array(), $headers);
    }
    
    // ============================================================
    // PRIVATE: Main Request Handler
    // ============================================================
    private function _request($method, $url, $curl_options, $headers)
    {
        $result = array(
            'success' => false,
            'status_code' => 0,
            'headers' => array(),
            'body' => '',
            'error' => '',
            'duration_ms' => 0,
        );
        
        $start_time = microtime(true);
        
        // Initialize cURL
        $ch = curl_init();
        if (!$ch) {
            $result['error'] = 'Failed to initialize cURL';
            return $result;
        }
        
        try {
            // ============================================================
            // Basic curl options
            // ============================================================
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $this->follow_redirects);
            curl_setopt($ch, CURLOPT_MAXREDIRS, $this->max_redirects);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->verify_ssl ? 1 : 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->verify_ssl ? 2 : 0);
            curl_setopt($ch, CURLOPT_HEADER, 1); // ต้องการให้ return header ด้วย
            
            // ============================================================
            // Request method
            // ============================================================
            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, 1);
            } else {
                curl_setopt($ch, CURLOPT_HTTPGET, 1);
            }
            
            // ============================================================
            // Custom curl options (e.g. POSTFIELDS)
            // ============================================================
            foreach ($curl_options as $option => $value) {
                curl_setopt($ch, $option, $value);
            }
            
            // ============================================================
            // Headers
            // ============================================================
            if (!empty($headers) && is_array($headers)) {
                $header_array = array();
                foreach ($headers as $key => $value) {
                    $header_array[] = $key . ': ' . $value;
                }
                curl_setopt($ch, CURLOPT_HTTPHEADER, $header_array);
            }
            
            // ============================================================
            // User Agent
            // ============================================================
            curl_setopt($ch, CURLOPT_USERAGENT, 'HospitalKPI/2.0 (OAuth Client)');
            
            // ============================================================
            // Execute
            // ============================================================
            $response = curl_exec($ch);
            $curl_errno = curl_errno($ch);
            $curl_error = curl_error($ch);
            $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $duration_ms = round((microtime(true) - $start_time) * 1000, 2);
            
            $result['duration_ms'] = $duration_ms;
            $result['status_code'] = (int)$http_status;
            
            // Check curl errors
            if ($curl_errno) {
                $result['error'] = 'cURL Error: ' . $curl_error . ' (code: ' . $curl_errno . ')';
                $this->_log('error', $url, $method, $http_status, $result['error'], $duration_ms);
                return $result;
            }
            
            if ($response === false) {
                $result['error'] = 'cURL request failed';
                $this->_log('error', $url, $method, $http_status, $result['error'], $duration_ms);
                return $result;
            }
            
            // ============================================================
            // Parse response (header + body)
            // ============================================================
            $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $response_headers = substr($response, 0, $header_size);
            $response_body = substr($response, $header_size);
            
            $result['body'] = $response_body;
            $result['headers'] = $this->_parse_header_string($response_headers);
            
            // HTTP 200-299 is considered success, 300-399 redirects (already handled)
            $result['success'] = ($http_status >= 200 && $http_status < 400);

            if (!$result['success'] && $result['error'] === '') {
                $snippet = trim(substr($response_body, 0, 500));
                $result['error'] = 'HTTP ' . $http_status . ($snippet !== '' ? ' | ' . $snippet : '');
            }
            
            // Log
            $log_body = $this->_mask_sensitive_data($response_body);
            $this->_log(
                $result['success'] ? 'success' : 'error',
                $url,
                $method,
                $http_status,
                $log_body,
                $duration_ms
            );
            
            return $result;
            
        } catch (Exception $e) {
            $result['error'] = 'Exception: ' . $e->getMessage();
            $result['duration_ms'] = round((microtime(true) - $start_time) * 1000, 2);
            return $result;
        } finally {
            if (is_resource($ch)) {
                curl_close($ch);
            }
        }
    }
    
    // ============================================================
    // PRIVATE: Helper - Encode Body
    // ============================================================
    private function _encode_body($data, $headers)
    {
        // Check Content-Type header
        $content_type = '';
        foreach ($headers as $key => $value) {
            if (strtolower($key) === 'content-type') {
                $content_type = strtolower($value);
                break;
            }
        }
        
        // Default to form-urlencoded if not specified
        if (empty($content_type)) {
            $content_type = 'application/x-www-form-urlencoded';
        }
        
        if (strpos($content_type, 'application/json') !== false) {
            // JSON encoding
            if (is_string($data)) {
                return $data; // already encoded
            }
            $json = json_encode($data);
            if ($json === false) {
                return '';
            }
            return $json;
        } else {
            // Form URL-encoded
            return $this->_build_query($data);
        }
    }
    
    // ============================================================
    // PRIVATE: Helper - Build Query String (PHP 5.6 compatible)
    // ============================================================
    private function _build_query($data)
    {
        if (!is_array($data) || empty($data)) {
            return '';
        }
        
        $pairs = array();
        foreach ($data as $key => $value) {
            $key = urlencode((string)$key);
            if (is_array($value)) {
                // Handle multiple values (rare in OAuth)
                foreach ($value as $v) {
                    $pairs[] = $key . '=' . urlencode((string)$v);
                }
            } else {
                $pairs[] = $key . '=' . urlencode((string)$value);
            }
        }
        
        return implode('&', $pairs);
    }
    
    // ============================================================
    // PRIVATE: Helper - Parse Response Headers
    // ============================================================
    private function _parse_header_string($header_string)
    {
        $headers = array();
        $lines = explode("\r\n", $header_string);
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }
            if (stripos($line, 'HTTP/') === 0) {
                // Status line, skip
                continue;
            }
            
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $key = trim(substr($line, 0, $pos));
                $value = trim(substr($line, $pos + 1));
                $headers[strtolower($key)] = $value;
            }
        }
        
        return $headers;
    }
    
    // ============================================================
    // PRIVATE: Helper - Mask Sensitive Data
    // ============================================================
    private function _mask_sensitive_data($data)
    {
        if (!$this->mask_tokens) {
            return $data;
        }
        
        // Mask access_token, refresh_token, etc.
        $data = preg_replace_callback(
            '/"(access_token|refresh_token|token|secret|client_secret)" *: *"([^"]*)"/',
            function ($matches) {
                $key = $matches[1];
                $val = $matches[2];
                if (strlen($val) > 8) {
                    $masked = substr($val, -8);
                    return '"' . $key . '": "...' . $masked . '"';
                }
                return $matches[0];
            },
            $data
        );
        
        return $data;
    }
    
    // ============================================================
    // PRIVATE: Helper - Logging
    // ============================================================
    private function _log($level, $url, $method, $status, $message, $duration_ms)
    {
        if (is_callable($this->logger_callback)) {
            call_user_func(
                $this->logger_callback,
                array(
                    'level' => $level,
                    'url' => $url,
                    'method' => $method,
                    'status' => $status,
                    'message' => $message,
                    'duration_ms' => $duration_ms,
                )
            );
        }
    }
}
