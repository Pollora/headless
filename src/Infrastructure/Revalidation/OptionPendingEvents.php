<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\Revalidation;

use Pollora\Headless\Domain\Revalidation\ChangeEvent;
use Pollora\Headless\Domain\Revalidation\PendingEvents;

/**
 * Keeps each waiting change in an option of its own (`headless_ev_<random>`, not autoloaded).
 *
 * One row per change means two requests that save posts at the same moment never overwrite each other's
 * list. Taking a change is deleting its row: of two workers that read the same row, only one deletes it.
 */
final class OptionPendingEvents implements PendingEvents
{
    private const string PREFIX = 'headless_ev_';

    private const int LIMIT = 500;

    public function push(ChangeEvent $event): void
    {
        add_option(self::PREFIX.bin2hex(random_bytes(8)), wp_json_encode($event->toArray()), '', false);
    }

    public function drain(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT %d",
            $wpdb->esc_like(self::PREFIX).'%',
            self::LIMIT,
        ));

        $events = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! delete_option($row->option_name)) {
                continue;
            }

            $data = json_decode((string) $row->option_value, true);
            $event = is_array($data) ? ChangeEvent::fromArray($data) : null;

            if ($event instanceof ChangeEvent) {
                $events[] = $event;
            }
        }

        return $events;
    }
}
