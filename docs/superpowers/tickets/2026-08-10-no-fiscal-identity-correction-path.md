# OWNER-ACK: No In-Product Correction Path Exists for Mis-Provisioned Country or Currency

Raised by: fiscal-light and tenancy-authz gate reviews, 2026-08-10.

Status: **OPEN — owner acknowledgement required before any live tenant onboards.**

## Finding

Company `country_code` and `currency` are now unconditionally immutable after creation. That guard
also applies during write-elevated support impersonation: impersonation may inherit
`settings.fiscal.update`, but it reaches the same controller and the immutable-field validation
still returns 422. There is no super-admin correction endpoint, audited command, or approved
support-operations runbook in the product. A mis-provisioned live company can currently be
corrected only by manual database surgery.

Both gate reviewers agree that immutability is fiscally **correct**. The former self-correction
path changed country/currency without re-running chart-of-accounts, tax-configuration, expense
category, or related country-specific provisioning. Restoring that path would create a partially
re-provisioned company and is not an acceptable remedy.

## Required owner acknowledgement

Before onboarding a live tenant, the owner must explicitly acknowledge all of the following:

- country and currency must be verified at creation because the application cannot correct them;
- support cannot promise an in-product remedy today;
- manual database surgery is the only current emergency mechanism and has no repository-defined,
  audited procedure;
- live onboarding remains blocked until a reviewed support-operations procedure is approved, or
  the owner expressly accepts the operational risk for launch.

Any productized correction lane must be super-admin-only, require evidence and explicit approval,
atomically audit old/new values, and define how every country/currency-derived provisioning output
is reconciled. It must not add an impersonation exception to the tenant settings controller.
