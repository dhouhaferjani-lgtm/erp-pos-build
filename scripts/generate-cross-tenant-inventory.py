#!/usr/bin/env python3
"""Regenerate the M2.0 CrossTenantRoute inventory CSV.

Walks `apps/api/app/` looking for `#[CrossTenantRoute(reason: '...')]`
attribute annotations and writes a classified CSV at
`docs/security/cross-tenant-route-inventory-2026-05-12.csv`.

Run from the repository root:

    python3 scripts/generate-cross-tenant-inventory.py

The README at `docs/security/cross-tenant-route-inventory-2026-05-12.md`
describes the heuristic and the next-step triage workflow.
"""

import re
import sys
from collections import Counter
from pathlib import Path

API_ROOT = Path('apps/api/app')
OUT_PATH = Path('docs/security/cross-tenant-route-inventory-2026-05-12.csv')

ANNOTATION_RE = re.compile(
    r"#\[CrossTenantRoute\(reason:\s*'((?:[^'\\]|\\.)*)'", re.DOTALL
)
CLASS_RE = re.compile(r"^(?:final\s+|abstract\s+)?class\s+(\w+)", re.MULTILINE)
METHOD_RE = re.compile(r"public\s+function\s+(\w+)\s*\(")

# M2.1-M2.5 named controllers — these get fix-now automatically.
FIX_NOW_CONTROLLERS = {
    'ProductImageController',
    'DocumentEmailController',
    'DocumentAdditionalCostController',
    'RoleController',
    'PurchaseHubOfferController',
}

# Default owner and reviewer-date stamp for bulk legitimate-platform
# promotion. Bump these when the script is re-run for a new triage round.
DEFAULT_OWNER = '@otospexsolutions'
TRIAGE_DATE = '2026-05-13'

# Per-controller (by class name) cluster-level triage decisions for the
# `TBD-needs-review` rows. After Phase C triage, every TBD row is matched
# by exactly one cluster below. The cluster's classification +
# first_tenant_exposed + acceptance reason apply to every annotation in
# that controller (modulo a per-method override below).
CLUSTER_TRIAGE = {
    # Pre-auth surface — tenant context does not exist yet. Routes are
    # rate-limited; checkEmail discloses email existence which is
    # accepted (alternative would break the registration UX).
    'AuthController': {
        'classification': 'accept-with-doc',
        'first_tenant_exposed': 'yes',
        'fix_pr_or_acceptance': 'accept-with-doc 2026-05-13 — pre-auth: tenant context does not exist before login/register; routes are rate-limited',
    },
    # Marketplace B2B peer discovery — cross-tenant reads are the feature.
    # The marketplace tables (marketplace_listings) have no tenant_id by
    # design; rows are the product of cross-tenant matchmaking.
    'MarketplaceListingController': {
        'classification': 'legitimate-platform',
        'first_tenant_exposed': 'yes',
        'fix_pr_or_acceptance': 'legitimate-platform 2026-05-13 — marketplace cross-tenant discovery is the feature',
    },
    # Parapharmacy regulatory reference catalogs — tables have no tenant_id
    # and are platform-shared. Write methods require admin permission per
    # routes.php; cross-tenant *data* leakage does not apply because the
    # data is intentionally global.
    'CertificationController': {
        'classification': 'legitimate-platform',
        'first_tenant_exposed': 'yes',
        'fix_pr_or_acceptance': 'legitimate-platform 2026-05-13 — platform-shared regulatory reference data (no tenant_id on table)',
    },
    'HealthClaimController': {
        'classification': 'legitimate-platform',
        'first_tenant_exposed': 'yes',
        'fix_pr_or_acceptance': 'legitimate-platform 2026-05-13 — platform-shared regulatory reference data (no tenant_id on table)',
    },
    'IngredientController': {
        'classification': 'legitimate-platform',
        'first_tenant_exposed': 'yes',
        'fix_pr_or_acceptance': 'legitimate-platform 2026-05-13 — platform-shared regulatory reference data (no tenant_id on table)',
    },
    'KeyComponentController': {
        'classification': 'legitimate-platform',
        'first_tenant_exposed': 'yes',
        'fix_pr_or_acceptance': 'legitimate-platform 2026-05-13 — platform-shared regulatory reference data (no tenant_id on table)',
    },
    # Public e-commerce product images — public route by design (B2C
    # storefronts read any tenant's catalog images). Rate-limited via
    # throttle:public-product-images.
    'PublicProductImageController': {
        'classification': 'accept-with-doc',
        'first_tenant_exposed': 'yes',
        'fix_pr_or_acceptance': 'accept-with-doc 2026-05-13 — public e-commerce catalog by design; rate-limited',
    },
}


