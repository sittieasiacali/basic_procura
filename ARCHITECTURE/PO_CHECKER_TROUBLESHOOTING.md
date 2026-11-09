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
Its checker is copied from **Administration → PO Workflow Assignments** into the
PO approval record. The assigned checker can sign and submit remarks, advancing
the PO to the Noter and then Approver.

Check these conditions when a PO does not appear:

- The PO's saved checker ID must match the intended checker account. Assigning
  the PO Checker role to another account does not transfer existing POs.
- Updating PO Workflow Assignments changes routing for new POs. It does not
  reassign pending POs that already contain approval records.
- A multi-role account must switch into its PO Checker workspace.
- The checker must be active. The same person may prepare and check a PO when
  assigned to both stages, using the PO Checker workspace and a separate
  checking signature and remarks. Noting and final approval retain their
  existing creator restrictions.
- The preparer's signature and handoff must be complete. Older records with a
  missing signature or unconfirmed handoff require the preparer's action.
- A PO which has already been checked moves to the next stage and leaves the
  checker's action queue.

Do not infer a live PO's problem from another server's database. Review the
diagnostic output before changing assignments or existing approval records;
preserve completed signatures and the audit history.

To transfer the two pending POs from legacy checker 24 to account 30 and route
future POs to account 30, first deploy this code on the live server, then run:

```sh
php artisan po:assign-checker ASI004636 ASI004635 --user=30 --from-user=24 --update-routing --apply
php artisan po:diagnose ASI004636 ASI004635
```

Omit `--apply` for a preview. The command validates every requested PO before
changing any record and performs the batch in one transaction. It refuses
signed, acted-on, completed, or unexpectedly assigned checking steps and
inactive accounts or accounts without the PO Checker role. It changes only
the pending checker assignment and, with `--update-routing`, future checker
routing. Each change is recorded in the linked request's audit history with
the console as its source; signatures and statuses stay intact. Repeating the
same completed transfer creates no duplicate audit entry. No email is sent by
the transfer command.

Local regression checks:

```sh
php artisan test --filter=PurchaseOrderCheckerFlowTest
```
