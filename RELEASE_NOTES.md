# hosp_kpis Release Notes

## Scope

This release bundles Patch Sets 1 through 5:

- Patch 1: KPI correctness fixes
  - fiscal year normalization
  - FY range handling
  - `SUM` target calculation alignment
  - dashboard filter correctness
- Patch 2: Security and authorization hardening
  - CSRF protection
  - role enforcement on KPI write flows
  - prepared statements for active dashboards
  - safer login/session handling
- Patch 3: Performance improvements
  - optional slow-query logging
  - narrower dashboard selects
  - KPI template list pagination and search
  - DB index migrations
- Patch 4: UI/UX modernization
  - shared layout shell
  - improved filter panels
  - cleaner chart styling
  - more readable KPI table shell
- Patch 5: Release readiness
  - smoke test checklist
  - admin-only health page
  - release documentation

## Migrations Apply Order

Apply in this order:

1. Deploy application files.
2. Run performance index migration:
   - `migrations/20260302_patch3_performance_up.sql`
3. Clear any PHP opcode cache if enabled.
4. Verify `health.php` as an admin user.
5. Run the checklist in `SMOKE_TEST.md`.

## Runtime Toggles

- Slow query logging is optional.
- Enable with environment variable:
  - `HOSP_KPI_LOG_SLOW_QUERIES=1`
- When enabled, only queries slower than 500ms are logged.

## Release Verification

Minimum post-deploy checks:

- Admin login succeeds.
- Viewer access is denied on KPI write pages.
- Create, edit, and delete KPI templates and KPI instances works.
- `dashboard.php`, `dashboard_yearly.php`, and `kpi_table.php` load without raw SQL errors.
- `health.php` returns HTTP 200 and shows DB connectivity as OK.

## Rollback Steps

### Application Rollback

Restore the changed files from backup:

- `auth.php`
- `login.php`
- `dashboard.php`
- `dashboard_yearly.php`
- `kpi_instance_manage.php`
- `kpi_template_manage.php`
- `kpi_table.php`
- `navbar_kpi.php`
- `index.php`
- `health.php`
- `SMOKE_TEST.md`
- `RELEASE_NOTES.md`

### Database Rollback

If the performance indexes need to be removed, run:

- `migrations/20260302_patch3_performance_down.sql`

### Rollback Order

1. Revert application files.
2. Revert DB indexes only if index-related regressions are confirmed.
3. Clear opcode cache if enabled.
4. Re-run core smoke checks:
   - login
   - dashboards
   - KPI create/edit

## Known Constraints

- `department_id` and `workgroup_id` are still stored as CSV fields in current tables.
- Department filtering is supported, but true index-based performance for those filters will require schema normalization later.
- Legacy pages outside the active KPI flow still need the same hardening/UX treatment in future patch sets.
