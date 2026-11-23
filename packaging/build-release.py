#!/usr/bin/env python3
"""Build the deployable generic PHP web ZIP and optional DSM SPK.

Both artifacts come from the same application source. No tests, audits, historic
source, credentials, runtime data, or DSM overlay appear in the generic ZIP.
"""
from __future__ import annotations

import argparse
import hashlib
import tempfile
import zipfile
from pathlib import Path

from dsm7 import build as dsm

ROOT = Path(__file__).resolve().parents[1]
DEFAULT_DIR = ROOT / "dist"
WEB_EXTRA = ("README.md", "LICENSE", "deploy/nginx.conf.example")


def build_web_zip(version: str, output_dir: Path) -> Path:
    app = dsm.application_files()  # Uses the same input validation as DSM.
    files = {path.removeprefix("webapp/"): data for path, data in app.items()}
    for name in WEB_EXTRA:
        source = ROOT / name
        if not source.is_file() or source.is_symlink():
            raise RuntimeError(f"Missing generic hosting resource: {name}")
        files[name] = source.read_bytes()

    destination = output_dir / f"NZB-Manager-Web-{version}.zip"
    output_dir.mkdir(parents=True, exist_ok=True)
    with tempfile.NamedTemporaryFile(dir=output_dir, prefix=".nzbmanager-", suffix=".zip.tmp",
                                     delete=False) as tmp:
        temp = Path(tmp.name)
    try:
        with zipfile.ZipFile(temp, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
            for name, data in sorted(files.items()):
                info = zipfile.ZipInfo(name, date_time=(1980, 1, 1, 0, 0, 0))
                info.create_system = 3
                info.external_attr = (0o644 & 0xFFFF) << 16
                info.compress_type = zipfile.ZIP_DEFLATED
                archive.writestr(info, data, compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
        with zipfile.ZipFile(temp, "r") as archive:
            actual = archive.namelist()
            if actual != sorted(files):
                raise RuntimeError("Web release ZIP contains unexpected or missing files.")
            for name, expected in files.items():
                if archive.read(name) != expected:
                    raise RuntimeError(f"Web release ZIP does not match application source: {name}")
            if archive.testzip() is not None:
                raise RuntimeError("Web ZIP CRC verification failed.")
        temp.replace(destination)
    finally:
        temp.unlink(missing_ok=True)
    return destination


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--dsm-version", help="DSM SPK version; defaults to packaging/dsm7/INFO")
    parser.add_argument("--output-dir", type=Path, default=DEFAULT_DIR,
                        help="Release output directory (default: dist/)")
    args = parser.parse_args()
    dsm_version = dsm.metadata_version(dsm.info_for_version(args.dsm_version))
    version = dsm_version.rsplit("-", 1)[0]
    web = build_web_zip(version, args.output_dir)
    spk = dsm.build(args.dsm_version, args.output_dir)
    verified_version, file_count = dsm.verify_spk(spk)
    if verified_version != dsm_version:
        raise RuntimeError("DSM release package version mismatch.")
    for path in (web, spk):
        print(f"PASS: {path.name} ({path.stat().st_size:,} bytes)")
        print(f"  SHA-256 {hashlib.sha256(path.read_bytes()).hexdigest()}")
    print(f"PASS: {file_count} generic application files used by both artifacts.")


if __name__ == "__main__":
    main()
