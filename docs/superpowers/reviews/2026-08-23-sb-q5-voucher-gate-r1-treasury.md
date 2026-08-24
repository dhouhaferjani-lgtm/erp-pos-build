# Gate record — Session B lane Q-5 (voucher void), treasury lens r1

Commit `94ec615bf`. **Verdict: ACCEPT (approved-with-conditions)** — #22 proven red against
base by revert-probe; GL reversal verified a true source-keyed mirror; gl_journal_entry_id on
INSERT inside the txn; ledger immutability proven live (UPDATE+DELETE raise on PG); partial
unique blessed (no un-void/re-issue path exists); precision verified (scale-5 = decimal(20,5));
64/64 PG + 64/64 sqlite; scope clean, VoucherStatus untouched.

**Rulings:** R1 auto-fraud alert-only **ACCEPTED** (disjointness verified — the old auto-void
could only corrupt; protection never existed) with a MANDATORY program ticket: fraud counter
never records attempts against ACTIVE vouchers (brute-force invisible); remediation for a live
voucher under attack is a freeze/hold edge, not void. R2 cascade fails-closed on Expired
**ACCEPTED** (nothing writes Expired; no engine) with recorded obligations on the future expiry
lane: own breakage GL treatment (never the void edge) + decide skip-vs-block for cascade before
the engine ships.

**Pre-merge corrections (consolidated micro-round after the fiscal lens):**
F-1 `VOIDABLE_STATUSES` advertises PartiallyRedeemed but the redemption guard makes it
structurally unreachable — fix the docs/constant honestly (do NOT loosen the guard);
F-4 lookup-service class docblock claims a live auto-void — align with :213-223;
F-5 wrong migration filename in VoucherVoidService docblock (140000 → 150000);
F-2 add the #22 money-hole census to the migration docblock + promotion checklist:
`SELECT COUNT(*), SUM(ABS(amount)) FROM voucher_ledger WHERE event='voided' AND
gl_journal_entry_id IS NULL AND amount <> 0;` (historical NULL rows are UNREPAIRABLE under the
immutability trigger — remediation = forward correcting JE).
Minor (fold if cheap): F-6 `->orderBy('id')` on cascade voucher load (deterministic lock
order); F-7 `fresh()?->toArray()` null-200; F-8 map VOUCHER_NOT_FOUND to 404 in the catch.

**Promotion obligations (register at merge):** (1) duplicate census + unposted-void census must
return empty on EVERY staging tenant before the promotion carrying `2026_08_23_150000`; any hit
⇒ split the index into a follow-up migration (immutability trigger blocks delete-based fixes);
(2) non-CONCURRENTLY index = ACCESS EXCLUSIVE window, fine at current sizes; (3) API-contract
note: FullyRedeemed void now returns VOUCHER_NOT_VOIDABLE (was VOUCHER_ALREADY_TERMINAL) — no
FE consumer branches on voucher error codes (grep-verified).
Faith items: staging censuses (no access); sealed-receipt payload question handed to the
fiscal lens; VoucherSource Loyalty/GiftCard/Promotional voids would 500 at the GL map the day
those sources ship (currently unreachable) — note for the loyalty/gift-card lane.
