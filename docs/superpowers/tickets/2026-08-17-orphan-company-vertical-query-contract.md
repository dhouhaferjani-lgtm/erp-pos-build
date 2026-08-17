# Remove or repurpose the orphan company-vertical query contract

## Finding

`CompanyVerticalQueryContract`, `CompanyVerticalQueryService`, and their service-provider binding have no remaining consumer. SV-9 removed the final dependency from `CompanyFraudSettingsService` because blind-count defaults are now identical for every vertical. Deleting shared architecture solely as cleanup was outside the Stage-1 settings-policy scope, so the orphan is recorded instead of being folded into that behavior change.

## Acceptance

Before removing the contract, repeat a repository-wide caller inventory across `app/`, `database/`, and `tests/`. If it remains unused, delete the interface, implementation, and binding together and run the Company module plus Compliance settings tests by path. If another lane needs vertical lookup, repurpose it only with a named consumer and preserve the module boundary through `Shared/Contracts`.

## References

- `apps/api/app/Shared/Contracts/Company/CompanyVerticalQueryContract.php`
- `apps/api/app/Modules/Company/Infrastructure/Services/CompanyVerticalQueryService.php`
- `apps/api/app/Modules/Company/CompanyServiceProvider.php`
- `docs/handoff/reviews/sv-stage1/M3-resume1-round2.md`
