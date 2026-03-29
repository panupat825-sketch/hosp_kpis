<?php
/**
 * lib/AuthService.php
 * 
 * Core authentication orchestrator
 * 
 * Responsibilities:
 * - Manage session lifecycle
 * - Coordinate Health ID + Provider ID OAuth flow
 * - User creation/update in local database
 * - Login audit logging
 */

class AuthService
{
    private $conn; // mysqli connection
    private $config;
    private $health_id_service;
    private $provider_id_service;
    private $logger;
    
    // State management
    private $session_name;
    private $state_param_name = 'oauth_state';
    private $nonce_param_name = 'oauth_nonce';
    
    // ============================================================
    // Constructor
    // ============================================================
    public function __construct($mysqli_conn, $oauth_config, $health_id_service, $provider_id_service, $logger = null)
    {
        $this->conn = $mysqli_conn;
        $this->config = $oauth_config;
        $this->health_id_service = $health_id_service;
        $this->provider_id_service = $provider_id_service;
        $this->logger = $logger;
        
        $this->session_name = isset($oauth_config['session']['name']) ? 
                              $oauth_config['session']['name'] : 'HOSP_KPI_SID';
    }
    
    // ============================================================
    // Generate OAuth Login Link & State
    // ============================================================
    /**
     * สร้าง OAuth authorization URL + เก็บ state ในเซสชั่น
     * 
     * @return array {
     *     'success' => bool,
     *     'auth_url' => string|null,
     *     'state' => string|null,
     *     'error' => string|null,
     * }
     */
    public function initializeOAuthFlow()
    {
        $result = array(
            'success' => false,
            'auth_url' => null,
            'state' => null,
            'error' => null,
        );
        
        try {
            // Generate random state (CSRF protection)
            $state = $this->_generateRandomString(32);
            
            // Store state in session with timestamp
            $_SESSION[$this->state_param_name] = array(
                'value' => $state,
                'created_at' => time(),
                'timeout' => isset($this->config['security']['state_timeout']) ? 
                            $this->config['security']['state_timeout'] : 600,
            );
            
            // Generate auth URL
            $auth_url = $this->health_id_service->generateAuthUrl($state);
            
            if (empty($auth_url)) {
                $result['error'] = 'Failed to generate auth URL';
                return $result;
            }
            
            $result['success'] = true;
            $result['auth_url'] = $auth_url;
            $result['state'] = $state;
            
            $this->_log('info', 'OAuth flow initialized. State: ' . substr($state, 0, 8) . '...');
            return $result;
            
        } catch (Exception $e) {
            $result['error'] = 'Exception: ' . $e->getMessage();
            $this->_log('error', 'initializeOAuthFlow exception: ' . $e->getMessage());
            return $result;
        }
    }
    
