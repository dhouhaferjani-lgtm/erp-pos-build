# T1-14: Open in-progress request cannot be cancelled by its requester

Status: open · severity: medium · owner: replenishment lifecycle/product ruling

Symptom: request → purchase order to warehouse sets in_progress. The original requester then POSTs cancel and receives 422, although status.isOpen() is true. Pending cancellation remains supported.

Seam: `apps/api/app/Modules/Replenishment/Presentation/Controllers/ReplenishmentRequestController.php:141` requires Pending; `apps/api/app/Modules/Replenishment/Domain/Enums/ReplenishmentStatus.php:15` considers both Pending and InProgress open.

Benchmark: T-1 brief case 14: “Odoo: cancel allowed until done; ERPNext: Material Request can be stopped” and “Ticket if inconsistent with the status enum's isOpen().” This is a policy mismatch, not evidence that isOpen() universally implies every transition is allowed.

Proposed fix: owner decides whether in-progress requests can be stopped independently of the sourcing PO. Specify actor permissions, sourcing-link retention, later transfer settlement and notification effects; encode an explicit cancellation transition predicate and update the controller. A one-line isOpen() substitution is intentionally not applied: it would decide unapproved sourcing lifecycle behavior without covering its consequences.

Acceptance: explicit, documented cancellation behavior for both open states and terminal states; requester and processor cases; sourcing PO unchanged or consistently stopped according to the ruling; later settlement cannot resurrect cancelled demand.

Reproduction: `T1_RUN_KNOWN_REDS=1` with `tests/Feature/Replenishment/ReplenishmentEdgeCasesTest.php --filter test_requester_can_cancel_in_progress_request_while_status_is_open`. Default ticket-linked skip; observed current behavior is 422, state remains in_progress.
