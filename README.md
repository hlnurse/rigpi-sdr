# rigpi-sdr

A Raspberry Pi SDR station repository containing the live web interface, operational radio tooling, and supporting machine configuration used to run a station for SDR monitoring, logging, FT8/CW operations, and related radio automation workflows.

This repository is best understood as a working station snapshot rather than a minimal library or clean starter project. It includes both the browser-facing application layer (`html/`) and the Pi-side operational environment (`pi/`), which contains generated artifacts, logs, local configuration, and supporting software components.

## Overview

The project combines:

- a PHP/HTML web application for station control and monitoring
- Raspberry Pi environment files and supporting SDR tooling
- generated outputs, logs, and local machine state
- project-specific radio and FT8-related directories for operational tasks

This makes it useful for preserving a working SDR setup, studying a real deployment, or using it as a base for a cleaner re-implementation.

## Features

- SDR station web UI for status and control
- PHP-based application pages for rig, rotor, keyer, spots, and settings functions
- FT8 and SDR-related tooling and project snapshots
- support for logging, schedulers, and operational data views
- Pi-side runtime configuration and custom station artifacts
- live operational snapshots for experimentation and troubleshooting

## Architecture

### html/
The `html/` directory contains the application layer used by the browser. It includes:

- PHP scripts such as `index.php`, `settings.php`, `keyer.php`, `log.php`, and `elmer.php`
- UI assets and helper directories such as `css/`, `js/`, `images/`, `assets/`, and `vendor/`
- support files for logs, scheduler controls, rotor settings, user management, and station operations
- generated/operational content such as databases, support logs, and helper scripts

This is the primary web-facing portion of the station.

### pi/
The `pi/` directory contains the Raspberry Pi-side environment. It includes:

- system/user configuration files
- runtime caches and local data directories
- source trees and operational artifacts for SDR-related software
- Elmer, FT8, SDR, and radio-support folders
- generated archives, backups, and local environment snapshots

This area is broader and more environment-specific than the `html/` app and reflects a live machine configuration.

## Installation

### Prerequisites

Depending on your deployment, you may need:

- Raspberry Pi OS or Debian/Ubuntu-based Linux
- Apache or Nginx
- PHP and relevant PHP extensions
- MariaDB/MySQL if database-backed features are enabled
- Git
- Optional: Git LFS for very large generated files

### Clone the repository

```bash
git clone https://github.com/hlnurse/rigpi-sdr.git
cd rigpi-sdr
```

### Review the project structure

```bash
ls -la
find html -maxdepth 2 -type d | head
find pi -maxdepth 2 -type d | head
```

### Configure the web application

If you want to run the browser-facing application from `html/`:

1. Configure your web server to serve the `html/` directory.
2. Enable PHP support.
3. Ensure the required directories are writable.
4. Validate database connectivity and any station config values used by the app.

Example Ubuntu/Debian setup:

```bash
sudo apt-get update
sudo apt-get install apache2 php php-mysql mariadb-server
sudo chown -R www-data:www-data html
```

Then set your web root or virtual host to the repository’s `html/` directory.

### Configure the Pi-side environment

The `pi/` directory is machine- and environment-specific. Before using it directly on another machine:

- review local paths and config
- remove or ignore runtime artifacts that are not needed
- validate software dependencies for the target hardware
- check whether the directory is meant to be copied as-is or used only as a reference snapshot

### Large files

This repository contains large generated files and databases. GitHub may warn about large file size. For long-term maintainability, consider Git LFS for large artifacts.

```bash
git lfs install
git lfs track "*.db" "*.sql" "*.tar.gz"
git add .gitattributes
git commit -m "Track large files with Git LFS"
```

## Configuration

Project configuration may be spread across:

- PHP config and runtime settings in `html/`
- local environment files in `pi/`
- generated database content and logs
- station-specific custom scripts and hardware configuration

Because the repo contains operational artifacts and machine-local files, you should expect to review these manually before deployment in a different environment.

## Usage

This repository is best used as:

- a working SDR station archive
- a reference for a live web UI implementation
- a starting point for a reworked and cleaned project structure
- a historical snapshot of a Pi-based SDR environment

Typical workflow:

```bash
git status
git pull origin main
git add .
git commit -m "Update station files"
git push origin main
```

## Maintenance recommendations

Because this repository includes generated data and environment-specific files, consider these cleanup steps if you want to turn it into a maintainable project:

1. Remove temporary logs and generated files that are not needed for source control.
2. Separate runtime environment config from application code.
3. Store large database or archive files with Git LFS or outside the repo.
4. Keep only the essential web app and reusable SDR tooling in the main branch.
5. Document hardware assumptions and local paths before sharing the repo broadly.

## Notes

- This repository is operationally rich and not yet a polished, generalized software project.
- It is most useful as a record of a specific working installation or as a base for re-engineering.
- Before deploying in a new environment, validate the hardware, service paths, permissions, and any environment-specific configuration.

## License

This repository includes multiple directories, generated outputs, and bundled components. Please check any relevant file or subdirectory for license terms before redistribution or reuse. Some artifacts may be subject to their own licensing requirements.

## Suggested next steps

1. Decide whether this remains an operational snapshot or should be cleaned into a reusable project.
2. Audit large generated files and move them to LFS or ignore them.
3. Separate the actual deployable app from the machine-specific runtime artifacts.
4. Document the station architecture and target deployment environment clearly.
