# Precise continuation checkpoint

SafariLink is a separate project; NetConnect was not modified. No original bus-booking repository/database was present. Source is in this folder; original requirements are in REQUIREMENTS.txt.

Completed: core PHP/MariaDB-compatible booking/inventory/identity/operations services, responsive passenger/staff UI, versioned migrations, separate demo seed, CLI staff setup, outbox worker, QR tickets, manual payments/refunds, rescheduling, bus replacement, reports/CSV and backup/restore tools. See checklist for boundaries.

Actual verification: 20 unit, 38 integration, 23 HTTP checks passed; PHP/JS syntax passed; QR SVG generation passed; 28 restored table counts plus financial/allocation aggregates matched. MySQL 8, Windows XAMPP and browser visual/mobile scanning checks remain unrun. Last minor source changes made cancellation settings configurable and added Apache source-directory access rules; final lint/unit checks are required after any further edits.

Next commands on a supported local install:
- Copy .env.example → .env; set DB settings and APP_KEY.
- php bin/console.php migrate
- Securely create staff JSON through stdin as documented in README.
- php tests/unit.php
- Run tests/integration.php against a fresh safarilink_test database.
- Run a PHP local server against that test database and tests/http_checks.py.
- Verify browser layouts/camera and Windows XAMPP/MySQL 8.

Next implementation priorities:
1. Split the large HTTP controller into focused controllers/views; retain authorization before any response output.
2. Implement Daraja against current official documentation, keeping all live contact disabled by default; add callback/state reconciliation tests.
3. Complete agent commissions/settlements, recurring schedules, advanced layouts/maintenance and notification provider/preferences workflows.
4. Add operator settings/job-management/retention interfaces and full change/audit details.
5. Complete independent security/performance/provider QA and production deployment gates.

No live service was called; no deployment occurred. No secrets, database dumps, runtime packages or operational customer data belong in the source archive.
