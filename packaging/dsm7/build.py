#!/usr/bin/env python3
"""Build/verify the optional DSM 7.2+ SPK from the generic NZB Manager web app.

Single source of truth: public/, src/, views/, bin/ at the repository root.
DSM-only files and historical artwork live under packaging/dsm7/.
No Synology-specific source fork, third-party packager or legacy source needed.
"""
from __future__ import annotations

import argparse
import gzip
import hashlib
import io
import json
import re
import tarfile
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PACK = Path(__file__).resolve().parent
DEFAULT_OUT = ROOT / "dist"

SCRIPTS = (
    "preinst", "postinst", "preuninst", "postuninst",
    "preupgrade", "postupgrade", "start-stop-status",
)
APP_FOLDERS = ("public", "src", "views", "bin")
APP_SUFFIXES = {
    "public": {".php", ".css", ".js", ".png", ".jpg", ".jpeg", ".webp",
               ".svg", ".ico", ".html", ".txt"},
    "src": {".php"},
    "views": {".php"},
    "bin": {".php"},
}
ICONS = {"PACKAGE_ICON.PNG": 64, "PACKAGE_ICON_256.PNG": 256}
ORIGINAL_ICONS = ("PACKAGE_ICON.PNG", "PACKAGE_ICON_120.PNG")
REQUIRED_APP_FILES = {
    "webapp/public/index.php",
    "webapp/src/AppConfig.php",
    "webapp/src/WebSecurity.php",
    "webapp/views/layout.php",
    "webapp/views/repair.php",
    "webapp/views/unpack.php",
    "webapp/views/file-organizer.php",
    "webapp/bin/par2-worker.php",
    "webapp/bin/automation-worker.php",
    "webapp/src/Modules/Par2Recovery.php",
    "webapp/src/Modules/Par2DiskMatrix.php",
}
VERSION_PATTERN = re.compile(r'^version="([0-9]+\.[0-9]+\.[0-9]+)"$',
                             re.MULTILINE)


def add_bytes(tar: tarfile.TarFile, name: str, body: bytes, mode: int = 0o644) -> None:
    member = tarfile.TarInfo(name)
    member.uid = member.gid = 0
    member.uname = member.gname = "root"
    member.mode = mode
    member.size = len(body)
    member.mtime = 0
    tar.addfile(member, io.BytesIO(body))


def add_dir(tar: tarfile.TarFile, name: str) -> None:
    member = tarfile.TarInfo(name.rstrip("/") + "/")
    member.uid = member.gid = 0
    member.mode = 0o755
    member.mtime = 0
    member.type = tarfile.DIRTYPE
    tar.addfile(member)


def icon_valid(data: bytes, size: int) -> bool:
    return (data[:8] == bytes.fromhex("89504e470d0a1a0a")
            and int.from_bytes(data[16:20], "big") == size
            and int.from_bytes(data[20:24], "big") == size)


def metadata_version(info: str) -> str:
    match = VERSION_PATTERN.search(info)
    if match is None:
        raise RuntimeError("INFO must contain a DSM package version such as 0.4.4.")
    return match.group(1)


def info_for_version(version: str | None = None) -> str:
    info = (PACK / "INFO").read_text(encoding="utf-8")
    default = metadata_version(info)
    if version is None:
        return info
    if VERSION_PATTERN.fullmatch(f'version="{version}"') is None:
        raise RuntimeError("Invalid DSM version. Use a value such as 0.4.4.")
    return info.replace(f'version="{default}"', f'version="{version}"', 1)


