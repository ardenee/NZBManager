# Synology Download Station native API module

This is distinct from Push To. Push To copies NZBs into a monitored folder
or sends them to an HTTP destination. Download Station submits an NZB as
a **native DSM Download Station task** and can list, pause, resume and
remove tasks using the DSM API. It does not copy files to a watch folder.

## Detection and availability

NZB Manager uses unauthenticated SYNO.API.Info discovery. When the NAS
advertises SYNO.DownloadStation.Task and SYNO.API.Auth, the Download Station
module appears in navigation even if no account is configured. When the
package is not installed, the module remains unavailable.

The test NAS at 192.168.0.67 advertises:
- SYNO.DownloadStation.Task: v1–3, DownloadStation/task.cgi
- SYNO.DownloadStation2.Task: v1–2, entry.cgi
- SYNO.API.Auth: v1–7, entry.cgi

The current adapter uses the established SYNO.DownloadStation.Task v1–3
interface, which this NAS advertises, and does not yet implement the
separate DownloadStation2 JSON API.

## Connection

On DSM, Settings > Synology Download Station connection accepts credentials
for a **dedicated restricted DSM account** with Download Station privileges.
NZB Manager uses the same-host DSM API at http://127.0.0.1:5000, without
sending these credentials to external services. The username/password are
stored in the package-private download-station.json with mode 0600; the
password is never returned in the Settings page.

An existing Linux instance can instead use the PHP server environment:
NZB_DSM_URL, NZB_DSM_USER, NZB_DSM_PASSWORD. Remote NAS URLs must use
HTTPS with certificate verification; only local loopback may use HTTP.

**Security restriction:** The alpha NZB Manager website is password-free.
Its current HTTP portal must not be exposed publicly. Before entering
DSM account credentials from a browser, put the portal behind HTTPS and
access controls, and use a non-administrative Download Station account.
A password-free HTTP page cannot protect supplied DSM credentials from
someone monitoring the LAN.

## User operations

- Browse up to 50 tasks returned by DSM, displaying name, status, type,
  bytes and progress calculated from transfer counters.
- Upload validated NZB documents from NZB Manager's private library as DSM
  Download Station tasks; optional share-relative destination.
- Pause, resume, or remove a selected DSM task. Removal asks for confirmation.
- Scoped DSM authentication and logout on each operation; server-side
  credentials and session identifiers are not rendered in browser HTML.
- When no credentials are configured, the module shows an account-required
  state rather than falsely reporting a working connection.

## Validation status

- On DSM, the API.Info discovery has been verified and the module displays
  without any Download Station credentials.
- Mock API tests cover discovery, scoped login/logout, NZB submission,
  task listing and progress, and pause/resume/delete control requests.
- **Actual authenticated task creation and lifecycle have not been verified
  against the user's DSM account** because no credentials were supplied.
  Do not claim a successful real task submission without that live test.
- The REST/JSON DownloadStation2 API may need a dedicated adapter on NAS
  versions not advertising the legacy Task endpoint.

No MySQL database and no separate NZB Manager login are required.
