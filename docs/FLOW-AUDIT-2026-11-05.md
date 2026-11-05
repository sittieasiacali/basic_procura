# Overview layout and procurement flow audit

Date: 2026-11-05, Asia/Singapore.

The affected accounts are on another server. That server was not accessed; its exact problem is not confirmed. This audit examined this checkout, the local Docker deployment, and a separate HTTP test instance. No production workflow or CSS was changed, and no test transactions were added to the cleared Docker database.

## Findings

### Confirmed: long account text can overlap adjacent overview fields

`resources/css/app.css:3171` uses a wrapping flex container for the Role/Team/Department/Status bar. Its children have `flex: 1 1 0` and `min-width: 8rem`, but the `dd` values at line 3210 have no long-word wrapping or overflow containment. The greeting and its flex item also lack equivalent protection.

The source HTTP instance reproduced overlapping context values with synthetic unbroken strings of 192 characters, within the account department/name length limits. At a 1366 px viewport the document expanded to 2660 px. At 390 px it expanded to 1669 px. Reproduced at 1920, 1366, 1024, 768, and 390 px. These are deliberately stressful values, not a claim about the affected account's actual content. The instance served the existing built frontend assets; the corresponding source selectors have the same missing wrapping rules.

This explains how two accounts with the same role can render differently: their names, department/campaign values, action counts, and visible records are different. If normal values wrap cleanly but one long value does not, a role change is unnecessary to trigger the problem.

Recommended correction: add appropriate `min-width: 0`, maximum widths, and `overflow-wrap: anywhere` to the context values and heading flex item. Test long real values, larger browser fonts, and embedded forms. Do not hide the whole page's horizontal overflow to conceal the defect.

### Confirmed: the main overview is request-based, not automatically synchronized

`DashboardController` reads the database when a page loads. `resources/js/app.js` does not poll or subscribe to changes for dashboard metrics or action tables. Another user's completed action will therefore not automatically appear in an already-open overview.

The Live remarks dialog polls every 30 seconds while open; its badge polls every 60 seconds. These timers do not refresh the overview. Empty-state wording suggesting the queue "updates automatically" overstates this behavior.

Recommended correction: agree on a refresh interval or a server-push design, show the last update time, preserve filters/pagination, and avoid refreshing an open signing form. This is a product behavior change, not simply a cache-clear command.

### Confirmed: role labels alone do not determine the same interface

- `User::activeRole()` and `ResolveWorkspaceRole` use a role selected in each browser session. Two accounts with the same default role can have different active workspaces.
- `User::hasPoRole()` also considers legacy PO assignments. Dedicated PO workflow users can have different signing stages despite sharing the same role.
- `resources/views/layouts/app.blade.php:40` treats any PO assignment as a PO approver for navigation. `DashboardController` determines its PO dashboard through the effective action queue. These are different predicates and can produce inconsistent navigation/dashboard combinations, especially with multiple roles.
- `RoleSeeder` names both `finance` and `finance-budget-staff` "Finance", although their permissions differ. Compare role IDs and slugs, not only the label shown on screen.
- Personal history uses the actor/requestor ID; screening work can be assigned to an individual. Different records for two same-role users can be intentional.
- `EnsurePasswordChanged` redirects accounts that still need a password change. The local browser audit observed this expected difference for one account.

### Confirmed locally: the deployed application differs from this checkout

SHA-256 comparisons differed for DashboardController, User, PurchaseRequest, PurchaseRequestWorkflow, dashboard Blade, app.js, app.css, and the Vite manifest. The local Docker migration history also lacks the newer November 3 migrations present in this checkout.

This proves local deployment/source drift. It does not establish which build is deployed on the other server. Editing files in this IDE does not update an image-based deployment automatically. Verify the actual release, migration status, and the CSS/JS URLs returned by both browsers. A stale browser page, a partial release, or missing built assets are additional possibilities, not confirmed remote causes.

### Confirmed: browser state is shared across accounts on one browser origin

`resources/js/app.js` uses origin-wide localStorage keys for Live remarks seen time, collapsed BR groups, and BR zoom. The keys do not include the authenticated user or active workspace. An account used later in the same browser can inherit another account's preferences or read marker.

This can explain different expansion/zoom/read states. It does not by itself prove that the Overview layout overlap comes from those keys. Scope keys by user and workspace if those preferences should be private to each account.

### Confirmed: budget handoff wording contradicts the actual owner

`BudgetController::store()` transitions to the legacy-named `AWAITING_PRESIDENT_FINAL_APPROVAL` state and returns "It is now waiting for Finance." The final-budget route and authorization instead allow Purchasing roles. The primary HTTP test confirmed Purchasing finalized the BR and moved it to Finance payment.

Use one business label and one responsible owner in success messages, status labels, emails, and dashboards. Do not reassign approval rights merely to match the current message.

## Validation performed

### Local Docker browser checks

Used short-lived audit sessions rather than changing account passwords. Checked 20 existing accounts at 1920, 1366, 1024, 768, and 390 px: 100 page loads. Results: 95 authenticated dashboards and five expected password-change pages. No document-width overflow, measured header/context/KPI/tab sibling collisions, clipped sampled labels, or JavaScript exceptions in these normal-content cases. The transaction database was empty during these checks, so these results are not coverage of populated queues, every modal, or every form.

The two local Purchasing accounts with the same role had matching main headings and sections at 1366 px. This does not reproduce the remote account pair.

### Separate source HTTP instance

