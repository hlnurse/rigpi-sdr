# rigpi-sdr

A repository snapshot for a Raspberry Pi SDR station and web control environment. It includes a PHP-based SDR web interface and a large collection of Pi-side configuration, generated data, and supporting SDR tooling used for radio operations, logging, keying, and related experiments.

## Repository layout

### html/
This directory contains the web application layer for the SDR station. It includes PHP files, HTML pages, CSS/JS assets, helper modules, and station management pages such as:

- SDR control and status pages
- user and settings interfaces
- logbook and scheduler views
- keyer, CAT, spots, and rotor pages
- database-backed support and asset directories
- Bootstrap and vendor dependencies for UI rendering

The content appears to be a live operational web UI for managing SDR functionality and related station behavior.

### pi/
This directory contains the Raspberry Pi environment and supporting artifacts used by the station. It includes:

- system and user configuration files
- SDR-related source trees and software components
- Elmer knowledge and output folders
- FT8 / SDR / signal-processing related project directories
- runtime caches, generated outputs, and research artifacts
- distribution notes and project snapshots

This area is broader and more operational than the `html/` application layer, and includes both source content and generated data from the Pi environment.

## Notes

- This repository appears to be a working, operational snapshot rather than a clean library-style project.
- Some generated data, archives, and database files are large and may require Git LFS or selective cleanup if you plan to reuse the repo heavily.
- The Pi-side content includes many environment-specific files and build artifacts, so it is best treated as a local operational archive or station backup unless intentionally curated.

## Intended use

This project is useful for:

- preserving a Raspberry Pi SDR station setup
- reviewing a live web UI and backend structure
- inspecting SDR, FT8, and station automation artifacts
- using the repository as a starting point for a cleaner, production-oriented SDR project

## License

This repository includes multiple components and generated artifacts. Check individual directories and bundled files for any licensing requirements before redistribution or reuse.
