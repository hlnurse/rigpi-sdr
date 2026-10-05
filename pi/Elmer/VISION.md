# Elmer Builder Vision

## Purpose

Elmer Builder turns scattered technical information into a trusted, local, searchable knowledge library. RigPi is the proving ground, but the architecture is intended to be useful to other software and hardware projects.

## Core philosophy

1. Build and curate the knowledge before adding a conversational interface.
2. Preserve source identity, authority, dates, and supporting context.
3. Distinguish official knowledge, development history, community experience, and engineering conversations.
4. Prefer locally cached, repeatable builds over live dependencies during search.
5. Keep configuration project-neutral; product names, paths, messages, authority, and connectors must not be hard-coded.
6. Make every answer traceable to its source.
7. Treat failed attempts and superseded advice as history, not as confirmed solutions.

## Product model

- **Elmer Builder** gathers, validates, analyzes, and compiles knowledge.
- **Knowledge Connectors** acquire material from Help, Groups.io, GitHub, chat archives, local documents, and future sources.
- **Ask Elmer** is a conversational client of the compiled knowledge base.
- Search Help, documentation QA, release advice, and dashboards are additional clients of the same database.

## Connector model

Every connector has a stable ID, type, display name, enabled state, authority, category, tags, refresh policy, and connector-specific options.

Connectors normalize source material into common records while retaining original metadata. A future connector interface will provide operations equivalent to:

- validate
- discover
- synchronize
- normalize
- statistics

The initial connector families are:

- Official Help and release documentation
- Groups.io archives and contributed files
- GitHub repositories, issues, comments, releases, README files, and wikis
- AI chat archives from ChatGPT, Claude, and compatible saved formats
- Markdown, HTML, PDF, DOCX, and local-folder sources
- Community snapshots from sites that do not offer tidy exports

## Authority and evidence

Authority is a configurable ranking aid, not proof that every statement is correct.

Suggested categories:

- Official: 90–100
- Development: 80–95
- Engineering history: 70–90
- Community: 50–75
- General reference: project-defined

Ask Elmer should separate canonical instructions from supporting context and disclose when a solution appears only in beta, community, GitHub, or chat history.

## Chat archives

Chat archives are engineering notebooks, not automatically authoritative documentation. The analyzer should identify:

- Problem
- Attempt
- Test result
- Correction
- Confirmed solution
- Unresolved question

Confirmed endings and user verification should outrank attractive but unsuccessful intermediate suggestions. Privacy filtering and local-only processing are requirements.

## Knowledge intelligence

Builder should identify:

- Topics repeatedly discussed but absent from Help
- Solutions present in development or community sources but not promoted to official documentation
- Documentation that refers to outdated versions or contradicted behavior
- High-value knowledge found only in engineering chats
- Changes in support volume after documentation improvements

## Near-term roadmap

### v0.18 — Connector configuration foundation

- `config/connectors.json`
- Common connector metadata
- Backward compatibility with `sources.json`
- GitHub and AI-chat connector definitions
- This vision document

### v0.19 — Ask Elmer baseline

- Source-diverse, authority-aware evidence retrieval with citations
- Streaming cited answers through the OpenAI Responses API
- Current-guidance rules for superseded RigPi procedures
- RigPi administrator authentication, Help-menu integration, and boot service
- Safe Markdown-to-HTML answer formatting and HTML Help references
- Personalized greeting and local answer-feedback pilot
- Tested code-only upgrades that preserve station data and configuration

### Next — Cloud service foundation

- Central, versioned Elmer knowledge database on rigpi.net
- Station pairing and subscription status
- Authenticated station and user permissions
- Anonymous, rate-limited demonstration access
- Central feedback intake with a local retry queue

### GitHub connector — implemented

- Public repository synchronization with local caching
- Source files pinned to an exact commit
- Releases, issues, issue comments, and wiki content
- Pagination beyond GitHub's 1,000-record API window
- Rate-limit reporting and optional token support
- Hamlib and RigPi repository connector configurations

### Next connector implementation

- Connector registry and incremental refresh support

### Chat archive implementation

- ChatGPT personal export when available
- Claude exports
- Saved HTML, Markdown, and text conversations
- Normalized conversation/message schema
- Solution-card extraction

### Later

- Local setup dashboard
- Incremental synchronization
- Cross-source correlation
- Historical trend dashboard
- Ask Elmer conversational interface

## Guiding test

A successful build should let a developer ask, “Where did we solve this before?” and receive the confirmed solution, exact supporting material, source provenance, and relevant version context without scrolling through years of files and conversations.
