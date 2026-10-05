# RigPi Elmer v0.19

v0.19 is the consolidated baseline for the RigPi knowledge builder and the
production-style Ask Elmer interface. See `RELEASE_0.19.md` for the included
features and upgrade-safety rules.

## Run

```bash
python3 build_knowledge.py
```

## Configuration

The root `config.json` includes modular configuration files. Knowledge inputs are now listed in `config/connectors.json` under the `connectors` array.

Each connector supports common metadata:

- `id`
- `name`
- `type`
- `enabled`
- `authority`
- `category`
- `tags`
- `refresh`
- `options`

Older `sources` arrays remain compatible.

## Included connector definitions

- Official Help ZIP
- RigPi Groups.io MBOX
- RigPi Beta Groups.io MBOX
- Hamlib GitHub placeholder
- RigPi GitHub placeholder
- ChatGPT archive placeholder
- Claude archive placeholder
- Groups.io contributed files

The GitHub connector is implemented as an explicit, cached synchronization step. Normal knowledge builds remain offline and repeatable:

```bash
python3 sync_github.py --config config.json --connector hamlib-github
python3 build_knowledge.py --config config.json
```

The cache records the exact repository commit and retains normalized releases, issues, issue comments, and wiki content. Set `GITHUB_TOKEN` before synchronization when possible to obtain a larger API rate limit. AI-chat definitions remain configuration placeholders.

Implemented GitHub connectors include `Hamlib/Hamlib`, `hlnurse/rigpi`, and `WSJTX/wsjtx`. Each connector can define its own source extensions and exclusions so bundled dependencies, generated assets, and duplicate documentation do not overwhelm project-owned implementation knowledge.

Synchronize WSJT-X before rebuilding:

```bash
python3 sync_github.py --config config.json --connector wsjtx-github
python3 build_knowledge.py --config config.json
```

Groups.io contributed files can be imported from an extracted owner export. Place the export at `sources/RigPi-2`, then run `python3 configure_groups_files.py` before rebuilding. The importer reads file metadata sidecars, extracts PDF, DOCX, RTF, and text-like formats, skips unsupported media, and removes exact duplicate files. PDF extraction requires the `pdftotext` command.

Create a source-diverse, authority-aware evidence packet for a natural-language question:

```bash
python3 question_knowledge.py "How do I configure an IC-7300 for remote audio and CAT control?"
```

Add `--json` for a machine-readable packet suitable for a later answer-generation step. The question tool retrieves and cites evidence; it does not generate unsupported answers.

`config/current_guidance.json` contains narrowly curated lifecycle rules for features
that newer authoritative documentation explicitly supersedes. Matching rules force
the current Help topic into the evidence packet and prevent historical procedures
from being presented as current. The initial rule marks Mumble as an earlier-version
workflow replaced by current browser audio.
The current remote-access rule maps no-port-forwarding questions to the authoritative
Cloudflare Tunnel topic and supplies a longer setup-focused excerpt.

Generate a cited answer through the OpenAI Responses API:

```bash
export OPENAI_API_KEY="your API key"
python3 answer_knowledge.py "How do I configure an IC-7300 for remote audio and CAT control?"
```

Answers stream to the terminal by default, so text appears as it is generated. Add
`--show-usage` to report token use and time to first text, or `--no-stream` to wait
for the complete answer before printing it.

Run the browser interface on the Pi and open `http://rigpi5.local:8090` from another
computer on the same network:

```bash
python3 elmer_web.py
```

The browser receives only streamed answer text. `OPENAI_API_KEY` remains in the Pi
process environment and is never included in the page or its requests. Completed
answers are safely formatted with headings, lists, emphasis, inline settings, and
clickable citations. Local
`elmer://` references open in an on-page source viewer; external references open
their original site.

Official Help references are treated specially: the topic opens inside the RigPi
Help viewer at `/Help/RigPi.html?<topic>` and is rendered as HTML. During knowledge builds, script, style,
noscript, and template content is excluded from searchable Help text.

For production, stop the foreground server and run `./install_elmer_service.sh` as
root. The installer adds an administrator-only **Help > Ask Elmer** entry using the
existing RigPi PHP session and `Access_Level = 1`. nginx proxies the protected API
to Elmer on `127.0.0.1:8090`; the Python port is not exposed to the LAN. The service
starts automatically at boot, and the OpenAI key is loaded as a protected systemd
credential from `/etc/elmer/openai_api_key`.

Ask Elmer greets the signed-in user by first name (falling back to callsign or
username) and adjusts GM, GA, or GE from the browser's local time. After each
completed answer, the user may submit a 1–10 rating and explanation. During the
v0.19 pilot, feedback is stored separately in `output/feedback.db`; it is never
added automatically to the knowledge database.

The default model is `gpt-5.6-terra`; override it with `--model` or `ELMER_OPENAI_MODEL`. Use `--dry-run` to inspect the complete evidence and instructions without making an API request. The API key is read only from `OPENAI_API_KEY` and is never stored in Elmer configuration or output.

For an offline comparison using the identical retrieved evidence and answering instructions, run Ollama with `qwen3:8b-q4_K_M`, then:

```bash
python3 answer_knowledge_local.py "How do I configure an IC-7300 for remote audio and CAT control?" --show-usage
```

The local adapter uses an 8192-token context by default, disables the separate thinking trace, and reports prompt tokens, generated tokens, elapsed time, and generation speed. Override the model with `--model` or `ELMER_LOCAL_MODEL`.

See `VISION.md` for the project philosophy and roadmap.
