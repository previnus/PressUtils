"""Build a reproducible WordPress plugin archive and its SHA-256 checksum."""

from hashlib import sha256
from pathlib import Path
import re
from zipfile import ZIP_DEFLATED, ZipFile, ZipInfo


ROOT = Path(__file__).resolve().parents[1]
PLUGIN = ROOT / "wp-utility-suite"
ENTRY = PLUGIN / "wp-utility-suite.php"
VERSION = re.search(r"(?m)^ \* Version: (\d+\.\d+\.\d+)$", ENTRY.read_text(encoding="utf-8")).group(1)
ARCHIVE = ROOT / f"wp-utility-suite-{VERSION}.zip"
REQUIRED = [
    ENTRY,
    PLUGIN / "uninstall.php",
    PLUGIN / "readme.txt",
    PLUGIN / "LICENSE",
    PLUGIN / "vendor/action-scheduler/action-scheduler.php",
]

for file in REQUIRED:
    if not file.is_file():
        raise FileNotFoundError(file)

with ZipFile(ARCHIVE, "w") as output:
    for file in sorted(PLUGIN.rglob("*")):
        if not file.is_file():
            continue
        if file.is_symlink():
            raise ValueError(f"Symlink in release input: {file}")
        name = file.relative_to(ROOT).as_posix()
        entry = ZipInfo(name)
        entry.create_system = 3
        entry.external_attr = 0o100644 << 16
        output.writestr(entry, file.read_bytes(), ZIP_DEFLATED, compresslevel=9)

with ZipFile(ARCHIVE) as output:
    bad_file = output.testzip()
    if bad_file:
        raise ValueError(f"Failed ZIP integrity check: {bad_file}")

checksum = sha256(ARCHIVE.read_bytes()).hexdigest()
ARCHIVE.with_suffix(".zip.sha256").write_text(f"{checksum}  {ARCHIVE.name}\n", encoding="ascii", newline="\n")
print(f"{ARCHIVE.name}  {checksum}")
