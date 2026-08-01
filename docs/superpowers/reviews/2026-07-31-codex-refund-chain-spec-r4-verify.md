# Scoped Verification — Lane C Refund-Chain Spec Revision 4

1. LANDED — §3.1–§3.5, lines 97–209, freezes positive payload magnitudes, pre-V3-builder normalization, the exact four-key return-line shape and disposition enum, and refusal of both partial and full refunds when the original transaction discount is non-zero.
2. LANDED — §3.6, lines 211–231, explicitly narrows window/cap/manager/disposition controls to server-advisory accept-and-flag behavior and defers signed device evidence.
3. LANDED — §4.4–§4.5, lines 299–344, makes only in-flight intents active, delegates cumulative repeat-partial authority to the server quantity cap, and specifies startup payout-confirmed/unprinted reprint recovery plus dispute evidence.
4. LANDED — §4.2 and §5.2–§5.3, lines 268–284 and 362–449, specify immediate job-level non-retryability, server-observable attestation, a fiscal-event-keyed quarantine/dead-letter target, unique compensation, atomic posted GL plus treasury movement, and permission/account provisioning; §17 lines 931–998 manifests the job, seeders, route, and regression tests.
5. LANDED — §6.2–§6.4, lines 471–544, names the one-time NULL-to-value trigger migration, narrows the no-rewrite rule, requires the PostgreSQL trigger regression, and names Receipt/Terminal model changes; §17 lines 924–940 and 985–989 manifest them.
6. LANDED — §9.1–§9.5, lines 670–755, defines the guard-independent repository setter, real HomePage dispatch seam, two-phase offer/acknowledge guard, exact-one-active-DEVICE preflight, and offline/stale transition coverage; §17 lines 942–944, 991–996, and 1027–1059 manifests the seams and tests.
7. PARTIAL — §15/§17 lands v65, file labels, ticket/fixture paths (lines 838–850, 908–910), but §17 still leaves API migrations generic (lines 922–925, 945, 973–974), §15 gives D1 citations `:120/:395/:458` rather than required `:120/:400/:465` (lines 835–837), and §9.6 retains the unverifiable tenant-specific v3-from-birth claim (lines 763–772).

VERDICT: REWORK — 7
