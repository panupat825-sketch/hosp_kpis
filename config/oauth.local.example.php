<?php
/**
 * Copy this file to config/oauth.local.php and fill in the real values.
 * This file is optional and is loaded after oauth-uat.php / oauth-prd.php.
 */

return array(
    'app' => array(
        'base_url' => 'http://192.168.111.39',
        'base_path' => '/digital-health-system',
    ),
    'health_id' => array(
        'client_id' => 'PUT_REAL_HEALTH_ID_CLIENT_ID_HERE',
        'client_secret' => 'PUT_REAL_HEALTH_ID_CLIENT_SECRET_HERE',
        'redirect_uri' => 'http://192.168.111.39/digital-health-system/oauth/callback.php',
    ),
    'provider_id' => array(
        'client_id' => 'PUT_REAL_PROVIDER_CLIENT_ID_HERE',
        'secret_key' => 'PUT_REAL_PROVIDER_SECRET_KEY_HERE',
    ),
);
