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

## OM and SMT activity emails

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
