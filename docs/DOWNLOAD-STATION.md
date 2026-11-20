# Optional Synology Download Station adapter

Download Station integration is independent from the NZB Manager DSM SPK.
It may be used from a standard Linux/PHP host when a NAS is configured.

**Not the same as Push To:** Push To copies an NZB into a monitored folder
or sends it to a configured HTTP endpoint. Download Station instead
authenticates to the Synology Download Station Task API and uploads an NZB
as a native download task, allowing Download Station to manage the download.
It does not simply drop an NZB into the configured workspace. If unused, this
optional module stays unavailable and requires no NAS credentials.

## Implementation status

The new DownloadStationClient supports:

- Discovery via SYNO.API.Info for SYNO.DownloadStation.Task and SYNO.API.Auth
- Authentication using a DownloadStation-scoped API session
- Upload of a valid NZB stored in the managed library
- Optional share-relative destination validation and logout after submission
- A separate module which stays unavailable when discovery fails

The tests mock discovery, authentication, upload and logout. They verify
handling of absent APIs, unsafe paths, invalid NZB data and HTTP restrictions.

**Actual Download Station submission on your NAS has not been tested yet.**
Some NAS versions advertise newer DownloadStation2 APIs that would need a
separate adapter.

## Optional connection configuration

Set the following only if Download Station is installed and you want to
submit managed NZBs to it:

    NZB_DSM_URL=https://nas.example.com:5001
    NZB_DSM_USER=downloadstation-user
    NZB_DSM_PASSWORD=nas-account-secret

These credentials are for the external Download Station API, NOT a login
for NZB Manager itself. The DSM NZB Manager test package requires no login,
password or installation setup wizard.

The integration remains disabled unless the server settings are present
and the remote NAS advertises the required Download Station task API.
It will recheck availability before submitting tasks.

## Security and testing

Only managed library documents may be submitted; the browser cannot supply
arbitrary filesystem paths or download URLs. HTTPS with validated certificates
is required for connections to the NAS except same-host loopback.

    D:\php8.5\php.exe tests/run.php

Live DSM API compatibility, permissions, certificate trust, and task creation
are outstanding. See docs/DSM7-PACKAGING.md.
