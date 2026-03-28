# CASAuth SCH for Joomla 5.4 / 6.0

**Status:** Greek School Network-oriented frontend CAS SSO plugin with fixed callback handling, bundled phpCAS 1.6.1, optional extra login button support, CAS account linking, and CAS logout orchestration.

## What it does
- Adds a dedicated Greek School Network login button to Joomla frontend login forms while preserving normal local account login.
- Uses a fixed callback URL suitable for registration in the Greek School Network SSO service.
- Creates or updates Joomla users from CAS attributes and stores a persistent CAS-to-Joomla link.
- Intercepts Joomla frontend logout and redirects the user to Greek School Network global logout.
- Lets administrators block automatic CAS relinking per Joomla user.

## Install
1. Copy the plugin folder to `plugins/system/casauth_sch`.
2. Ensure the plugin folder contains `vendor/autoload.php` and `vendor/apereo/phpcas/source/CAS.php`.
3. In Joomla Admin -> Extensions -> Plugins, enable **System - CASAuth SCH**.

## Greek School Network registration
- Register a single callback/service URL for your site.
- If you do not set `service_url` manually, the plugin uses:
  `https://your-site.example/index.php?option=com_users&view=login&casauth_sch=1`
- The CAS logout endpoint is expected to be:
  `https://sso-01.sch.gr/logout`
- If the Greek School Network requests demo-user restrictions, use the optional `allowed_umdobject` and `allowed_business_category` settings.

## Required configuration
- PHP 8.1+
- PHP `curl` extension
- PHP `dom` extension
- `host`: `sso.sch.gr`
- `port`: `443`
- `context`: `/`
- `ca_cert`: absolute path to a PEM CA bundle which validates the Greek School Network CAS certificate
- `logout_url`: `https://sso-01.sch.gr/logout`

## Optional configuration
- `service_url`: exact HTTPS callback URL registered in the Greek School Network
- `username_attribute`: leave blank to use the CAS principal
- `mail_attribute`: typically `mail`
- `fullname_attribute`: defaults to `cn` for Greek School Network
- `auto_create`: create Joomla users automatically when missing
- `sync_profile`: update local name/email from CAS on each login
- `allowed_umdobject`: comma-separated allowlist
- `allowed_business_category`: comma-separated allowlist
- `unit_code_attribute`: defaults to `edupersonorgunitdn-gsnunitcode-extended`
- `allowed_unit_codes`: comma-separated allowlist of school unit codes

## Frontend behavior
- Requests to the Joomla login view can start CAS automatically.
- Standard frontend logout routes are intercepted only for CAS-origin sessions.
- After Greek School Network logout, the user is returned to the same internal Joomla target used by the logout request.
- Failed CAS bootstrap attempts now return to the originating page instead of getting stuck on the Joomla callback view.

## Security notes
- This plugin refuses to run without a readable CA bundle for CAS certificate validation.
- Use HTTPS for the Joomla site itself.
- Keep a separate local Super User for backend access and recovery.

## Notes
- This plugin is frontend-focused. Backend login remains independent.
- The callback URL must be stable and under your control before you register it with the Greek School Network.
- The distributed plugin is intended to be self-contained. Composer is only needed if you want to refresh bundled dependencies during maintenance.
