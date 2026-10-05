# RigPi Elmer v0.19

v0.19 consolidates the working knowledge builder and the first production-style
Ask Elmer interface into one tested code baseline.

## Included

- Official RigPi Help ingestion with HTML-aware extraction and Help-viewer citations
- Groups.io message and contributed-file ingestion
- Cached GitHub connectors for Hamlib, RigPi, and WSJT-X
- Authority-aware retrieval, source diversity, lifecycle guidance, and citations
- Streaming OpenAI answers with formatted headings, lists, emphasis, and links
- Administrator-only RigPi session integration and automatic systemd startup
- Personalized time-of-day greeting using the RigPi user's first name
- 1–10 answer feedback with an optional explanation and privacy notice
- Local feedback storage in `output/feedback.db` for the pilot

## Upgrade safety

The v0.19 update package contains code, deployment files, tests, documentation,
and version metadata. It intentionally does not contain or replace:

- `config/connectors.json`
- `config.json`
- `sources/`
- `cache/`
- `output/`
- `/etc/elmer/openai_api_key`

This preserves the Pi's enabled connectors, downloaded source material, compiled
knowledge database, feedback, and credentials.

## Verification

Run all tests with:

```bash
for test in tests/test_*.py; do python3 "$test"; done
```

The release archive is accompanied by a SHA-256 checksum file and a manifest of
every packaged path.
