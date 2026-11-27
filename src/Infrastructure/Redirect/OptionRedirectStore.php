<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\Redirect;

use Pollora\Headless\Domain\Redirect\Redirect;
use Pollora\Headless\Domain\Redirect\RedirectStore;

/**
 * Keeps the redirects an editor manages in one option (`headless_redirects`, not autoloaded), keyed by source path.
 */
final class OptionRedirectStore implements RedirectStore
{
    public const string OPTION = 'headless_redirects';

    /** A page of rules is read in full on each call: the store is for hundreds, not for a migration of a hundred thousand. */
    public const int LIMIT = 5000;

    public function redirects(): array
    {
        $rules = [];

        foreach ($this->read() as $stored) {
            $rule = is_array($stored) ? Redirect::tryFromArray($stored) : null;

            if ($rule instanceof Redirect) {
                $rules[] = $rule;
            }
        }

        return $rules;
    }

    public function save(Redirect $redirect): void
    {
        $all = $this->read();
        $all[self::key($redirect->from)] = $redirect->toArray();

        update_option(self::OPTION, array_slice($all, -self::LIMIT, null, true), false);
    }

    public function remove(string $from): bool
    {
        $all = $this->read();
        $key = self::key($from);

        if (! isset($all[$key])) {
            return false;
        }

        unset($all[$key]);
        update_option(self::OPTION, $all, false);

        return true;
    }

    /**
     * @return array<mixed>
     */
    private function read(): array
    {
        $all = get_option(self::OPTION, []);

        return is_array($all) ? $all : [];
    }

    /** `/about` and `/about/` are the same rule. */
    private static function key(string $from): string
    {
        return $from === '/' ? '/' : rtrim($from, '/');
    }
}
