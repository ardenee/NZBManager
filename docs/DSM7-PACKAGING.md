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
- The installer declares **cURL, DOM, fileinfo, mbstring, OpenSSL, SimpleXML,
  XML, XMLReader, XMLWriter, ZIP and zlib** in the NZB Manager PHP 8.2 profile.
  JSON, session and hash are PHP runtime requirements, not separate SPK toggles.
  Manual checkbox changes should not be needed on a freshly registered profile.
- Correct permission support for package users and PHP-FPM
- HTTPS is recommended even on the local network

The Web Station service uses nginx_php and PHP profile backend ID **11** for
PHP 8.2. The 0.4.3-0001 SPK declares the required PHP extensions, module workspace
access to /volume1–/volume4 and PHP CLI workers inside webapp/bin. PAR2
verification and bounded PAR2 recovery are native PHP only and never call
par2cmdline. New background jobs launch automatically through PHP-FPM
`proc_open` and Linux CLI `pcntl_fork`; availability must be verified on DSM.
This revision has not yet been installed/validated on a real NAS. Earlier test packages also granted the PHP profile open_basedir access
to /volume1 through /volume4, allowing folder imports and monitored-folder
outputs when the package execution user has the necessary share permissions.
The version can be read from /etc.defaults/VERSION on supported DSM hosts. The earlier NZB test packages incorrectly used backend 10, and the
DSM web portal was registered without a working package-based PHP profile.
A known-working DUP 0.6.3-0009 DSM 7 SPK provided as a reference uses backend
11 with a named PHP 8.2 profile. Its postinst script only logs installation:
the Web Station package resource creates the PHP profile automatically.
The NZB SPK also adopts DUP's alias-only service and startable-app metadata.
The PHP profile remains minimal, with no experimental PHP ini overrides.
PHP session storage is configured by the application itself before
session_start(). The service's document root remains restricted to:

    /var/services/web_packages/nzbmanager/public

The rest of the source is outside the document root. The private NZB library,
session files and no-auth marker live under:

    /var/packages/nzbmanager/var

The legacy current/source directory is not part of the modern SPK.

## Automatic PHP extension configuration

The Web Station resource `packaging/dsm7/conf/resource` declares the
extensions `curl`, `dom`, `fileinfo`, `mbstring`, `openssl`, `simplexml`,
`xml`, `xmlreader`, `xmlwriter`, `zip` and `zlib` in its
`webservice.services[0].php.extensions` array. Synology treats these as
package-required extensions when it creates the package-based PHP profile:
users do not have to enable them manually. The SPK builder and validation
suite both fail if any is absent.

`zip` enables `ZipArchive` for the archive extractor; `zlib` provides zlib
compression streams. Native PAR2 verification and repair use PHP hashing and
GF(2^16) operations without par2cmdline, FFI or MySQL. The web worker launcher
uses PHP-FPM `proc_open` and the Linux CLI must expose `pcntl_fork`; these
functions are not installable as a Web Station PHP profile extension toggle.
The runtime status in Settings reports the **actual PHP-FPM extensions**, not
merely the package configuration. On 20 November 2026, a short-lived PHP
probe on DSM 7 / PHP 8.2.35 verified `proc_open`, successful PHP CLI launch
and `pcntl_fork`; its temporary public diagnostic file was deleted. This
checks worker prerequisites, not yet completion of a real PAR2 repair job.

**Existing package profiles:** Synology may preserve an already-registered
package PHP profile during an in-place upgrade. The new SPK specifies the
correct defaults and requires them on **new profile registration**; it
cannot certify that an old manually modified profile has been refreshed
without inspecting the running DSM PHP-FPM process. If a previous profile
remains unchanged, back up package-private data before re-registering the
Web Station package service or performing a clean reinstall. Do not delete
package storage without a verified backup.

## Browser-side folder picker and asset cache

The Settings folder picker uses a CSRF-checked JSON POST to the same-origin
index.php endpoint. src/WebSecurity.php explicitly permits
connect-src 'self' in the Content Security Policy while maintaining
default-src 'none' and script-src 'self'. Omitting that directive blocks
folder navigation in the browser even though the backend and NAS filesystem
permissions are correct.

