# Pollora Headless

Prepare WordPress for headless mode: lock the public front, remove what a headless site does not use, and (in the releases to come) secure and speed up the API, preview drafts from a JS front end and tell it when content changes.

> **Status: `0.6`, not released.** The front gate and clean-up (0.1), CORS, REST security and speed, front-end links, multilingual sites and API tokens (0.2), the preview of drafts (0.3), the notifications of changes (0.4) the normalised content (site manifest, redirects, media, blocks: 0.5) and the SEO metadata (0.6) are in. The API may change until `1.0`. The roadmap below says what comes next; the API may change until `1.0`.

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
| Revalidation | When a post is published, updated, unpublished or deleted, or a term, a menu, a site option or a file changes, the front end is told: one signed POST, merged with the other changes of the last seconds, sent off the request, tried again if it fails. See [Revalidation](#telling-your-front-end-what-changed). |
| Site manifest | `GET /wp-json/headless/v1/site`: the site and its languages, the menus as trees with front-end paths, the menu locations, the front page and the content types with their REST routes, in one public, cacheable answer. |
| Redirects | The redirects of the site for a front end: the ones you manage (`wp headless redirect add /old/ /new/`, patterns, 410 for a page that is gone) and the old addresses WordPress remembers for renamed posts, at `GET /wp-json/headless/v1/redirects` (every rule) and `/redirects/resolve?path=/old/` (one path). |
| Media | A file of the library in one shape whatever its kind (`GET /wp-json/headless/v1/media/{id}`: url, sizes, srcset, alt, caption, the focal point set with `pollora/media`), the featured image of a post in full without a second request, and the uploads served from a CDN. |
| Blocks | The blocks of a post as JSON that does not change shape: `?_fields=id,headless_blocks`. See [Blocks](#blocks-as-json). |
| SEO | The search and social metadata of a post or a term in one shape whichever plugin runs (Yoast SEO, Rank Math, All in One SEO, SEOPress), with every address on the front end: `?_fields=id,headless_seo`. See [SEO](#seo-metadata). |
| GraphQL | WPGraphQL made safe for a public site: its settings imposed in code, CORS, API tokens with scopes. See [GraphQL](#graphql-optional). |
| Schema, search, forms | `wp headless schema` (openapi or graphql), `GET /headless/v1/search`, `GET /headless/v1/forms` (Contact Form 7). |
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

## Telling your front end what changed

Add a target and a secret you share with your front end:

```php
define('HEADLESS_REVALIDATE_URL', 'https://www.example.com/api/revalidate');
define('HEADLESS_REVALIDATE_SECRET', 'a long random string');
// several targets, a debounce or more options to watch: 'revalidation' in config/headless.php
```

`wp headless revalidate ping` sends a signed `ping` to every target and says how each one answered.

**What is sent.** A change is stored when it happens and sent about three seconds later, merged with the others of those seconds (an import of 500 posts is a few notifications, not 500). It is sent by [Action Scheduler](https://actionscheduler.org) when it is loaded (WooCommerce ships it) and by WP-Cron otherwise, which runs only when a page is visited: run a system cron on a production site. A target that does not answer 2xx is tried again after 30 seconds, 2 minutes, 10 minutes and 1 hour, with the same delivery ID, then given up (a line in the PHP error log).

```json
{
  "id": "5f0c1a…",
  "sent_at": 1767225600,
  "truncated": false,
  "events": [
    { "event": "post.published", "tags": ["post:12", "type:post", "site:1", "term:5"], "urls": ["https://www.example.com/hello/"],
      "post": { "id": 12, "type": "post", "slug": "hello", "old_slug": null, "status_from": "draft", "status_to": "publish" },
      "site": 1, "locale": "en_US" }
  ],
  "tags": ["post:12", "type:post", "site:1", "term:5"],
  "urls": ["https://www.example.com/hello/"],
  "paths": ["/hello/"]
}
```

Events: `post.published`, `post.updated`, `post.unpublished` (draft, trash, private), `post.deleted`, `term.changed`, `menu.changed`, `options.changed`, `media.changed`. A draft that is saved tells nobody. `urls` are on the front end (the address the post has now, and the one it had). `locale` is the language of the post when Polylang or WPML is active. `truncated: true` means more than 200 tags or URLs changed: revalidate everything.

**Check it.** Every request carries `X-Headless-Signature: sha256=<hex>`, the HMAC-SHA256 of `<timestamp>.<raw body>` with your secret, and `X-Headless-Timestamp`. Reject a request older than five minutes, compare in constant time, and ignore a delivery ID you have already handled (`X-Headless-Delivery`: a retry has the same one).

```js
// Next.js: app/api/revalidate/route.js
import crypto from 'node:crypto';
import { revalidatePath, revalidateTag } from 'next/cache';

const seen = new Set(); // use a shared store (Redis, KV) with several instances

export async function POST(request) {
  const body = await request.text(); // the RAW body: sign what was sent, not what you parsed
  const timestamp = Number(request.headers.get('x-headless-timestamp'));
  const signature = request.headers.get('x-headless-signature') ?? '';
  const expected = 'sha256=' + crypto.createHmac('sha256', process.env.HEADLESS_REVALIDATE_SECRET).update(`${timestamp}.${body}`).digest('hex');

  const valid = Math.abs(Date.now() / 1000 - timestamp) < 300 && signature.length === expected.length
    && crypto.timingSafeEqual(Buffer.from(signature), Buffer.from(expected));
  if (!valid) return new Response('invalid signature', { status: 401 });

  const payload = JSON.parse(body);
  if (seen.has(payload.id)) return Response.json({ ok: true, repeated: true });
  seen.add(payload.id);

  payload.tags.forEach((tag) => revalidateTag(tag, 'max')); // tag your fetches: fetch(url, { next: { tags: ['post:12'] } })
  payload.paths.forEach((path) => revalidatePath(path));
  return Response.json({ ok: true });
}
```

Nuxt, Astro, SvelteKit or Express work the same way: verify, then purge whatever your framework caches by tag or by path.

## Reading the site once

```js
const site = await (await fetch('https://cms.example.com/wp-json/headless/v1/site')).json();
site.menus.find((m) => m.slug === 'main').items; // [{ id, title, url, path: '/about/', target, classes, type, object, object_id, children: [...] }]
site.locations;   // [{ location: 'primary', description: 'Primary Menu', menu: 'main' }]
site.post_types;  // [{ name: 'post', rest_base: 'posts', rest_namespace: 'wp/v2', has_archive, rewrite_slug, ... }]
```

`path` is the address on your front end (`null` for a link to another site). The answer is the same until the site changes, so it carries an `ETag`; revalidate it on the `menus`, `options` and `site:<id>` tags of the [notifications](#telling-your-front-end-what-changed).

## Redirects

```bash
wp headless redirect add /old-page/ /new-page/                       # 301 by default
wp headless redirect add '/blog/(\d{4})/(.*)' '/news/$1/$2' --regex   # a pattern, matched against the whole path
wp headless redirect add /discontinued/ --status=410                 # a page that is gone
wp headless redirect list      # every rule, from every source
wp headless redirect test /blog/2024/hello
```

```js
// at build or start time: every rule, to feed your framework's redirects
const { redirects } = await (await fetch('https://cms.example.com/wp-json/headless/v1/redirects')).json();
// or, in a middleware, one path (a 200 either way, so a CDN can keep the answer)
const { redirect } = await (await fetch(`https://cms.example.com/wp-json/headless/v1/redirects/resolve?path=${encodeURIComponent(pathname)}`)).json();
// redirect: null | { from, to, status, source: 'manual' | 'old-slug' }
```

An exact rule wins over a pattern, and the rules you write win over the old slugs. A rule is checked when it is made (a path, a destination that is a path or an absolute http(s) URL, a status of 301, 302, 307, 308 or 410, a pattern that compiles, no loop). Changing the rules is announced to the front end with the `option:headless_redirects` tag.

## Media

```js
const image = await (await fetch('https://cms.example.com/wp-json/headless/v1/media/42')).json();
// { id, kind: 'image', mime, url, title, alt, caption, description, width, height, filesize, duration,
//   focal_point: { x: 0.25, y: 0.75 } | null, sizes: { medium: { url, width, height }, …, full: {…} }, srcset, modified_gmt }

// The featured image of a post, in the same request (a field that costs something is computed only when you name it)
const post = await (await fetch('https://cms.example.com/wp-json/wp/v2/posts/12?_fields=id,title,headless_featured_media')).json();
```

`focal_point` is the part of the image to keep whatever the crop (use it as `object-position: 25% 75%`); it comes from [`pollora/media`](https://github.com/Pollora/media) and is `null` without it. A file attached to a draft answers 404 to a visitor.

To serve the uploads from a CDN or an image proxy, set `media.cdn_url` (or `HEADLESS_MEDIA_CDN_URL` on Pollora): `https://cdn.example.com` replaces `https://cms.example.com/wp-content/uploads` in the address of every file, in `srcset` and in the HTML of the content. The administration keeps the WordPress addresses, so that an editor sees a file they have just uploaded.

## Blocks as JSON

```js
const post = await (await fetch('https://cms.example.com/wp-json/wp/v2/posts/12?_fields=id,headless_blocks')).json();
post.headless_blocks;
// [ { id: '0', name: 'core/heading', attributes: { level: 3 }, html: '<h3 …>Title</h3>', rendered: '<h3 …>Title</h3>', inner_blocks: [] },
//   { id: '1', name: 'core/group', attributes: {}, html: '<div class="wp-block-group"></div>', rendered: '…', inner_blocks: [ { id: '1.0', name: 'core/paragraph', … } ] } ]
```

Every block has the same keys in the same order. `id` is its position (`1.0` is the first block inside the second): a key for a list. `attributes` are those of the block comment, completed with the defaults of the block type, `{}` when there are none; a value a block takes from its HTML (the text of a paragraph) is in `html`. `html` is the block's own HTML; `rendered` is what WordPress prints for it, inner blocks included, and is present on top-level blocks and on dynamic blocks (a list of posts has no HTML of its own). Text outside blocks (classic content) is one `core/freeform` block. A post with a password gives `null`. The field is computed only when `_fields` names it. It is not in the preview answer.

## SEO metadata

```js
const post = await (await fetch('https://cms.example.com/wp-json/wp/v2/posts/12?_fields=id,headless_seo')).json();
post.headless_seo;
// { source: 'yoast', title, description, robots: { index: true, follow: true, directives: ['max-image-preview:large'] }, canonical,
//   open_graph: { type, title, description, url, site_name, locale, images: [{ url, width, height, alt }] },
//   twitter: { card, title, description, image, site, creator }, json_ld: [ …nodes of the structured data graph ], breadcrumbs: [{ label, url }] }
```

The keys are the same whichever plugin gave them, and `null` when it has nothing. Every address (canonical, `og:url`, images, the `@id` and `url` of the structured data, the breadcrumbs) is moved from the WordPress host to your front end, which the plugins do not do. The same field is on terms (`/wp/v2/categories/5?_fields=id,headless_seo`). It is computed only when `_fields` names it.

| Plugin | Read from | Verified |
| --- | --- | --- |
| Yoast SEO | its `yoast_head_json` REST field | against the real plugin, in CI |
| Rank Math | its `/rankmath/v1/getHead` route (turn on *Headless Support* in its general settings) | not run against the plugin: unit tests on the head it prints |
| All in One SEO | its `aioseo_head_json` REST field | not run against the plugin: unit tests, read from its documentation |
| SEOPress | its `/seopress/v1/posts/{id}` and `/terms/{id}` routes | not run against the plugin: unit tests |

The Rank Math redirections of its manager (exact and pattern ones) join the [redirects](#redirects) of the site. The redirections of Yoast Premium, All in One SEO Pro and SEOPress are not read yet, and The SEO Framework is not supported yet.

## GraphQL (optional)

REST is the contract; if you also run [WPGraphQL](https://wordpress.org/plugins/wp-graphql/), the package makes it safe for a public site. Nothing happens while the plugin is not active, and the package never ships its code.

- Its settings are **imposed in code** (the `graphql` section of the settings), not written to the database: no public introspection, no debug output, batches of five at most and a depth limit of 15, because WPGraphQL has no query cost limit. Set a key to `null` to leave that setting to WPGraphQL.
- Its `Access-Control-Allow-Origin: *` is replaced by the [CORS](#install) policy of the package.
- An API token (`Authorization: Bearer hl.…`) may call it. A token without the `write` scope cannot run a mutation, and a token limited to routes needs `/graphql` among them.
- **Preview**: a server-side client sends its API token and `X-GraphQL-Preview: database_id=12`, which WPGraphQL handles itself. The preview *session* (`hlp.…`) is not accepted on GraphQL on purpose: it is made for one post, and WPGraphQL would run the request with all the rights of the editor.
- With introspection closed, a client generator reads the schema with a token, or from the file `wp headless schema graphql --file=schema.graphql`.

## Schema files

`wp headless schema openapi --namespace=wp/v2,headless/v1 --file=openapi.json` writes an OpenAPI 3.0 document of the REST API (routes, path and query parameters, request bodies), and `wp headless schema graphql` the GraphQL schema as SDL. Feed them to your client generator. WordPress does not describe what an endpoint returns, so every answer is a plain `200`.

## Search

```js
const found = await (await fetch('https://cms.example.com/wp-json/headless/v1/search?q=finch&type=post,page&lang=fr&page=1&per_page=10')).json();
// { query, page, per_page, total, pages, results: [{ id, type, title, excerpt, url, language, date, image }] }
```

It looks in the public content types (or the ones in `type`), leaves out what a password protects, and gives each result its address on the front end. `per_page` is capped by `per_page_max`. The filter `headless/search/args` changes the `WP_Query` arguments, to bring in a search plugin. Only active once a front-end URL is set.

## Forms

`GET /wp-json/headless/v1/forms` lists the forms of the site, and `/forms/cf7/12` describes one: its fields (`name`, `type`, `required`, `multiple`, choices) and where to post them.

```js
const form = await (await fetch('https://cms.example.com/wp-json/headless/v1/forms/cf7/12')).json();
const body = new FormData();
for (const [name, value] of Object.entries(form.submit.extra)) body.append(name, value); // Contact Form 7 needs its unit tag
body.append('your-email', 'a@example.com');
await fetch(form.submit.url, { method: 'POST', body }); // multipart/form-data, taken by the form plugin itself
```

Contact Form 7 is supported and verified against the real plugin in CI. A checkbox group is posted as `name[]`. Gravity Forms, WPForms and the others are not supported yet; `add_filter('headless/forms/providers', …)` adds an adapter (a `FormProvider`).

## Roadmap

`0.1` core · `0.2` CORS, REST security and speed, URL mapping, Polylang and WPML, API tokens · `0.3` content preview · `0.4` revalidation webhooks · `0.5` site manifest, redirects, media, blocks as JSON · `0.6` SEO: Yoast, Rank Math, AIOSEO, SEOPress · `0.7` optional WPGraphQL, schema files, search and forms (this release) · `0.8` doctor, end-to-end tests · `1.0` frozen contract.

## Quality

The repository follows the [Pollora harness](https://github.com/Pollora/harness) (profile `core`): `composer qa` runs every gate.

## License

MIT.
