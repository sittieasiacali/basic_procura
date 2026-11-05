# ASI PROCURA deployment readiness audit

Audit date: 2026-11-04 (Asia/Singapore). **Verdict: NOT READY for production acceptance.**

Scope: checked-out source, migrations, configuration, seeders, dependency locks, automated tests and deployment scripts. No records were deleted, no migrations or seeders were executed against the application database, and nothing was deployed. Existing unrelated working-tree changes were preserved. Tests use isolated SQLite databases. The private `.env` was inspected without printing its keys or passwords and was not modified.

## Findings ranked by severity

### High — required approval workflow is not implemented

The requested Request → OM → SMT → Finance → President sequence is **not** the current application flow. `app/Services/PurchaseRequestWorkflow.php::submitForScreening()` routes submissions to IT/Premises. `app/Policies/PurchaseRequestPolicy.php::reviewOm()` always denies OM approval. `database/migrations/2026_10_29_000100_route_management_approval_requests_to_screening.php` reroutes existing management approvals. `app/Services/WorkflowEmailNotifier.php` emails assigned OM/SMT recipients; notification is not approval. Budget finalization is allowed to Purchasing roles, not a required Finance-then-President approval chain (`reviewBudgetFinal()` and `BudgetController::finalizeBudget()`).

**Blocker:** obtain an approved process specification and implement/test the required sequence or explicitly accept the implemented process. Changing these rules during a technical audit would risk invalidating existing approvals and historical data; they were not silently rewritten.

### High — available production configuration and credentials need remediation

The supplied private `.env` declares production with debug disabled, but uses an HTTP application URL ending in `/login`, a root MySQL account with an empty password, no explicit secure session cookie, and debug-level single-file logging. These are verified for this workspace, **not independently verified on the tunnel server**. Credentials were also disclosed earlier in the conversation; rotation is not verified. Do not regenerate the application key blindly: encrypted data and sessions may depend on it.

Added `.env.production.example` with HTTPS, a dedicated DB user, secure cookies, database queues, rotating warning logs, and no secrets. Populate server-specific values privately. Confirm the tunnel terminates HTTPS, serves the intended checkout, and preserves secure redirects. Use a managed PHP/web-server service rather than an interactive `artisan serve` terminal for production availability.

### High — demo seeding previously reached production; existing records unknown

`database/seeders/DatabaseSeeder.php` previously called sample-account and transaction seeders unconditionally. `SampleUserSeeder` uses a known fallback demo password. **Fixed:** default production seeding now runs reference seeders only; directly invoked demo seeders refuse all environments except local/testing. `LocalPoUatSeeder` already has a local/database guard. Seeders remain available for isolated development.

This does not remove or disable existing sample users. They must be inventoried and reviewed on the actual server; `@example.com` addresses or sample-looking names alone do not prove current records are disposable.

### High — test suite is not green

Final full PHPUnit run: **294 tests; 220 passed, 73 failures, 1 error; 2,711 assertions**. Failures span account management, authentication UI expectations, dashboards, inventory, President queues, request lists, workflow, withdrawal, and routing. Some explicitly expect retired OM approvals or temporary Purchasing permissions; others require investigation. The account-management error is a missing user after an unsuccessful creation. Do not treat all failures as cosmetic or change expectations merely to obtain green tests.

Evidence: `storage/logs/deployment-suite-final.xml` (local, ignored). These failures block release sign-off.

### High — vulnerable dependencies (fixed in lockfiles)

Initial Composer audit: six advisories across Laravel, CommonMark and Symfony HttpFoundation/Mime/Yaml, including high-severity denial-of-service advisories. Initial npm audit: five affected dependencies, including two critical development-tool findings. Updated compatible locked versions without a major-version migration. Final Composer and npm audits report **zero advisories** at audit time. Laravel is now 12.71.0; Vite is 7.3.7. Runtime reachability differs from registry severity; development tools must not be publicly exposed.

Deployments must install the new locks (`composer install --no-dev`, `npm ci`, build assets); no deployment was performed. Vendored Bootstrap 5.3.8 is outside npm's lockfile advisory coverage; this audit is not a comprehensive third-party penetration test.

### Medium — unsafe test permissions and inactive sessions (fixed)

Removed the explicitly marked temporary Purchasing receiving privilege from `app/Policies/PurchaseRequestPolicy.php`. Finance/inventory/admin receiving behavior is retained. `EnsurePasswordChanged` now terminates authenticated sessions for deactivated users, including routes without role middleware. Login already rejects inactive accounts, regenerates sessions, and has route throttling. Broader Purchasing payment and final-budget permissions remain a business-process decision requiring sign-off.

### Medium — signature and export validation (fixed)

`WorkflowSignature` and `PurchaseOrderApproval` previously accepted arbitrary decoded text of sufficient length as a PNG signature. They now require PNG image metadata and bounded dimensions/pixel count before display processing. This is an authenticated drawn-signature workflow, not a cryptographically certified digital-signature system. Tests now submit actual PNG fixtures.

The normal budget CSV export lacked the formula escaping already used by its preparation variant. Both branches now escape formula-leading cells (`BudgetRequestExportController`). A regression test verifies supplier text cannot become an Excel formula.

### Medium — misleading printed signatories and reseeding bank details (fixed)

Budget sheets previously printed fixed named signatories. They now default to recorded requestor/approver accounts; explicit environment label overrides remain possible and require owner approval. `CampaignSeeder` now creates missing reference records without overwriting administrator-edited bank accounts or reactivating disabled campaigns.

`app/Models/Campaign.php::BUDGET_ACCOUNT_OPTIONS` still contains organization bank-account reference data. Its legitimacy was not inferred from its appearance; it was **preserved**. Obtain Finance verification and assess repository access. Historical migrations also write a placeholder Operations account and transform workflow states; review them before any fresh production install. No existing values or historical migrations were rewritten.

