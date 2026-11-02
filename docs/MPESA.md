# M-Pesa integration status

Daraja is not implemented. Do not configure a real callback URL pointing at this application. There is no STK Push endpoint, OAuth client, provider callback parser or authenticated query reconciliation in this release. Manual payment tests are not Daraja callback tests.

Before implementation, verify the current Safaricom Daraja API documentation and sandbox requirements. Obtain the sandbox application consumer key/secret, permitted test shortcode, passkey, supported transaction type and a public HTTPS callback endpoint. Live use needs approved production credentials/shortcode configuration and provider testing. Store those secrets outside source control; masking fields in a UI is not implemented.

Required next work:
1. Add payment request records with expected booking, amount/currency, provider request IDs, idempotency keys and explicit pending/failed/cancelled/timed-out states.
2. Implement bounded-timeout HTTPS OAuth, STK initiation and authoritative status query adapters. No provider success may be accepted from a browser.
3. Treat callbacks as untrusted input; match server-created requests and verify amounts/transaction evidence with supported provider mechanisms. Do not assume a JSON callback has a provider signature.
4. Reuse transactional payment/inventory logic after verified evidence. Preserve late money even if seats are unavailable.
5. Add unmatched-payment reconciliation, idempotent provider transaction constraints and an audited finance resolution workflow.
6. Test duplicates, out-of-order events, callbacks around hold expiry, amount mismatches, timeouts and recovery using isolated mocks and then sandbox credentials. Never use live payments for automated tests.

Credentials alone do not enable the missing implementation.
