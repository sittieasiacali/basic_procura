# PROCURA Chat for Android and iPhone

A native Expo / React Native app using the same ASI PROCURA backend as the website.
Includes secure sign-in, temporary-password replacement, assigned workspace selection,
request conversations, PDF/image attachments, request and supplier details, signatures,
OM/President decisions and optional SMT acknowledgement.

The API defaults to:
https://asiprocura.allianzsynergia.com.ph/api/mobile/v1

The tunnel currently serves another server/folder. Deploy the Laravel changes there first;
see ../../ARCHITECTURE/PROCURA_CHAT.md. A JSON 401 from /api/mobile/v1/me is the expected
unauthenticated response. A 404 means the new routes have not reached the serving copy.

## Development

Use Node 22 or newer and install the locked dependencies:

npm ci
npm run typecheck
npm run start

The Android and iPhone bundles can be verified with:

npm run export

Run native builds using Android Studio or Xcode, or use EAS Build.
Windows can build Android locally; iOS compilation/signing needs macOS/Xcode or EAS.

## Configure a device build

1. Sign in to your organization's Expo account with npx eas-cli login.
2. Create/link the app's EAS project with npx eas-cli init. Record the project UUID.
3. Put the following values in a local .env file (use .env.example as a template),
   and set the same values for your EAS build environment:

EXPO_PUBLIC_PROCURA_API_URL=https://asiprocura.allianzsynergia.com.ph/api/mobile/v1
EXPO_PUBLIC_EAS_PROJECT_ID=<project UUID>

Only public configuration belongs in EXPO_PUBLIC variables. Never put service-account keys or passwords there.

4. Confirm com.asiprocura.chat is the intended Android package and Apple bundle identifier in app.config.js before the first signed build.
5. Configure Android Firebase FCM v1 and Apple APNs/signing credentials using npx eas-cli credentials.
   Upload credentials using Expo's supported credential flow; keep credential files outside this repository.
6. Build for internal testing:

npx eas-cli build --profile preview --platform android
npx eas-cli build --profile preview --platform ios

Internal iOS distribution needs registered devices. For TestFlight, use the production build and Apple account:

npx eas-cli build --profile production --platform ios

No builds are automatically uploaded or published by this repository.

## Phone alerts

Test push on physical devices with a development/internal build; Expo Go is not sufficient.
Sign in and allow notifications. Android creates the "PROCURA approvals and updates" channel.
If registration fails or permissions are disabled, the app shows a notice with Retry and Settings actions.

Enable PROCURA_CHAT_PUSH_ENABLED on the Laravel server only after push credentials are configured.
A persistent Laravel queue worker must be running.

Test on BOTH platforms:
- OM receives a notification for a request in its campaign.
- SMT receives a pop-up/sound and can acknowledge without delaying OM approval.
- OM approval goes to the correct IT/Premises category.
- President can inspect supplier quotations, sign, approve, or return for correction.
- Another campaign cannot be opened by changing a request ID.
- Tapping an alert opens its request after authentication.
- Logout revokes that phone's token and device registration.
- Disabled/removed role assignments no longer allow access.
- Silent mode and Do Not Disturb behavior is understood by approvers.

Notifications request default sound and banner presentation; phone settings and platform delivery determine the actual alert.

Official guide: https://docs.expo.dev/push-notifications/push-notifications-setup/

## Current verification limits

TypeScript and both platform bundle exports can be verified locally.
No Apple/Firebase credentials or Expo project ID were available during implementation.
A signed installable APK/IPA and physical-device notification delivery therefore still need configuration and testing.