def validate_dsm_config(info: str) -> None:
    if ('package="nzbmanager"' not in info or 'os_min_ver="7.2-' not in info
            or 'startable="yes"' not in info or 'adminurl="nzbmanager/"' not in info):
        raise RuntimeError("DSM metadata must identify the NZB Manager portal and runtime.")
    if (PACK / "WIZARD_UIFILES").exists():
        raise RuntimeError("No login/setup wizard may be included in this package.")

    resource = json.loads((PACK / "conf/resource").read_text(encoding="utf-8"))
    privilege = json.loads((PACK / "conf/privilege").read_text(encoding="utf-8"))
    services = resource.get("webservice", {}).get("services", [])
    if (len(services) != 1 or services[0].get("type") != "nginx_php"
            or services[0].get("root") != "nzbmanager/public"):
        raise RuntimeError("Web Station must serve only the public/ document root.")
    php = services[0].get("php", {})
    prepared = resource.get("webservice", {}).get("pkg_dir_prepare", [])
    if php.get("backend") != 11 or php.get("profile_name") != "NZB Manager PHP 8.2":
        raise RuntimeError("The DSM package needs the configured PHP 8.2 FPM profile.")
    required = {"curl", "dom", "fileinfo", "mbstring", "openssl", "simplexml",
                "xml", "xmlreader", "xmlwriter", "zip", "zlib"}
    enabled = php.get("extensions", [])
    if not isinstance(enabled, list) or not required.issubset(set(enabled)):
        raise RuntimeError("Required PHP modules are missing from the Web Station profile.")
    if len(enabled) != len(set(enabled)):
        raise RuntimeError("The Web Station PHP profile contains duplicate extensions.")
    if (len(prepared) != 1
            or prepared[0].get("source") != "/var/packages/nzbmanager/target/webapp"
            or prepared[0].get("target") != "nzbmanager"):
        raise RuntimeError("DSM app/web directory mapping is invalid.")
    if (php.get("user") != "nzbmanager" or php.get("group") != "http"
            or php.get("user") != prepared[0].get("user")
            or php.get("group") != prepared[0].get("group")):
        raise RuntimeError("PHP-FPM and web directory ownership must match.")
    if services[0].get("support_server") is not False or services[0].get("icon") != "ui/nzb_{0}.png":
        raise RuntimeError("DSM must register only the known alias portal and artwork.")
    if "php_settings" in php or privilege.get("defaults", {}).get("run-as") != "package":
        raise RuntimeError("Unsupported PHP overrides or elevated DSM package privileges.")
    for script in ("postinst", "postupgrade"):
        contents = (PACK / "scripts" / script).read_text(encoding="utf-8")
        if ('touch "$DATA_DIR/no-auth"' not in contents
                or "wizard_nzb_password" in contents or "auth.hash" in contents):
            raise RuntimeError(f"{script} violates the password-free package policy.")
    if "function passwordlessDsm()" not in (ROOT / "src/AppConfig.php").read_text(encoding="utf-8"):
        raise RuntimeError("Application lacks the DSM no-login marker integration.")
    if "!$passwordlessDsm" not in (ROOT / "src/WebSecurity.php").read_text(encoding="utf-8"):
        raise RuntimeError("Application authentication gate still requires a DSM password.")


def application_files() -> dict[str, bytes]:
    """Explicitly ship modern source/assets; reject runtime data and secrets."""
    files: dict[str, bytes] = {}
    for folder in APP_FOLDERS:
        base = ROOT / folder
        if not base.is_dir() or base.is_symlink():
            raise RuntimeError(f"Missing or redirected generic application directory: {base}")
        for entry in sorted(base.rglob("*")):
            relative = entry.relative_to(base)
            if entry.is_symlink():
                raise RuntimeError(f"Symlinks are not allowed in release payload: {entry}")
            if any(part.startswith(".") for part in relative.parts[:-1]):
                raise RuntimeError(f"Hidden source directory is not allowed in release: {entry}")
            if entry.is_dir():
                continue
            if not entry.is_file():
                raise RuntimeError(f"Unexpected non-file in application source: {entry}")
            if entry.name != ".htaccess" and (
                    entry.name.startswith(".") or entry.suffix.lower() not in APP_SUFFIXES[folder]):
                raise RuntimeError(f"Unexpected source/possible secret in SPK payload: {entry}")
            if entry.name == ".htaccess" and folder != "public":
                raise RuntimeError(f".htaccess outside public/ is not allowed: {entry}")
            files["webapp/" + entry.relative_to(ROOT).as_posix()] = entry.read_bytes()

    missing = REQUIRED_APP_FILES - files.keys()
    if missing:
        raise RuntimeError(f"Required generic application source is absent: {sorted(missing)}")
    return files