Created a new SQLite database and independent storage outside the repository. Only reference data and isolated audit accounts were created. Mail used the array driver; no outbound email was sent. Requests used real HTTP, sessions, CSRF validation, policies, controllers, and uploaded image evidence.

The following main path passed:

1. Requestor submits a signed IT request with item evidence.
2. Duplicate request submission is denied.
3. Direct IT screening works without a separate claim; trying the unnecessary claim is denied.
4. Inventory unavailable sends the request to Purchasing canvassing.
5. Purchasing Team records a recommended quotation.
6. President approves the supplier.
7. Purchasing Team prepares and signs the PO.
8. PO Checker, Noter, and Approver sign and advance it.
9. Purchasing Team creates the BR.
10. Purchasing finalizes the BR.
11. Finance records a partial payment; the request remains For payment.
12. Finance clears the balance; the request moves to For receiving.
13. Inventory receives the goods; the request becomes Completed.
14. Repeated receiving is denied and does not create duplicate inventory or receipts.
15. Requestor can view the completed request.

Outcome: one inventory line for the PO item and two payments. This establishes one main-path success. It does not certify no-PO, available-stock fulfillment, rejection/return, withdrawal, batch/group BRs, concurrency, real SMTP, remote MySQL behavior, or every role combination.

### Complete existing PHPUnit suite

294 tests: **220 passed, 73 failed, 1 errored; 2,711 assertions**. Ran against in-memory SQLite with an ephemeral test-only application key. The initial run lacked an application key and was discarded; no server key was changed.

| Test class | Failed or errored cases |
| --- | ---: |
| AdminAccountManagementTest | 13 |
| AuthenticationTest | 1 |
| DashboardTest | 6 |
| InventorySkuTest | 1 |
| PresidentApprovalQueueTest | 3 |
| PurchaseRequestListTest | 4 |
| PurchaseRequestWorkflowTest | 44 |
| RequestWithdrawalTest | 1 |
| ScreeningAutoRoutingTest | 1 |

The account-management error occurs when the test cannot find the user it expected to create. Several tests expect retired OM approval or a separate claim for directly routed IT requests; the current policy intentionally denies that claim. Other failures need individual triage. Do not classify all 74 unsuccessful cases as stale assertions or weaken permissions to make them pass. The full suite is not green.

## Redundant, legacy, or confusing flow areas

| Area | Evidence and recommendation |
| --- | --- |
| Request-item quotation vs later canvassing | Submission requires item quotation/specification text plus approval evidence; Purchasing later records formal quotations again. Distinguish requestor reference/specification from Purchasing's commercial quotation, and carry forward usable evidence instead of asking users to re-enter it. These may be different business documents. |
| OM/SMT approval steps | The current submission path goes directly to IT/Premises. OM review routes, role descriptions, old states, tests, and the older browser E2E script still describe management approval. Remove obsolete active instructions after the intended process is agreed; retain historical audit data. |
| IT claim before screening | Direct IT requests are screenable without claiming. A separate Claim button would be an unnecessary action and is denied by policy. Premises/shared classification still has a claim path. |
| Budget Maker/Checker/Proceeder stages | Legacy states/roles remain, while the new tested path is BR preparation followed by Purchasing finalization. Clarify which are historical compatibility paths and which users should actually follow today. |
| Signature save plus action | PO controllers support signing within the action and also expose separate signature-save endpoints. Prefer one signed handoff for normal use; keep separate saving only for a deliberate draft-signature use case. |
| Dashboard, request-flow, specialist queues | Multiple entry points show overlapping records and offer different filters. This is navigation redundancy, not proof of duplicated transactions. Use consistent queue eligibility and one obvious primary entry point for each role. |
| Several receiving entry points | Finance can combine final payment and receiving, or receive later; Inventory has its own receiving path. These are valid alternatives sharing GoodsReceiving. Do not remove them solely because multiple screens reach the same service. The tested path prevented double receiving. |
| Final-budget owner labels | "President final approval", "waiting for Finance", and Purchasing authorization describe the same handoff differently. Align wording around the actual approved process. |

## Recommended order of work

1. Correct long-value wrapping and reproduce the affected account content at its actual browser width/zoom.
2. Compare both remote accounts' effective workspace, role slug, PO assignments, current filters, and browser state.
3. Verify both browsers receive the same release and built assets from the intended server.
4. Align workflow labels and retire obsolete active instructions.
5. Triage the failed tests by current business requirements, then add the desired overview refresh behavior.

No approval-chain changes, live deployment, database migrations, or application CSS fixes were performed during this audit.

## Evidence and rerunning

Private artifacts: `%LOCALAPPDATA%/Procura/audits/flow-20261105/` contains normal-layout results, stress-layout results, screenshots, the isolated test database, and HTTP step results. Session cookies and the isolated test environment key in that directory are temporary/private and must not be committed. Audit-created live sessions are removed after testing.

Ignored local logs: `storage/logs/flow-audit-20261105.xml`, `flow-audit-20261105.txt`, and `overview-audit-20261105.txt`.

Reusable audit helpers are in `tests/Browser/overview-audit.mjs`, `flow-audit-setup.php`, `flow-audit-server.php`, and `flow-http-audit.mjs`. Setup requires a private directory without an existing `flow.sqlite`. The HTTP server binds to localhost port 18474. `PROCURA_AUDIT_DIR` selects the private directory. The browser helper additionally requires a separate headless Chromium debugging endpoint, and receives audit sessions through a private JSON file. The HTTP script expects setup and the isolated server to be running and never points at the live application.
