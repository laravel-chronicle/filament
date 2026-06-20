# Changelog

All notable changes to `laravel-chronicle/filament` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Package manifest (`composer.json`) for `laravel-chronicle/filament`: PHP 8.2+, Filament 4/5, Laravel 12/13, `laravel-chronicle/core` ^1.13, with Pest/PHPStan/Pint tooling and `composer test` scripts.
- `ChronicleFilamentServiceProvider` (Spatie package-tools) publishing `config/chronicle-filament.php` - navigation group/sort, slug, verification toggle + queue threshold + result-store connection, and `entry_model` (default `\Chronicle\Entry\Entry::class`) - plus a `chronicle-filament` views namespace.
- Orchestra Testbench + Pest test harness (`tests/TestCase.php`, `tests/Pest.php`, `phpunit.xml.dist`) covering provider auto-discovery, config defaults, and config publishing.
- Static-analysis and code-style gate: PHPStan via Larastan at level 10 with no baseline (`phpstan.neon.dist`) and Laravel Pint on the default preset.
