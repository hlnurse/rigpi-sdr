# rigpi-sdr

A Raspberry Pi SDR station repository containing both the web UI layer and the Pi-side operational environment used to run, monitor, and manage SDR-related workflows.

This repository appears to be a snapshot of a live station installation rather than a small library or clean starter project. It includes operational web pages, configuration data, generated outputs, and supporting source trees used for SDR monitoring, logging, FT8/CW workflows, and related radio automation.

## Architecture overview

The repository is organized into two primary areas:

### html/
The `html/` directory is the web-facing application layer. It contains PHP scripts, HTML pages, CSS/JS assets, plugin/vendor dependencies, and station management interfaces used for:

- SDR status and control pages
- user and access management
- logging and scheduler interfaces
- keyer and CAT integration views
- spots, rotor, and related operational tools
- generated content and helper assets for the station UI

This is the application layer that presents the station functions to the browser.

### pi/
The `pi/` directory holds the Raspberry Pi environment and supporting operational content. It contains:

- user and system configuration files
- radio software and source trees
- device/tooling directories for SDR work
- Elmer and FT8-related project folders
- caches, generated reports, logs, and output artifacts
- distribution notes and environment-specific helper scripts

This layer reflects the actual machine state of a working Pi-based SDR deployment and is broader and more operational than a clean source-only codebase.

## Typical deployment model

A practical deployment pattern for this repository is:

1. Deploy the web application from `html/` on a web server or local hosting stack.
2. Run the Raspberry Pi-side tooling from `pi/` as part of the station environment.
3. Connect the web UI to SDR-related services and local radio/control layers.
4. Use the generated logs, databases, and helper folders to operate the station and troubleshoot runtime issues.

## Installation steps

The exact steps depend on how you intend to use the repo, but the following is a practical baseline.

### 1. Prerequisites

You will likely need:

- Raspberry Pi OS or a Debian-based system
- Apache or Nginx
- PHP and required PHP extensions
- MariaDB or MySQL (if the web UI uses database-backed features)
- Git
- Optional: Git LFS for large files in the repo

### 2. Clone the repository

```bash
git clone https://github.com/hlnurse/rigpi-sdr.git
cd rigpi-sdr
```

### 3. Review repo content

```bash
ls -la
find html -maxdepth 2 -type d | head
find pi -maxdepth 2 -type d | head
```

### 4. Configure the web application

If you are using the PHP front end in `html/`:

- configure the web server to serve `html/`
- make sure PHP is enabled
- ensure the required database and runtime directories are writable
- verify any `.env`, config, or database connection settings used by the application

Example Apache-style layout:

```bash
sudo apt-get update
sudo apt-get install apache2 php php-mysql mariadb-server
sudo chown -R www-data:www-data html
```

Then configure your virtual host or document root to point at the repository’s `html/` directory.

### 5. Configure the Pi-side environment

The files in `pi/` are environment-specific. Any local Pi-specific deployment should be reviewed carefully before using them as-is.

Check for:

- configuration files
- local paths
- generated caches
- database or runtime state files
- custom scripts or subsystems intended for specific hardware or station layouts

### 6. Large-file handling

This repository contains large generated files and database artifacts. GitHub may warn about oversized files. For best results, consider using Git LFS for large artifacts if you plan to manage the repo long-term.

```bash
git lfs install
git lfs track "*.db" "*.sql" "*.tar.gz"
git add .gitattributes
git commit -m "Track large files with Git LFS"
```

## Usage notes

### Intended use

This repo is best treated as:

- a station snapshot
- an operational archive
- a reference implementation for a Raspberry Pi SDR web environment
- a base for further cleanup and production hardening

### Operational caution

Because much of the content appears to be generated or machine-specific, it is recommended to:

- review before deploying to a different host
- remove or ignore machine-local artifacts if they are not needed
- validate any copy of the repo against your target radio, SDR, and server environment
- avoid committing sensitive local configuration or private runtime state

### Working with the repo

Common commands:

```bash
git status
git pull origin main
git add .
git commit -m "Update station files"
git push origin main
```

### Recommended cleanup workflow

If this repository is meant to become a maintainable codebase rather than a local archive, consider a cleanup phase that includes:

- removing temporary logs and generated data
- separating environment-specific config from source-controlled app files
- archiving large runtime artifacts outside the repo or tracking them with Git LFS
- narrowing the project to the actual reusable web app and SDR drivers

## Repository status

This project currently looks like a working SDR station environment with supporting application code and Pi-generated artifacts. It is useful for research, operational history, and restoration of a specific setup, but it may need organization before formalizing as a repeatable software project.

## License

The repository contains multiple components, generated artifacts, and third-party content. Check the relevant files and directories before reuse, redistribution, or publication. Some assets may carry their own licensing terms.

## Suggested next steps

1. Decide whether this will remain an operational archive or be cleaned into a reusable project.
2. Identify the actual deployable web app and ignore runtime-only artifacts.
3. Consider Git LFS or selective cleanup for large database and archive files.
4. Review the `html/` app and `pi/` environment separately for documentation and deployment needs.
