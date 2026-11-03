# Basic Procura

A role-based procurement and RFP workflow built with Laravel, Blade, Bootstrap, and MySQL/MariaDB.

## Local requirements

- PHP 8.2 or newer (Laravel 12 supports PHP 8.2–8.5)
- MySQL 8+ or MariaDB 10.3+
- Composer

This XAMPP workspace uses `C:\xampp\php\php.exe`. A local `composer.phar` is included because Composer is not on the machine PATH.

## Setup

1. Copy `.env.example` to `.env` and set the database credentials.
2. Create the configured database.
3. Run:

   ```powershell
   & 'C:\xampp\php\php.exe' composer.phar install
   & 'C:\xampp\php\php.exe' artisan key:generate
   & 'C:\xampp\php\php.exe' artisan migrate --seed
   ```

4. Point the web server document root to `public`, or run:

   ```powershell
   & 'C:\xampp\php\php.exe' artisan serve
   ```

## Phase 1 sample accounts

All sample accounts use the password from `DEMO_USER_PASSWORD` (`Procura123!` by default). Change or remove these accounts outside local development.

| Role | Email |
| --- | --- |
| Requestor | requestor@example.com |
| Team Leader | teamleader@example.com |
| Canvassing Staff | canvassing@example.com |
| OM/SMTS Approver | approver@example.com |
| Procurement Staff | procurement@example.com |
| Finance/Budget Staff | finance@example.com |
| Inventory Staff | inventory@example.com |
| Admin | admin@example.com |

## Account registration

Use **Add New User** in Admin's dashboard or Manage Accounts. The compact dialog offers PURCHASING, FINANCE, REQUESTOR, IT, PREMESIS, PO CHECKER, PO NOTED, PO APPROVER, and ADMIN.

Employee ID, full name, email, role, and a temporary password are required. Username and Team ID are optional. Users can sign in with their email address or assigned username. Requestor accounts also need a campaign and the assigned OM/SMT notification addresses.

Auto-generate creates a random 16-character temporary password. Passwords require at least eight characters. New accounts must replace their temporary password before accessing PROCURA; Admin password resets also require a change at the next sign-in.

Use **PO Workflow Assignments** to select the default account for each PO stage. PO CHECKER, PO NOTED, and PO APPROVER accounts can act only at their corresponding stage on POs assigned to them.

### Multiple roles and workspaces

The **Add New User / Create Account** campaign dropdown uses the 41 operational campaigns in `AccountCampaignCatalog`. Its data migration adds missing names without renaming existing campaigns, changing bank account numbers, or reassigning users. Existing campaigns remain available when editing existing accounts. Budget Request bank account choices remain separate.

In **Manage Users / Manage Accounts**, use **Add New User** or **Edit** and check every role the person needs. For example, assign PURCHASING and TL / REQUESTOR to let one account prepare purchase orders and submit its own requests. Campaign and assigned OM/SMT emails are required whenever TL / REQUESTOR is selected, including as an additional role. Roles are managed by Admins; no employee names are hardcoded.

Accounts with multiple roles have **Switch role** in the profile menu. Selecting a role opens its dashboard and uses that role's permissions for subsequent requests. Each session remembers its selection; signing in again starts with the account's original/default role. Removing a role takes effect on the user's next request. Existing single-role accounts keep their original access. Manage PO stage defaults separately in **PO Workflow Assignments**.

This feature adds the `role_user` table. Before deploying, back up the server database, install dependencies and build assets as usual, then run `php artisan migrate --force`, `php artisan config:clear`, and `php artisan view:clear` before restarting the application. Preserve the server's `.env`, APP_KEY, database and uploads. The feature does not automatically assign new roles to existing accounts.

## OM and SMT activity emails

### Canvassing without quotations

In the Purchasing workspace, a request with no saved supplier quotations offers **Proceed without quotation** with an optional note. This sends it to the shared President review queue. Existing quotations continue through supplier recommendation and approval.

