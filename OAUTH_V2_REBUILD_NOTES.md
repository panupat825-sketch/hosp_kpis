# OAuth V2 Rebuild Notes

This is a clean-room replacement flow added alongside the existing implementation.

Files:

- [login-oauth-v2.php](c:\hosp_kpis\login-oauth-v2.php)
- [oauth/callback-v2.php](c:\hosp_kpis\oauth\callback-v2.php)
- [lib/OAuthGatewayV2.php](c:\hosp_kpis\lib\OAuthGatewayV2.php)
- [lib/OAuthUserRepositoryV2.php](c:\hosp_kpis\lib\OAuthUserRepositoryV2.php)

Design goals:

- keep the full OAuth flow readable in a few files
- remove orchestration hidden across multiple services
- preserve debug tokens in session for investigation
- avoid overwriting the current production-like path until explicitly switched

Current status:

- ready for isolated testing
- still depends on the same config loader and database connection
- external blocker remains: UAT Provider API says the tested user has no provider id

How to test:

1. Open `http://localhost/hosp_kpis/login-oauth-v2.php`
2. Complete Health ID login
3. Observe whether callback-v2 reaches Provider/profile/save flow

If V2 behaves the same as V1, the blocker is confirmed to be external rather than architectural in the old service layer.
