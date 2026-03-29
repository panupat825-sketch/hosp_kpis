# OAuth Migration Manual

Last updated: 2026-03-29

## Goal

Move legacy login to Health ID + Provider ID with predictable routing, local secret management, and clearer operational troubleshooting.

## Migration Rules

- Do not commit real secrets into `oauth-uat.php` or `oauth-prd.php`
- Keep deployment-specific values in `oauth.local.php` or environment variables
- Always verify which folder the web server actually serves
- When running under XAMPP localhost, confirm the active app path is `C:\xampp\htdocs\hosp_kpis`

## Deployment Steps

### 1. Prepare config

- Confirm [config/oauth-config-loader.php](c:\hosp_kpis\config\oauth-config-loader.php) is deployed
- Create [config/oauth.local.php](c:\hosp_kpis\config\oauth.local.php)
- Set:
  - `app.base_url`
  - `app.base_path`
  - `health_id.client_id`
  - `health_id.client_secret`
  - `health_id.redirect_uri`
  - `provider_id.client_id`
  - `provider_id.secret_key`

### 2. Validate redirect URI

The redirect URI must exactly match the value registered in Health ID.

Examples:

- `http://localhost/hosp_kpis/oauth/callback.php`
- `http://192.168.111.39/digital-health-system/oauth/callback.php`

### 3. Deploy runtime files

Deploy at least:

- [config/oauth-config-loader.php](c:\hosp_kpis\config\oauth-config-loader.php)
- [config/oauth-uat.php](c:\hosp_kpis\config\oauth-uat.php)
- [config/oauth-prd.php](c:\hosp_kpis\config\oauth-prd.php)
- [config/oauth.local.php](c:\hosp_kpis\config\oauth.local.php)
- [lib/HttpClient.php](c:\hosp_kpis\lib\HttpClient.php)
- [lib/HealthIdService.php](c:\hosp_kpis\lib\HealthIdService.php)
- [lib/ProviderIdService.php](c:\hosp_kpis\lib\ProviderIdService.php)
- [oauth/callback.php](c:\hosp_kpis\oauth\callback.php)
- [middleware/auth.php](c:\hosp_kpis\middleware\auth.php)
- [logout.php](c:\hosp_kpis\logout.php)

### 4. Test in order

1. Open `login-oauth.php`
2. Confirm redirect to Health ID
3. Confirm callback returns to the correct app path
4. Confirm Health token exchange succeeds
5. Confirm Provider token exchange succeeds
6. Confirm profile fetch succeeds
7. Confirm session creation and dashboard redirect

## Common Failure Modes

### Redirect goes to wrong path

Symptoms:

- redirect points to `/oauth/error.php`
- 404 after callback

Action:

- verify `app.base_path`
- verify latest `oauth-config-loader.php` is deployed

### Health login works but Provider fails with 400

Symptoms:

- callback is reached
- Health token exchange succeeds
- Provider token exchange fails with `HTTP 400`

Action:

- verify the account exists in Provider ID for that environment
- test with a known provider account

### Provider fails with 401

Action:

- verify Provider `client_id` and `secret_key`
- verify environment is correct: UAT vs PRD

## Cutover Checklist

- [ ] XAMPP or production server points to the intended codebase
- [ ] `oauth.local.php` exists on the server
- [ ] redirect URI matches the registered value exactly
- [ ] Provider user exists in the correct environment
- [ ] error page shows actionable HTTP detail
- [ ] dashboard redirect works after successful login

## Operational Note

The current implementation is now good at distinguishing:

- routing/config issues
- credential issues
- missing Provider ID user data

That distinction should be preserved in future edits.