    // ============================================================
    // Process OAuth Callback
    // ============================================================
    /**
     * ประมวลผล OAuth callback จาก Health ID
     * 
     * Flow:
     * 1. Verify state (CSRF protection)
     * 2. Exchange code for Health ID token
     * 3. Exchange Health ID token for Provider token
     * 4. Fetch user profile from Provider
     * 5. Create/update user in local DB
     * 6. Create session
     * 
     * @param string $code - Authorization code from Health ID
     * @param string $state - State from callback
     * @return array {
     *     'success' => bool,
     *     'provider_id' => string|null,
     *     'user_id' => int|null,
     *     'error_code' => string|null, // NO_PROVIDER_ID, CSRF_FAILED, etc.
     *     'error_message' => string|null,
     * }
     */
    public function handleOAuthCallback($code, $state)
    {
        $result = array(
            'success' => false,
            'provider_id' => null,
            'user_id' => null,
            'error_code' => null,
            'error_message' => null,
        );
        
        $start_time = microtime(true);
        
        try {
            // ============================================================
            // 1. VERIFY STATE (CSRF Protection)
            // ============================================================
            $state_verify = $this->_verifyState($state);
            if (!$state_verify['valid']) {
                $result['error_code'] = 'CSRF_FAILED';
                $result['error_message'] = 'Invalid or expired state parameter';
                $this->_createLoginAudit(null, null, 'login_failed', 'INVALID_STATE', $state_verify['message']);
                $this->_log('error', 'State verification failed');
                return $result;
            }
            
            $this->_log('info', 'State verification passed');
            
            // ============================================================
            // 2. EXCHANGE CODE FOR HEALTH ID TOKEN
            // ============================================================
            $health_token_result = $this->health_id_service->exchangeCodeForToken($code, $state);
            
            if (!$health_token_result['success']) {
                $result['error_code'] = $health_token_result['error'];
                $result['error_message'] = $health_token_result['error_description'];
                $this->_createLoginAudit(null, null, 'login_failed', $health_token_result['error'], null);
                $this->_log('error', 'Health ID token exchange failed: ' . $health_token_result['error']);
                return $result;
            }
            
            $health_access_token = $health_token_result['access_token'];
            if (!empty($this->config['debug'])) {
                $_SESSION['debug_health_access_token'] = $health_access_token;
                $_SESSION['debug_health_token_time'] = time();
            }
            $this->_log('info', 'Health ID token obtained');
            
            // ============================================================
            // 3. EXCHANGE HEALTH ID TOKEN FOR PROVIDER TOKEN
            // ============================================================
            $provider_token_result = $this->provider_id_service->exchangeHealthIdTokenForProviderToken($health_access_token);
            
            if (!$provider_token_result['success']) {
                // Special case: user not in Provider ID system
                if ($provider_token_result['error_code'] === 'NO_PROVIDER_ID') {
                    $result['error_code'] = 'NO_PROVIDER_ID';
                    $result['error_message'] = 'ผู้ใช้นี้ไม่มีข้อมูลในระบบ Provider ID';
                    $this->_createLoginAudit(null, null, 'access_denied', 'NO_PROVIDER_ID', null);
                    $this->_log('info', 'User has no Provider ID assignment');
                } else {
                    $result['error_code'] = $provider_token_result['error_code'];
                    $result['error_message'] = $provider_token_result['error'];
                    $this->_createLoginAudit(null, null, 'login_failed', $provider_token_result['error_code'], null);
                    $this->_log('error', 'Provider token exchange failed: ' . $provider_token_result['error']);
                }
                return $result;
            }
            
            $provider_access_token = $provider_token_result['access_token'];
            if (!empty($this->config['debug'])) {
                $_SESSION['debug_provider_access_token'] = $provider_access_token;
                $_SESSION['debug_provider_token_time'] = time();
            }
            $this->_log('info', 'Provider token obtained');
            
            // ============================================================
            // 4. FETCH USER PROFILE FROM PROVIDER
            // ============================================================
            $profile_result = $this->provider_id_service->fetchUserProfile($provider_access_token);
            
            if (!$profile_result['success']) {
                $result['error_code'] = $profile_result['error_code'];
                $result['error_message'] = $profile_result['error'];
                $this->_createLoginAudit(null, null, 'login_failed', $profile_result['error_code'], null);
                $this->_log('error', 'Profile fetch failed: ' . $profile_result['error']);
                return $result;
            }
            
            $provider_id = $profile_result['provider_id'];
            $this->_log('info', 'User profile obtained. Provider ID: ' . $provider_id);
            
            // ============================================================
            // 5. CHECK IF USER DISABLED IN LOCAL SYSTEM
            // ============================================================
            $user_check = $this->_checkUserDisabled($provider_id);
            if ($user_check['is_disabled']) {
                $result['error_code'] = 'USER_DISABLED';
                $result['error_message'] = 'ผู้ใช้นี้ถูกปิดใช้งานแล้ว';
                $this->_createLoginAudit($user_check['user_id'], $provider_id, 'login_failed', 'USER_DISABLED', null);
                $this->_log('info', 'User is disabled locally. Provider ID: ' . $provider_id);
                return $result;
            }
            
            // ============================================================
            // 6. CREATE OR UPDATE USER IN LOCAL DATABASE
            // ============================================================
            $user_op = $this->saveOrUpdateUserFromProviderProfile($provider_id, $profile_result);
            
            if (!$user_op['success']) {
                $result['error_code'] = 'USER_SAVE_FAILED';
                $result['error_message'] = $user_op['error'];
                $this->_createLoginAudit(null, $provider_id, 'login_failed', 'USER_SAVE_FAILED', null);
                $this->_log('error', 'User save/update failed: ' . $user_op['error']);
                return $result;
            }
            
            $app_user_id = $user_op['app_user_id'];
            $is_new_user = $user_op['is_new'];
            
            $this->_log('info', 'User saved. App User ID: ' . $app_user_id . ', Is New: ' . ($is_new_user ? 'yes' : 'no'));
            
            // ============================================================
            // 7. CREATE SESSION
            // ============================================================
            session_regenerate_id(true);
            
            $user_roles = $this->_getUserRoles($app_user_id);
            $user_org = $this->_getUserDefaultOrg($app_user_id);
            
            $_SESSION['app_user_id'] = (int)$app_user_id;
            $_SESSION['provider_id'] = $provider_id;
            $_SESSION['name_th'] = $profile_result['name_th'] ?: '';
            $_SESSION['name_eng'] = $profile_result['name_eng'] ?: '';
            $_SESSION['position_name'] = $profile_result['position_name'] ?: '';
            $_SESSION['hcode'] = $user_org ? $user_org['hcode'] : '';
            $_SESSION['hname_th'] = $user_org ? $user_org['hname_th'] : '';
            $_SESSION['roles'] = $user_roles;
            $_SESSION['login_time'] = time();
            $_SESSION['last_activity'] = time();
            
            // Clean up state
            unset($_SESSION[$this->state_param_name]);
            
            $this->_log('success', 'Session created for user: ' . $provider_id);
            
            // ============================================================
            // 8. CREATE LOGIN AUDIT LOG (SUCCESS)
            // ============================================================
            $duration_ms = round((microtime(true) - $start_time) * 1000, 2);
            $this->_createLoginAudit(
                $app_user_id,
                $provider_id,
                'login_success',
                'OK',
                null,
                $duration_ms
            );
            
            // ============================================================
            // SUCCESS!
            // ============================================================
            $result['success'] = true;
            $result['provider_id'] = $provider_id;
            $result['user_id'] = $app_user_id;
            
            return $result;
            
        } catch (Exception $e) {
            $this->_log('error', 'handleOAuthCallback exception: ' . $e->getMessage());
            $result['error_code'] = 'EXCEPTION';
            $result['error_message'] = $e->getMessage();
            return $result;
        }
    }
    
