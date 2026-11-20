#!/usr/bin/env python3
"""Build and validate a PASSWORD-FREE DSM 7.2+ test package.

Only the modern application is shipped. No install wizard, admin password,
password hashes, secrets, historical PHP source or local data are included.
"""
from __future__ import annotations

import hashlib
import io
import json
import re
import tarfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PACK = Path(__file__).resolve().parent
OUT = ROOT / "dist"

SCRIPTS = (
    "preinst", "postinst", "preuninst", "postuninst",
    "preupgrade", "postupgrade", "start-stop-status"
)


def add_bytes(tar: tarfile.TarFile, name: str, body: bytes, mode: int = 0o644) -> None:
    member = tarfile.TarInfo(name)
    member.uid = member.gid = 0
    member.uname = member.gname = "root"
    member.mode = mode
    member.size = len(body)
    member.mtime = 0
    tar.addfile(member, io.BytesIO(body))


def add_dir(tar: tarfile.TarFile, path: str) -> None:
    entry = tarfile.TarInfo(path.rstrip("/") + "/")
    entry.uid = entry.gid = 0
    entry.mode = 0o755
    entry.mtime = 0
    entry.type = tarfile.DIRTYPE
    tar.addfile(entry)


def icon_valid(data: bytes, size: int) -> bool:
    return data[:8] == bytes.fromhex("89504e470d0a1a0a") and int.from_bytes(data[16:20], "big") == size and int.from_bytes(data[20:24], "big") == size


