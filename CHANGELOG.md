# Changelog

All notable changes are listed here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project follows semantic versioning (`0.x` may change between minors).

## [Unreleased]

### Added
- Front gate: redirect, holding page or JSON for the public pages, with an allow-list (administration, login, REST, GraphQL, cron, uploads, XML-RPC, sitemap, robots.txt).
- Head clean-up and XML-RPC switch-off.
- `UrlMapper`, the single place a WordPress URL becomes a front-end URL.
- Settings from `HEADLESS_CONFIG` / `HEADLESS_FRONTEND_URL` (WordPress, Bedrock) or `config/headless.php` (Pollora).
- mu-plugin loader (`stubs/pollora-headless.php`) and a Pollora service provider discovered by Composer.
