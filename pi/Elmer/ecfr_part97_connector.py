#!/usr/bin/env python3
"""Download, parse, cache, and install current 47 CFR Part 97."""

import argparse
import gzip
import json
import os
import re
import shutil
import sqlite3
import tempfile
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET
from datetime import datetime, timezone
from pathlib import Path

SOURCE_ID = "fcc-part-97"
TITLES_URL = "https://www.ecfr.gov/api/versioner/v1/titles.json"
FULL_URL = "https://www.ecfr.gov/api/versioner/v1/full/{date}/title-47.xml?part=97"
HUMAN_BASE = "https://www.ecfr.gov/current/title-47/chapter-I/subchapter-D/part-97"


def _download_json(url):
    req = urllib.request.Request(url, headers={"Accept": "application/json", "User-Agent": "RigPi-Elmer/1.0"})
    with urllib.request.urlopen(req, timeout=45) as response:
        return json.load(response)


def _download_xml(url):
    req = urllib.request.Request(url, headers={"Accept": "application/xml", "Accept-Encoding": "gzip", "User-Agent": "RigPi-Elmer/1.0"})
    with urllib.request.urlopen(req, timeout=90) as response:
        data = response.read(2 * 1024 * 1024 + 1)
        encoding = response.headers.get("Content-Encoding", "").casefold()
    if len(data) > 2 * 1024 * 1024:
        raise ValueError("The eCFR Part 97 response is unexpectedly large")
    if encoding == "gzip" or data[:2] == b"\x1f\x8b":
        data = gzip.decompress(data)
    return data


def current_title_47_date():
    payload = _download_json(TITLES_URL)
    for title in payload.get("titles", []):
        if int(title.get("number", 0)) == 47:
            value = str(title.get("up_to_date_as_of", "")).strip()
            if re.fullmatch(r"\d{4}-\d{2}-\d{2}", value):
                return value
    raise ValueError("The current Title 47 date was not present in the eCFR response")


def _tag(element):
    return element.tag.rsplit("}", 1)[-1].upper()


def _text(element):
    return re.sub(r"\s+", " ", " ".join(element.itertext())).strip()


def _section_body(section):
    blocks = []
    for element in section.iter():
        tag = _tag(element)
        if tag in {"P", "FP", "NOTE", "SOURCE", "AUTH"}:
            value = _text(element)
            if value:
                blocks.append(value)
        elif tag in {"ROW", "TR"}:
            values = [_text(entry) for entry in element if _tag(entry) in {"ENTRY", "TH", "TD"}]
            values = [value for value in values if value]
            if values:
                blocks.append(" | ".join(values))
    result = []
    for block in blocks:
        if not result or block != result[-1]:
            result.append(block)
    return "\n".join(result)


def parse_part97(xml_bytes, as_of):
    root = ET.fromstring(xml_bytes)
    part = next((node for node in root.iter() if _tag(node) == "DIV5" and node.get("N") == "97"), None)
    if part is None:
        raise ValueError("The eCFR response did not contain Part 97")
    documents = []
    for subpart in [node for node in part.iter() if _tag(node) == "DIV6"]:
        subpart_head = next((_text(child) for child in subpart if _tag(child) == "HEAD"), "")
        for section in [node for node in subpart if _tag(node) == "DIV8" and node.get("TYPE") == "SECTION"]:
            number = str(section.get("N", "")).strip()
            if not re.fullmatch(r"97\.\d+", number):
                continue
            heading = next((_text(child) for child in section if _tag(child) == "HEAD"), f"§ {number}")
            body = _section_body(section)
            if not body:
                continue
            suffix = number.split(".", 1)[1]
            stable_id = "ECFR-47-97-" + suffix
            heading_label = re.sub(r"^§?\s*" + re.escape(number) + r"\s*", "", heading).strip()
            title = f"47 CFR § {number} — {heading_label}"
            source_url = f"{HUMAN_BASE}/section-{number}"
            local_ref = "elmer://source/fcc-part-97/document/" + urllib.parse.quote(stable_id, safe="")
            content = (f"Official United States amateur-radio regulation. Current as of {as_of}.\n"
                       f"Part 97 — Amateur Radio Service. {subpart_head}\n{heading}\n{body}")
            documents.append({
                "stable_id": stable_id, "title": title, "path": f"title-47/part-97/section-{number}",
                "source_type": "ecfr_regulation", "authority": 100,
                "words": len(re.findall(r"\b\w+\b", content)), "author": "Federal Communications Commission",
                "published_at": as_of + "T00:00:00+00:00", "thread_id": "",
                "source_url": source_url, "local_ref": local_ref, "body": content,
            })
    if len(documents) < 40:
        raise ValueError(f"Only {len(documents)} Part 97 sections were parsed")
    return documents