def main() -> None:
    info = (PACK / "INFO").read_text(encoding="utf-8")
    match = re.search(r'^version="([0-9A-Za-z.-]+)"$', info, re.MULTILINE)
    if match is None or 'package="nzbmanager"' not in info or 'os_min_ver="7.2-' not in info:
        raise RuntimeError("Missing or invalid DSM7 package metadata.")
    if (PACK / "WIZARD_UIFILES").exists():
        raise RuntimeError("Refusing any password/setup wizard in the no-login package.")

    config = json.loads((PACK / "conf/resource").read_text(encoding="utf-8"))
    privilege = json.loads((PACK / "conf/privilege").read_text(encoding="utf-8"))
    services = config.get("webservice", {}).get("services", [])
    if len(services) != 1 or services[0].get("type") != "nginx_php" or services[0].get("root") != "nzbmanager/public":
        raise RuntimeError("Web Station must serve only the public/ document root.")
    php = services[0].get("php", {})
    prepared = config.get("webservice", {}).get("pkg_dir_prepare", [])
    if php.get("backend") != 10 or php.get("profile_name") != "NZB Manager PHP 8.2":
        raise RuntimeError("A dedicated PHP 8.2 package-based profile is required.")
    if len(prepared) != 1 or prepared[0].get("source") != "/var/packages/nzbmanager/target/webapp" or prepared[0].get("target") != "nzbmanager":
        raise RuntimeError("Web Station app directory registration is incorrect.")
    if php.get("user") != "nzbmanager" or php.get("group") != "http" or php.get("user") != prepared[0].get("user") or php.get("group") != prepared[0].get("group"):
        raise RuntimeError("PHP-FPM identity and web-directory ownership must match.")
    if services[0].get("icon") != "ui/nzb_{0}.png":
        raise RuntimeError("Web Station must display the original NZB artwork.")
    if "php_settings" in php:
        raise RuntimeError("Test package requires a minimal PHP-FPM profile without experimental ini overrides.")
    if privilege.get("defaults", {}).get("run-as") != "package":
        raise RuntimeError("A restricted package user is required.")
    for script in ("postinst", "postupgrade"):
        text = (PACK / "scripts" / script).read_text(encoding="utf-8")
        if 'touch "$DATA_DIR/no-auth"' not in text or "wizard_nzb_password" in text or "auth.hash" in text:
            raise RuntimeError(f"{script} is not passwordless.")
    if "function passwordlessDsm()" not in (ROOT / "src/AppConfig.php").read_text(encoding="utf-8"):
        raise RuntimeError("The shipped application does not recognize the DSM no-login marker.")
    if "!$passwordlessDsm" not in (ROOT / "src/WebSecurity.php").read_text(encoding="utf-8"):
        raise RuntimeError("The shipped authentication gate still requires a password.")

    compressed = io.BytesIO()
    with tarfile.open(fileobj=compressed, mode="w:gz", format=tarfile.USTAR_FORMAT) as inner:
        # Preserve both historical icon files byte-for-byte inside the SPK.
        originals = ROOT / "current" / "spk"
        add_dir(inner, "original-icons")
        for original in ("PACKAGE_ICON.PNG", "PACKAGE_ICON_120.PNG"):
            source = originals / original
            if not source.is_file():
                raise RuntimeError(f"Historical NZB icon missing: {source}")
            add_bytes(inner, "original-icons/" + original, source.read_bytes())
        # Web Station's package-service icon resolves relative to package target.
        add_dir(inner, "ui")
        add_bytes(inner, "ui/nzb_64.png", (PACK / "PACKAGE_ICON.PNG").read_bytes())
        add_bytes(inner, "ui/nzb_256.png", (PACK / "PACKAGE_ICON_256.PNG").read_bytes())
        add_dir(inner, "webapp")
        for folder in ("public", "src", "views"):
            base = ROOT / folder
            if not base.is_dir():
                raise RuntimeError(f"Missing {folder}/")
            for entry in [base, *sorted(base.rglob("*"))]:
                if entry.is_symlink():
                    raise RuntimeError(f"Symlink in application source: {entry}")
                name = "webapp/" + entry.relative_to(ROOT).as_posix()
                if entry.is_dir():
                    add_dir(inner, name)
                else:
                    add_bytes(inner, name, entry.read_bytes())

    icons = {
        "PACKAGE_ICON.PNG": 64,
        "PACKAGE_ICON_256.PNG": 256,
    }
    OUT.mkdir(parents=True, exist_ok=True)
    dest = OUT / f"NZB-Manager-DSM7-{match.group(1)}-noauth-test.spk"
    with tarfile.open(dest, mode="w", format=tarfile.USTAR_FORMAT) as outer:
        add_bytes(outer, "INFO", info.encode("utf-8"))
        add_bytes(outer, "package.tgz", compressed.getvalue())
        add_dir(outer, "scripts")
        for script in SCRIPTS:
            data = (PACK / "scripts" / script).read_bytes().replace(b"\r\n", b"\n")
            if not data.startswith(b"#!/bin/sh\n"):
                raise RuntimeError(f"Script missing POSIX shebang: {script}")
            add_bytes(outer, "scripts/" + script, data, 0o755)
        add_dir(outer, "conf")
        for file in ("privilege", "resource"):
            add_bytes(outer, "conf/" + file, (PACK / "conf" / file).read_bytes())
        for file, dimensions in icons.items():
            data = (PACK / file).read_bytes()
            if not icon_valid(data, dimensions):
                raise RuntimeError(f"Invalid icon dimensions: {file}")
            add_bytes(outer, file, data)

    with tarfile.open(dest, mode="r") as outer:
        members = set(outer.getnames())
        if "WIZARD_UIFILES" in members or "package.tgz" not in members:
            raise RuntimeError("Invalid SPK archive members.")
        payload = outer.extractfile("package.tgz")
        if payload is None:
            raise RuntimeError("Missing application payload.")
        with tarfile.open(fileobj=io.BytesIO(payload.read()), mode="r:gz") as inner:
            names = set(inner.getnames())
            required = {
                "webapp/public/index.php",
                "webapp/src/AppConfig.php",
                "webapp/src/WebSecurity.php",
                "webapp/views/layout.php"
            }
            required.update({"ui/nzb_64.png", "ui/nzb_256.png", "original-icons/PACKAGE_ICON.PNG", "original-icons/PACKAGE_ICON_120.PNG"})
            if not required.issubset(names) or any(name.startswith("webapp/current/") for name in names):
                raise RuntimeError("Invalid or incomplete application payload.")
            for legacy in ("PACKAGE_ICON.PNG", "PACKAGE_ICON_120.PNG"):
                payload = inner.extractfile("original-icons/" + legacy)
                if payload is None or payload.read() != (ROOT / "current" / "spk" / legacy).read_bytes():
                    raise RuntimeError(f"Historical NZB icon changed in SPK: {legacy}")

    print("PASS: password-free DSM7 test package:", dest)
    print("PASS: package size:", dest.stat().st_size)
    print("PASS: SHA-256:", hashlib.sha256(dest.read_bytes()).hexdigest())


if __name__ == "__main__":
    main()
