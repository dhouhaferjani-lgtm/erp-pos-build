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


def classify(rel: str, cls: str, reason: str) -> tuple[str, str]:
    """Returns (classification, first_tenant_exposed)."""
    if cls in FIX_NOW_CONTROLLERS:
        return ('fix-now', 'yes')

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
            f.write(
                f"{r['file']},{r['line']},{r['class']},{r['method']},TBD,"
                f"{r['reason_excerpt']},{r['classification']},"
                f"{r['first_tenant_exposed']},TBD,TBD,\n"
            )
    print(f"wrote {OUT_PATH}")


if __name__ == '__main__':
    main()
