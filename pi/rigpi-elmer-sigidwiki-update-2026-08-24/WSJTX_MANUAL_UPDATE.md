# RigPi Elmer — WSJT-X User Guide ingestion

This update gives Elmer full, versioned access to the official WSJT-X User
Guide instead of relying mainly on incidental source-code matches.

## What changes

- Downloads and sections the official WSJT-X 3.0.0 HTML User Guide.
- Indexes the guide's AsciiDoc source files from the official WSJTX repository.
- Marks both forms as official manual evidence and ranks the versioned HTML
  guide ahead of implementation source code.
- Recognizes the common `FTS4W` transposition as `FST4W` during retrieval.
- Instructs Elmer not to claim the manual is unavailable when manual evidence
  is present.
- Preserves every existing connector and creates a configuration/database
  backup before rebuilding.

## Install on RigPi

Copy this release tree to the RigPi, then run from the tree's root:

```sh
sudo bash deployed/pi/Elmer/deploy/install_wsjtx_manual.sh
```

The installer uses `/home/pi/Elmer` and `/home/pi/venv/bin/python`, downloads
the two official WSJT-X sources, rebuilds the full knowledge database, validates
at least 40 manual sections from each source form, and restarts `elmer-web` only
if it was already running. Set `ELMER_ROOT` or `ELMER_PYTHON` if the local paths
differ.

If Elmer is using the cloud pilot, deploy the resulting
`/home/pi/Elmer/output/knowledge.db` to the cloud host as described below.

## Update the running cloud host

Copy two things from the Pi to a temporary directory on the cloud host:

1. This same extracted release tree.
2. `/home/pi/Elmer/output/knowledge.db`, after the Pi installer completes.

On the cloud host, run:

```sh
sudo bash elmer_cloud_pilot/deploy/install_wsjtx_manual_update.sh knowledge.db
```

The updater validates the database, backs up the current answer/retrieval files
and database under `/opt/elmer/backups`, installs the update, and restarts only
the Elmer services that were already running. It checks each restarted service's
local health endpoint before reporting success.

## Regression case

The automated regression asks:

> fts4w shows near 14.095 in WSJT-X. What is it, and does it use the same timing as FT8?

It verifies that the official User Guide is the first evidence source and that
the answer evidence includes FST4W's 120/300/900/1800-second sequences and the
approximately 109.3-second FST4W-120 transmit duration.
