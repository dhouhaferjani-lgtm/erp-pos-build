# Wave 3D inherited deptrac ratchet blocker

Owner: parent orchestrator / architecture gate owner  
Raised: 2026-08-19  
Blocks: promotion of Wave 3D to a `main`-bound CI run; does not attribute a violation to M4

The exact CI command is:

```text
php tools/deptrac-ratchet.php --config=deptrac.yaml --baseline=deptrac.baseline.json
```

At the pinned 3D base `48cebf0f2c1b481c592bf35d478302499f9fda5d`, it reports:

```text
TOTAL 99 174
BLOCKER — new Domain-tier leakage: ModuleDomain on ModuleApplication (35 -> 52, +17)
RESULT: FAIL — architecture boundary regression. See above.
```

The M4 tip reports the same 174 violations and the same failing categories. Intersection of the
machine-readable violations with M4-changed files found no M4-introduced edge: the changed-file hits are
pre-existing constructor/import edges in `ChartOfAccountsService` and `GeneralLedgerService` outside the
M4 hunks.

Three authorities disagree and require parent reconciliation before promotion:

- `apps/api/deptrac.baseline.json` totals 99;
- the Wave 3C/3D dispatch says the non-regression baseline is 111;
- the pinned 3D base already measures 174 and returns `RESULT: FAIL`.

Do not change the baseline or workflow in this lane. The parent must reconcile the accepted baseline and
ensure the hard CI ratchet is green before promotion.
