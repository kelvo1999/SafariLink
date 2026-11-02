# SafariLink

A PHP/MySQL bus booking development implementation for a Kenyan operator. The name, hold duration and cancellation policy are configurable. **This is not the complete production system described in the supplied specification.** The core seat inventory, manual payment, ticket and boarding workflows have automated test coverage; substantial external integrations and operations features remain unfinished. See IMPLEMENTATION_CHECKLIST.md.

## What is implemented

- Guest booking with a separate 256-bit private retrieval key; optional passenger accounts.
- Password hashing, CSRF, login throttling, staff authenticator MFA, encrypted MFA secrets, password reset tokens and session invalidation. Reset delivery is local development only.
- Route stops with positions, journey fares, buses, initial seat layouts, crew and dated trips with layout/stop snapshots and conflicting assignment checks.
- Search, segment-aware availability, interactive group seat selection, atomic holds and request-time expiry.
- Integer KES minor-unit money, fare/policy snapshots, manual cash/bank recording, partial payments, duplicate-reference protection, late payment resolution cases.
- Transactional ticket/notification outbox, bounded retry worker, QR printable tickets, separate receipts and atomic boarding validation.
- Cancellations, seat release, independently approved/confirmed manual refunds, payment-credit transfers during operator rescheduling, explicit bus replacement mappings.
- Agent ownership boundaries for sales/reports; financial date filters, CSV summaries and per-segment confirmed occupancy counts.
- Database backup/empty-target restore commands, a tested restoration rehearsal and health command.

## XAMPP installation

Requirements: PHP 8.2+ (tested 8.3.6), PDO MySQL, mbstring, OpenSSL, ctype and iconv. Target database: MySQL 8+. Tests were run against MariaDB 10.11.14, relevant to XAMPP, **not MySQL 8**. Verify your exact MySQL/XAMPP version before deployment.

1. Extract this folder to `C:/xampp/htdocs/safarilink` for local development. For a real server, make **only `public/` the document root**. Parent source/configuration/private files must not be exposed.
2. Create an empty UTF-8 database named `safarilink` and a dedicated database user. Do not use an existing booking/customer database without inspection and a backup.
3. Copy `.env.example` to `.env`; set DB_DSN, DB_USER, DB_PASSWORD and APP_URL.
4. Run `C:/xampp/php/php.exe bin/console.php key`. Copy its output into APP_KEY in `.env`. Keep this key outside the database and source control. It protects staff MFA secrets. Back it up separately.
5. Run `C:/xampp/php/php.exe bin/console.php migrate`.
6. Create the first staff account using the PowerShell example below. No production account or default password is seeded.
7. Optionally run `C:/xampp/php/php.exe bin/console.php demo` in local mode. This creates a clearly labelled demo route, fares and bus, **not fake operational users/payments/trips**.
8. Start Apache/MySQL, visit `http://localhost/safarilink/public/`, and sign in. Staff must enroll an authenticator and submit its current code before gaining staff access.
9. Schedule a dated trip in Dispatch. Guests can search for that date, select seats and hold a booking. Finance must independently verify and record cash/bank payments. Run the worker to issue tickets and local notices.

Apache root/public `.htaccess` files deny source access and allow the public directory; this depends on Apache AllowOverride/auth settings. A public-only document root is the deployment boundary. Do not rely on `.htaccess` for a PHP built-in server launched at the project root.

### Secure staff creation (PowerShell)

Run from the project folder. Replace non-secret contact fields; password is prompted, not placed in command history or process arguments.

```powershell
$secure = Read-Host 'Staff password (12+ characters)' -AsSecureString
$ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
try {
    $plain = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr)
    @{name='Your Name';email='you@example.com';phone='0712345678';role='super_admin';password=$plain} | ConvertTo-Json -Compress | & C:/xampp/php/php.exe bin/console.php staff
} finally {
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr)
    Remove-Variable plain -ErrorAction SilentlyContinue
}
```

Allowed staff roles: `super_admin`, `operations`, `dispatcher`, `clerk`, `agent`, `finance`, `boarding`. Passenger accounts use web registration. Role permission grants are inserted by migrations; staff account/permission administration UI is not implemented. There are no authenticator recovery codes or self-service staff MFA reset yet; protect access and establish an operator recovery procedure before production.

## Development commands

```sh
php bin/console.php migrate
php bin/console.php demo
php bin/console.php worker
php bin/console.php schedule
php bin/console.php health
php tests/unit.php
```

`worker` processes one eligible job. Repeat until empty in local testing. Run it frequently using a supervised process or Windows Task Scheduler. Run `schedule` once a minute; request-time expiry also releases stale holds independently of this job. Local notification jobs write database delivery logs; no email/SMS is sent. Failed jobs stop after five attempts; the health command reports counts, but a retry-management UI is unfinished.

PHP development server: `php -S 127.0.0.1:8080 -t public`. A writable PHP session.save_path is required. This is a development server only.

## Payment and inventory rules

See docs/ARCHITECTURE.md. The system never accepts a browser-submitted success flag. Only authorized finance staff can record independently verified cash/bank payments. M-Pesa is **not implemented**; credentials alone will not enable it. No Daraja endpoint, callback URL or simulated-success checkout is exposed.

A held seat is allocated separately for each adjacent route segment. A seat sold A→B can be sold B→C; it cannot overlap A→C. All seats in a group are acquired in one database transaction. Hold expiry uses database UTC time. Browser state is only a display.

Cancellation settings apply to future bookings and are snapshotted: CANCEL_BEFORE_MINUTES (default 120) and CANCEL_FEE_CENTS (default 0). Existing booking terms remain unchanged. HOLD_SECONDS is clamped to 60–1800. Normal trip sales close 30 minutes before the trip's first departure; per-stop sales cutoffs remain unfinished.

## Tests and actual results

- PHP syntax: application and test PHP files passed.
- `php tests/unit.php`: **20 passed**.
- `php tests/integration.php` on an isolated empty database: **38 passed**.
- `python3 tests/http_checks.py` against an isolated local PHP server after integration fixtures: **23 passed**.
- QR SVG generation: passed; end-to-end camera decoding/device UX was not browser-tested.
- Backup/restore rehearsal: **28 restored table row counts matched**, plus payment/refund totals and allocation counts.
- JavaScript syntax: `node --check public/assets/app.js` passed.

Tests used PHP 8.3.6 and MariaDB 10.11.14, not MySQL 8 or your Windows installation. No live payment/SMS/email provider was contacted. Browser layout/mobile-camera QA, load testing and independent security review remain pending. See docs/VERIFICATION.md for repeatable commands and limits.

## Third-party libraries

Local Bootstrap 5.3.1 CSS is bundled under public/assets/vendor. BaconQrCode 2.0.8 and DASPRiD Enum 1.0.5 are bundled under third_party for QR matrix encoding, with copyright/license notices. A small PSR-4 loader in app/Qr.php loads these local copies. Composer metadata declares PHP/runtime requirements; Composer installation is not required to use the bundled libraries. No external CDN is needed for the UI. QR generation uses SVG directly and does not need Imagick.

## Not production-ready

Outstanding work includes Daraja, provider-backed messaging/reset delivery, recurring schedules, seasonal/promotional fares, advanced/multi-deck layout editing, fleet maintenance, commissions and settlements, operational change history details, configurable settings UI, notification preferences, retention controls and broader security/performance tests. Basic reports are not a general ledger. Do not deploy as a complete commercial booking platform until the checklist is finished.
