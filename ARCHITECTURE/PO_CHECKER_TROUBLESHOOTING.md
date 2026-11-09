# Purchase Order checker routing

Git push/pull deploys application code. It does not copy workflow assignments,
accounts, signatures, or Purchase Orders between databases. Run diagnostics on
the server that serves the affected site, using that deployment's application
directory or PHP container.

```sh
php artisan po:diagnose ASI004636 ASI004635
```

The command only reads records. It reports each PO's saved stage assignees,
current workflow routing, signature validity, preparer handoff, and the assigned
checker's queue and permissions in each available workspace. It does not print
signature images, credentials, or payment information.

A new PO records its preparer's signature and completed handoff during creation.
Its checker is copied from **Administration → PO Workflow Assignments** into the
PO approval record. The assigned checker can sign and submit remarks, advancing
the PO to the Noter and then Approver.

Check these conditions when a PO does not appear:

- The PO's saved checker ID must match the intended checker account. Assigning
  the PO Checker role to another account does not transfer existing POs.
- Updating PO Workflow Assignments changes routing for new POs. It does not
  reassign pending POs that already contain approval records.
- A multi-role account must switch into its PO Checker workspace.
- The checker must be active and cannot be the person who created the PO.
- The preparer's signature and handoff must be complete. Older records with a
  missing signature or unconfirmed handoff require the preparer's action.
- A PO which has already been checked moves to the next stage and leaves the
  checker's action queue.

Do not infer a live PO's problem from another server's database. Review the
diagnostic output before changing assignments or existing approval records;
preserve completed signatures and the audit history.

Local regression checks:

```sh
php artisan test --filter=PurchaseOrderCheckerFlowTest
```
