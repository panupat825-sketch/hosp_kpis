# hosp_kpis Security Notes

## Secrets

- Do not commit `config/database.local.php`.
- The repository includes `config/.gitignore` to ignore that file.
- The installer writes secrets only to `config/database.local.php`.
- The installer does not echo database passwords back to the browser after submission.

## Installer Hardening

- The installer creates `install/install.lock` after success.
- If `install/install.lock` exists, `/install/install.php` refuses to run.
- After installation:
  - delete the `/install` directory, or
  - block it in the web server

## Runtime DB Bootstrap

- Runtime DB access now reads `config/database.local.php` when present.
- If configuration is missing or invalid, the app fails with a safe generic DB message and logs details with `error_log`.

## Web Server Recommendations

- Deny direct web access to:
  - `config/`
  - log directories
  - backup files
- Serve the app over HTTPS in production.
- Keep PHP error display disabled in production, and log to server logs instead.

## Credentials

- Use a database user with only the privileges required by the app.
- Use a separate elevated database user only when you need the installer to create the database automatically.
- Rotate the admin password immediately after installation if a temporary password was used.

## Logging

- Application issues are written to PHP/server logs via `error_log`.
- Optional slow-query logging can be enabled with:
  - `HOSP_KPI_LOG_SLOW_QUERIES=1`
