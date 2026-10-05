# rigpi-sdr

Raspberry Pi SDR station repository containing the web UI, Pi-side configuration, and supporting operational files for a live SDR environment.

## Overview

This repository combines a PHP/HTML SDR web application with the associated Raspberry Pi runtime environment used for monitoring, logging, rig control, keying, FT8/CW workflows, and general station operations.

## Architecture

### html/
Browser-facing application layer for the station, including PHP pages, UI assets, support scripts, and operational interfaces.

### pi/
Raspberry Pi runtime environment with local configuration, generated data, logs, source trees, and operational artifacts.

## Quick start

```bash
git clone https://github.com/hlnurse/rigpi-sdr.git
cd rigpi-sdr
```

Review the `html/` and `pi/` directories before use. The web app is in `html/`, and the Pi environment is in `pi/`.

## Installation

1. Install a web stack suitable for PHP, such as Apache or Nginx with PHP.
2. Configure the web server to serve the `html/` directory.
3. Enable database support if the UI requires MariaDB/MySQL.
4. Check the `pi/` folder for machine- and environment-specific files before deploying on another host.
5. Ensure required directories are writable and any runtime configuration is valid.

## Usage

This repo is best used as:

- a live station snapshot
- a reference for a Raspberry Pi SDR web environment
- a starting point for a cleaned, reusable SDR project

Common commands:

```bash
git status
git pull origin main
git add .
git commit -m "Update station files"
git push origin main
```

## Notes

- This appears to be an operational snapshot rather than a minimal software library.
- The repository includes generated files, large artifacts, and machine-specific configuration.
- Before reuse on a new machine, review local paths, permissions, and hardware assumptions.
- Large generated files may need Git LFS or selective cleanup for long-term maintenance.

## License

This repository contains multiple components, generated files, and bundled content. Check individual files and directories before reuse or redistribution, as some assets may carry their own licensing terms.