    // ============================================================
    // Check if User is Currently Logged In
    // ============================================================
    public function isLoggedIn()
    {
        return !empty($_SESSION['app_user_id']) && !empty($_SESSION['provider_id']);
    }
    
    // ============================================================
    // Get Current User Session Data
    // ============================================================
    public function getCurrentUser()
    {
        if (!$this->isLoggedIn()) {
            return null;
        }
        
        return array(
            'app_user_id' => (int)$_SESSION['app_user_id'],
            'provider_id' => $_SESSION['provider_id'],
            'name_th' => $_SESSION['name_th'],
            'name_eng' => $_SESSION['name_eng'],
            'position_name' => $_SESSION['position_name'],
            'hcode' => $_SESSION['hcode'],
            'hname_th' => $_SESSION['hname_th'],
            'roles' => isset($_SESSION['roles']) ? $_SESSION['roles'] : array(),
            'login_time' => isset($_SESSION['login_time']) ? $_SESSION['login_time'] : null,
        );
    }
    
    // ============================================================
    // Check Session Idle Timeout
    // ============================================================
    public function checkSessionIdleTimeout()
    {
        if (!$this->isLoggedIn()) {
            return true; // Not logged in, OK
        }
        
        $idle_timeout = isset($this->config['session']['idle_timeout']) ? 
                       $this->config['session']['idle_timeout'] : 1800;
        
        $last_activity = isset($_SESSION['last_activity']) ? $_SESSION['last_activity'] : time();
        $now = time();
        
        if (($now - $last_activity) > $idle_timeout) {
            // Expired
            $this->logout('SESSION_TIMEOUT');
            return false;
        }
        
        // Update activity time
        $_SESSION['last_activity'] = time();
        return true;
    }
    
    // ============================================================
    // Logout
    // ============================================================
    public function logout($reason = 'MANUAL')
    {
        $provider_id = isset($_SESSION['provider_id']) ? $_SESSION['provider_id'] : null;
        $app_user_id = isset($_SESSION['app_user_id']) ? $_SESSION['app_user_id'] : null;
        
        // Log the logout event
        $this->_createLoginAudit(
            $app_user_id,
            $provider_id,
            'logout',
            $reason,
            null
        );
        
        // Destroy session
        $_SESSION = array();
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        session_destroy();
        
        $this->_log('info', 'User logged out. Provider: ' . $provider_id . ', Reason: ' . $reason);
    }
    
