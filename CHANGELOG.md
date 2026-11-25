# Changelog

All notable changes to siFruit are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). The version of the inherited
Core is `Modules\Core\Config\Version::CORE` (kpskeletor's); each module has its own version in its
manifest.

## [Unreleased]

### Changed

- **Updates:** installations trust the owner's minisign key by default
  (`Config\Updates::$publicKeys`; upstream kpskeletor `30ef4b0`).

### Added

- siFruit starts from **kpskeletor 1.1.0** (`c5ff670`, https://github.com/SIENSIS/kpskeletor): the
  Core and the modules of the skeleton, without kpskeletor's build sessions and reports. Its
  history is in [kpskeletor's changelog](https://github.com/SIENSIS/kpskeletor/blob/main/CHANGELOG.md).
