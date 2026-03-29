# OAuth Integration Guide

Last updated: 2026-03-29

## Overview

This project uses:

- Health ID for OAuth login
- Provider ID for provider validation and profile lookup
- `provider_id` as the application-side unique identifier

Actual login flow:

1. User clicks `login-oauth.php`
2. Browser is redirected to Health ID
3. Health ID redirects back to `oauth/callback.php` with `code`
4. Backend exchanges `code` for Health ID access token
5. Backend exchanges Health ID token for Provider ID token
6. Backend fetches provider profile
7. App creates or updates local user/session

## Important Corrections

The current implementation was corrected based on real testing and the provider document in [`config/provider id.txt`](c:\hosp_kpis\config\provider%20id.txt):

- Health ID token response may be wrapped under `data`
- Provider token response may be wrapped under `data`
- Provider token endpoint returns `HTTP 400` when the user does not have Provider ID
- Application redirects must not hardcode `/hosp_kpis`
- `base_path` must be detected from the app root, not from `/oauth`
- Real secrets should be stored in `config/oauth.local.php` or environment variables, not directly in committed config files

## Configuration

Main files:

- [config/oauth-config-loader.php](c:\hosp_kpis\config\oauth-config-loader.php)
- [config/oauth-uat.php](c:\hosp_kpis\config\oauth-uat.php)
- [config/oauth-prd.php](c:\hosp_kpis\config\oauth-prd.php)
- [config/oauth.local.php](c:\hosp_kpis\config\oauth.local.php)
- [config/oauth.local.example.php](c:\hosp_kpis\config\oauth.local.example.php)

Recommended approach:

1. Keep generic defaults in `oauth-uat.php` and `oauth-prd.php`
2. Put real credentials in `oauth.local.php`
3. Let the loader merge local overrides by environment

Example `config/oauth.local.php`:

```php
<?php
return array(
    'uat' => array(
        'app' => array(
            'base_url' => 'http://localhost',
            'base_path' => '/hosp_kpis',
        ),
        'health_id' => array(
            'client_id' => 'UAT_HEALTH_ID_CLIENT_ID',
            'client_secret' => 'UAT_HEALTH_ID_SECRET',
            'redirect_uri' => 'http://localhost/hosp_kpis/oauth/callback.php',
        ),
        'provider_id' => array(
            'client_id' => 'UAT_PROVIDER_CLIENT_ID',
            'secret_key' => 'UAT_PROVIDER_SECRET',
        ),
    ),
    'prd' => array(
        'app' => array(
            'base_url' => 'https://your-domain.example',
            'base_path' => '/digital-health-system',
        ),
        'health_id' => array(
            'client_id' => 'PRD_HEALTH_ID_CLIENT_ID',
            'client_secret' => 'PRD_HEALTH_ID_SECRET',
            'redirect_uri' => 'https://your-domain.example/digital-health-system/oauth/callback.php',
        ),
        'provider_id' => array(
            'client_id' => 'PRD_PROVIDER_CLIENT_ID',
            'secret_key' => 'PRD_PROVIDER_SECRET',
        ),
    ),
);
```

## URLs

Health ID:

- UAT: `https://uat-moph.id.th`
- PRD: `https://moph.id.th`

Provider ID:

- UAT: `https://uat-provider.id.th`
- PRD: `https://provider.id.th`

Provider endpoints:

- Token: `/api/v1/services/token`
- Profile: `/api/v1/services/profile`

## Response Handling

Health ID token response example:

```json
{
  "status": "success",
  "data": {
    "access_token": "..."
  },
  "message": "You logged in successfully"
}
```

Provider token response example:

```json
{
  "status": 200,
  "message": "OK",
  "data": {
    "access_token": "...",
    "account_id": "..."
  }
}
```

The application must read `access_token` from `data.access_token` when present.

## Known Provider Error Cases

Provider token endpoint:

- `400 This user has not provider id`
  Meaning: user authenticated with Health ID successfully, but that account is not registered as a Provider ID user
- `401 Authentication is required to access this resource`
  Meaning: invalid or missing `client_id` / `secret_key`
- `400 The requested parameter can not used`
  Meaning: request body is missing or malformed, often `token_by` or `token`

Provider profile endpoint:

- `401 access_token is invalid`
- `404 This user has no provider id`
- `404 The requested resource was not found`

## Test Checklist

- `login-oauth.php` loads successfully
- Redirect to Health ID works
- Callback returns to the correct app path
- Health ID token is read correctly from response
- Provider token is read correctly from response
- User with valid Provider ID can continue to profile fetch
- User without Provider ID gets a meaningful error or access denied message
- Redirects do not point to `/oauth/error.php`

## Troubleshooting

### Error: redirect goes to `/oauth/error.php`

Cause:

- app base path was detected from the callback folder instead of the project root

Fix:

- use the current `oauth-config-loader.php`
- set explicit `app.base_path` in `oauth.local.php` if needed

### Error: `Provider token request failed: HTTP 400`

Cause:

- most commonly the authenticated Health ID user does not have Provider ID

Fix:

- confirm that the same person exists in Provider ID UAT or PRD
- test with an account that is known to have Provider ID

### Error: `NO_ACCESS_TOKEN`

Cause:

- code expected `access_token` at the top level while the API returned it under `data`

Fix:

- use the updated service classes in `lib/HealthIdService.php` and `lib/ProviderIdService.php`

## Related Files

- [lib/HttpClient.php](c:\hosp_kpis\lib\HttpClient.php)
- [lib/HealthIdService.php](c:\hosp_kpis\lib\HealthIdService.php)
- [lib/ProviderIdService.php](c:\hosp_kpis\lib\ProviderIdService.php)
- [oauth/callback.php](c:\hosp_kpis\oauth\callback.php)
- [middleware/auth.php](c:\hosp_kpis\middleware\auth.php)
- [logout.php](c:\hosp_kpis\logout.php)
