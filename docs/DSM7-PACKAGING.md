# DSM 7.2+ password-free test package

The modern NZB Manager now has an optional DSM 7.2+ SPK test installer.
This is not a production-certified release, and important legacy functions
are still being ported.

## Passwords and access

**There is NO app login and NO installation password prompt on DSM.**
The SPK contains no WIZARD_UIFILES, creates no password hash, and does
not require any application credentials.

On installation and upgrade the scripts create this private file:

    /var/packages/nzbmanager/var/no-auth

The PHP application recognizes the marker and skips Basic authentication.
Ordinary non-DSM Linux installations retain their independently configurable
authentication.

**Security warning:** A Web Station site is NOT automatically protected by
DSM account login. Anyone able to reach the site can inspect, upload, export,
and delete NZBs and potentially use configured integrations. Restrict the
portal to your trusted LAN using DSM firewall or reverse-proxy controls. Do
not expose the password-free test package to the public internet.

## Package requirements and layout

- DSM 7.2+ with Web Station and Synology PHP 8.2
- PHP extensions for cURL, DOM/XML, mbstring, JSON, session and OpenSSL
- Correct permission support for package users and PHP-FPM
- HTTPS is recommended even on the local network

The Web Station service uses nginx_php and PHP profile backend ID 10 for
PHP 8.2. Its document root is restricted to:

    /var/services/web_packages/nzbmanager/public

The rest of the source is outside the document root. The private NZB library,
session files and no-auth marker live under:

    /var/packages/nzbmanager/var

The legacy current/source directory is not part of the modern SPK.

## Build on DEV

From the repository root:

    python packaging/dsm7/build.py

Output:

    dist/NZB-Manager-DSM7-0.2.1-0001-noauth-test.spk

The builder validates the resource configuration, PHP profile, privilege
policy, icons, lifecycle scripts, absence of an installation wizard, and
the no-login marker. The SPK outer archive is a plain tar. It contains
INFO, package.tgz, scripts/, conf/, and the required package icons. The
inner package.tgz contains only the application public/, src/, and views/.

## Install for testing

1. Install Web Station and PHP 8.2 from DSM Package Center if necessary.
2. Restrict Web Station access to trusted clients; the app has no login.
3. Open Package Center > Manual Install and select the test SPK.
4. Check Web Station for the NZB Manager service and portal.
5. Open the alias URL /nzbmanager/ on your NAS.
6. Confirm that the dashboard loads without an application login.
7. Upload, inspect, export and delete a small known-good NZB.
8. Try Binsearch and verify that Download Station stays unavailable if the
   service is not configured or installed.

If DSM rejects the package, record the error and the Web Station/package
logs. A successful archive build is not proof that the portal registers
correctly or that the PHP profile and permissions work on the NAS.

## Pending certification

- Actual DSM installation, uninstall and upgrade testing
- PHP 8.2 execution and extension availability on the NAS
- Web Station service/portal and filesystem permission verification
- Download Station real API discovery/login/task upload
- Remaining PAR2, extraction and file-management parity

Official references:
- https://help.synology.com/developer-guide/synology_package/introduction.html
- https://help.synology.com/developer-guide/resource_acquisition/web_service.html
- https://help.synology.com/developer-guide/privilege/privilege_config.html