The main quotation Submit button also accepts completely empty Manual or Bulk rows: confirm the request code and destination to proceed without adding a quotation. Cancel leaves the form untouched; partially entered quotations still require valid details. Existing saved quotations are preserved. IT / Premises can complete their quotation handoff to Canvassing in the same way after recording inventory as unavailable. Request codes appear in the action modal header and handoff receipts for Screening, Canvassing, and President decisions.

The President dashboard shows newest handoffs first, offers an oldest-first sort, and paginates the full pending queue. **Canvassing processed** shows only requests personally handed off by the signed-in account, including submissions without quotations; its total uses the same account ownership rule.

Successful workflow submissions display a **Submission saved** popup with the request code, result and next/current stage. Choose **View request** for the read-only request preview or **Cancel** to dismiss the popup and remain on the current page. The saved submission is retained. This covers request submission, Screening, Canvassing, President review, PO steps, Budget, payment and receiving, including forms submitted inside dashboard modals. Failed validation does not display a success popup. Each receipt appears once, and grouped submissions list their request codes.

The President sees a missing quotation/pricing notice and can sign **Acknowledge & return to Purchasing** for follow-up. Acknowledgements are listed in their own history tab and do not select a supplier, approve a price, or generate a PO/Budget Request. Normal supplier approval still requires a specific quotation. Modal submissions show a handoff receipt and refresh the submitting dashboard; the President queue also provides **Refresh pending requests**.

Admin account creation and editing require an **OM notification email** and **SMT notification email** for Requestor / Team Leader accounts. Update existing TL accounts to assign these recipients. Other account roles do not require them.

Submitted and resubmitted purchase requests go directly to the selected IT / Premises screening queue; mixed or unclear requests without a selected team go to the shared screening queue. OM and SMT receive email updates only and do not approve, return, or edit purchase requests. Existing pending management approvals are migrated to screening.

Only the requestor/TL's assigned OM and SMT receive workflow emails. Staff account emails are used for sign-in/contact; they do not receive these alerts. The PROCURA notification bell is not used for workflow email alerts.

Submitting, resubmitting, or withdrawing a request queues an email to the assigned OM and SMT. Subsequent staff handoffs also notify the same pair: IT/Premises screening and quotations, Purchasing canvassing, supplier approval, PO checking/noting/approval, Budget Request preparation/finalization, Finance payment, receiving, and completion. Returns, rejection, and acknowledged withdrawals also send an update. Draft saves, ordinary edits, and signature saves without a handoff do not generate emails.

Messages queue after the database transaction commits and contain the stage plus a PROCURA sign-in link. Request details remain in PROCURA. Identical OM/SMT addresses receive one email per update. Reconfirming the same PO handoff does not queue a duplicate email for the same recipient pair.

Configure the SMTP sender in the local `.env` file:

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=smtps
MAIL_HOST=mail.allianz-synergia.com
MAIL_PORT=465
MAIL_REQUIRE_TLS=true
MAIL_TIMEOUT=30
MAIL_USERNAME=businessdev.app@allianz-synergia.com
MAIL_PASSWORD=replace-with-mailbox-password-or-app-password
MAIL_FROM_ADDRESS=businessdev.app@allianz-synergia.com
MAIL_FROM_NAME="${APP_NAME}"
```

Replace the password placeholder locally. `MAIL_SCHEME=smtps` uses implicit SSL/TLS on port 465. Laravel uses the `MAIL_*` settings shown above for the supplied SMTP mailbox. Set `APP_URL` to the application's browser URL so notification links reach the correct site.

The local app uses the database queue. After configuring the credentials, clear cached configuration and run the worker in a terminal or managed background service:

```powershell
& 'C:\xampp\php\php.exe' artisan config:clear
& 'C:\xampp\php\php.exe' artisan queue:work --sleep=3 --tries=3 --timeout=45
```

Mail delivery is retried up to three times. Queue failures are recorded in `failed_jobs`; missing recipient assignments are recorded in the application log. Restart running workers after changing SMTP credentials.

## Verification

```powershell
& 'C:\xampp\php\php.exe' artisan test
& 'C:\xampp\php\php.exe' vendor\bin\pint --test
```

Phase 1 provides the authentication, authorization, database, model, and workflow-status foundation. Business modules are implemented in subsequent phases.
