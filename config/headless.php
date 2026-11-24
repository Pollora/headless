<?php

declare(strict_types=1);

/*
 * Settings of pollora/headless, under the `pollora.headless` key.
 *
 * Nothing changes on the public site until `frontend_url` is set.
 */
return [
    // Where the JS front end lives (https://www.example.com). Empty: the package leaves the front as it is.
    'frontend_url' => env('HEADLESS_FRONTEND_URL', ''),

    // A multilingual site (Polylang, WPML) may serve each language from its own front end: language code => URL.
    // A language that is not listed uses frontend_url.
    'frontend_urls' => [],

    // What the public front of WordPress does: redirect | page | json | off. Empty: redirect when a front URL is set, off otherwise.
    'mode' => env('HEADLESS_MODE', ''),

    // Status of the redirect: 302 (default), 307, 301 or 308. Browsers keep a 301: use it once everything works.
    'redirect_status' => env('HEADLESS_REDIRECT_STATUS', 302),

    // Keep the query string when redirecting.
    'preserve_query' => true,

    // Status of the holding page and of the JSON answer.
    'blocked_status' => 404,

    // Paths the gate never touches, on top of /wp-admin, /wp-login.php, /wp-json, /graphql, /wp-cron.php, the uploads, /xmlrpc.php, the sitemap and robots.txt.
    'allow' => [],

    // Remove the generator, shortlink, feeds, emoji, oEmbed and REST discovery from the head and the headers.
    'clean_head' => true,

    // Turn XML-RPC and the pingback header off.
    'disable_xmlrpc' => true,

    // Answer a feed of the WordPress host with a 404 (the front end publishes its own).
    'disable_feeds' => true,

    // Turn the core sitemap off: its URLs are the WordPress ones.
    'disable_sitemaps' => true,

    // Tell search engines not to index the WordPress host (X-Robots-Tag, robots.txt, robots meta).
    'noindex_origin' => true,

    // Who may call the API from a browser. WordPress answers any origin by default; this answers the listed ones only.
    // The front-end URL is always listed. Without any origin, WordPress's own answer stays.
    'cors' => [
        'enabled' => true,
        // More origins: 'https://www.example.com', or 'https://*.example.com' for every sub-domain.
        'origins' => [],
        // Allow cookies and Authorization on cross-origin calls (only for origins you control).
        'credentials' => false,
        'max_age' => 600,
    ],

    // /wp/v2/users answers 401 to a visitor who is not logged in (core lists every author and their login name).
    'restrict_users' => true,

    // Turn the theme and plugin editors off in the administration (DISALLOW_FILE_EDIT).
    'disallow_file_edit' => true,

    // Cache-Control and ETag on the public REST answers (anonymous GET on /wp/v2). WordPress sends none, so a CDN keeps nothing.
    // Only active once a front-end URL is set. Seconds: for browsers, for shared caches (CDN), and how long a stale copy may be served while it refreshes.
    'rest_cache' => [
        'enabled' => true,
        'max_age' => 60,
        's_maxage' => 300,
        'stale_while_revalidate' => 60,
    ],

    // The largest per_page a request may ask for (WordPress allows 100). A number under 100 lowers it.
    'per_page_max' => 100,

    // Point permalinks (posts, pages, terms, archives, authors) to the front end: the REST "link" field, "View post", feeds, sitemaps.
    'rewrite_links' => true,

    // Also move the links inside a post's content (pages of the site only: files and the API stay on WordPress).
    'rewrite_content_links' => true,

    // API tokens (`wp headless token create <user>`): Authorization: Bearer hl.…, as the user, limited to scopes (read, write) and routes.
    'tokens' => [
        'enabled' => true,
    ],

    // Preview of drafts, scheduled and private posts from the front end. Only active once a front-end URL is set.
    // The editor's Preview button opens `url` with a token that works once, for five minutes; the front end trades it
    // at POST /wp-json/headless/v1/preview/exchange for a session and reads GET /wp-json/headless/v1/preview/posts/{id}.
    'preview' => [
        'enabled' => true,
        // Where the front end handles it: a path on the front end, or a full URL.
        'url' => '/api/preview',
        // Seconds: the link in the editor, and the session the front end holds.
        'link_ttl' => 300,
        'session_ttl' => 1800,
        // The key that signs the tokens. Empty: derived from the WordPress salts. Set HEADLESS_PREVIEW_SECRET to share one across servers.
        'secret' => env('HEADLESS_PREVIEW_SECRET', ''),
    ],

    // Tell the front end what changed (a post published, a term edited, a menu, a site option, a file), so it can
    // refresh its cache. A change is stored at once and sent a few seconds later, merged with the others, from
    // Action Scheduler when it is loaded and from WP-Cron otherwise; a target that fails is tried again later.
    // Each notification is a signed POST: see the README for how a front end checks it.
    'revalidation' => [
        'enabled' => true,
        'targets' => [
            // ['name' => 'next', 'url' => 'https://www.example.com/api/revalidate', 'secret' => env('HEADLESS_REVALIDATE_SECRET')],
        ],
        // Seconds to wait for more changes before sending.
        'debounce' => 3,
        // Attempts per target (1 to 5): after the first failure, 30 seconds, 2 minutes, 10 minutes, 1 hour.
        'max_attempts' => 5,
        // More options whose change is announced, on top of the title, the permalinks, the front page and a few more.
        'options' => [],
    ],

    // GET /wp-json/headless/v1/site: the site, its languages, its menus as trees (with front-end paths), the front page and the
    // content types in one answer, public and cacheable. Only active once a front-end URL is set.
    'manifest' => [
        'enabled' => true,
    ],

    // Redirects for the front end: GET /wp-json/headless/v1/redirects (every rule) and /redirects/resolve?path=/old/ (one path).
    // Manage yours with `wp headless redirect add|remove|list|test`. `old_slugs` adds the earlier addresses WordPress remembers
    // for renamed posts, each to the current address. Only active once a front-end URL is set.
    'redirects' => [
        'enabled' => true,
        'old_slugs' => true,
    ],

    // GET /wp-json/headless/v1/media/{id}: a file in one shape (url, sizes, srcset, alt, focal point from pollora/media…), and the
    // field `headless_featured_media` of a post when _fields asks for it. `cdn_url` serves the uploads from a CDN or an image proxy:
    // https://cdn.example.com replaces https://cms.example.com/wp-content/uploads in the addresses of files, in srcset and in the content
    // (not in the administration). The routes are active once a front-end URL is set; the CDN once its address is.
    'media' => [
        'enabled' => true,
        'cdn_url' => env('HEADLESS_MEDIA_CDN_URL', ''),
    ],

    // The blocks of a post as stable JSON, in the field `headless_blocks` of its REST answer when _fields names it:
    // /wp-json/wp/v2/posts/12?_fields=id,headless_blocks. Only active once a front-end URL is set.
    'blocks' => [
        'enabled' => true,
    ],

    // The search and social metadata of a post or a term in one shape whichever SEO plugin runs (Yoast SEO, Rank Math, All in One SEO,
    // SEOPress), in the field `headless_seo` of its REST answer when _fields names it, with every address on the front end:
    // /wp-json/wp/v2/posts/12?_fields=id,headless_seo. Rank Math needs "Headless Support" on in its general settings; its redirections
    // join the redirects of the site. Only active once a front-end URL is set.
    'seo' => [
        'enabled' => true,
    ],

    // WPGraphQL, when the plugin is active (nothing happens otherwise). Its settings live in the database, where an editor can change
    // them; these are imposed in code instead. Set a key to null to leave that setting to WPGraphQL. Introspection stays closed to
    // visitors (a logged-in API token can still read the schema, and `wp headless schema graphql` writes it to a file); WPGraphQL has no
    // query cost limit, so the batch size and the depth are capped. The CORS answer comes from the `cors` section above.
    // An API token may call GraphQL: a token without the write scope cannot run a mutation. Preview with a token and `X-GraphQL-Preview`.
    'graphql' => [
        'enabled' => true,
        'introspection' => false,
        'debug' => false,
        'batch_limit' => 5, // 0 turns batching off
        'max_depth' => 15, // 0 turns the depth limit off
        'graphiql' => null,
        'restrict_to_logged_in' => null,
    ],

    // The search of the site in one shape: GET /wp-json/headless/v1/search?q=word&type=post,page&lang=fr&page=1&per_page=10, with each
    // result's address on the front end, its language, a short text and its image. Public content types only, no protected post. The
    // filter `headless/search/args` changes the WP_Query arguments. Only active once a front-end URL is set.
    'search' => [
        'enabled' => true,
    ],
];
