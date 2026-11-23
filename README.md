# NZB Manager

NZB Manager is a web-based tool for organizing NZB files and managing downloaded
content. It runs on standard PHP web hosting and can also be packaged for
Synology DSM. Both versions use the **same PHP application**, with no database,
Composer dependencies, or external PAR2 repair executables required.

## Features

- **NZB library:** Upload, inspect, search, sort, rename, merge, export, and
  delete NZB documents. Review files, Usenet groups, segments, and missing or
  duplicate segment references.
- **NZB search:** Search Binsearch and configured Newznab-compatible indexers;
  import selected NZBs into the library.
- **Download integration:** Send NZBs to a monitored folder, an HTTPS destination,
  or Synology Download Station. Optional scheduled folder scanning and download
  completion checks are disabled by default.
- **PAR2 verification and repair:** Verify PAR2 sets and protected-file checksums,
  detect damaged or missing slices, and perform native PHP recovery with progress
  monitoring. Repairs preserve damaged originals and check disk capacity before
  creating replacement files.
- **Archive extraction:** Extract ZIP (including encrypted ZIP and raw-split
  ZIP), TAR, TAR.GZ/TGZ, and SPK files in PHP background jobs. Choose a default
  extraction location and manage existing output folders. RAR, 7z, and true
  multi-disk ZIP extraction are not yet supported.
- **File Organizer:** Browse the download workspace; rename and move files,
  merge folder contents, delete individual files, and delete empty folders.
- **Web interface:** Responsive interface with job progress, sorting, paging,
  configurable settings, and safety checks on file operations.

## Requirements

- **PHP 8.2 or newer** on Linux, with the cURL, DOM/XML, fileinfo, hash, JSON,
  mbstring, OpenSSL, SimpleXML, XMLReader, XMLWriter, ZIP, and zlib extensions.
- A web server such as **Apache or nginx**, with PHP-FPM or another compatible
  PHP runtime, and a **private writable data directory** outside the document root.
- For background repair, extraction, and automated jobs: PHP CLI, `proc_open`
  in the web runtime, and `pcntl_fork` in Linux PHP CLI.
- HTTPS for remote access and any authenticated sessions.

## Generic web hosting

Download the **generic web ZIP** (named `NZB-Manager-Web-*.zip`) from
[Releases](https://github.com/ardenee/NZBManager/releases), or clone this
repository.

1. Extract the archive, keeping `public/`, `src/`, `views/`, and `bin/`
   together. For example, place them in `/srv/nzbmanager/`.
2. Set the website **document root** to `/srv/nzbmanager/public/`.
   Do not expose `src/`, `views/`, `bin/`, or private application data.
3. Create a writable private storage directory, such as
   `/var/lib/nzbmanager/nzb/`. Set `NZB_STORAGE_DIR` in the PHP runtime
   environment to that directory.
4. Set `NZB_ADMIN_PASSWORD_HASH` (recommended) or `NZB_ADMIN_PASSWORD` in
   the PHP runtime environment. The default username is `admin`, overridable
   with `NZB_ADMIN_USERNAME`. Without authentication configuration the generic
   application refuses requests.
5. Configure PHP-FPM permissions, PHP CLI access, upload limits, and TLS.
   If you intend to browse external download folders, set `NZB_IMPORT_ROOT`
   to the authorized parent directory and grant the PHP service user access.
6. Open the website and use **Settings** to configure workspace directories,
   indexers, Download Station credentials, extraction options, and schedules.

An example nginx configuration is provided in
`deploy/nginx.conf.example`. Adapt its paths, PHP-FPM socket, and HTTPS
configuration for your host.

## Synology DSM

For **DSM 7.2 or newer**, install **Web Station** and **PHP 8.2**, then download
the **DSM SPK** (named `NZB-Manager-DSM7-*.spk`) from
[Releases](https://github.com/ardenee/NZBManager/releases).
Open **DSM Package Center → Manual Install** and select the SPK. The
package installs the application and configures its PHP 8.2 Web Station
service. Access the installed web application at `/nzbmanager/` on your NAS.

The DSM package uses package-private application storage and does not ask
for an additional application password. **Restrict its web portal to a
trusted network**: DSM login does not automatically protect Web Station
websites.

### Build the DSM package from source

The DSM build is an optional packaging layer over the **generic application**.
It does not maintain a second copy of the PHP code.

Requires Python 3.11+ (standard library only):

```sh
python packaging/dsm7/build.py
```

The resulting SPK is written to `dist/`. When preparing a later upgrade, supply a new DSM package version (example):

```sh
python packaging/dsm7/build.py --version 0.4.5
```

To generate **both** the generic web ZIP and DSM package from the current
source:

```sh
python packaging/build-release.py
```

Generated packages do not install themselves; deploy the ZIP to your web
server or install the SPK through DSM Package Center.

## Licence

NZB Manager is released under the [MIT License](LICENSE). The full licence is
included in the web ZIP and DSM SPK, including the installed DSM application.
