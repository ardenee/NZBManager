# Optional Synology Download Station adapter

Status: IMPLEMENTED WITH MOCK TESTS; live DSM task submission is unverified.
The integration is independent of packaging NZB Manager as a DSM SPK.

It uses SYNO.API.Info discovery to check for both SYNO.API.Auth and
SYNO.DownloadStation.Task. The UI is hidden if the service cannot be found.

For remote DSM, configure these server-side settings:

    NZB_DSM_URL=https://your-nas:5001
    NZB_DSM_USER=dedicated_nzb_username
    NZB_DSM_PASSWORD=server_only_secret

Use a least-privilege account. API credentials stay on the PHP server and are
never returned in browser responses. The adapter requires HTTPS except for
same-machine loopback, and enforces TLS certificate validation.

Workflow: discover API versions/paths, create a DownloadStation session,
upload a validated NZB from the managed library, and logout. The action does
not accept arbitrary browser-supplied paths or file URLs. The selected DSM
destination is checked for unsafe traversal syntax.

Fixture tests cover discovery, absent APIs, login, upload, task response,
safe destination and logout. Real-world API permissions, version differences
and actual task submission must still be checked on DSM.

The newer SYNO.DownloadStation2 APIs are not yet integrated and may require a
later adapter. No Download Station package is installed or enabled by this SPK.
