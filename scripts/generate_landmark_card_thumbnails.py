"""Create versioned WebP previews for public cultural photos."""

import argparse
import hashlib
import os
import re
import tempfile
from pathlib import Path

from PIL import Image, ImageOps


THUMBNAIL_DIRECTORY = "card-thumbnails-v1"
THUMBNAIL_NAME = re.compile(r"[0-9a-f]{64}\.webp")
SOURCE_EXTENSIONS = {".jpg", ".jpeg", ".png", ".webp", ".gif"}
MAX_SIZE = (480, 420)
Image.MAX_IMAGE_PIXELS = 24_000_000


def thumbnail_name(path: str, stat: os.stat_result) -> str:
    fingerprint = f"{path}\n{stat.st_ino}\n{int(stat.st_mtime)}\n{stat.st_size}"
    return hashlib.sha256(fingerprint.encode()).hexdigest() + ".webp"


def write_thumbnail(source: Path, target: Path, stat: os.stat_result) -> bool:
    with Image.open(source) as original:
        image = ImageOps.exif_transpose(original)
        mode = "RGBA" if image.mode in ("RGBA", "LA") or "transparency" in image.info else "RGB"
        image = image.convert(mode)
        image.thumbnail(MAX_SIZE, Image.Resampling.LANCZOS)

    with tempfile.NamedTemporaryFile(dir=target.parent, suffix=".tmp", delete=False) as output:
        temp_path = Path(output.name)
        try:
            image.save(output, "WEBP", quality=75, method=4)
            output.flush()
            current = source.stat()
            if output.tell() >= stat.st_size or (
                current.st_ino, current.st_mtime_ns, current.st_size
            ) != (stat.st_ino, stat.st_mtime_ns, stat.st_size):
                return False
            temp_path.chmod(0o644)
            os.replace(temp_path, target)
            return True
        finally:
            temp_path.unlink(missing_ok=True)


def generate(uploads: Path) -> None:
    contents = uploads / "contents"
    if not contents.is_dir():
        raise FileNotFoundError(contents)

    output = uploads / THUMBNAIL_DIRECTORY
    output.mkdir(exist_ok=True)
    expected = set()
    generated = 0
    for source in contents.glob("*/images/*"):
        if not source.parent.parent.name.isascii() or not source.parent.parent.name.isdecimal():
            continue
        if source.suffix.lower() not in SOURCE_EXTENSIONS or source.is_symlink() or not source.is_file():
            continue

        try:
            stat = source.stat()
            name = thumbnail_name(source.relative_to(uploads).as_posix(), stat)
            expected.add(name)
            target = output / name
            if not target.is_file() and write_thumbnail(source, target, stat):
                generated += 1
        except (OSError, ValueError, Image.DecompressionBombError) as error:
            print(f"Skipped {source.relative_to(uploads)}: {error}", flush=True)

    for target in output.iterdir():
        if THUMBNAIL_NAME.fullmatch(target.name) and target.name not in expected:
            target.unlink(missing_ok=True)

    print(f"Created {generated} thumbnail(s) from {len(expected)} source image(s).")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("uploads", type=Path)
    generate(parser.parse_args().uploads)
