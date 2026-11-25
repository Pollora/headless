# Changelog

All notable changes are listed here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project follows semantic versioning (`0.x` may change between minors).

## [Unreleased]

### Added
- Front gate: redirect, holding page or JSON for the public pages, with an allow-list (administration, login, REST, GraphQL, cron, uploads, XML-RPC, sitemap, robots.txt).
- Head clean-up and XML-RPC switch-off.
- `UrlMapper`, the single place a WordPress URL becomes a front-end URL.
- Settings from `HEADLESS_CONFIG` / `HEADLESS_FRONTEND_URL` (WordPress, Bedrock) or `config/headless.php` (Pollora).
- Public surface: feeds (404), core sitemap off, origin noindex.
- `wp headless status` and `wp headless theme install [--activate]` (the empty theme WordPress needs).
- Multisite: per-site settings (`sites`), site context. Sub-directory installs.
- Integration suite against a real WordPress.
- CORS policy for the REST API (listed origins only, `Vary: Origin`, patterns, preflight).
- REST security: users routes need a login, no users provider in the core sitemap, `DISALLOW_FILE_EDIT`.
- REST speed: `Cache-Control`, `ETag` and 304 on public answers, `per_page` ceiling.
- Front-end links: permalinks and content links point to the front end.
- Multilingual sites: Polylang and WPML, a front end per language (`frontend_urls`).
- API tokens with scopes, routes, expiry and rotation (`wp headless token`).
- Preview of drafts, scheduled and private posts from the front end: one-time link token, session, `GET /wp-json/headless/v1/preview/posts/{id}` (autosave, live or revision).
- Revalidation: signed notifications of changes (posts, terms, menus, site options, files) to the front end, merged, off the request (Action Scheduler or WP-Cron), with retries; `wp headless revalidate ping`.
- Site manifest: `GET /wp-json/headless/v1/site` (site, languages, menus as trees, locations, front page, content types and taxonomies).
- Redirects: rules managed with `wp headless redirect`, the old slugs of renamed posts, `GET /wp-json/headless/v1/redirects` and `/redirects/resolve`.
- Media: `GET /wp-json/headless/v1/media/{id}` (one shape for every kind, with the focal point of `pollora/media`), the `headless_featured_media` field, and `media.cdn_url` to serve the uploads from a CDN.
- Blocks as stable JSON: the field `headless_blocks` of a post, when `_fields` names it.
- SEO metadata in one shape (`headless_seo` on posts and terms) from Yoast SEO, Rank Math, All in One SEO and SEOPress, with every address on the front end; the redirections of Rank Math join the site's redirects.
- Optional WPGraphQL support: its settings imposed in code (introspection, debug, batch size, depth), CORS from the package policy, API tokens with scopes (a read token cannot run a mutation).
- `wp headless schema openapi` and `wp headless schema graphql`: the schema of the API as files for a client generator.
- `GET /wp-json/headless/v1/search`: the site search in one shape, with front-end addresses (`headless/search/args` filter).
- `GET /wp-json/headless/v1/forms` and `/forms/{provider}/{id}`: forms and how to send them, from Contact Form 7 (`headless/forms/providers` filter for other plugins).
- `X-GraphQL-Preview` among the request headers CORS allows.
- mu-plugin loader (`stubs/pollora-headless.php`) and a Pollora service provider discovered by Composer.
