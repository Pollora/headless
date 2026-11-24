# Pollora Headless

Prepare WordPress for headless mode: lock the public front, remove what a headless site does not use, and (in the releases to come) secure and speed up the API, preview drafts from a JS front end and tell it when content changes.

> **Status: `0.2`, not released.** The front gate and clean-up (0.1), then CORS, REST security and speed, front-end links, multilingual sites and API tokens (0.2) are in. The API may change until `1.0`. The roadmap below says what comes next; the API may change until `1.0`.

It works on a classic WordPress, on Bedrock and inside a [Pollora](https://pollora.dev) project, with the same core. Any Node application can use it: the contract is plain HTTP, no client library is needed.

## What is in

| Module | What it does |
| --- | --- |
| Front gate | A request for a public page is redirected to the same path on your front end (302 by default), answered with a holding page, or with JSON. The administration, the login, `/wp-json`, `/graphql`, the cron, the uploads, `xmlrpc.php`, the sitemap and `robots.txt` are never touched; you add more with `allow`. |
| Head clean-up | Removes the generator, RSD, shortlink, feeds, emoji, oEmbed and REST discovery from the page head and the headers, and turns XML-RPC and the pingback header off. |
| Public surface | Feeds answer 404, the core sitemap is off (its URLs are WordPress's), and the WordPress host tells search engines not to index it (`X-Robots-Tag`, `robots.txt`, robots meta). |
| Empty theme | `wp headless theme install --activate` installs the one empty theme WordPress needs: without a valid theme it falls into recovery mode for administrators. |
| Status | `wp headless status` shows the setup and what looks wrong (no front URL, a front on the same host, no theme, WP-Cron). |
| CORS | WordPress answers any origin and allows credentials. The package answers the listed origins only (the front-end URL is always one), never `*`, with `Vary: Origin`, `https://*.example.com` patterns and preflight. |
| REST security | `/wp/v2/users` answers 401 to a visitor (core lists every author and their login), the core sitemap loses its users provider, the file editors are off. |
| REST speed | Anonymous successful GET/HEAD on `/wp/v2` carry `Cache-Control` (`public`, `max-age`, `s-maxage`, `stale-while-revalidate`), an `ETag` and a 304; a `per_page` ceiling. Never for a logged-in user or an `Authorization` request. |
| Front-end links | Permalinks of posts, pages, custom post types, terms, archives and authors (so the REST `link`, "View post", feeds and sitemaps), and the links inside a post's content, point to the front end. Files and the API stay on WordPress. |
| Languages | Polylang and WPML: each language may have its own front end (`frontend_urls`), and a post is mapped with its own language. (WPML is closed source: unit-tested only.) |
| API tokens | `wp headless token create <user> --scopes=read --routes=/wp/v2/posts --expires=90d`: `Authorization: Bearer hl.…`, as the user, limited to read or write and to route prefixes. Only a hash is stored; a wrong token answers 401, a request outside the scopes 403. `rotate` keeps the old token alive for a grace period. |
| URL mapper | `UrlMapper::toFront()` turns a URL of the WordPress site into the URL of the same page on the front end. It is the one place every URL the package hands out goes through. |

Nothing changes on the public site until you set the front-end URL: a site that is not told where its front end lives is never locked.

## Install

```bash
composer require pollora/headless
```

**Pollora**: nothing to do, Laravel loads the service provider. Set `HEADLESS_FRONTEND_URL` in `.env`, or publish the settings with `php artisan vendor:publish --tag=headless-config`.

**Bedrock or classic WordPress**: copy `stubs/pollora-headless.php` to the mu-plugins directory (Composer's autoloader must be loaded first: Bedrock does it) and define the settings before WordPress loads:

```php
define('HEADLESS_FRONTEND_URL', 'https://www.example.com');
// or every option:
define('HEADLESS_CONFIG', ['frontend_url' => 'https://www.example.com', 'redirect_status' => 302, 'allow' => ['/wc-api']]);
```

`config/headless.php` lists every option with its default. On a multisite network, give one site its own settings with `'sites' => [2 => ['frontend_url' => 'https://blog.example.com']]` in `HEADLESS_CONFIG`. WordPress installed in a sub-directory (`https://example.com/wp`) works: the gate reads paths relative to it.

## Roadmap

`0.1` core · `0.2` CORS, REST security and speed, URL mapping, Polylang and WPML, API tokens (this release) · `0.3` content preview · `0.4` revalidation webhooks · `0.5` site manifest, media, blocks as JSON, redirects · `0.6` SEO (Yoast, Rank Math, AIOSEO, SEOPress, The SEO Framework) · `0.7` optional WPGraphQL, schema snapshots, forms and search · `0.8` doctor, end-to-end tests · `1.0` frozen contract.

## Quality

The repository follows the [Pollora harness](https://github.com/Pollora/harness) (profile `core`): `composer qa` runs every gate.

## License

MIT.
