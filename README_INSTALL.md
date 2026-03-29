# hosp_kpis Installation

## Installer URL

- Run the installer at: `/install/install.php`

## New Machine Setup

1. Copy the release package to the target machine.
2. Extract the zip into the web root.
3. Ensure PHP can write to:
   - `config/`
   - `install/`
4. Open `/install/install.php`.
5. Step 1: enter database host, port, name, user, password, and charset.
6. Step 2: choose the import mode:
   - `Schema only`
   - `Schema + Seed`
   - `Full import`
7. Step 3: choose whether to create or reset the admin user.
8. Run the installer.
9. Verify that:
   - `config/database.local.php` was generated
   - `install/install.lock` was created
10. Log in and run the checks in `SMOKE_TEST.md`.
11. Remove or block the `/install` directory after successful setup.

## Import Sources

- Preferred installer-friendly files:
  - `db/schema.sql`
  - `db/seed.sql`
  - `db/data.sql` (optional)
- Current safe fallback:
  - `db/hospital_kpi.sql`

The installer streams SQL line-by-line and can fall back to `db/hospital_kpi.sql` when the dedicated files are placeholders or missing.

## Notes

- The installer ignores `CREATE DATABASE` and `USE` statements inside SQL files.
- The installer strips `DEFINER=...` clauses before execution.
- The installer stops on the first SQL error and reports:
  - database error
  - statement excerpt
  - approximate line number

## Troubleshooting

### Large SQL Imports

- If the import fails on large `INSERT` batches:
  - increase MariaDB/MySQL `max_allowed_packet`
  - increase PHP `max_execution_time`
  - increase web server/PHP-FPM request timeout if applicable

### Suggested DB Settings

- MariaDB / MySQL:
  - `max_allowed_packet=64M` or higher for larger dumps

### Suggested PHP Settings

- `max_execution_time=300` or higher
- `memory_limit=256M` or higher

### Log Locations

- Windows XAMPP:
  - Apache error log: `xampp/apache/logs/error.log`
  - PHP error log: often routed into Apache error log unless configured separately
  - MariaDB log: `xampp/mysql/data/*.err`

- Linux:
  - Apache: `/var/log/apache2/error.log` or `/var/log/httpd/error_log`
  - PHP-FPM: `/var/log/php*-fpm.log` or distro-specific service logs
  - Nginx: `/var/log/nginx/error.log`
  - MariaDB / MySQL: `/var/log/mysql/error.log` or `/var/log/mariadb/mariadb.log`
