# Admin transaction deletion

Admin-only Delete links on request cards and workflow registers open a review page
for the entire purchase request. The page lists affected record counts and any
inventory release reversals. Deletion requires a reason, explicit confirmation,
and a token representing the reviewed records. The service locks and rechecks
the records before changing anything; a changed transaction requires a new review.

`AdminTransactionRecords` follows foreign-key dependencies among procurement
models and includes polymorphic attachments and exclusively owned budget groups.
Accounts, roles, campaigns, suppliers, delivery locations, and existing audit
history are retained. Shared budgets and records owned by another request block
the operation instead of deleting another transaction's data.

`AdminRecordDeletion` stores complete workflow snapshots, including recorded
signatures, in an `ADMIN_TRANSACTION_DELETED` audit entry. Linked database rows
are removed in dependency order, and the purchase request is soft deleted.
Uploaded evidence files are retained. There is no automatic restore screen.

Legacy screening releases are identified by their system-generated request
remarks and the screening assets' stock IDs. When a release used surviving stock,
its recorded quantity is restored and a `RELEASE_REVERSED` inventory activity is
added. Deletion, snapshots, and stock changes share one database transaction.

The requestor and assigned OM/SMT must have valid email addresses. Email addresses
are normalized and deduplicated per request. `AdminRecordDeleted` queues email
and database notifications after commit; matching active accounts receive bell
alerts. A running queue worker is required. Notification links point to the
dashboard, since the deleted request is no longer available.