    // ============================================================
    // Has Role
    // ============================================================
    public function hasRole($role_names)
    {
        if (!$this->isLoggedIn()) {
            return false;
        }
        
        $user_roles = isset($_SESSION['roles']) ? $_SESSION['roles'] : array();
        
        if (!is_array($role_names)) {
            $role_names = array($role_names);
        }
        
        foreach ($role_names as $role) {
            if (in_array($role, $user_roles, true)) {
                return true;
            }
        }
        
        return false;
    }
    
    // ============================================================
    // SAVE OR UPDATE USER FROM PROVIDER PROFILE
    // ============================================================
    /**
     * บันทึกหรือ update user ในฐานข้อมูล local จากข้อมูล Provider profile
     * 
     * @param string $provider_id
     * @param array $profile_data จาก ProviderIdService->fetchUserProfile()
     * @return array {
     *     'success' => bool,
     *     'app_user_id' => int,
     *     'is_new' => bool,
     *     'error' => string|null,
     * }
     */
    public function saveOrUpdateUserFromProviderProfile($provider_id, $profile_data)
    {
        $result = array(
            'success' => false,
            'app_user_id' => null,
            'is_new' => false,
            'error' => null,
        );
        
        if (empty($provider_id)) {
            $result['error'] = 'Provider ID is empty';
            return $result;
        }
        
        if (!is_array($profile_data)) {
            $result['error'] = 'Profile data is not an array';
            return $result;
        }
        
        try {
            // ============================================================
            // Extract profile fields
            // ============================================================
            $health_account_id = isset($profile_data['health_account_id']) ? 
                                $profile_data['health_account_id'] : null;
            $provider_account_id = isset($profile_data['provider_account_id']) ? 
                                  $profile_data['provider_account_id'] : null;
            $name_th = isset($profile_data['name_th']) ? $profile_data['name_th'] : null;
            $name_eng = isset($profile_data['name_eng']) ? $profile_data['name_eng'] : null;
            $position_name = isset($profile_data['position_name']) ? $profile_data['position_name'] : null;
            $position_type = isset($profile_data['position_type']) ? $profile_data['position_type'] : null;
            
            // ============================================================
            // Check if user exists
            // ============================================================
            $stmt = $this->conn->prepare("SELECT id FROM app_user WHERE provider_id = ? LIMIT 1");
            if (!$stmt) {
                $result['error'] = 'Prepare failed: ' . $this->conn->error;
                return $result;
            }
            
            $stmt->bind_param('s', $provider_id);
            if (!$stmt->execute()) {
                $result['error'] = 'Execute failed: ' . $stmt->error;
                $stmt->close();
                return $result;
            }
            
            $res = $stmt->get_result();
            $is_new = ($res->num_rows === 0);
            $existing_user = null;
            
            if (!$is_new) {
                $existing_user = $res->fetch_assoc();
            }
            
            $stmt->close();
            
            // ============================================================
            // INSERT OR UPDATE
            // ============================================================
            if ($is_new) {
                // INSERT
                $stmt = $this->conn->prepare(
                    "INSERT INTO app_user 
                    (provider_id, health_account_id, provider_account_id, name_th, name_eng, position_name, position_type, is_active, first_login_at, last_login_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())"
                );
                
                if (!$stmt) {
                    $result['error'] = 'Prepare INSERT failed: ' . $this->conn->error;
                    return $result;
                }
                
                $stmt->bind_param(
                    'sssssss',
                    $provider_id,
                    $health_account_id,
                    $provider_account_id,
                    $name_th,
                    $name_eng,
                    $position_name,
                    $position_type
                );
                
                if (!$stmt->execute()) {
                    $result['error'] = 'INSERT failed: ' . $stmt->error;
                    $stmt->close();
                    return $result;
                }
                
                $app_user_id = (int)$this->conn->insert_id;
                $stmt->close();
                
                // Create default role for new users
                $this->_assignDefaultRole($app_user_id);
                
                $this->_log('info', 'New user created: ' . $provider_id . ' (ID: ' . $app_user_id . ')');
                
            } else {
                // UPDATE
                $app_user_id = (int)$existing_user['id'];
                
                $stmt = $this->conn->prepare(
                    "UPDATE app_user 
                    SET health_account_id = ?, provider_account_id = ?, name_th = ?, name_eng = ?, position_name = ?, position_type = ?, last_login_at = NOW() 
                    WHERE id = ?"
                );
                
                if (!$stmt) {
                    $result['error'] = 'Prepare UPDATE failed: ' . $this->conn->error;
                    return $result;
                }
                
                $stmt->bind_param(
                    'ssssssi',
                    $health_account_id,
                    $provider_account_id,
                    $name_th,
                    $name_eng,
                    $position_name,
                    $position_type,
                    $app_user_id
                );
                
                if (!$stmt->execute()) {
                    $result['error'] = 'UPDATE failed: ' . $stmt->error;
                    $stmt->close();
                    return $result;
                }
                
                $stmt->close();
                $this->_log('info', 'User updated: ' . $provider_id . ' (ID: ' . $app_user_id . ')');
            }
            
            // ============================================================
            // SYNC ORGANIZATIONS FROM PROFILE
            // ============================================================
            if (isset($profile_data['organizations']) && is_array($profile_data['organizations'])) {
                $this->_syncUserOrganizations($app_user_id, $profile_data['organizations']);
            }
            
            // ============================================================
            // SUCCESS
            // ============================================================
            $result['success'] = true;
            $result['app_user_id'] = $app_user_id;
            $result['is_new'] = $is_new;
            
            return $result;
            
        } catch (Exception $e) {
            $result['error'] = 'Exception: ' . $e->getMessage();
            $this->_log('error', 'saveOrUpdateUserFromProviderProfile exception: ' . $e->getMessage());
            return $result;
        }
    }
    
