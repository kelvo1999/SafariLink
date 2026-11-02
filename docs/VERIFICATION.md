# Verification record — 30 October 2026

Tested runtime: PHP 8.3.6, MariaDB 10.11.14, Python 3.12, local PHP development HTTP server. The original workspace had no project/source/database/runtime. Runtime packages were extracted into temporary test directories; they are not part of the deliverable.

## Results

| Check | Actual result |
|---|---|
| Unit checks | 20 passed |
| Database/integration checks | 38 passed |
| HTTP/security checks | 23 passed |
| Application/test PHP syntax | Passed |
| JavaScript syntax | Passed |
| QR SVG generation | Passed |
| Restore table counts | 28 of 28 matched |
| Restore payment/refund totals and allocation counts | Matched |
| MySQL 8 / Windows XAMPP | Not run |
| Browser visual/mobile-camera QA | Not run |
| Daraja callbacks and messaging providers | Not implemented/not tested |

## Repeatable commands

```sh
php tests/unit.php
find app public bin tests -name '*.php' -exec php -l {} \;
node --check public/assets/app.js
```

Create a **new empty isolated** database named exactly safarilink_test. The integration script refuses an initialized database. It creates only test fixtures and never deletes an operational database. A future rerun must use an intentionally reset/recreated disposable database.

```sh
export SAFARILINK_TEST_DSN='mysql:host=127.0.0.1;dbname=safarilink_test;charset=utf8mb4'
export DB_USER='your_test_user'
export DB_PASSWORD='your_test_password'
php tests/integration.php
```

The test-only fixture password is clearly isolated in tests/integration.php; the demo seed and staff setup do not create default operational credentials. Two independent PHP processes race to acquire the same seat; exactly one succeeds. Tests cover non-overlap/overlap, atomic groups, stale holds, partial payments, duplicate manual references, late-money conflicts/reacquisition, ticket retries, token tampering/wrong-trip/wrong-stop/repeated scans, refunds, authorization, rescheduling and bus replacement.

After integration fixtures, point a local PHP server at that test database only:

```sh
export DB_DSN="$SAFARILINK_TEST_DSN"
export APP_KEY="$(php bin/console.php key)"
php -S 127.0.0.1:8080 -t public
# In another terminal with that isolated server still running:
SAFARILINK_TEST_URL=http://127.0.0.1:8080 python3 tests/http_checks.py
```

The HTTP script permits loopback URLs only. It checks CSRF, cookie headers, anonymous role restrictions, passenger registration/login/isolation, guest-key denial, staff MFA enrollment/access gating, financial reporting and CSV authorization. It changes only isolated test data. A writable PHP session directory is required.

Use bin/backup.php and bin/restore.php in two isolated databases to repeat the recovery rehearsal; compare every table row count and financial aggregates. See OPERATIONS.md.

## Limits

Passing these checks does not cover live provider verification, MySQL 8 compatibility, production traffic, every role matrix combination, all errors/deadlocks, historical general-ledger accuracy, offline devices or a complete independent security audit. UI is implemented but not visually browser-verified. The remaining feature checklist is part of the deliverable.
