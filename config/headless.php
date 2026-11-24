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
];
