<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\Redirect;

use Pollora\Headless\Domain\Redirect\Redirect;
use Pollora\Headless\Domain\Redirect\RedirectSource;
use Pollora\Headless\Domain\Scalar;

/**
 * The redirections of Rank Math's manager, read from its table (`{prefix}rank_math_redirections`): an exact or a
 * regular-expression source, its destination and its status. The other comparisons (contains, starts with, ends
 * with) have no equivalent here and are left out. Only active rules are read, and the table may not exist.
 */
final class RankMathRedirects implements RedirectSource
{
    private const int LIMIT = 2000;

    public function redirects(): array
    {
        global $wpdb;

        if (! $wpdb instanceof \wpdb) {
            return [];
        }

        $table = $wpdb->prefix.'rank_math_redirections';

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) {
            return [];
        }

        $rows = $wpdb->get_results($wpdb->prepare('SELECT sources, url_to, header_code FROM %i WHERE status = %s ORDER BY id ASC LIMIT %d', $table, 'active', self::LIMIT));
        $rules = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_object($row) || ! is_string($row->sources ?? null)) {
                continue;
            }

            $sources = maybe_unserialize($row->sources);

            foreach (is_array($sources) ? $sources : [] as $source) {
                $comparison = is_array($source) ? ($source['comparison'] ?? '') : '';
                $pattern = is_array($source) ? Scalar::string($source['pattern'] ?? '') : '';

                if (($comparison !== 'exact' && $comparison !== 'regex') || $pattern === '') {
                    continue;
                }

                try {
                    $rules[] = new Redirect('/'.ltrim($pattern, '/'), Scalar::string($row->url_to ?? ''), Scalar::int($row->header_code ?? 0), $comparison === 'regex', 'rankmath');
                } catch (\InvalidArgumentException) {
                    // A rule that cannot be one here (a destination that is not an address, a status that is not a redirect): left out
                    continue;
                }
            }
        }

        return $rules;
    }
}
