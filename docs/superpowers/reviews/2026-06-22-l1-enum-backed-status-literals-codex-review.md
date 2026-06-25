# L-1 Codex Review — Enum-Backed Status Literals

Date: 2026-06-22
Scope:
- document aged receivables status queries
- treasury payment allocation open-document status queries
- opening-balance row count status queries
- fiscal-period open status queries
- architecture regression for enum-backed status literals

## Findings

No BLOCKER, HIGH, or MEDIUM findings.

## Checks

- Document queries now use `DocumentStatus::Posted` instead of the posted string literal.
- Payment allocation open-document queries now use `DocumentStatus::Posted` and `DocumentStatus::Confirmed`.
- Opening-balance valid/invalid row counters now use `OpeningImportRowStatus` enum cases.
- Fiscal-period resolver queries now use `PeriodStatus::Open`.
- The architecture test guards the exact audited literals from returning in the listed files.

## Residual Risk

- The architecture test is scoped to the audited enum-backed literals rather than every status-like string in the codebase, which keeps L-1 narrow.
