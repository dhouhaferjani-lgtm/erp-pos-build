# ERP Project Documentation

**Products:** Otospex (Automotive) | IziPOS (Generic Retail/F&B)  
**Updated:** December 29, 2025

---

## Quick Navigation

### 📊 Status & Overview
- [Project Status](00-PROJECT-OVERVIEW/PROJECT-STATUS.md) - Current state, what's done, what's next

### 🏗️ Architecture
- [Multi-Product Specification](01-ARCHITECTURE/MULTI-PRODUCT-SPECIFICATION.md) - How IziPOS and Otospex share code

### 🗺️ Roadmap
- [Master Roadmap](02-ROADMAP/MASTER-ROADMAP.md) - Overview of all tracks
- [Track 1: IziPOS POS](02-ROADMAP/TRACK-1-IZIPOS-POS.md) - POS module development
- [Track 2: Tunisia Compliance](02-ROADMAP/TRACK-2-TUNISIA-COMPLIANCE.md) - TEJ, withholding tax
- [Track 3: Core Refinement](02-ROADMAP/TRACK-3-CORE-REFINEMENT.md) - Background improvements

### 📋 Module Specifications
- [POS Module](03-MODULE-SPECS/POS-MODULE-SPEC.md) - Terminals, sessions, transactions
- [Batch & Expiry](03-MODULE-SPECS/BATCH-EXPIRY-SPEC.md) - FEFO, lot tracking for pharmacy
- [Tax Withholding](03-MODULE-SPECS/TAX-WITHHOLDING-SPEC.md) - Tunisia withholding tax

### 🛠️ Development Resources
- [Claude Code Context](04-PROMPTS/CLAUDE-CODE-CONTEXT.md) - Base context for any session
- [Module Development Prompt](04-PROMPTS/MODULE-DEVELOPMENT-PROMPT.md) - Template for starting work
- [Verification Checklist](04-PROMPTS/VERIFICATION-CHECKLIST.md) - Quality control

### 📁 Archive
- `05-ARCHIVE/` - Old documents (move outdated files here)

---

## Starting Development

### For Any Claude Code Session

1. Provide the [Claude Code Context](04-PROMPTS/CLAUDE-CODE-CONTEXT.md) document
2. Use the [Module Development Prompt](04-PROMPTS/MODULE-DEVELOPMENT-PROMPT.md) template
3. Reference relevant spec from `03-MODULE-SPECS/`

### Parallel Work Opportunities

| Track 1A (POS Terminal) | Track 2A (Tax Rules) | Track 3 (Core) |
|-------------------------|----------------------|----------------|
| Can run together        | Can run together     | Always OK      |

---

## Key Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Receipt numbering | Per-terminal sequences | Enables offline, no exhaustion |
| Hash algorithm | SHA-256 | Industry standard, fast |
| Multi-product | APP_PRODUCT env var | Same image, different config |
| Credit notes | `CreditNote` entity | Naming convention |
| Company model | `App\Modules\Company\Domain\Company` | Location reference |

---

## Current Focus

**Week of Dec 29, 2025:**

| Track | Activity | Owner |
|-------|----------|-------|
| Track 3 | Import functionality | Claude Code |
| Planning | Module development setup | This session |

---

## Getting Help

- **Architecture questions** → Ask in planning chat
- **Implementation** → Claude Code with context docs
- **Verification** → Use multi-AI approach (Codex, Gemini)

---

## Document Maintenance

- Update `PROJECT-STATUS.md` when phases complete
- Move outdated docs to `05-ARCHIVE/`
- Keep specs updated as implementation reveals gaps
