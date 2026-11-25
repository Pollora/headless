<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\WordPress;

use Pollora\Headless\Domain\Front\FrontUrls;
use Pollora\Headless\Domain\Language\Languages;
use Pollora\Headless\Domain\Search\SearchQuery;

/**
 * `GET /wp-json/headless/v1/search?q=…`: the search of the site in one stable shape.
 *
 * The REST search endpoint (`/wp/v2/search`) answers with a REST address and no excerpt, one type at a time.
 * This one looks in the public content types (or the ones named in `type`), leaves out what is protected by
 * a password, and gives each result its address on the front end, its language and a short text.
 *
 * `apply_filters('headless/search/args', $queryArgs, $query)` changes the WP_Query arguments, to bring in a search plugin.
 */
final readonly class SearchRoutes implements Module
{
    private const int EXCERPT_WORDS = 30;

    public function __construct(
        private FrontUrls $urls,
        private Languages $languages,
        private int $maxPerPage = 50,
    ) {}

    public function register(HookRegistrar $hooks): void
    {
        $hooks->addAction('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        register_rest_route(ManifestRoutes::NAMESPACE, '/search', [
            'methods' => 'GET',
            'callback' => [$this, 'search'],
            // Public on purpose: it returns what the site search page returns
            'permission_callback' => '__return_true',
            'args' => [
                'q' => ['type' => 'string', 'required' => true, 'description' => 'The words to search for (two characters at least).'],
                'type' => ['type' => 'string', 'description' => 'Comma-separated content types to look in; every public type by default.'],
                'lang' => ['type' => 'string', 'description' => 'A language code, when the site has languages.'],
                'page' => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
                'per_page' => ['type' => 'integer', 'default' => SearchQuery::DEFAULT_PER_PAGE, 'minimum' => 1, 'description' => sprintf('Capped at %d.', $this->maxPerPage)],
            ],
        ]);
    }

    /**
     * @param  \WP_REST_Request<array<string, mixed>>  $request
     */
    public function search(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $query = SearchQuery::fromRequest(
            (string) $request->get_param('q'),
            (string) $request->get_param('type'),
            is_string($request->get_param('lang')) ? $request->get_param('lang') : null,
            (int) $request->get_param('page'),
            (int) $request->get_param('per_page'),
            $this->maxPerPage,
            $this->exposedTypes(),
            $this->languages->all(),
        );

        if (! $query instanceof SearchQuery) {
            return new \WP_Error('headless_search_term', sprintf('Search for at least %d characters.', SearchQuery::MIN_LENGTH), ['status' => 400]);
        }

        $args = [
            's' => $query->term,
            'post_type' => $query->types === [] ? ['__none__'] : $query->types,
            'post_status' => 'publish',
            'has_password' => false,
            'posts_per_page' => $query->perPage,
            'paged' => $query->page,
            'ignore_sticky_posts' => true,
            'no_found_rows' => false,
        ];

        if ($query->language !== null) {
            $args['lang'] = $query->language;
        }

        $found = new \WP_Query(apply_filters('headless/search/args', $args, $query));
        $results = [];

        foreach ($found->posts as $post) {
            if ($post instanceof \WP_Post) {
                $results[] = $this->result($post);
            }
        }

        $total = (int) $found->found_posts;
        $pages = (int) $found->max_num_pages;

        $response = new \WP_REST_Response([
            'query' => $query->term,
            'page' => $query->page,
            'per_page' => $query->perPage,
            'total' => $total,
            'pages' => $pages,
            'results' => $results,
        ]);
        $response->header('X-WP-Total', (string) $total);
        $response->header('X-WP-TotalPages', (string) $pages);

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function result(\WP_Post $post): array
    {
        $language = $this->languages->ofPost($post->ID);
        $excerpt = wp_trim_words(wp_strip_all_tags($post->post_excerpt !== '' ? $post->post_excerpt : strip_shortcodes($post->post_content)), self::EXCERPT_WORDS);
        $thumbnail = get_post_thumbnail_id($post);

        return [
            'id' => $post->ID,
            'type' => $post->post_type,
            'title' => html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8'),
            'excerpt' => html_entity_decode($excerpt, ENT_QUOTES, 'UTF-8'),
            'url' => $this->urls->toFront((string) get_permalink($post), $language),
            'language' => $language,
            'date' => get_post_time('c', true, $post),
            'image' => $thumbnail > 0 ? (string) wp_get_attachment_url($thumbnail) : null,
        ];
    }

    /**
     * @return list<string>
     */
    private function exposedTypes(): array
    {
        $types = get_post_types(['public' => true, 'exclude_from_search' => false]);
        unset($types['attachment']);

        return array_values(array_map(strval(...), $types));
    }
}