    // ============================================================
    // PRIVATE HELPERS
    // ============================================================
    
    private function _verifyState($received_state)
    {
        $result = array(
            'valid' => false,
            'message' => null,
        );
        
        if (empty($_SESSION[$this->state_param_name])) {
            $result['message'] = 'State not found in session';
            return $result;
        }
        
        $state_data = $_SESSION[$this->state_param_name];
        $stored_state = isset($state_data['value']) ? $state_data['value'] : '';
        $created_at = isset($state_data['created_at']) ? $state_data['created_at'] : 0;
        $timeout = isset($state_data['timeout']) ? $state_data['timeout'] : 600;
        
        // Check timeout
        if ((time() - $created_at) > $timeout) {
            $result['message'] = 'State expired (timeout)';
            return $result;
        }
        
        // Compare states
        if (!hash_equals($stored_state, $received_state)) {
            $result['message'] = 'State mismatch (CSRF detected)';
            return $result;
        }
        
        $result['valid'] = true;
        return $result;
    }
    
    private function _checkUserDisabled($provider_id)
    {
        $result = array(
            'is_disabled' => false,
            'user_id' => null,
        );
        
        $stmt = $this->conn->prepare("SELECT id, is_active FROM app_user WHERE provider_id = ? LIMIT 1");
        if (!$stmt) {
            return $result;
        }
        
        $stmt->bind_param('s', $provider_id);
        $stmt->execute();
        $res = $stmt->get_result();
        
        if ($res->num_rows > 0) {
            $row = $res->fetch_assoc();
            $result['user_id'] = (int)$row['id'];
            $result['is_disabled'] = ($row['is_active'] == 0 || $row['is_active'] === '0');
        }
        
        $stmt->close();
        return $result;
    }
    
