# hosp_kpis Smoke Test

Run this checklist after deploying code and applying DB migrations.

## 1. Login And Roles

- Open `login.php`.
- Sign in as `admin`.
- Confirm redirect to `index.php`.
- Confirm `System Health` link is visible on the home page for admin.
- Open `health.php` and confirm it loads with `READY` or clear failure details.
- Sign out.
- Sign in as `viewer`.
- Confirm protected pages still require login.
- Confirm `viewer` cannot open `kpi_instance_manage.php` or `kpi_template_manage.php` and receives `403`.
- Confirm delete buttons are hidden for `viewer` on `dashboard.php` and `kpi_table.php`.

## 2. KPI Template Create Edit Delete

- Sign in as `admin` or `editor`.
- Open `kpi_template_manage.php`.
- Create a new KPI template with:
  - KPI name
  - strategy
  - description
  - `SUM` and then `AVG` in separate tests
- Confirm success message and that the row appears in the template list.
- Edit the same template.
- Confirm changes persist.
- Delete the same template from the template list.
- Confirm the row is removed.
- Repeat delete with a tampered or missing `csrf_token` and confirm the action is blocked.

## 3. KPI Actual Entry And Update

- Open `kpi_instance_manage.php`.
- Create a new KPI instance for a valid template.
- Use a fiscal year in CE format (for example `2025`) and confirm it stores/behaves as BE.
- Set target and actual values.
- Confirm success redirect and visibility in:
  - `dashboard.php`
  - `dashboard_yearly.php`
  - `kpi_table.php`
- Edit the same KPI instance.
- Change actual value and confirm the updated value appears consistently on all three pages.
- Delete the instance from:
  - `dashboard.php`
  - `kpi_table.php`
- Confirm delete is blocked if CSRF token is invalid.

## 4. Dashboard Filters

### Quarterly Dashboard

- Open `dashboard.php`.
- Filter by fiscal year only.
- Filter by fiscal year + department.
- Filter by fiscal year + category.
- Filter by keyword.
- Confirm selected filter values remain visible after submit.
- Confirm empty state renders clearly when no rows match.

### Yearly Dashboard

- Open `dashboard_yearly.php`.
- Use a 5-year range.
- Reverse `year_from` and `year_to` and confirm the page still works.
- Filter by department and category.
- Confirm charts still render and legends remain readable.

## 5. Reports And Exports

- Open `kpi_table.php`.
- Use search text and different `per_page` values.
- Confirm page count updates correctly.
- Confirm sticky table header remains visible while scrolling the table area.
- If the environment uses any external export/report flow, open that route and confirm it still loads after deployment.
- Confirm no report page shows raw SQL or connection errors to end users.

## 6. Security Regression Checks

- Submit any state-changing POST with an invalid `csrf_token`.
- Confirm the write is rejected.
- Try SQL injection strings in dashboard filter inputs.
- Confirm pages still render safely and do not expose SQL errors.
- Enter HTML/script-like text in template description or KPI notes.
- Confirm output is escaped and no script executes.

## 7. Performance Spot Check

- Load `dashboard.php` with broad filters.
- Load `dashboard_yearly.php` with the default year range.
- Load `kpi_template_manage.php` with and without `list_q`.
- Load `kpi_table.php` with `per_page=100`.
- Confirm response is acceptable and pagination/search behave normally.
- If slow-query logging is enabled, verify only queries slower than 500ms are written to PHP logs.
