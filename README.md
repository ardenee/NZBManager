# NZB Manager

PHP 8.2+ application for managing NZBs, sending them to download clients,
native PHP PAR2 verification/repair, archive extraction, and organizing files.

**The generic web-hosted application is the primary release.** The optional
Synology DSM package is generated from exactly the same application files;
there is no separate DSM PHP codebase.

## Option 1 — Deploy to standard web hosting

Requirements: PHP **8.2 or newer**, PHP-FPM or Apache PHP, a writable private
storage directory, and the PHP extensions **cURL, DOM/XML, fileinfo, hash,
JSON, mbstring, OpenSSL, SimpleXML, XMLReader, XMLWriter, ZIP and zlib**.
For independent repair/extraction jobs, PHP CLI must also be available, with
`proc_open` in the web PHP runtime and `pcntl_fork` in Linux PHP CLI.
No database, Composer packages, or third-party PAR2/archiver executables
are required.

1. Copy `public/`, `src/`, `views/`, and `bin/` to the web application
   directory, e.g. `/srv/nzbmanager/`.
2. Set the web-server **document root** to `/srv/nzbmanager/public/`.
   Do not expose the repository root, `src/`, `views/`, `bin/` or private data.
3. Create a **private writable directory** for app data (e.g.
   `/var/lib/nzbmanager/`) and set `NZB_STORAGE_DIR` in the PHP-FPM environment.
   The PHP web user and CLI worker must have appropriate access.
4. Set `NZB_ADMIN_PASSWORD_HASH` (preferred, generated with PHP
   `password_hash()`) or `NZB_ADMIN_PASSWORD` in the PHP-FPM environment.
   Without authentication configuration the generic application denies access.
5. Enable HTTPS before entering credentials, and make sure PHP-FPM receives
   the `Authorization` header and the configured environment variables.
6. Configure PHP upload limits as necessary (each NZB input is limited to
   32 MiB) and grant filesystem access only to the intended download
   workspace.

An nginx starting configuration is provided at `deploy/nginx.conf.example`.
Adapt its hostname, PHP-FPM socket and directories; it is not production-ready
until HTTPS and authentication are configured.

Open the site's URL and finish setup under **Settings**.

## Option 2 — Generate and install the Synology DSM SPK

Requirements: **DSM 7.2+**, Web Station and PHP 8.2 on the NAS. To build the
SPK on any computer, use **Python 3.11+** (standard library only).

From the source repository root:

```sh
python packaging/dsm7/build.py
```

Or specify a new DSM version for an upgrade:

```sh
python packaging/dsm7/build.py --version 0.4.5-0001
```

On Windows, `packaging/dsm7/build-spk.ps1` is an equivalent wrapper.

The SPK is created in `dist/` (ignored by Git), e.g.:

`dist/NZB-Manager-DSM7-0.4.4-0001-noauth-test.spk`

The script automatically includes the generic application, DSM Web Station
configuration, lifecycle scripts and required icons, validates the archive
against the application source, and prints its SHA-256 checksum. Verify an
existing SPK with:

```sh
python packaging/dsm7/build.py --verify dist/NZB-Manager-DSM7-0.4.4-0001-noauth-test.spk
```

Install the SPK using **DSM Package Center → Manual Install**. It configures
the PHP 8.2 Web Station portal at `/nzbmanager/` and private package data.
The installer does not create an application password or login. **This DSM
test package must be restricted to a trusted LAN/private access control:
logging into DSM does not secure a Web Station website.** Building an SPK
does not install or update the running package.

## Source layout

| Directory | Deploy or build purpose |
| --- | --- |
| `public/` | Web document root, CSS/JS and entry point |
| `src/` | PHP application and modules (not web-served) |
| `views/` | PHP templates (not web-served) |
| `bin/` | PHP background workers (not web-served) |
| `deploy/` | Generic web-server configuration example |
| `packaging/dsm7/` | Optional DSM-only SPK builder and package assets |

Development tests, audits, historical sources and internal documentation are
maintained offline and are not part of this deployment repository.
