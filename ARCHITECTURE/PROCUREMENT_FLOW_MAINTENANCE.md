# Procurement workflow maintenance

This document records the flow confirmed by the system owner and the current
application behavior. Update it and the relevant tests whenever a rule changes.

## Confirmed flow

1. A purchase order is prepared, checked, noted and approved, in that order.
2. The Budget Maker prepares a Budget Request, which goes through final approval.
3. Finance processes payment once the request is ready for payment and its
   Budget Request is approved. Partly paid requests have a separate queue.
4. After payment, goods are received and recorded in inventory.

The active Checker, Noted or Approver workspace determines access to pending PO
work. Users can perform multiple PO stages on the same order when an administrator
has granted the corresponding roles. Each stage requires its own signature;
the actual actor is recorded. A signature saved by one user cannot be submitted
as another user's action. Default PO stage assignees do not limit role access.

The Finance awaiting-payment queue also requires the purchase request's status
to be For Payment, a qualifying approved Budget Request, and no payment yet.
Search/date filters and the user's visibility permissions also affect the list.

## Checks on every change

GitHub Actions runs the PO, Budget Maker, and receiving/inventory feature tests
on pushes and pull requests. These tests use an isolated in-memory SQLite
database and test mail, session and cache settings from `phpunit.xml`. They do
not connect to live POs or send real messages. To run the same checks locally:

```sh
php artisan test tests/Feature/PurchaseOrderCheckerFlowTest.php tests/Feature/BudgetMakerSelectionTest.php tests/Feature/ReceivingInventoryTest.php
```

The checks cover role switching, PO handoffs and signatures, multiple PO queue
pages, budget preparation/final approval, payment and receiving. They are not
an end-to-end browser test of every user journey or a test of the live tunnel.
Review the GitHub Actions result before deploying; branch protection must be
configured separately by a repository administrator to require the check.

The broader `PurchaseRequestWorkflowTest` suite has known failures, including
fixtures that omit quotation attachments now required for submission. Investigate
and fix those failures, then include the suite in the required checks. A green
focused check does not mean that the full test suite has passed.

## Before deploying

- Confirm the expected role, handoff and signature rules with the system owner.
- Add or update regression tests for the affected stage and the next handoff.
- Review the GitHub Actions result and test the deployed version on staging.
- Check the live server's checkout, release and database before troubleshooting;
  code deployment does not transfer POs or workflow records.
- Use read-only diagnostics first. Never reset or seed the production database.

The detailed request submission and early screening rules, rejection/return
paths, live monitoring requirements, staging environment and recovery ownership
still need confirmation. Do not infer these rules from the abbreviated flow.
