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
];
