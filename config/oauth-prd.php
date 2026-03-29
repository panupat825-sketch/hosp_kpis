<?php
/**
 * config/oauth-prd.php
 *
 * Production configuration. Real credentials should come from environment
 * variables or config/oauth.local.php.
 */

return array(
    'environment' => 'prd',
    'debug' => false,

    'health_id' => array(
        'base_url' => 'https://moph.id.th',
        'client_id' => getenv('HEALTH_ID_CLIENT_ID') ?: 'YOUR_HEALTH_ID_CLIENT_ID_HERE',
        'client_secret' => getenv('HEALTH_ID_CLIENT_SECRET') ?: 'YOUR_HEALTH_ID_CLIENT_SECRET_HERE',
        'redirect_uri' => getenv('HEALTH_ID_REDIRECT_URI') ?: '',
        'scope' => 'openid profile',
        'oauth_redirect_endpoint' => '/oauth/redirect',
        'token_endpoint' => '/api/v1/token',
        'userinfo_endpoint' => '/api/v1/userinfo',
    ),

    'provider_id' => array(
        'base_url' => 'https://provider.id.th',
        'client_id' => getenv('PROVIDER_CLIENT_ID') ?: 'YOUR_PROVIDER_CLIENT_ID_HERE',
        'secret_key' => getenv('PROVIDER_SECRET_KEY') ?: 'YOUR_PROVIDER_SECRET_KEY_HERE',
        'token_endpoint' => '/api/v1/services/token',
        'profile_endpoint' => '/api/v1/services/profile',
        'profile_params' => array(
            'moph_center_token' => 1,
            'moph_idp_permission' => 1,
            'position_type' => 1,
        ),
    ),

    'session' => array(
        'name' => 'HOSP_KPI_SID',
        'lifetime' => 3600,
        'idle_timeout' => 1800,
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ),

    'security' => array(
        'state_timeout' => 600,
        'token_mask_chars' => 8,
    ),

    'logging' => array(
        'enabled' => true,
        'dir' => getenv('HOSP_KPI_LOG_DIR') ?: __DIR__ . '/../logs',
        'prefix' => 'oauth_',
        'mask_tokens' => true,
    ),
);