    private function _syncUserOrganizations($app_user_id, $orgs)
    {
        if (!is_array($orgs) || empty($orgs)) {
            return;
        }
        
        // Delete old organizations (synced from provider)
        $stmt = $this->conn->prepare("DELETE FROM app_user_org WHERE app_user_id = ? AND synced_from_provider = 1");
        if ($stmt) {
            $stmt->bind_param('i', $app_user_id);
            $stmt->execute();
            $stmt->close();
        }
        
        // Insert new organizations
        $is_default = true;
        
        foreach ($orgs as $org) {
            if (!is_array($org)) {
                continue;
            }
            
            $hcode = isset($org['hcode']) ? substr($org['hcode'], 0, 20) : null;
            $hname_th = isset($org['hname_th']) ? substr($org['hname_th'], 0, 255) : null;
            $hname_eng = isset($org['hname_eng']) ? substr($org['hname_eng'], 0, 255) : null;
            $zone_id = isset($org['zone_id']) ? substr($org['zone_id'], 0, 50) : null;
            
            if (empty($hcode)) {
                continue;
            }
            
            $default_flag = $is_default ? 1 : 0;
            $is_default = false; // Only first org is default
            
            $stmt = $this->conn->prepare(
                "INSERT IGNORE INTO app_user_org 
                (app_user_id, hcode, hname_th, hname_eng, zone_id, is_default, synced_from_provider, is_active) 
                VALUES (?, ?, ?, ?, ?, ?, 1, 1)"
            );
            
            if ($stmt) {
                $stmt->bind_param('isssssi', $app_user_id, $hcode, $hname_th, $hname_eng, $zone_id, $default_flag);
                $stmt->execute();
                $stmt->close();
            }
        }
    }
    
    private function _assignDefaultRole($app_user_id)
    {
        $role = 'user';
        
        $stmt = $this->conn->prepare(
            "INSERT IGNORE INTO app_user_role 
            (app_user_id, role, assigned_by, assigned_at, is_active) 
            VALUES (?, ?, 'system', NOW(), 1)"
        );
        
        if ($stmt) {
            $stmt->bind_param('is', $app_user_id, $role);
            $stmt->execute();
            $stmt->close();
        }
    }
    
    private function _getUserRoles($app_user_id)
    {
        $roles = array();
        
        $stmt = $this->conn->prepare(
            "SELECT role FROM app_user_role WHERE app_user_id = ? AND is_active = 1"
        );
        
        if (!$stmt) {
            return $roles;
        }
        
        $stmt->bind_param('i', $app_user_id);
        $stmt->execute();
        $res = $stmt->get_result();
        
        while ($row = $res->fetch_assoc()) {
            $roles[] = (string)$row['role'];
        }
        
        $stmt->close();
        return $roles;
    }
    
    private function _getUserDefaultOrg($app_user_id)
    {
        $stmt = $this->conn->prepare(
            "SELECT hcode, hname_th FROM app_user_org WHERE app_user_id = ? AND is_default = 1 LIMIT 1"
        );
        
        if (!$stmt) {
            return null;
        }
        
        $stmt->bind_param('i', $app_user_id);
        $stmt->execute();
        $res = $stmt->get_result();
        
        $org = null;
        if ($res->num_rows > 0) {
            $org = $res->fetch_assoc();
        }
        
        $stmt->close();
        return $org;
    }
    
    private function _createLoginAudit($app_user_id, $provider_id, $event_type, $outcome_code, $error_message = null, $response_time_ms = null)
    {
        try {
            $ip_address = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '127.0.0.1';
            $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 500) : null;
            
            $stmt = $this->conn->prepare(
                "INSERT INTO app_login_audit 
                (app_user_id, provider_id, event_type, outcome_code, ip_address, user_agent, error_message, response_time_ms) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            
            if (!$stmt) {
                $this->_log('error', 'createLoginAudit prepare failed');
                return;
            }
            
            $stmt->bind_param(
                'issssssi',
                $app_user_id,
                $provider_id,
                $event_type,
                $outcome_code,
                $ip_address,
                $user_agent,
                $error_message,
                $response_time_ms
            );
            
            $stmt->execute();
            $stmt->close();
            
        } catch (Exception $e) {
            $this->_log('error', 'createLoginAudit exception: ' . $e->getMessage());
        }
    }
    
    private function _generateRandomString($length = 32)
    {
        if (function_exists('random_bytes')) {
            return bin2hex(random_bytes($length / 2));
        }
        
        // PHP 5.6 fallback
        if (function_exists('openssl_random_pseudo_bytes')) {
            return bin2hex(openssl_random_pseudo_bytes($length / 2));
        }
        
        // Last resort
        $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $result = '';
        for ($i = 0; $i < $length; $i++) {
            $result .= $chars[mt_rand(0, strlen($chars) - 1)];
        }
        return $result;
    }
    
    private function _log($level, $message)
    {
        if (is_callable($this->logger)) {
            call_user_func($this->logger, $level, '[AuthService] ' . $message);
        } else {
            error_log('[AuthService] ' . $message);
        }
    }
}
