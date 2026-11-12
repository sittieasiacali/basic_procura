# PROCURA Chat

The Laravel application is the shared source of truth for the website and Android/iPhone app.
The native Expo/React Native client is in mobile/procura-chat. The web interface is /chats.
The mobile API prefix is /api/mobile/v1.

## Confirmed workflow

- New submissions and resubmissions wait at FOR_OM_APPROVAL.
- Only an OM (including the legacy Operations Manager role) assigned to the request's campaign approves.
- The requestor's campaign is captured in chat_campaign_id at first submission. Older records use their requestor's campaign.
- OM approval follows the selected IT/Premises team and category, with the existing keyword/shared-queue fallback for older requests.
- Returning requires a reason and signature; the requestor corrects and resubmits to OM.
- SMT receives updates and can acknowledge the latest workflow message. Acknowledgement never advances or blocks a request.
- President supplier approval keeps the existing quotation selection, signature, row locking and audit workflow, then hands off to Purchasing at FOR_BUDGET_PROCESSING. Return goes to Canvassing for correction.
- Existing downstream requests are not moved backward by the new migration.

## Access and conversations

Administrators create OM, SMT and President accounts in Users. Assign an active campaign to each OM/SMT account.
The current account model supports one campaign per account; multiple workspace roles remain supported.
Requestor accounts must have an active campaign before submission.

List, detail, message, approval and attachment endpoints check access on the server.
OM/SMT cannot monitor another campaign, including through direct IDs or web document routes.
Existing specialist visibility rules are retained. The last handoff actor can access its request conversation.
Only the requestor, current handler, last handoff actor and an administrator can send messages.
SMT is read-only except for acknowledgement. Closed requests are read-only.
Files are private, authenticated downloads. New chat attachments accept PDF/JPEG/PNG/WebP, up to 10 MB.

A request has one conversation throughout its workflow. Stage changes and recorded remarks become system entries.
Messages support UUID retry keys; repeated approval attempts fail after the state changes.
Read positions only advance. Messages are paginated in batches of 80; request lists use batches of 30.

Live updates currently poll every four seconds while the app or tab is active. Background phone alerts use push notifications.
There is no WebSocket service to operate for this release. This polling interval should be reviewed for a larger deployment.

## Authentication

Mobile login accepts the existing email or username and password, then issues a Laravel Sanctum token valid for 30 days.
Tokens are hashed on the server and stored in Expo SecureStore on the phone.
Every API request rechecks account activity and the token's assigned workspace role.
Role changes are limited to the account's currently assigned roles.
Admin password resets revoke mobile sessions. Logout deletes the current session and its push registration.
Temporary passwords must be changed in the app before requests, chat and decisions are available.

## Deploy to the tunnel's serving copy

The development checkout is C:\xampp\htdocs\basic procura.
The supplied public URL is https://asiprocura.allianzsynergia.com.ph.
The user confirmed the tunnel points to a different server/folder.
A check during implementation found /login available, while /chats and /api/mobile/v1/me returned 404.
Consequently these local changes must be deployed to the serving copy before the mobile app can connect.

1. Transfer the changed application files, composer.json/composer.lock and built public/build assets through the normal deployment process. Preserve the serving copy's .env, APP_KEY and uploads.
2. Install PHP dependencies with composer install --no-dev --optimize-autoloader. Sanctum is locked in composer.lock.
3. Set APP_URL=https://asiprocura.allianzsynergia.com.ph (without /dashboard or /login).
4. Apply the new additive migration:
   php artisan migrate --path=database/migrations/2026_11_12_000100_create_procura_chat_tables.php --force
5. Clear old route/config caches and rebuild them if the deployment normally uses caches:
   php artisan optimize:clear
   php artisan config:cache
   php artisan route:cache
6. Use a persistent database queue worker:
   php artisan queue:work --tries=3 --timeout=90
   Restart existing workers after deploying code with php artisan queue:restart.
7. Create/verify active OM and SMT accounts and campaign assignments before accepting new submissions.
8. Confirm unauthenticated GET /api/mobile/v1/me returns JSON 401, rather than 404. Confirm signed-in /chats opens.
9. Verify one request through OM -> category screening, SMT acknowledgement, President -> Purchasing using non-production test requests.

The new migration has been applied to the local development database only.
Never run migrate:fresh or demo seeders on the serving database as a deployment step.

## Android and iPhone builds

See ../mobile/procura-chat/README.md.
The app defaults to the supplied public API URL:
https://asiprocura.allianzsynergia.com.ph/api/mobile/v1

An Expo/EAS project ID, Firebase FCM v1 credentials and Apple push/signing credentials have not been supplied.
No signed APK/IPA, store upload or physical-phone notification test has been completed.
The app source and Android/iOS JavaScript bundles can be built without those credentials.

## Push notifications

Server settings:

PROCURA_CHAT_PUSH_ENABLED=true
EXPO_ACCESS_TOKEN=<server-side Expo access token, when enhanced push security is enabled>
QUEUE_CONNECTION=database

Push remains off by default until credentials and device builds are configured.
Devices register their Expo token after sign-in and notification permission.
The backend queues alerts after the transaction commits, checks campaign and account access again at delivery,
and sends high-priority notifications on the procura-approvals Android channel with default sound.
SMT receives these alerts without holding the workflow.

Lock-screen text is generic. Tapping an alert opens the request; the server rechecks access.
Expo ticket and receipt failures are logged and DeviceNotRegistered registrations are removed.
Receipts are checked with a delayed queue job; use a real queue worker when enabling push.
Expo/Apple/Firebase delivery and phone silent/Focus/Do Not Disturb settings can prevent a sound or pop-up.
This is a normal push notification, not a call or an alarm that bypasses phone settings.

Official setup:
- https://laravel.com/docs/12.x/sanctum#mobile-application-authentication
- https://docs.expo.dev/push-notifications/push-notifications-setup/
- https://docs.expo.dev/push-notifications/sending-notifications/

## Verification

Focused backend tests: tests/Feature/ProcuraChatTest.php.
Coverage includes campaign isolation, native login/logout, temporary passwords, stale decisions, signatures,
category routing, optional acknowledgements, retries, attachment access, unread markers, pagination and push recipients.
Phone push requests are mocked in automated tests; they do not establish real-world notification delivery.

The repository had pre-existing failing legacy tests before this change; compare isolated baseline results separately.

Final verification (development checkout):
- 17 new PROCURA Chat feature tests passed.
- 87 focused tests passed with 1,063 assertions.
- Full suite: 418 tests; 354 passed, 64 failed (63 failures and one error).
- An isolated untouched checkout had 70 failing tests. Every remaining failure also failed in that baseline; there are no newly failing tests in the comparison.
- Web production build passed; TypeScript and Android/iOS bundle exports passed.
- Hidden Chrome checks passed for desktop/mobile layout, sending, text escaping, mandatory signature, approval update and phone-width back navigation.
- Browser checks used an isolated rendered fixture and mocked API responses. Backend behavior was tested separately against in-memory SQLite.
- Public tunnel login responded, but new chat/API routes returned 404. Deployment to the serving copy remains required.