def payload_assets() -> dict[str, bytes]:
    assets = {}
    for filename in ORIGINAL_ICONS:
        # These are DSM packaging assets, not runtime application dependencies.
        source = PACK / "original-icons" / filename
        if not source.is_file():
            raise RuntimeError(f"Original DSM artwork is missing: {source}")
        assets["original-icons/" + filename] = source.read_bytes()
    for filename, size in ICONS.items():
        data = (PACK / filename).read_bytes()
        if not icon_valid(data, size):
            raise RuntimeError(f"Invalid DSM PNG icon dimensions: {filename}")
    assets["ui/nzb_64.png"] = (PACK / "PACKAGE_ICON.PNG").read_bytes()
    assets["ui/nzb_256.png"] = (PACK / "PACKAGE_ICON_256.PNG").read_bytes()
    return assets


def inner_tar_bytes(app: dict[str, bytes], assets: dict[str, bytes]) -> bytes:
    compressed = io.BytesIO()
    # Fixed gzip timestamp and filename ensure identical sources produce
    # byte-for-byte identical SPKs across builds/machines.
    with gzip.GzipFile(fileobj=compressed, filename="", mode="wb", mtime=0) as stream:
        with tarfile.open(fileobj=stream, mode="w", format=tarfile.USTAR_FORMAT) as inner:
            add_dir(inner, "original-icons")
            for name in sorted(n for n in assets if n.startswith("original-icons/")):
                add_bytes(inner, name, assets[name])
            add_dir(inner, "ui")
            for name in sorted(n for n in assets if n.startswith("ui/")):
                add_bytes(inner, name, assets[name])
            add_dir(inner, "webapp")
            for folder in APP_FOLDERS:
                add_dir(inner, "webapp/" + folder)
                base = ROOT / folder
                for entry in sorted(base.rglob("*")):
                    if entry.is_dir():
                        add_dir(inner, "webapp/" + entry.relative_to(ROOT).as_posix())
                    else:
                        name = "webapp/" + entry.relative_to(ROOT).as_posix()
                        add_bytes(inner, name, app[name])
    return compressed.getvalue()


def outer_files(info: str, payload: bytes) -> dict[str, tuple[bytes, int]]:
    files: dict[str, tuple[bytes, int]] = {
        "INFO": (info.encode("utf-8"), 0o644),
        "package.tgz": (payload, 0o644),
    }
    for script in SCRIPTS:
        contents = (PACK / "scripts" / script).read_bytes().replace(b"\r\n", b"\n")
        if not contents.startswith(b"#!/bin/sh\n"):
            raise RuntimeError(f"DSM script lacks POSIX shebang: {script}")
        files["scripts/" + script] = (contents, 0o755)
    for filename in ("privilege", "resource"):
        files["conf/" + filename] = ((PACK / "conf" / filename).read_bytes(), 0o644)
    for filename in ICONS:
        files[filename] = ((PACK / filename).read_bytes(), 0o644)
    return files


