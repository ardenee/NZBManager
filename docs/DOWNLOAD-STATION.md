# Optional Synology Download Station adapter (planned)

The Download Station integration is independent from packaging NZB Manager as
a Synology SPK. NZB Manager should work on an ordinary Linux server and submit
NZB documents to a separately configured DSM 7 NAS, if appropriate.

## Activation policy

- Never assume Synology DSM itself means Download Station is installed.
- Do not show a functioning Download Station submission module merely because
  NZB Manager is running on DSM.
- Keep the module disabled when no NAS connection is configured, Download
  Station is not installed, its API is missing or unreachable, credentials
  are invalid, or the installed API version is unsupported.
- Query SYNO.API.Info for Download Station task capabilities, rather than
  assuming a hardcoded endpoint or protocol version.
- Recheck capability and authorization before submitting a task; cache only
  status for a short period, not active authentication sessions.
- Support HTTPS, verified certificates, and explicit private CA configuration
  for NAS devices using internally issued certificates.
- Keep the username/password or API secrets in server-side settings, never
  include them in the browser response or source control.
- Use POST with CSRF and an explicit user-selected, managed NZB identifier.
  Do not permit arbitrary browser-supplied file paths or remote URLs.
- Validate upload size, destination, task responses, API errors and lifecycle.

## Current status

Download Station submission is NOT implemented in this alpha. The module
remains unlinked in ModuleRegistry and cannot be enabled or used accidentally.
The future adapter will be added and tested independently. No DSM 7 SPK
builder is part of this step.

DSM 7 has both legacy SYNO.DownloadStation.Task and newer
SYNO.DownloadStation2.* interfaces on some versions. API discovery is a
necessary starting point, but does not by itself guarantee authenticated
task creation will work: that requires live testing on an installed DSM NAS.

Official references:
- https://kb.synology.com/en-us/DG/DSM_Login_Web_API_Guide/2
- https://kb.synology.com/en-us/DSM/help/DownloadStation/download_create
- https://www.synology.com/en-us/dsm/packages/DownloadStation

See docs/DSM7-PACKAGING.md for the separate, deferred SPK release workflow.
