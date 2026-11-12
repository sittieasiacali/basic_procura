# PROCURA Chat

PROCURA Chat is served by the existing Laravel application at /chats.
The Expo/React Native app source and its Android/iPhone build configuration have been removed.
The next mobile-responsive website and browser notification changes are paused pending the user's approval.
No PWA install flow or background web-push delivery is included in this cleanup.

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

Live updates poll every four seconds while the browser tab is active. Background phone alerts are not currently provided by the website.
There is no WebSocket service to operate for this release. This polling interval should be reviewed for a larger deployment.

## Authentication and compatibility

The website uses the existing session login, active workspace role and temporary-password checks.
The authenticated /api/mobile/v1 endpoints remain available for compatibility and continue enforcing campaign and role access.
API tokens remain hashed, expire after 30 days and are revoked by logout or administrator password resets.
Native device registration now returns HTTP 410 and stores no new push credentials.
Existing database tables and already-applied migrations are retained; this cleanup does not delete production data.

## Notifications

SendProcuraChatPush still creates the existing in-system notification entries for eligible recipients.
It no longer sends any external native push requests, regardless of old PROCURA_CHAT_PUSH_ENABLED or EXPO_ACCESS_TOKEN settings.
CheckProcuraPushReceipt is a no-op compatibility handler for any previously queued receipt jobs.
Campaign, role, active-account and request permissions still determine the recipients.
A persistent Laravel queue worker is still needed for queued in-system notifications.
The previous native APK and external cloud build/credential records are not deleted by removing source code.

## Deploy this cleanup on the serving Windows/XAMPP server

The development checkout is C:\xampp\htdocs\basic procura.
The serving checkout is C:\xampp\htdocs\basic_procura_github.
The public site is https://asiprocura.allianzsynergia.com.ph.

1. From the serving repository root, run git pull --ff-only origin main.
2. Run php artisan optimize:clear and php artisan queue:restart.
3. Ignored local mobile dependencies, native .env files, downloaded Firebase client files and exported bundles can remain after Git removes tracked source.
   Remove only the leftover C:\xampp\htdocs\basic_procura_github\mobile\procura-chat directory after confirming its resolved path.
   Change the terminal's directory to the repository root first, and close editor/terminal windows using the native app folder.
4. npm cache clean --force can reclaim downloaded package cache space. No global Expo installation was required by the npx workflow.
5. Confirm that signed-in /chats still opens. Existing active queue workers must reload the cleanup before using the new notification code.

No new migration or web asset build is needed for this cleanup. Preserve the server .env, APP_KEY, uploads, database and XAMPP installation.
Do not run migrate:fresh, drop tables or delete historical migrations to remove the native app.
Old native push environment entries may be removed from the server .env if desired; the application no longer reads them.
Cloud accounts, Google service-account keys and Expo credentials are separate resources and are not removed automatically.

## Verification

Run php artisan test --filter=ProcuraChatTest for campaign isolation, API compatibility, approvals, signatures,
attachments, unread markers, web chat and in-system notification recipient behavior.
Native notification tests assert that old settings and receipt jobs cannot make external push requests.
The repository has pre-existing failing legacy tests; compare broad-suite results with the baseline separately.
