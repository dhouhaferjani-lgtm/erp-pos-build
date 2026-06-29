# Autonomous Product Development — Kickoff Prompt

> Paste this into a fresh Claude Code session opened in the ERP project directory.
> It will verify the setup, finalize the PRODUCT-BIBLE, configure the agents, and establish the development workflow.

---

You are starting the autonomous product development setup for AutoERP. This is a meticulous, multi-phase session. Take your time — getting this right is more important than getting it done fast.

## What Already Exists

Pre-built analysis and agent configs are at:
- `.agents/` — orchestrator, finance, supply-chain, product-intel agents with CLAUDE.md + YAML configs
- `docs/autonomous-product-dev-setup/` — codebase analysis (modules, flows, tech debt) + discovery prompt + PRODUCT-BIBLE template
- `scripts/visual-test-gate.sh` — Playwright pre-commit gate

## Your Mission (4 phases, in order)

### Phase 1: Verify the Pre-Built Analysis (~30 min)

The codebase was analyzed by automated agents. Their findings may be outdated or incomplete. Verify each one:

1. **Read** `docs/autonomous-product-dev-setup/discovery-module-inventory.md`
   - Spot-check 5-6 modules by actually reading their directories
   - Are the completeness ratings accurate?
   - Are there any new modules that were missed?
   - Update the file with corrections

2. **Read** `docs/autonomous-product-dev-setup/discovery-flow-analysis.md`
   - Trace at least 2 flows through actual code (prioritize Purchase and Treasury — they were rated lowest)
   - Verify the gaps are real
   - Check if anything was fixed since the analysis
   - Update with corrections

3. **Read** `docs/autonomous-product-dev-setup/discovery-tech-debt.md`
   - Verify the 5 critical issues still exist (grep for them)
   - Check if any were fixed
   - Are there new critical issues?
   - Update with corrections

4. **Commit** corrections: `git commit -m "docs: verify and correct pre-discovery analysis"`

### Phase 2: Build the PRODUCT-BIBLE (~1-2 hours, interactive)

This phase requires the founder (Houssam). It follows the discovery prompt.

1. **Read** `docs/autonomous-product-dev-setup/product-discovery-prompt.md` — this is your interview guide
2. **Read** `docs/autonomous-product-dev-setup/PRODUCT-BIBLE-TEMPLATE.md` — this is your output format
3. **Skip Phase 1 of the discovery prompt** (codebase exploration) — it's already done in the files above. Go directly to Phase 2 (present findings) and Phase 3 (interview).

Present the verified findings to Houssam:
- "Here's the module inventory. 18 complete, 14 partial, 5 scaffolded. Does this match your understanding?"
- "Here are the 6 business flows and their gaps. Which gaps are the highest priority?"
- "Here are the critical technical debt items. Which are intentional vs need fixing?"

Then conduct the strategic interview (Phase 3 of the discovery prompt). Only ask questions the codebase can't answer: vision, priorities, verticals, business rules, quality bars.

Output: `docs/PRODUCT-BIBLE.md` — the source of truth for all future development.

Commit: `git commit -m "docs: create PRODUCT-BIBLE from founder interview"`

### Phase 3: Verify and Tune the Agents (~30 min)

The agents at `.agents/` were created from an automated codebase scan. Verify them against the PRODUCT-BIBLE and real code:

1. **Read** `.agents/orchestrator/CLAUDE.md` — does it match how Houssam wants to work? Adjust the autonomy tiers based on what he said in the interview.

2. **Read** `.agents/finance/CLAUDE.md` and `.agents/finance/config/domain.yaml`
   - Are the module paths correct?
   - Are the business rules complete? (compare against PRODUCT-BIBLE)
   - Add any rules from the interview

3. **Read** `.agents/supply-chain/CLAUDE.md` and config
   - Same verification
   - Especially check inventory valuation rules and batch/expiry handling

4. **Read** `.agents/product-intel/config/verticals.yaml`
   - Update vertical priorities based on the interview
   - Adjust gap assessments based on what Houssam said about what's important

5. **Commit** corrections: `git commit -m "feat: tune agents based on PRODUCT-BIBLE"`

### Phase 4: Establish the Development Workflow (~20 min)

Set up the infrastructure for autonomous development:

1. **Install tooling** (if not already installed):
   ```bash
   # Architecture enforcement
   cd apps/api && composer require --dev qossmic/deptrac
   
   # Install Playwright gate as git hook
   cp scripts/visual-test-gate.sh .git/hooks/pre-commit
   chmod +x .git/hooks/pre-commit
   ```

2. **Create the initial MANIFEST.yaml** at `.agents/orchestrator/MANIFEST.yaml`
   - List the top 5 tasks from the PRODUCT-BIBLE (highest priority gaps)
   - Set them all to `status: backlog`
   - This becomes the starting backlog

3. **Create a deptrac.yaml** (if it doesn't exist) with rules matching the hexagonal architecture:
   - Domain layer cannot import from Infrastructure or Presentation
   - Modules cannot import from other modules except via Shared/Contracts
   - Run `deptrac analyse` to see current violations

4. **Test the Playwright gate**:
   ```bash
   # Make a trivial change to a .tsx file, try to commit
   # The gate should trigger and run Playwright
   ```

5. **Commit** the setup: `git commit -m "feat: establish autonomous dev workflow — tooling + initial backlog"`

## When You're Done

Push everything to dev:
```bash
git push origin dev
```

The ERP now has:
- A PRODUCT-BIBLE that answers every product question
- Tuned domain agents that know the business rules
- An orchestrator ready to dispatch and track work
- Architecture enforcement via Deptrac + Pest arch tests
- A Playwright visual test gate
- An initial backlog of the highest-priority work

From here, development can proceed with minimal founder involvement — the orchestrator dispatches, agents review, CI gates prevent regressions, and Houssam gets Telegram updates only when decisions are needed.
