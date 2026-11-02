# Operations and recovery

## Local worker and scheduler

Run `php bin/console.php worker` repeatedly to process pending tickets/notices. It consumes one job per invocation. Use Windows Task Scheduler or a supervisor loop; run `php bin/console.php schedule` once a minute to expire holds. Queue leases are recovered after five minutes; failed jobs stop after five attempts with non-secret error labels. `health` displays queue counts and validates the encryption-key shape. Failed-job diagnosis/retry UI remains unfinished.

No real payment, email, SMS, vehicle or production database service was contacted during tests.

## Backup

Stop schema changes and coordinate application writes when taking a production recovery checkpoint. The bundled logical backup uses an InnoDB consistent snapshot and records tables/rows; it does not back up users/grants, server routines, `.env`, APP_KEY or private files. There are no application-created routines/triggers in this release. Large databases need a streaming/native backup implementation; the current command fetches each table into memory.

```sh
php bin/backup.php /private/backups/safarilink-2026-10-30.sql
```

The output must be a new file outside public/. The tool refuses overwrite and sets restrictive file permissions. Encrypt the dump and keep its checksum, `.env`, key and any private files securely with separate access controls. Never put a production database dump or APP_KEY in the source archive.

## Restore rehearsal

Use a separate empty database with no live integrations. Change DB_DSN in a separate process/application copy and run:

```sh
php bin/restore.php /private/backups/safarilink-2026-10-30.sql --empty-db-confirmed
```

Only restore a trusted, integrity-checked backup; SQL backups are executable administrative input. The tool refuses a non-empty target. MySQL DDL is not transactional: a failed restoration needs investigation in the isolated target, not an automatic destructive retry.

Actual rehearsal: source database was backed up, restored into a separate empty database on MariaDB 10.11.14, and all 28 table row counts matched. SUM(payment amounts), SUM(refund amounts) and allocation counts also matched. This validates the tested database backup/restore path; it does not verify your infrastructure recovery time, Windows environment, encryption-key recovery or future private-file attachments.

After restore, additionally test login/MFA with the separate APP_KEY, booking ownership, manual payment idempotency and worker processing before reopening writes. Those post-restore interactive checks were not performed in the rehearsal.

## Deployment gate

Keep this release private/local until the unfinished checklist is resolved. Use a public-only document root, HTTPS, SESSION_SECURE=1, a least-privilege application database account, protected session storage, backed-up APP_KEY and disabled PHP error display. PHP-FPM/Apache should log internal errors outside the public directory. Configure logs/monitoring/backup schedules and supervise workers. Run exact-version MySQL/XAMPP, browser and provider tests. No deployment was performed.

## Troubleshooting

- Session storage unavailable: ensure PHP session.save_path exists and the web user can write it. Do not weaken ownership of the entire project.
- Database unavailable: check DB_DSN, credentials, PDO MySQL and migrations. Database sessions must use UTC.
- Staff cannot sign in: configure a valid APP_KEY, then enroll TOTP. Check authenticator clock; repeated codes are intentionally rejected. No recovery-code UI exists yet.
- Tickets pending after payment: run worker; inspect jobs status/attempts. A receipt does not mean a ticket has been issued.
- Hold expired: new requests clean stale holds. Late money may create a resolution case instead of travel.
- M-Pesa button absent: intentional; Daraja is not implemented.
- Camera scanner unavailable: use HTTPS and a BarcodeDetector-capable browser, or type the printed token. No offline duplicate prevention is claimed.
- Migration interrupted: stop writes, inspect applied DDL and migration ledger, and restore/reconcile in an isolated copy. ALTER migrations are not automatically restart-safe.
