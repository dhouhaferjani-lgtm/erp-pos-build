Reviewed v5 at SHA-256 `b07be91b557127a9b739f06bf54f8d0d7dfffdf79059e4f597e9a0761a807c03`. The repository is read-only, so no review file was created.

1. BLOCKER-1 — Horizon drain

   **Status: NOT CLOSED — BLOCKER**

   **Evidence:**

   - Mode B now scales the worker directly to zero and compares the full pre-stop task-ID set afterward: [plan:1181](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:1181), [plan:1190](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:1190), [plan:1193](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:1193).
   - The proposed relationships and values are `supervisor.timeout=3900`, `retry_after=4200`, and `stop_grace_period > supervisor.timeout`: [plan:1182](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:1182).
   - However, D-21 and the service-definition table still require only `stop_grace_period > 3600`, not `>3900`: [plan:211](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:211), [plan:297](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:297).
   - More seriously, cluster-wide restore Mode A still runs `horizon:terminate` first and stops the worker service afterward: [plan:1147](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:1147), [plan:1151](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:1151), [plan:1152](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:1152).
   - Current source defaults remain `timeout=60` and `retry_after=90`: [config/horizon.php:217](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/config/horizon.php:217), [config/queue.php:71](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/config/queue.php:71). The import job can run for 3600 seconds: [ProcessImportJob.php:51](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Jobs/ProcessImportJob.php:51).
   - Phase 0.8k is a real prerequisite and is correctly before bootstrap/production: [plan:2209](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:2209), [plan:2210](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:2210).

   **Reasoning:**

   `docker service scale SERVICE=0` changes the Swarm desired state to zero. Swarm shuts down the selected task, and the engine applies the task’s stop grace period before SIGKILL. That part is mechanically sound under Swarm: [Docker scaling](https://docs.docker.com/engine/swarm/swarm-tutorial/scale-service/), [task lifecycle](https://docs.docker.com/engine/swarm/how-swarm-mode-works/swarm-task-states/), [stop grace semantics](https://docs.docker.com/reference/compose-file/services/#stop_grace_period).

   `retry_after > Horizon supervisor timeout > job timeout` is also the correct ordering. Laravel explicitly requires the worker timeout to be shorter than `retry_after`, while `RedisQueue` migrates expired reserved jobs when queues are popped: [Laravel queue documentation](https://laravel.com/docs/12.x/queues), [RedisQueue.php:297](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/laravel/framework/src/Illuminate/Queue/RedisQueue.php:297).

   The design nevertheless retains a replacement window in Mode A. `horizon:terminate` exits the current Horizon process while the Swarm desired replica count remains one; Swarm can replace it before the later service stop. Thus the exact abandonment/replacement race that scale-to-zero was intended to remove still exists during the most destructive restore path.

   **Required fix:** Convert Mode A to the same atomic scale-to-zero/full-task-set drain procedure. Update D-21 and every service-definition requirement to say explicitly `stop_grace_period > HORIZON_SUPERVISOR_TIMEOUT`, with operational margin, not merely `> max job timeout`.

2. BLOCKER-2 — Restic verification and cycle retention

   **Status: NOT CLOSED — BLOCKER**

   **Evidence:**

   - B4a now runs before attestation and deadman ping and binds `inventory_sha256`: [plan:795](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:795), [plan:915](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:915).
   - Restore rule 2 and O-3b recheck that inventory: [plan:801](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:801), [plan:1632](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:1632).
   - A fourth cycle snapshot, `role=media-mirror`, is created: [plan:870](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:870).
   - The new expiry loop forgets only `data`, `complete`, and `attestation`: [plan:919](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:919), [plan:923](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:923).
   - It selects only `.[0].id`, silently skips missing roles, and invokes `restic forget` separately for each role: [plan:924](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:924), [plan:925](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:925).
   - The postcondition checks retained cycles only; it does not require expired cycles to have zero snapshots: [plan:929](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:929).
   - Elsewhere the plan says media mirror snapshots are governed by retention: [plan:652](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:652), [plan:1029](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:1029).

   **Reasoning:**

   B4a’s `restic ls --json` inventory is not content verification. Its node records expose paths, sizes, timestamps, and metadata, but not a per-file plaintext content digest. A same-size mutation can therefore produce a COMPLETE snapshot whose inventory hash still matches the expected name/size inventory, followed by a valid attestation and deadman ping. The later restore checksum gate may correctly refuse it, but the marker still advertised an unverified restore candidate. See restic’s documented JSON node schema: [restic scripting documentation](https://restic.readthedocs.io/en/stable/075_scripting.html).

   The retention operation is not cycle-atomic. Three independent `forget` calls can leave a partial triple after a failure. Duplicates beyond `.[0]` remain. Missing roles are silently accepted. Most definitively, every expired cycle’s `media-mirror` snapshot is orphaned because the loop never selects it.

   Restic permits passing multiple explicit snapshot IDs to one `forget`, followed by `prune`; retention grouping does not itself supply the plan’s custom cycle semantics: [restic forget documentation](https://restic.readthedocs.io/en/latest/060_forget.html).

   **Required fix:**

   - Verify actual file content from the COMPLETE snapshot against `SHA256SUMS` before creating the attestation or sending the success ping.
   - Resolve and assert exactly one snapshot for every cycle role, including `media-mirror`.
   - Pass the complete ID set to one `restic forget ID…` invocation.
   - Fail on missing or duplicate roles.
   - Assert both exactly one bound role set for every retained cycle and zero snapshots for every expired cycle.
   - Define and test `cycles_to_expire` against explicit calendar-boundary fixtures so cycles cannot be mis-aged.

3. BLOCKER-3 — Bootstrap concurrency and attempt cap

   **Status: CLOSED**

   **Evidence:**

   - The workflow has a fixed workflow-level group with `cancel-in-progress:false`: [plan:1734](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:1734).
   - Increment and re-read are the first job step and precede retrieval of any build credential: [plan:1747](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:1747), [plan:1749](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:1749).
   - The cap is three attempts: [plan:1745](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:1745).
   - Phase 0.8b1 requires the workflow and a two-dispatch contention test before production bootstrap: [plan:2200](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:2200), [plan:2210](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:2210).

   **Reasoning:**

   A fixed workflow-level concurrency group permits only one running member. Consequently, the state increment/re-read occurs inside the serialized run, before credentials can be acquired. Two simultaneous dispatches cannot both enter the build section from the same pre-increment count. This closes the Round-4 race. GitHub’s concurrency behavior is documented here: [GitHub Actions concurrency](https://docs.github.com/en/actions/how-tos/write-workflows/choose-when-workflows-run/control-workflow-concurrency).

   The “CAS” must still be instantiated as a concrete state-store operation during buildout; concurrency, rather than the unspecified CAS syntax, is what establishes mutual exclusion.

4. MAJOR-4 — Offsite-lock ordering

   **Status: PARTIALLY CLOSED — MAJOR**

   **Evidence:**

   - The core phase ordering is now correct: Phase 0 uses the workflow artefact only, while the GitHub-independent offsite property starts in Phase 4: [plan:1753](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:1753), [plan:1781](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:1781), [plan:1787](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:1787).
   - Phase 4.3b follows creation of the two offsite legs in Phases 4.1 and 4.2: [plan:2258](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:2258).
   - DR-3 correctly describes sourcing the lock from the workflow artefact or offsite legs: [plan:1394](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:1394).
   - But §7.0.2 still says all seven audit records belong to the Phase-0 evidence set, while its bootstrap-lock row lists the workflow artefact plus both offsite legs: [plan:1760](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:1760), [plan:1768](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:1768).
   - DR-3 also says every prerequisite in that cold-start procedure is a Phase-0 deliverable: [plan:1386](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:1386).

   **Reasoning:**

   The implementation sequence is now viable, and the GitHub-independent property is honestly assigned to Phase 4 in the main design. Two stale universal claims still make the document internally contradictory: Phase 0 cannot contain offsite-leg evidence that is not produced until Phase 4, and the fully GitHub-independent version of DR-3 cannot be a Phase-0 prerequisite.

   **Required fix:** Qualify the audit row as “workflow artefact in Phase 0; both offsite legs from Phase 4.3b,” and separate DR-3’s Phase-0 panel-independence from the Phase-4 GitHub-independence guarantee.

5. MAJOR-5 — 0.8h1 ordering

   **Status: CLOSED**

   **Evidence:**

   - Phase 0.8h1 is physically and logically before 0.8g: [plan:2205](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:2205), [plan:2206](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:2206).
   - The commissioning section identifies 0.8h1 as the permission-capture prerequisite: [plan:1973](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:1973).
   - Deployment is prohibited until that capture is complete: [plan:2010](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:2010).
   - The later 0.8g script consumes the commissioned values: [plan:2023](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:2023).

   **Reasoning:**

   All operative mentions now agree: discover and record the runtime ownership values in 0.8h1, then generate/apply the ACL procedure in 0.8g. No reverse dependency remains.

6. MINOR-6 — Origin workflow claim and Probe A wording

   **Status: CLOSED**

   **Evidence:**

   - The origin/main claim lists only `ci.yml`, `smoke-test.yml`, and `sonarcloud.yml`; `react-doctor.yml` is described separately as dev-only: [plan:1728](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:1728), [plan:1732](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:1732).
   - The appendix repeats the corrected three-workflow claim: [plan:2741](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:2741).
   - Repository inspection of `origin/main:.github/workflows` confirms exactly those three files.
   - Probe A now reports the task as remaining reserved after the intentional worker crash rather than incorrectly saying the job “reappeared”: [plan:1214](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:1214), [plan:1220](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-08-05-production-environment-design.md:1220).

   **Reasoning:**

   Both previously incorrect statements are fixed consistently; no remaining operative occurrence of the old Probe A message was found.

## New defects introduced in v5

- **BLOCKER:** The cycle retention rewrite omits `role=media-mirror`, so expired cycles accumulate orphaned media snapshots.
- **BLOCKER:** The retention loop is described as cycle-unit deletion but performs separate `forget` operations, permitting partially deleted cycles. Its first-only lookup and retained-only postcondition also allow duplicates and expired-cycle remnants.
- **BLOCKER:** The new B4a inventory is metadata verification, not content verification. It can attest and ping a COMPLETE snapshot whose files no longer match `SHA256SUMS`.
- **MAJOR:** D-21 was not strengthened when the supervisor timeout was raised to 3900 seconds, leaving the documented manifest requirement weaker than the runtime invariant.
- **MINOR:** GitHub concurrency permits one running and, by default, only one pending member; a newer third dispatch can replace the older pending dispatch. If all three approved attempts must be preserved, the workflow needs an explicit queue policy or dispatch admission mechanism.
- **MINOR:** Invalid or unauthorized dispatch input appears able to consume an attempt because the attempt counter is incremented before input validation. The plan should state whether this is intentional.

The single most dangerous remaining flaw is the Mode A terminate-before-stop window, because Swarm can start a replacement worker while a destructive cluster restore is trying to fence all queue consumers.

**Verdict: REJECT**

