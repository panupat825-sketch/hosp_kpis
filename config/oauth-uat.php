<?php
/**
 * config/oauth-uat.php
 *
 * UAT configuration. Prefer environment variables or config/oauth.local.php
 * for real credentials so secrets do not need to live in the repo.
 */

return array(
    'environment' => 'uat',
    'debug' => true,

    'health_id' => array(
        'base_url' => 'https://uat-moph.id.th',
        'client_id' => getenv('HEALTH_ID_CLIENT_ID') ?: 'test_health_id_client_123',
        'client_secret' => getenv('HEALTH_ID_CLIENT_SECRET') ?: 'test_health_id_secret_456',
        'redirect_uri' => getenv('HEALTH_ID_REDIRECT_URI') ?: '',
        'scope' => 'openid profile',
        'oauth_redirect_endpoint' => '/oauth/redirect',
        'token_endpoint' => '/api/v1/token',
        'userinfo_endpoint' => '/api/v1/userinfo',
    ),

    'provider_id' => array(
        'base_url' => 'https://uat-provider.id.th',
        'client_id' => getenv('PROVIDER_CLIENT_ID') ?: 'test_provider_client_789',
        'secret_key' => getenv('PROVIDER_SECRET_KEY') ?: 'test_provider_secret_012',
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
        'secure' => false,
        'httponly' => true,
        'samesite' => 'Lax',
    ),

    'security' => array(
        'state_timeout' => 600,
        'token_mask_chars' => 8,
    ),

    'logging' => array(
        'enabled' => true,
        'dir' => __DIR__ . '/../logs',
        'prefix' => 'oauth_',
        'mask_tokens' => true,
    ),
);
