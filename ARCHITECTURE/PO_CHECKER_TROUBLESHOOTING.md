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

If the browser shows a nonzero queue count but no action rows, sign into the
affected PO workspace and open `/dashboard?po_diagnostics=1`. This read-only
JSON report uses the website's own PHP process and database. It reports only
the signed-in user's queued requests, comparing action rows and permissions
on dashboard-loaded and freshly loaded orders. It includes a policy version,
PHP runtime, and an application-path fingerprint (not the path itself). The
CLI diagnosis prints the same fingerprint so different checkouts can be
distinguished. The response is private and must not be cached. It excludes
signature images and credentials. If it returns the ordinary dashboard rather
than JSON, the web process is not running the updated dashboard controller.

A new PO records its preparer's signature and completed handoff during creation.
Default assignees are copied from **Administration → PO Workflow Assignments**
into the PO approval record. Any user in the matching Checker, Noter, or Approver
workspace may act at that stage. The signature and actual actor are recorded
separately from the default assignee.

Check these conditions when a PO does not appear:

- The user must have the role for the current stage and select that workspace.
- Updating PO Workflow Assignments changes routing for new POs. It does not
  reassign pending POs that already contain approval records.
- A multi-role account must switch into its PO Checker workspace.
- The user must be active. A person with all required roles may prepare, check,
  note and approve the same PO, recording a separate signature at each stage.
- The preparer's signature and handoff must be complete. Older records with a
  missing signature or unconfirmed handoff require the preparer's action.
- A PO which has already been checked moves to the next stage and leaves the
  checker's action queue.

Do not infer a live PO's problem from another server's database. Review the
diagnostic output before changing assignments or existing approval records;
preserve completed signatures and the audit history.

The `po:assign-checker` command can change a default assignment when needed,
but a transfer is not required for role-based access. Use its preview before
applying a transfer; existing signatures and workflow statuses are preserved.

Local regression checks:

```sh
php artisan test --filter=PurchaseOrderCheckerFlowTest
```