def sync_cache(cache_dir):
    cache_dir = Path(cache_dir)
    cache_dir.mkdir(parents=True, exist_ok=True)
    as_of = current_title_47_date()
    url = FULL_URL.format(date=as_of)
    xml_bytes = _download_xml(url)
    documents = parse_part97(xml_bytes, as_of)
    (cache_dir / "part-97.xml").write_bytes(xml_bytes)
    (cache_dir / "documents.json").write_text(json.dumps(documents, ensure_ascii=False, indent=2), encoding="utf-8")
    manifest = {
        "source_id": SOURCE_ID, "title": "47 CFR Part 97 — Amateur Radio Service",
        "title_number": 47, "part": 97, "up_to_date_as_of": as_of,
        "retrieved_at": datetime.now(timezone.utc).isoformat(), "source_url": url,
        "documents": len(documents),
    }
    (cache_dir / "manifest.json").write_text(json.dumps(manifest, indent=2), encoding="utf-8")
    return manifest


def load_cached_ecfr(cache_dir, connector):
    cache_dir = Path(cache_dir)
    manifest = json.loads((cache_dir / "manifest.json").read_text(encoding="utf-8"))
    documents = json.loads((cache_dir / "documents.json").read_text(encoding="utf-8"))
    if manifest.get("source_id") != connector.get("id", SOURCE_ID) or not documents:
        raise ValueError("The cached eCFR source is invalid")
    authority = int(connector.get("authority", 100))
    for document in documents:
        document["authority"] = authority
    return {
        "source_id": connector.get("id", SOURCE_ID), "name": connector.get("name", manifest.get("title", SOURCE_ID)),
        "tags": connector.get("tags", []), "source_type": "ecfr_part", "authority": authority,
        "path": str(cache_dir), "summary": {"documents": len(documents), "as_of": manifest.get("up_to_date_as_of", "")},
        "manifest": manifest, "documents": documents,
    }


def install_in_database(db_path, report, backup=True):
    db_path = Path(db_path)
    if not db_path.exists():
        raise FileNotFoundError(db_path)
    db = sqlite3.connect(db_path)
    try:
        if backup:
            stamp = datetime.now().strftime("%Y%m%d-%H%M%S")
            backup_db = sqlite3.connect(db_path.with_name(db_path.name + f".bak-part97-{stamp}"))
            try:
                db.backup(backup_db)
            finally:
                backup_db.close()
        with db:
            db.execute("DELETE FROM chunks WHERE source_id=?", (report["source_id"],))
            db.execute("DELETE FROM documents WHERE source_id=?", (report["source_id"],))
            db.execute("DELETE FROM sources WHERE id=?", (report["source_id"],))
            db.execute("INSERT INTO sources VALUES(?,?,?,?,?,?,?)", (
                report["source_id"], report["source_type"], report["path"], report["authority"], 1,
                len(report["documents"]), datetime.now(timezone.utc).isoformat()))
            for document in report["documents"]:
                db.execute("INSERT INTO documents(stable_id,title,path,source_id,source_type,authority,word_count,quality_score,author,published_at,thread_id,source_url,local_ref) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)", (
                    document["stable_id"], document["title"], document["path"], report["source_id"],
                    document["source_type"], document["authority"], document["words"], None,
                    document["author"], document["published_at"], "", document["source_url"], document["local_ref"]))
                text = document["body"]
                for offset in range(0, len(text), 1200):
                    db.execute("INSERT INTO chunks VALUES(?,?,?,?,?,?,?,?,?,?)", (
                        document["stable_id"], document["title"], document["path"], text[offset:offset + 1200],
                        report["source_id"], document["source_type"], document["author"], "",
                        document["source_url"], document["local_ref"]))
    finally:
        db.close()


def main():
    parser = argparse.ArgumentParser(description="Synchronize current eCFR Part 97 for Ask Elmer")
    parser.add_argument("--cache", required=True)
    parser.add_argument("--db")
    parser.add_argument("--no-backup", action="store_true")
    args = parser.parse_args()
    manifest = sync_cache(args.cache)
    connector = {"id": SOURCE_ID, "name": manifest["title"], "authority": 100}
    report = load_cached_ecfr(args.cache, connector)
    if args.db:
        install_in_database(args.db, report, not args.no_backup)
    print(json.dumps({"as_of": manifest["up_to_date_as_of"], "sections": manifest["documents"], "database": args.db or ""}))


if __name__ == "__main__":
    main()
