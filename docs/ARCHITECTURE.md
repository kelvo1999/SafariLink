# Architecture and invariants

HTTP forms/rendering are in public/index.php; shared configuration, persistence helpers and server permissions are in app/bootstrap.php; services are separated into inventory/booking/payment/ticket, operations, identity, rescheduling, QR and reporting files. SQL uses native prepared statements except fixed, validated internal table identifiers in backup tools. The HTTP file is still large; splitting individual controllers/views is a future maintainability task.

## Inventory

`segment_allocations` primary key `(trip_id,seat_label,segment_position)` is the final exclusion constraint. `Inventory::lock()` locks the trip before reservations, expirations, payments, cancellations, boarding, rescheduling and bus replacement. This deliberately serializes allocation changes for each trip; high-volume workloads need performance measurements before relaxing locks.

Request-time expiry removes expired held allocations and marks bookings expired even if the scheduler has not run. Available-seat queries ignore expired holds. All group inserts share one transaction. A transaction failing after one seat insert rolls back the entire group. Database UTC is explicitly configured on every application connection.

Seat geometry and trip stop names/times are copied from the current layout/route when a trip is created. Later route/layout changes therefore do not rewrite an existing trip. Fare and policy snapshots are recorded on the booking. Fares are not frozen per trip before booking; a newly accepted booking uses the current route fare.

## Financial evidence and late payments

Payments, status changes and outbox jobs commit together. Monetary values are integer KES cents. Provider + reference uniqueness enforces idempotency; repeating the same reference with different amount/booking is rejected. Current provider values are manual cash/bank only. Partial payments do not issue tickets. Exact payment confirms available travel. A payment after expiry attempts original-seat reacquisition under the same trip lock.

Unavailable original seats, unavailable travel, or non-travelable bookings create a visible resolution case and preserve the payment. No alternative seats are silently selected. Overpayments on held bookings enter resolution; excess payments on already confirmed bookings retain valid travel and create an excess-payment case. Case investigation/closure and unmatched external-payment imports still need a dedicated workflow.

`payment_status`, booking status, individual ticket status and passenger boarding status are separate. Receipt IDs PAY-<payment ID> identify payment records; receipts do not prove travel validity.

## Tickets and outbox

The worker issues one ticket per passenger with a unique 256-bit token and queues confirmation notices. Repeated issuance finds the unique passenger ticket and does not duplicate it. QR content is only the unguessable token, without personal information. Printable tickets and receipts authorize against booking ownership/session retrieval keys before rendering.

Boarding takes trip and origin stop, validates state, then inserts a unique boarding event and updates ticket/passenger status atomically. Wrong trip, wrong boarding stop, tampered/cancelled/already-used tickets are rejected. Staff with boarding.scan currently have operator-wide boarding access; per-departure staff assignment scopes remain a future permission refinement. No offline scanning is implemented.

Notification delivery is local database logging. A notification is not marked as externally sent. Five-attempt jobs have delayed retries and stale-lease recovery. All implemented job effects are database-local and transactional. Real network/email providers require further idempotency, provider delivery tracking and timeout handling.

## Changes

Cancellation releases only that booking's allocations and invalidates its tickets in one transaction. Confirmed cancellation checks the purchased deadline policy. Paid cancellations create refund requests, not completed refunds. A requester cannot approve their own refund; a different officer must confirm external processing, providing a unique reference. Provider execution/automatic refund reconciliation is absent.

Rescheduling is operator-controlled. Hold a replacement under the same contact phone, record any additional fare, then complete the transition. It locks trip IDs in sorted order, checks original status/deadline and replacement allocations, transfers eligible original payment credit, cancels old tickets, releases old allocations, and queues new tickets. An insufficient fare difference or failed replacement leaves the original valid. A cheaper replacement creates a surplus refund request. Transfers are not new collections and do not create synthetic cash payments. Superseded bookings remain stored.

Bus replacement requires an explicit old→new label mapping for every active passenger. It checks the replacement bus schedule, seat validity and segment overlaps. Invalid/insufficient mappings roll back. A successful replacement changes the trip snapshot, passenger labels and allocations together. The full old→new mapping is not yet preserved in an audit-detail payload; the action and notification are logged.

## Identity

Passwords use PHP password_hash. Guest keys are returned once to the booking session and stored as SHA-256 hashes in the database. Retrieval submits reference + private key via POST and rotates the session ID; no retrieval secret goes into URLs. Staff must complete TOTP with replay counter checks. Recoverable MFA secrets use AES-256-GCM with a separate APP_KEY. Password reset tokens are hashed, expiring and single-use; resetting increments auth_version to invalidate existing signed-in sessions. Local development reset delivery writes a mode-0600 private file. Production reset delivery is disabled until a provider exists.

No attachments/uploads are accepted. This intentionally avoids an unprotected placeholder upload path.

## Reporting

Charged fares are currently confirmed bookings created within the selected local-date interval. Cancelled/superseded bookings are excluded; this is not an immutable accounting ledger. Collections use actual payment dates, completed refunds use processing timestamps, net = collections − completed refunds. Agent queries include sold_by ownership. Per-segment occupancy is confirmed allocated seats / unblocked snapshot seats for that same adjacent segment. No claimed network or vehicle telemetry is fabricated.
