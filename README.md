# Pollora Headless

Prepare WordPress for headless mode: lock the public front, remove what a headless site does not use, and (in the releases to come) secure and speed up the API, preview drafts from a JS front end and tell it when content changes.

> **Status: `0.3`, not released.** The front gate and clean-up (0.1), CORS, REST security and speed, front-end links, multilingual sites and API tokens (0.2), and the preview of drafts (0.3) are in. The API may change until `1.0`. The roadmap below says what comes next; the API may change until `1.0`.

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
| Preview | The editor's Preview button opens your front end with a one-time token; the front end trades it for a session and reads the draft, scheduled or private post, with the editor's latest autosave laid over it. See [Preview](#preview-from-your-front-end). |
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

## Preview from your front end

No library is needed: any Node application (Next.js, Nuxt, Astro, SvelteKit, Remix…) calls two HTTP endpoints. Set `HEADLESS_FRONTEND_URL`; the Preview button of the editor then opens `https://www.example.com/api/preview?token=…&id=42&type=post&path=/?p=42&site=1&locale=en_US` (`preview.url` changes the path). The token works **once**, for **five minutes**, for **one post** and **one user**.

```js
// Your front end, at /api/preview (a route handler, server side)
const base = 'https://cms.example.com/wp-json/headless/v1/preview';

// 1. Trade the link token for a session (valid 30 minutes, read-only, bound to this post)
const exchange = await fetch(`${base}/exchange`, {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ token }),
});
if (!exchange.ok) return new Response((await exchange.json()).code, { status: exchange.status });
const { session, post } = await exchange.json(); // { session: 'hlp.…', expires_in: 1800, post: { id, type, status, link } }

// 2. Keep the session on your own origin (an HttpOnly cookie), then read the post with it
const preview = await fetch(`${base}/posts/${post.id}`, { headers: { Authorization: `Bearer ${session}` } });
const data = await preview.json(); // the post as WordPress's REST API gives it, with data.preview = { source, status, modified_gmt, scheduled_gmt }
```

- `GET …/posts/{id}?rev=autosave` (default) lays the latest autosave over the post; `rev=live` is the saved post; `rev=<revision id>` is a revision. `_fields` works.
- Answers carry `Cache-Control: no-store, private`, `Vary: Authorization` and `X-Robots-Tag: noindex`.
- Errors have a stable `code`: `preview_token_invalid`, `preview_token_expired` (401), `preview_token_used` (409), `preview_forbidden` (403), `preview_not_found` (404).
- **Redirect to the post's own address, read from the CMS, never to a value of the request** (an open redirect otherwise): the answer's `link`, or your own route for `type` and `id`.
- Drafts and the post's author: WordPress autosaves a draft into the post itself (`preview.source` is then `live`); for a published or someone else's post the autosave is separate.
- Like WordPress's own preview, only the title, content and excerpt come from the autosave; the featured image, terms and meta are those of the saved post.
- Every read checks that the user can still edit the post, so removing their right ends the session at once.
- The signing key is derived from the WordPress salts; set `HEADLESS_SECRET` (or `HEADLESS_PREVIEW_SECRET` on Pollora) to share one between servers.

## Roadmap

`0.1` core · `0.2` CORS, REST security and speed, URL mapping, Polylang and WPML, API tokens · `0.3` content preview (this release) · `0.4` revalidation webhooks · `0.5` site manifest, media, blocks as JSON, redirects · `0.6` SEO (Yoast, Rank Math, AIOSEO, SEOPress, The SEO Framework) · `0.7` optional WPGraphQL, schema snapshots, forms and search · `0.8` doctor, end-to-end tests · `1.0` frozen contract.

## Quality

The repository follows the [Pollora harness](https://github.com/Pollora/harness) (profile `core`): `composer qa` runs every gate.

## License

MIT.