DSM packages use reproducible archive modification timestamps (often Unix
zero). The views/layout.php script and stylesheet URLs therefore use a
short SHA-256 digest of the public file contents instead of filemtime();
otherwise their query versions can remain stuck at v=1 and browsers may
run an older JavaScript handler after DSM source updates.

The DEV HTTP smoke test checks both the same-origin CSP directive and the
actual asset content-hash URLs. The folder picker was also exercised with
headless Chrome on the running DSM website, including opening the modal,
navigating /volume1/data, checking permissions, selecting a folder and
reopening for Push To.

## Build on DEV

From the repository root:

    python packaging/dsm7/build.py

Output:

    dist/NZB-Manager-DSM7-0.4.3-0001-noauth-test.spk

The builder validates the resource configuration, PHP profile, privilege
policy, icons, lifecycle scripts, absence of an installation wizard, and
the no-login marker. The two original 2013 icons (72x72 and 120x120) are
preserved byte-for-byte as original-icons/ files inside package.tgz.
Their artwork also supplies DSM 7's required 64x64 and 256x256 package icons,
as well as the Web Station service icons under ui/. The SPK outer archive is a plain tar. It contains
INFO, package.tgz, scripts/, conf/, and the required package icons. The
inner package.tgz contains the application public/, src/, views/ and bin/ trees,
the original 2013 icons, and the service icons under ui/.

## Install for testing

1. Install Web Station and PHP 8.2 from DSM Package Center if necessary.
2. Restrict Web Station access to trusted clients; the app has no login.
3. If using a previous 0.2.1-0001 or 0.2.1-0002 test SPK, **back up any NZB
   library data** first. The Web Station webservice resource is not updatable
   after registration, so **uninstall the failing old test package** in
   Package Center and install 0.2.1-0003 fresh to recreate its PHP profile.
   Uninstalling may remove private data; do not proceed without a backup.
4. Open Package Center > Manual Install and select the test SPK.
5. Check Web Station > Script Language Settings > PHP for an automatically
   generated **NZB Manager PHP 8.2** profile. The new package requires
   cURL, mbstring, OpenSSL, ZIP and zlib; none should require manually
   enabling. Verify NZB Manager Web Service appears normal.
6. Open the alias URL /nzbmanager/ on your NAS.
7. Confirm that the dashboard loads without an application login or 502 error.
   In NZB Manager > Settings > PHP module status, all five extensions should
   show Enabled under the *active* FPM runtime. This check does not require
   manually changing the profile.
8. Upload, inspect, export and delete a small known-good NZB.
9. Try Binsearch and verify that Download Station stays unavailable if the
   service is not configured or installed.

If DSM rejects the package, record the error and the Web Station/package
logs. A successful archive build is not proof that the portal registers
correctly or that the PHP profile and permissions work on the NAS.

## Pending certification

- Actual DSM installation, uninstall and upgrade testing
- PHP 8.2 execution and extension availability on the NAS
- Web Station service/portal and filesystem permission verification
- Download Station real API discovery/login/task upload
- Live PAR2 executable/worker and repair certification
- Pure PHP RAR/7z and true multi-disk ZIP extraction, expanded automated workflow certification
- Password-protected ZIP and raw-split `.zip.001` extraction workflow on the actual NAS (DEV-tested; DSM PHP-FPM AES decryption probe passed)

Official references:
- https://help.synology.com/developer-guide/synology_package/introduction.html
- https://help.synology.com/developer-guide/resource_acquisition/web_service.html
- https://help.synology.com/developer-guide/privilege/privilege_config.html

## Automation worker service (20 November 2026)

The PHP-only automation worker is included at `bin/automation-worker.php`; package `start-stop-status` now starts it on package startup and stops its validated PID. The worker exits when both schedules are disabled. **No new SPK has been built or installed yet**, so DSM lifecycle changes in this script take effect only after a later package release. The live web Settings can launch the worker directly after schedules are enabled.
