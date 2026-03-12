# CASAuth SCH for Joomla 5.4 / 6.0

**Status:** Prototype for testing in a controlled environment. Review before production.

## What it does
- Redirects users to the CAS server (default: `sso.sch.gr`) and logs them into Joomla on return.
- Optionally auto-creates Joomla users with attributes from CAS.
- Redirects logout to CAS global logout (`https://sso-01.sch.gr/logout?service=...`).

## Install
1. Copy the `plugins/authentication/casauth_sch` folder into your Joomla root (same structure).
2. SSH to that folder and run:
   ```bash
   composer install --no-dev --prefer-dist
   ```
3. In Joomla Admin -> Extensions -> Plugins, enable **Authentication - CASAuth SCH** and move it **above** the default `Cookie` plugin.
4. Configure:
   - Host: `sso.sch.gr`
   - Port: `443`
   - Context: `/cas`
   - Version: `3.0`
   - Validate SSL: Yes (recommended)
   - CA cert: (optional path to a PEM bundle)
   - Username attribute: (leave blank to use CAS username)
   - Mail attribute: `mail`
   - Fullname attribute: e.g. `displayName`
   - Auto-create: Yes
   - Default group: Registered
   - Logout URL: `https://sso-01.sch.gr/logout`

## Security notes
- Keep `Validate SSL` enabled.
- Consider keeping the plugin frontend-only to avoid locking yourself out of Admin; keep a local Super User account that bypasses CAS.
- Limit auto-provisioning with site-specific allowlists if needed (not implemented in this minimal build).
- phpCAS session cookies use your Joomla session; ensure HTTPS sitewide.

## Troubleshooting
- If you see "phpCAS not installed", run `composer install` inside the plugin folder.
- If redirects loop, check `site url` and the `service_url` parameter. Sometimes setting an explicit Service URL helps.
- For attribute debugging, temporarily enable Joomla debug and add logs around `phpCAS::getAttributes()` (remove afterwards).

## Uninstall
Remove the plugin folder or disable it from the Plugin Manager.
