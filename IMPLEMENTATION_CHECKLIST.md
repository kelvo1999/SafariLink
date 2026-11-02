# Implementation checklist

[x] means source implemented, not complete production certification. Verified items are explicitly noted. No phase is fully complete.

## A — Foundation (partial)
- [x] Versioned schema, environment configuration, first-staff CLI and permission grants.
- [x] Passenger accounts, secure login, staff MFA, hashed reset tokens and local reset delivery.
- [x] Routes/stops/fares; bus intake/initial layouts; crew; dated trips; schedule conflict checks.
- [x] Relevant identity/permissions and schema paths exercised on PHP 8.3/MariaDB 10.11.
- [ ] Staff administration UI, MFA recovery, provider-backed reset email/SMS.
- [ ] Town/terminal master data; advanced deck/aisle/class/block editors; fleet maintenance.
- [ ] Recurring schedule templates/generation; full trip/stop operational editing.

## B — Booking (mostly core)
- [x] Search, local date handling, segment availability and interactive multi-seat selection.
- [x] Atomic group holds, unique segment allocation constraints, server-controlled expiry, guest secure retrieval.
- [x] Fare/policy snapshots, passenger/contact records, role/channel attribution.
- [x] Concurrent same-seat, overlap, non-overlap, atomic group and stale-hold tests passed.
- [ ] Seasonal fares/promotions/fees, per-stop sales cutoffs, complete filtering/pagination.

## C — Payments, tickets, boarding (partial)
- [x] Authorized manual cash/bank, minor units, partial/exact/excess payment handling, duplicate references.
- [x] Late payment original-seat recovery or visible resolution with retained money.
- [x] Transactional outbox, unique tickets, QR generation, printable separate tickets/receipts.
- [x] Atomic online boarding, trip/stop/token/state validation and no-show marking.
- [x] Manual financial and boarding scenarios passed integration/HTTP tests.
- [ ] Daraja OAuth/STK/query/callback integration, sandbox/live config and provider verification.
- [ ] Duplicate/out-of-order **provider callback** tests (manual idempotency tests do not cover callbacks).
- [ ] Resolution-case closure/refund tooling, unmatched external transactions and full reconciliation.
- [ ] Camera/device QA, downloadable PDF generation and ticket reprint auditing.

## D — Operations (partial)
- [x] Cancellation policy snapshots, atomic seat release, independent manual refund approval/confirmation.
- [x] Credit-transfer rescheduling with fare differences and original preservation on failure.
- [x] Explicit bus replacement mappings with transactional rollback on unresolved seats.
- [x] Agent-scoped ownership/reports, local queued notices, financial date filtering/CSV and segment occupancy counts.
- [x] Core cancellation/refund/reschedule/replacement tests passed.
- [ ] Agent commissions, settlements, discount/override rules.
- [ ] Real email/SMS interfaces/providers/templates/preferences and all operational notifications.
- [ ] Complete journey/change histories, detailed audit payloads, SLA/route/conversion/agent analytics.

## E — Hardening/delivery (partial)
- [x] PHP and JS syntax, 20 unit checks, 38 database checks, 23 HTTP checks.
- [x] Backup and empty-target restoration tools; 28 table counts plus financial/allocation aggregates matched.
- [x] XAMPP guide, architecture, verification results, operations guide and continuation checkpoint.
- [ ] MySQL 8 verification, Windows XAMPP installation test, browser/mobile layout and camera QA.
- [ ] Load testing, independent security review, provider failure tests, production TLS/monitoring/supervision.
- [ ] Configurable settings UI, job retry UI, retention/account closure controls.

Externally dependent: payment and messaging providers need both adapter implementation and credentials; camera scanning needs HTTPS/support/permission. Credentials alone do not complete the missing integrations.
