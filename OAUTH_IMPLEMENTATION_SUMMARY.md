# OAuth Implementation Summary

Last updated: 2026-03-29

## What Was Corrected

The implementation was reviewed against live behavior and the provider document. The following issues were found and corrected:

1. Config overrides were missing a stable local override path.
2. Redirects were hardcoded to `/hosp_kpis` in several places.
3. Base path detection could incorrectly resolve to `/hosp_kpis/oauth`.
4. Health ID and Provider ID token parsing assumed `access_token` existed at the top level.
5. Provider HTTP errors were not surfaced clearly to the UI.
6. Live XAMPP site was using `C:\xampp\htdocs\hosp_kpis`, not only the workspace copy in `C:\hosp_kpis`.

## Current Config Model

Files:

- [config/oauth-uat.php](c:\hosp_kpis\config\oauth-uat.php)
- [config/oauth-prd.php](c:\hosp_kpis\config\oauth-prd.php)
- [config/oauth-config-loader.php](c:\hosp_kpis\config\oauth-config-loader.php)
- [config/oauth.local.php](c:\hosp_kpis\config\oauth.local.php)

Behavior:

- environment defaults come from `oauth-uat.php` or `oauth-prd.php`
- local secrets and host-specific settings can be layered from `oauth.local.php`
- `oauth.local.php` supports top-level `uat` and `prd` sections
- redirect paths are built from `app.base_url` and `app.base_path`

## Current Runtime Findings

Based on testing through `localhost/hosp_kpis/login-oauth.php`:

- Health ID login succeeded
- Health ID token exchange succeeded
- Provider token exchange returned `HTTP 400`
- Provider response body indicated the user does not have Provider ID

This means the current code path is functioning far enough to confirm that:

- callback routing works
- token parsing works
- provider request is reaching the endpoint

The remaining blocker is user data in Provider ID, not application routing.

## User-Facing Meaning of the Current Error

Current failure:

- `PROVIDER_TOKEN_REQUEST_FAILED`
- body includes `HTTP 400`
- provider message indicates the authenticated user has no Provider ID

Practical interpretation:

- the person can authenticate with Health ID
- but that same account is not registered as a provider in Provider ID UAT

## Recommended Next Action

- Test with a Health ID account that is already known to exist in Provider ID UAT
- Or ask the Provider ID owner to verify that the target user has Provider ID in the correct environment

## Files Most Relevant to OAuth Runtime

- [login-oauth.php](c:\hosp_kpis\login-oauth.php)
- [oauth/callback.php](c:\hosp_kpis\oauth\callback.php)
- [lib/HealthIdService.php](c:\hosp_kpis\lib\HealthIdService.php)
- [lib/ProviderIdService.php](c:\hosp_kpis\lib\ProviderIdService.php)
- [lib/HttpClient.php](c:\hosp_kpis\lib\HttpClient.php)
- [error.php](c:\hosp_kpis\error.php)

## XAMPP Note

If testing via `http://localhost/hosp_kpis/...`, the active codebase is:

- `C:\xampp\htdocs\hosp_kpis`

Do not assume changes in `C:\hosp_kpis` affect the browser until they are synced into the XAMPP document root.