## Workflow, uploads and operational checks

- Implemented path: request → IT/Premises screening → Purchasing canvassing → President supplier review → optional PO → budget preparation/finalization → Finance payment → receiving. Inventory availability can fulfill a request directly.
- PO workflow has assigned preparer/checker/noted/approver accounts, stage authorization, row locking and signature checks. Sequential PO-number allocation and PO-derived budget selection/amount tests pass. `PurchaseOrder::isApproved()` requires an approved/issued status and issuance date; `BudgetController::store()` derives amount from the PO rather than trusting a submitted amount. Allocation rows snapshot source accounts. This is accounting allocation, not a verified external funding/bank transfer.
- Skipping a PO is explicitly supported (`PurchaseOrderController::skip()`); a BR may instead use approved quotation pricing. Mandatory PO issuance or a Finance/President funding chain therefore requires a business-rule change. BR preparation signatures are currently optional by configuration; final approval requires signatures. Concurrent BR numbering still needs MySQL/MariaDB race testing.
- Attachments use private storage, constrained uploads and authorized downloads (`AttachmentController`, request validators, `InventoryClaimController`). No public debug/PHP-info route was found. Antivirus/content scanning and real-server upload limits were not verified. Preserve legitimate logos, images, fonts, templates, campaign catalogues and units; no static assets were removed. Dashboard metrics inspected in `DashboardController` use database aggregates, not hardcoded financial figures. The unused Laravel welcome template is not a routed production feature.
- Queued handoff emails implement `ShouldQueue`, after-commit dispatch, retries and timeouts. SMTP credentials, successful delivery, failed-job backlog, and supervised worker operation require server verification. Scheduled infrastructure exists in `infra/compose.yaml`; no application `Schedule` jobs are defined in `routes/console.php`. Do not assume this schedules backups or monitors queue failures.
- Docker setup excludes dev dependencies/secrets from build context and uses non-root containers/private uploads. `infra/Backup.ps1` backs up database, uploads, manifests, release images and secrets, with verification tooling. This path was inspected, **not executed or restore-tested**; backup confidentiality, off-host copies, retention, scheduling, disk capacity and tunnel-server supervision remain unverified.
- `.env` is ignored and not tracked. A limited tracked-file secret-pattern scan found no matching private keys/access tokens; this is not proof that all history or external artifacts are clean. Never publish logs, backups or cleanup manifests. Credentials disclosed in chat remain a separate rotation requirement.

## Safe demo-data cleanup plan

**Do not use the earlier blanket SQL reset.** It cannot distinguish real transactions from test data.

Added `php artisan demo:cleanup-plan`: a **read-only planner with no deletion implementation**, even without `--dry-run`. It uses 114 exact request identifiers derived from the repository seeders, never broad `LIKE '%sample%'` matching:

- `REQ-SAMPLE-001` through `015`, plus six explicit PO sample request codes.
- Thirteen `REQ-UAT-<stage>-01..05` groups and five `UAT-PO-<stage>-01..05` groups.
- `REQ-BR-DEMO-001..003`.

The manifest identifies actual matching request IDs and linked items, approvals, quotations, PO lines/signatures, BRs, payments, receipts, inventory, releases/proofs, attachments and audit rows when run on the chosen DB. Exact sample-account emails are reported as review candidates, not removed. Shared grouped budgets/external stock are flagged; suppliers, bank references, campaigns, accounts and uploaded files are never deleted. Identifiers may have been reused manually, so review ownership/provenance before a separately authorized cleanup.

On the tunnel server, first take a consistent database backup **and** upload backup, protect the original application key, and verify a restore in isolation. An example XAMPP dump command (replace user and path; the password is prompted):

```powershell
& 'C:\xampp\mysql\bin\mysqldump.exe' --host=127.0.0.1 --user=BACKUP_USER --password --single-transaction --routines --events --triggers --result-file=C:\Procura-backups\before-demo-review.sql basic_procura
```

Then run from that server's application root:

```powershell
& 'C:\xampp\php\php.exe' artisan demo:cleanup-plan --dry-run --database=basic_procura --production-review --backup=C:\Procura-backups\before-demo-review.sql --output=storage/app/demo-review.json
```

The command checks the exact configured database, refuses production without explicit review and a nonempty backup, records its SHA-256, and refuses to overwrite a manifest. File existence/hash does not establish a restorable backup. The attempted workspace run was correctly refused for missing production backup safeguards; **no live IDs or demo counts are claimed**. Apply-mode deletion is intentionally unavailable. Review actual schema/foreign keys, mixed real/demo BRs, externally allocated inventory and pending notification jobs before preparing any deletion transaction. Never reset shared sequence counters as part of demo cleanup.

## Verification and release blockers

Passed: 56 targeted tests (667 assertions) covering the audit safeguards, formula escaping, signature images, inactive sessions, workspace roles, direct screening, notifications, VAT, PO numbers, PO-derived BR amounts, source accounts, finalization and HTTPS popup completion. PHP syntax checks for changed/new PHP files, Composer manifest validation, frontend production build, and final dependency audits passed. New command/seeder tests exercise isolated SQLite only.

Full suite remains failing as reported above. SQLite migrations ran during tests; production MySQL migration status and locking were not verified. No live cleanup/deployment occurred.

Remaining release gates: resolve approval-process mismatch; triage full-suite failures; privately correct and verify server configuration/rotate exposed credentials; run the backed-up demo inventory and review exact records; verify Finance reference accounts and PO assignments; validate MySQL migrations/concurrency, SMTP and supervised workers; confirm backup restore, monitoring, TLS/cookies and file-storage protections on the real server.