def verify_spk(path: Path) -> tuple[str, int]:
    """Verify SPK layout and every packaged file against the current web source."""
    app = application_files()
    assets = payload_assets()
    with tarfile.open(path, mode="r:") as outer:
        members = outer.getmembers()
        if any(not member.isfile() and not member.isdir() for member in members):
            raise RuntimeError("SPK contains an unexpected link/device/member type.")
        outer_names = {member.name for member in members if member.isfile()}
        file_info = outer.extractfile("INFO")
        if file_info is None:
            raise RuntimeError("SPK has no INFO metadata.")
        info = file_info.read().decode("utf-8")
        version = metadata_version(info)
        validate_dsm_config(info)
        expected_outer = outer_files(info, b"")
        if outer_names != set(expected_outer):
            raise RuntimeError(f"Unexpected outer SPK members: {sorted(outer_names ^ set(expected_outer))}")
        for name, (expected, mode) in expected_outer.items():
            if name == "package.tgz":
                continue
            member = outer.getmember(name)
            stream = outer.extractfile(member)
            if stream is None or stream.read() != expected or (member.mode & 0o777) != mode:
                raise RuntimeError(f"DSM metadata/script/icon differs from packaging source: {name}")

        payload = outer.extractfile("package.tgz")
        if payload is None:
            raise RuntimeError("SPK has no compressed application payload.")
        with tarfile.open(fileobj=io.BytesIO(payload.read()), mode="r:gz") as inner:
            members = inner.getmembers()
            if any(not member.isfile() and not member.isdir() for member in members):
                raise RuntimeError("Application payload contains unsafe links/devices.")
            names = {member.name for member in members if member.isfile()}
            expected = {**app, **assets}
            if names != set(expected):
                raise RuntimeError(f"Application payload mismatch: {sorted(names ^ set(expected))}")
            for name, original in expected.items():
                source = inner.extractfile(name)
                if source is None or source.read() != original:
                    raise RuntimeError(f"Application payload does not match generic web source: {name}")
    return version, len(app)


def build(version: str | None, output_dir: Path) -> Path:
    info = info_for_version(version)
    validate_dsm_config(info)
    app = application_files()
    assets = payload_assets()
    payload = inner_tar_bytes(app, assets)
    outer = outer_files(info, payload)
    output_dir.mkdir(parents=True, exist_ok=True)
    output = output_dir / f"NZB-Manager-DSM7-{metadata_version(info)}.spk"
    # Validate the temporary SPK before publishing it to dist/.
    with tempfile.NamedTemporaryFile(prefix=".nzbmanager-", suffix=".spk.tmp",
                                     dir=output_dir, delete=False) as staging:
        temporary = Path(staging.name)
    try:
        with tarfile.open(temporary, mode="w", format=tarfile.USTAR_FORMAT) as archive:
            # Follow Synology's usual SPK layout: INFO and package.tgz first.
            for name in ("INFO", "package.tgz"):
                data, mode = outer[name]
                add_bytes(archive, name, data, mode)
            add_dir(archive, "scripts")
            for name in (f"scripts/{script}" for script in SCRIPTS):
                data, mode = outer[name]
                add_bytes(archive, name, data, mode)
            add_dir(archive, "conf")
            for name in ("conf/privilege", "conf/resource", *ICONS.keys()):
                data, mode = outer[name]
                add_bytes(archive, name, data, mode)
        verified_version, count = verify_spk(temporary)
        if verified_version != metadata_version(info) or count != len(app):
            raise RuntimeError("Build verification returned inconsistent source metadata.")
        temporary.replace(output)
    finally:
        temporary.unlink(missing_ok=True)
    return output


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--version", help="DSM package version (e.g. 0.4.4); default INFO version")
    parser.add_argument("--output-dir", type=Path, default=DEFAULT_OUT,
                        help="Where to write the .spk (default repository dist/)")
    parser.add_argument("--verify", metavar="SPK", type=Path,
                        help="Verify an existing SPK against this generic application checkout")
    args = parser.parse_args()
    if args.verify:
        version, count = verify_spk(args.verify)
        if args.version is not None and version != args.version:
            raise RuntimeError(f"Package version {version} differs from requested {args.version}.")
        output = args.verify
    else:
        output = build(args.version, args.output_dir)
        version, count = verify_spk(output)
    print(f"PASS: {'verified' if args.verify else 'built'} DSM 7.2+ NZB Manager SPK: {output}")
    print(f"PASS: version={version}; generic application files={count}; DSM package only adds metadata/icons/scripts")
    print(f"PASS: bytes={output.stat().st_size}; sha256={hashlib.sha256(output.read_bytes()).hexdigest()}")


if __name__ == "__main__":
    main()
