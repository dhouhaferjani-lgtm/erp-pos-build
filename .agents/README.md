# ERP Agent Ecosystem -- Synerivia ERP

This directory contains the specialized AI agents that coordinate development of the Synerivia ERP codebase at `~/Projects/syneriva/apps/erp/`.

---

## Architecture

```
~/Projects/syneriva/apps/erp/.agents/   # in-repo agent ecosystem
  orchestrator/           # Master agent -- dispatches, tracks, reviews, merges
  finance/                # Finance domain agent -- GL, treasury, tax, compliance
  supply-chain/           # Supply Chain domain agent -- inventory, products, procurement
  product-intel/          # Product Intelligence agent -- gap analysis, research, specs
```

The agents follow an **orchestrator-worker pattern**:

```
Houssam (via Telegram/Adam)
    |
    v
Orchestrator (this is the brain)
    |
    |-- dispatches tasks to -->  Workers (subagents in git worktrees)
    |-- dispatches tasks to -->  Codex (autonomous, long-running)
    |-- dispatches tasks to -->  Long-running sessions (~/erp-sessions/)
    |
    |-- requests review from --> Finance agent (business logic review)
    |-- requests review from --> Supply Chain agent (business logic review)
    |-- requests specs from -->  Product Intelligence agent (research)
    |
    |-- reports status to -->    Adam (who relays to Houssam)
```

Workers never communicate directly with each other. Everything flows through the orchestrator.

---

## Agents

### Orchestrator (`orchestrator/`)

The master development coordinator. It:
- Receives work items from Houssam (via Adam) or from the backlog
- Decides where each task goes using a dispatch decision tree
- Tracks all active work in `MANIFEST.yaml`
- Monitors worker sessions for staleness or failure
- Manages the merge pipeline (feature -> local-dev -> main)
- Enforces the autonomy tier system (what it can do freely vs. what needs approval)

**Key files:**
- `CLAUDE.md` -- full orchestrator identity, rules, and procedures
- `MANIFEST.yaml` -- persistent state of all development activity
- `config/decision-tree.yaml` -- task dispatch logic (machine-readable)
- `config/autonomy-tiers.yaml` -- what the orchestrator can do at each tier
- `templates/HANDOFF.md` -- template for worker session context
- `templates/STATE.yaml` -- template for session progress tracking

### Finance Agent (`finance/`)

Owns all financial modules: Accounting, Treasury, Billing, Expense, Taxation, Compliance, and the fiscal lifecycle portion of Document.

**Invoked for:**
- Implementing features in financial modules
- Reviewing PRs that touch financial code
- Writing specs for financial features
- Enforcing double-entry accounting, fiscal hash chains, and payment integrity

**Key business rules it enforces:**
- Double-entry balance (debits = credits)
- bcmath arithmetic (no floats for money)
- Pessimistic locking on financial state mutations
- Fiscal hash chain integrity
- Sequential document numbering
- Tax calculation correctness

### Supply Chain Agent (`supply-chain/`)

Owns all product and inventory modules: Product, Catalog, Inventory, BatchExpiry, Uom, PurchaseHub, Pricing, Promotion, Coupon, and the fulfillment lifecycle portion of Document.

**Invoked for:**
- Implementing features in supply chain modules
- Reviewing PRs that touch inventory or product code
- Writing specs for supply chain features
- Enforcing stock accuracy, WAC costing, and FEFO batch allocation

**Key business rules it enforces:**
- Weighted Average Cost (WAC) recalculation on inbound movements
- Stock movement audit trail (before/after snapshots)
- FEFO allocation for batch-tracked products
- Reservation consistency (reserved <= total)
- Pessimistic locking on stock mutations
- Negative stock prevention

### Product Intelligence Agent (`product-intel/`)

Research and analysis agent. Does NOT write code.

**Invoked for:**
- Vertical-specific feature gap analysis
- Competitive ERP research
- Industry requirements discovery
- Feature spec generation from high-level requests
- Roadmap recommendations

**Output:** Feature specs, gap reports, and roadmaps that feed into the orchestrator's dispatch pipeline.

---

## How It Works

### Task lifecycle

1. **Intake:** Houssam sends a request via Telegram, or a GitHub issue is created
2. **Dispatch:** Orchestrator evaluates the task against the decision tree and dispatches
3. **Execution:** Worker implements in an isolated git worktree on a feature branch
4. **Quality:** Worker runs preflight.sh (PHPStan, Pint, Pest, TypeScript, ESLint)
5. **Review:** Domain agent reviews business logic; orchestrator reviews architecture
6. **Merge:** PR merged to `local-dev` (Tier 2 -- needs approval)
7. **Promotion:** `local-dev` -> `main` -> remote (Tier 2 -- needs approval)

### Communication

- Workers write progress to `~/erp-sessions/{task}/STATE.yaml`
- Orchestrator reads STATE.yaml to monitor progress
- Orchestrator writes context to `~/erp-sessions/{task}/HANDOFF.md` when starting sessions
- All persistent state lives in `orchestrator/MANIFEST.yaml`
- Houssam is reached via Telegram through Adam

### Safety

- All work happens on feature branches (never direct commits to local-dev or main)
- CI must pass before any merge
- Fiscal/compliance code is NEVER handled autonomously (Tier 3)
- MANIFEST.yaml survives crashes -- the orchestrator can resume from any state
- Git is the ultimate safety net -- frequent commits, nothing is lost

---

## Quick Reference

### Starting the orchestrator

```bash
cd ~/Projects/syneriva/apps/erp/.agents/orchestrator
claude "Read CLAUDE.md and MANIFEST.yaml. Generate a status report of all active work."
```

### Dispatching a task manually

```bash
# Create a worktree for the task
cd ~/Projects/syneriva/apps/erp
git worktree add ~/erp-worktrees/TASK-001 -b feature/TASK-001

# Write the handoff
# (copy from templates/HANDOFF.md and fill in the details)

# Launch the worker
tmux new-session -d -s TASK-001 "cd ~/erp-worktrees/TASK-001 && claude 'Read ~/erp-sessions/TASK-001/HANDOFF.md and implement the task.'"
```

### Checking task status

```bash
# Read the MANIFEST
cat ~/Projects/syneriva/apps/erp/.agents/orchestrator/MANIFEST.yaml

# Check a specific session
cat ~/erp-sessions/TASK-001/STATE.yaml

# Check active worktrees
cd ~/Projects/syneriva/apps/erp && git worktree list
```

### Running quality gates

```bash
cd ~/Projects/syneriva/apps/erp

# Full preflight
./scripts/preflight.sh

# Visual test gate (for UI changes)
./scripts/visual-test-gate.sh

# Individual tools
cd apps/api && ./vendor/bin/phpstan
cd apps/api && ./vendor/bin/pint
cd apps/api && composer test
cd apps/web && pnpm typecheck
cd apps/web && pnpm lint
cd apps/web && pnpm test
```

---

## Directory Layout

```
~/Projects/syneriva/apps/erp/   # Synerivia ERP codebase (the thing being built)
  .agents/                      # in-repo agent ecosystem
    orchestrator/               # This ecosystem's brain
    finance/                    # Finance domain expertise
    supply-chain/               # Supply chain domain expertise
    product-intel/              # Research and analysis
~/
  erp-sessions/                 # Active session state (HANDOFF.md + STATE.yaml)
    {task-id}/
  erp-worktrees/                # Git worktrees for isolated parallel work
    {task-id}/
```
