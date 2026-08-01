# Ticket: refund per-line disposition UI (post-launch)

Lane C wave-2 ruling (2026-08-01): v4 refund launch scope has NO per-line disposition UI — every
refund line defaults to 'restock' in RefundReceiptV4Payload. Safe for regulated items (the wave-1
projector honors RestockPolicyResolver's never-restock override regardless of payload disposition)
but damaged-goods refunds will restock at launch and need manual stock adjustment. Accepted
limitation for the single-terminal tenant #1. Follow-up: add a per-line disposition selector
(RESTOCK/SCRAP/NOT_RECEIVED) to the refund flow; the payload, validator, and projector already
carry the field end-to-end.
