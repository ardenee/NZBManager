#!/usr/bin/env python3
"""Build a reviewable, reproducible Synology DSM 7.2+ test SPK.

Only the modern public/, src/ and views/ are included. Historical PHP,
test fixtures, server credentials and local private data are excluded.
"""
from __future__ import annotations

import io
import json
import os
from pathlib import Path
import tarfile
from PIL import Image

ROOT = Path(__file__).resolve().parents[2]
PACKAGE = Path(__file__).resolve().parent
DEST = ROOT / "dist" / "NZBManager-0.2.1-0001-noarch-test.spk"
EPOCH = int(os.getenv("SOURCE_DATE_EPOCH", "0"))

def info(name: str, data: bytes, mode: int = 0o644) -> tuple[tarfile.TarInfo, bytes]:
    member = tarfile.TarInfo(name)
    member.size = len(data)
    member.mode = mode
    member.mtime = EPOCH
    member.uid = member.gid = 0
    member.uname = member.gname = "root"
    return member, data

def add_directory(archive: tarfile.TarFile, name: str) -> None:
    member = tarfile.TarInfo(name.rstrip("/") + "/")
    member.type = tarfile.DIRTYPE
    member.mode = 0o755
    member.mtime = EPOCH
    member.uid = member.gid = 0
    archive.addfile(member)

def add_bytes(archive: tarfile.TarFile, path: str, content: bytes, mode: int = 0o644) -> None:
    member, data = info(path, content, mode)
    archive.addfile(member, io.BytesIO(data))

def icon(source: Path, dimension: int) -> bytes:
    with Image.open(source) as img:
        resized = img.convert("RGBA").resize((dimension, dimension), Image.Resampling.LANCZOS)
        buffer = io.BytesIO()
        resized.save(buffer, format="PNG")
        return buffer.getvalue()

def main() -> None:
    for name in ("public", "src", "views"):
        if not (ROOT / name).is_dir():
            raise RuntimeError("Missing required application source: " + name)
    if "passwordlessDsm" in (ROOT / "src" / "WebSecurity.php").read_text(encoding="utf-8"):
        raise RuntimeError("Refusing passwordless DSM package")
    config_source = (ROOT / "src" / "AppConfig.php").read_text(encoding="utf-8")
    if "auth.hash" not in config_source or "no-auth" in config_source:
        raise RuntimeError("DSM credential support is missing or passwordless")
    for script in (PACKAGE / "scripts").iterdir():
        if script.is_file() and "no-auth" in script.read_text(encoding="utf-8"):
            raise RuntimeError("Refusing passwordless lifecycle script: " + script.name)
    for name in ("INFO", "conf/privilege", "conf/resource", "WIZARD_UIFILES/install_uifile"):
        if not (PACKAGE / name).is_file():
            raise RuntimeError("Missing mandatory packaging file: " + name)
    json.loads((PACKAGE / "conf/resource").read_text(encoding="utf-8"))
    json.loads((PACKAGE / "conf/privilege").read_text(encoding="utf-8"))
    wizard = json.loads((PACKAGE / "WIZARD_UIFILES/install_uifile").read_text(encoding="utf-8"))
    if "wizard_nzb_password" not in json.dumps(wizard):
        raise RuntimeError("Install wizard must request a password")
    if "wizard_nzb_password" not in (PACKAGE / "scripts/postinst").read_text(encoding="utf-8"):
        raise RuntimeError("postinst does not consume installer password")

    data_stream = io.BytesIO()
    with tarfile.open(fileobj=data_stream, mode="w:gz", format=tarfile.PAX_FORMAT) as tar:
        add_directory(tar, "webapp")
        for folder in ("public", "src", "views"):
            for directory in sorted((ROOT / folder).rglob("*")):
                if directory.is_dir() and not directory.is_symlink():
                    add_directory(tar, "webapp/" + directory.relative_to(ROOT).as_posix())
            for path in sorted((ROOT / folder).rglob("*")):
                if path.is_symlink():
                    raise RuntimeError("Symlink not allowed in app source: " + str(path))
                if path.is_file():
                    add_bytes(tar, "webapp/" + path.relative_to(ROOT).as_posix(), path.read_bytes())
    packaged_app = data_stream.getvalue()

    source_icon = ROOT / "current" / "spk" / "PACKAGE_ICON_120.PNG"
    if not source_icon.exists():
        source_icon = ROOT / "current" / "spk" / "PACKAGE_ICON.PNG"
    DEST.parent.mkdir(parents=True, exist_ok=True)
    with tarfile.open(DEST, "w:gz", format=tarfile.PAX_FORMAT) as tar:
        add_bytes(tar, "INFO", (PACKAGE / "INFO").read_bytes())
        add_bytes(tar, "package.tgz", packaged_app)
        add_directory(tar, "scripts")
        for file in sorted((PACKAGE / "scripts").iterdir()):
            if file.is_file():
                add_bytes(tar, "scripts/" + file.name, file.read_bytes().replace(b"\r\n", b"\n"), 0o755)
        add_directory(tar, "conf")
        for name in ("privilege", "resource"):
            add_bytes(tar, "conf/" + name, (PACKAGE / "conf" / name).read_bytes())
        add_directory(tar, "WIZARD_UIFILES")
        add_bytes(tar, "WIZARD_UIFILES/install_uifile", (PACKAGE / "WIZARD_UIFILES/install_uifile").read_bytes())
        add_bytes(tar, "PACKAGE_ICON.PNG", icon(source_icon, 64))
        add_bytes(tar, "PACKAGE_ICON_256.PNG", icon(source_icon, 256))
    print("Built:", DEST)
    print("Bytes:", DEST.stat().st_size)
    print("Contains only modern application source and DSM 7.2+ package metadata.")
if __name__ == "__main__":
    main()
