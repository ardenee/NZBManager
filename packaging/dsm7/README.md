# Optional Synology DSM 7.2+ SPK

**The generic web-hosting application is the source of truth.** The modern
application resides in the repository `public/`, `src/`, `views/` and `bin/`.
DSM adds only the packaging overlay under `packaging/dsm7/` — there is
no separate PHP code fork.

The builder reads these four generic application trees, along with the DSM
INFO, configuration, lifecycle scripts and icon files in this directory.
The original icon artwork is self-contained under `original-icons/`; the
historical `current/` folder is **not** required for any build.

## Build (Python 3.11+, standard library only)

From the repository root:

```powershell
python packaging/dsm7/build.py
```

Optional PowerShell wrapper on Windows:

```powershell
.\packaging\dsm7\build-spk.ps1
```

The default package version comes from `packaging/dsm7/INFO` (currently
`0.4.4-0001`). Supply a **new version** when generating an upgrade; the
override changes the SPK's INFO, not the source file:

```powershell
python packaging/dsm7/build.py --version 0.4.5-0001
python packaging/dsm7/build.py --version 0.4.5-0001 --output-dir D:\Temp\spk
.\packaging\dsm7\build-spk.ps1 -Version 0.4.5-0001 -OutputDir D:\Temp\spk
```

The default output is `dist/NZB-Manager-DSM7-0.4.4-0001-noauth-test.spk`.
The `dist/` directory is Git-ignored. The builder verifies every package
member against the current generic application source, rejects unexpected
files that could contain secrets, and prints a SHA-256 digest. Repeated
builds with identical input bytes produce identical SPKs.

Verify a generated package or run the clean-checkout build test:

```powershell
python packaging/dsm7/build.py --verify dist/NZB-Manager-DSM7-0.4.4-0001-noauth-test.spk
python tests/spk-build.py
```

The clean-checkout test copies only `public/`, `src/`, `views/`, `bin/`
and `packaging/`; it checks that no `current/` or `original-spk/` files
are needed and tests reproducibility, version overrides and secret rejection.

## SPK contents and security

Outer archive: `INFO`, `package.tgz`, executable `scripts/`, DSM Web Station
`conf/`, 64/256-pixel icons. Inner archive: the generic application,
Web Station icons and packaged original icon artwork. **No runtime data,
settings, NZB downloads, passwords or historical PHP endpoints** are copied.

DSM requires Web Station and PHP 8.2. There is no application login or
installation wizard. **DSM login does not automatically secure Web Station**:
limit the portal to a trusted LAN/private reverse proxy or firewall.

Building does not install or update DSM. The SPK's install/upgrade behavior
must be validated separately in DSM Package Center.

See `docs/DSM7-PACKAGING.md` for installation and troubleshooting.
