<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\WordPress;

/**
 * Removes what a site that only serves content and APIs does not use: the head
 * of the page (generator, shortlink, feeds, emoji, oEmbed discovery, REST
 * discovery) and XML-RPC.
 *
 * Every removal runs on `init`, once WordPress has registered what it removes,
 * with the priority it was added at (a removal with another priority does nothing).
 */
final readonly class CleanUp implements Module
{
    public function __construct(
        private bool $cleanHead,
        private bool $disableXmlRpc,
    ) {}

    public function register(HookRegistrar $hooks): void
    {
        if ($this->cleanHead) {
            $hooks->addAction('init', [$this, 'cleanHead'], 1);
        }

        if ($this->disableXmlRpc) {
            $hooks->addFilter('xmlrpc_enabled', '__return_false');
            $hooks->addFilter('wp_headers', [$this, 'withoutPingbackHeader']);
        }
    }

    public function cleanHead(): void
    {
        remove_action('wp_head', 'wp_generator');
        remove_action('wp_head', 'rsd_link');
        remove_action('wp_head', 'wlwmanifest_link');
        remove_action('wp_head', 'wp_shortlink_wp_head', 10);
        remove_action('wp_head', 'rest_output_link_wp_head', 10);
        remove_action('wp_head', 'feed_links', 2);
        remove_action('wp_head', 'feed_links_extra', 3);
        // WordPress registers the discovery links twice, at priorities 4 and 10 (found by the integration suite)
        remove_action('wp_head', 'wp_oembed_add_discovery_links', 4);
        remove_action('wp_head', 'wp_oembed_add_discovery_links', 10);
        remove_action('wp_head', 'print_emoji_detection_script', 7);
        remove_action('wp_print_styles', 'print_emoji_styles');
        remove_action('template_redirect', 'rest_output_link_header', 11);
        remove_action('template_redirect', 'wp_shortlink_header', 11);
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    public function withoutPingbackHeader(array $headers): array
    {
        unset($headers['X-Pingback']);

        return $headers;
    }
}