def classify(rel: str, cls: str, reason: str) -> tuple[str, str]:
    """Returns (classification, first_tenant_exposed).

    Order matters: reason-based checks run BEFORE the
    FIX_NOW_CONTROLLERS controller-name heuristic so that a
    legitimate-platform annotation inside a fix-now controller (e.g.,
    Spatie team-scoped or platform-integration methods on RoleController
    / PurchaseHubOfferController) does not inherit the controller's
    fix-now classification.
    """
    # Super-admin / platform-level routes.
    if ('Http/Controllers/Api/Admin/SuperAdmin' in rel
            or 'SuperAdminController' in cls
            or 'super-admin' in reason.lower()
            or 'platform-level' in reason.lower()
            or 'platform-defined' in reason.lower()):
        return ('legitimate-platform-candidate', 'no')

    # Webhooks (signed external callbacks, intentionally tenant-agnostic).
    if 'Webhook' in cls:
        return ('legitimate-platform-candidate', 'no')

    # Static / public reference data.
    if ('Public reference data' in reason
            or 'Static enum' in reason
            or 'enum endpoint' in reason
            or ('Public ' in reason and ('load-balancer' in reason or 'health probe' in reason))):
        return ('legitimate-platform-candidate', 'no')

    # Permissions catalog (Spatie shared platform contract).
    if 'Permissions catalog' in reason or 'Permission::all' in reason:
        return ('legitimate-platform-candidate', 'no')

    # Spatie TeamScope auto-scoping (safe by Spatie design).
    if 'Spatie TeamScope auto-scoping' in reason:
        return ('legitimate-platform-candidate', 'no')

    # Platform-integration outbound (tenant tagged by header per
    # locked api.platform-integration cluster).
    if 'PurchaseHub outbound integration' in reason or 'Marketplace outbound' in reason:
        return ('legitimate-platform-candidate', 'no')

    # Lookup / monitoring controllers (tenant-tagged at HTTP-client layer).
    if cls in (
        'BarcodeLookupController',
        'VinDecodeController',
        'EnrichmentWebhookController',
        'MonitoringController',
        'StampDutyRuleController',
    ):
        return ('legitimate-platform-candidate', 'no')

    # M2.1-M2.5 named controllers — any annotation in these controllers
    # that has NOT been classified as legitimate-platform above is a
    # fix-now gap.
    if cls in FIX_NOW_CONTROLLERS:
        return ('fix-now', 'yes')

    # KNOWN GAP markers — needs human review whether to fix now or defer.
    if 'KNOWN TENANT-ISOLATION GAP' in reason:
        return ('TBD-needs-review-fix-likely', 'TBD')

    return ('TBD-needs-review', 'TBD')


