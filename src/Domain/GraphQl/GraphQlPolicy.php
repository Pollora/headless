<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\GraphQl;

/**
 * The WPGraphQL settings the package imposes in code.
 *
 * WPGraphQL keeps them in the database (`graphql_general_settings`), where an editor can change them
 * and a staging copy can carry the wrong ones. Each field here is either a value to impose or null to
 * leave the setting alone. The defaults are the cautious ones for a public API: no public introspection,
 * no debug output, a short batch and a depth limit, because WPGraphQL has no query cost limit.
 *
 * `batchLimit` 0 turns batching off, `maxDepth` 0 turns the depth limit off.
 */
final readonly class GraphQlPolicy
{
    /** The option group WPGraphQL reads its general settings from. */
    public const string SECTION = 'graphql_general_settings';

    public function __construct(
        public ?bool $introspection = false,
        public ?bool $debug = false,
        public ?int $batchLimit = 5,
        public ?int $maxDepth = 15,
        public ?bool $graphiql = null,
        public ?bool $restrictToLoggedIn = null,
    ) {}

    /**
     * The settings to impose, by WPGraphQL option name, as WPGraphQL stores them (`on`/`off`, numbers).
     *
     * @return array<string, string|int>
     */
    public function forced(): array
    {
        $forced = [];

        if ($this->introspection !== null) {
            $forced['public_introspection_enabled'] = self::onOff($this->introspection);
        }

        if ($this->debug !== null) {
            $forced['debug_mode_enabled'] = self::onOff($this->debug);
        }

        if ($this->batchLimit !== null) {
            $forced['batch_queries_enabled'] = self::onOff($this->batchLimit > 0);

            if ($this->batchLimit > 0) {
                $forced['batch_limit'] = $this->batchLimit;
            }
        }

        if ($this->maxDepth !== null) {
            $forced['query_depth_enabled'] = self::onOff($this->maxDepth > 0);

            if ($this->maxDepth > 0) {
                $forced['query_depth_max'] = $this->maxDepth;
            }
        }

        if ($this->graphiql !== null) {
            $forced['graphiql_enabled'] = self::onOff($this->graphiql);
        }

        if ($this->restrictToLoggedIn !== null) {
            $forced['restrict_endpoint_to_logged_in_users'] = self::onOff($this->restrictToLoggedIn);
        }

        return $forced;
    }

    /**
     * What WPGraphQL should read for a setting: the imposed value, or the one it had.
     */
    public function resolve(string $section, string $option, mixed $value): mixed
    {
        if ($section !== self::SECTION) {
            return $value;
        }

        return $this->forced()[$option] ?? $value;
    }

    private static function onOff(bool $on): string
    {
        return $on ? 'on' : 'off';
    }
}