def main() -> None:
    rows: list[dict] = []

    for php_file in API_ROOT.rglob('*.php'):
        text = php_file.read_text()
        if 'CrossTenantRoute' not in text:
            continue
        if 'Shared/Architecture/CrossTenantRoute' in str(php_file):
            continue
        if 'Application/Sweep/Visitors' in str(php_file):
            continue

        cls_match = CLASS_RE.search(text)
        cls = cls_match.group(1) if cls_match else ''
        for m in ANNOTATION_RE.finditer(text):
            line_num = text[:m.start()].count('\n') + 1
            reason = m.group(1).replace("\\'", "'").replace('\n', ' ').strip()
            tail = text[m.end():]
            meth = METHOD_RE.search(tail)
            method = meth.group(1) if meth else ''
            classification, fte = classify(str(php_file), cls, reason)
            rows.append({
                'file': str(php_file),
                'line': line_num,
                'class': cls,
                'method': method,
                'reason_excerpt': reason[:140].replace(',', ';').replace('"', "'"),
                'classification': classification,
                'first_tenant_exposed': fte,
            })

    # Apply cluster-level triage overrides.
    for r in rows:
        triage = CLUSTER_TRIAGE.get(r['class'])
        if triage is not None:
            r['classification'] = triage['classification']
            r['first_tenant_exposed'] = triage['first_tenant_exposed']
            r['fix_pr_or_acceptance'] = triage['fix_pr_or_acceptance']
        elif r['classification'] == 'legitimate-platform-candidate':
            # Auto-promote remaining candidates to locked
            # legitimate-platform with the script's classification
            # reason as the acceptance note (one-line per the M2.0
            # README's lock procedure).
            r['classification'] = 'legitimate-platform'
            r['first_tenant_exposed'] = 'no'
            r['fix_pr_or_acceptance'] = (
                f"legitimate-platform {TRIAGE_DATE} — "
                "auto-locked from generator heuristic (Spatie/super-admin/webhook/static-reference); "
                "see scripts/generate-cross-tenant-inventory.py classify()"
            )

    rows.sort(key=lambda r: (r['classification'], r['file'], r['line']))

    counts = Counter(r['classification'] for r in rows)
    print(f"total: {len(rows)}")
    for k, v in sorted(counts.items()):
        print(f"  {k}: {v}")

    OUT_PATH.parent.mkdir(parents=True, exist_ok=True)
    with OUT_PATH.open('w') as f:
        f.write(
            "file,line,class,method,route,reason_excerpt,classification,"
            "first_tenant_exposed,fix_pr_or_acceptance,owner,notes\n"
        )
        for r in rows:
            fix_pr = r.get('fix_pr_or_acceptance', 'TBD').replace(',', ';')
            owner = DEFAULT_OWNER if r['classification'] != 'TBD-needs-review' else 'TBD'
            f.write(
                f"{r['file']},{r['line']},{r['class']},{r['method']},TBD,"
                f"{r['reason_excerpt']},{r['classification']},"
                f"{r['first_tenant_exposed']},{fix_pr},{owner},\n"
            )
    print(f"wrote {OUT_PATH}")


def self_test() -> None:
    """Lightweight in-process unit test for the classify() heuristic.

    Run with `python3 scripts/generate-cross-tenant-inventory.py --self-test`.
    Closes Round 2 review NIT-2 without adding a pytest dependency.
    """
    cases = [
        # (file, class, reason, expected_classification, expected_fte)
        ('apps/api/app/Http/Controllers/Api/Admin/SuperAdminController.php',
         'SuperAdminController',
         'reads platform-level tenant list',
         'legitimate-platform-candidate', 'no'),
        ('apps/api/app/Modules/Stripe/Presentation/Controllers/StripeWebhookController.php',
         'StripeWebhookController',
         'External Stripe webhook callback, signature-verified',
         'legitimate-platform-candidate', 'no'),
        ('apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php',
         'RoleController',
         'Spatie TeamScope auto-scoping: role queries filter by team_id',
         'legitimate-platform-candidate', 'no'),
        ('apps/api/app/Modules/PurchaseHub/Presentation/Controllers/PurchaseHubOfferController.php',
         'PurchaseHubOfferController',
         'PurchaseHub outbound integration: tenant-tagged via headers',
         'legitimate-platform-candidate', 'no'),
        ('apps/api/app/Modules/Product/Presentation/Controllers/ProductImageController.php',
         'ProductImageController',
         'KNOWN TENANT-ISOLATION GAP — Product RMB without tenant scope',
         'fix-now', 'yes'),
        ('apps/api/app/Modules/Foo/Presentation/Controllers/SomeRandomController.php',
         'SomeRandomController',
         'no heuristic match — needs human triage',
         'TBD-needs-review', 'TBD'),
        ('apps/api/app/Modules/Foo/Presentation/Controllers/AnotherController.php',
         'AnotherController',
         'KNOWN TENANT-ISOLATION GAP — some unique gap',
         'TBD-needs-review-fix-likely', 'TBD'),
    ]

    failures: list[str] = []
    for path, cls, reason, expected_cls, expected_fte in cases:
        got_cls, got_fte = classify(path, cls, reason)
        if got_cls != expected_cls or got_fte != expected_fte:
            failures.append(
                f"  {cls} (reason '{reason[:40]}…'): "
                f"expected ({expected_cls!r}, {expected_fte!r}), "
                f"got ({got_cls!r}, {got_fte!r})"
            )

    if failures:
        print(f"self-test: FAIL ({len(failures)} of {len(cases)})", file=sys.stderr)
        for f in failures:
            print(f, file=sys.stderr)
        sys.exit(1)

    print(f"self-test: OK ({len(cases)} cases)")


if __name__ == '__main__':
    if len(sys.argv) > 1 and sys.argv[1] == '--self-test':
        self_test()
    else:
        main()
