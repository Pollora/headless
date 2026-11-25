<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Redirect;

use Pollora\Headless\Domain\Exceptions\InvalidRedirectException;

/**
 * A rule that sends a visitor of one path to another: `/old/` to `/new/` with a 301, or a pattern such as
 * `/blog/(.*)` to `/news/$1`. A 410 says the page is gone and has no destination.
 *
 * The rule is checked when it is made: a front end that reads a list of redirects must be able to trust it.
 */
final readonly class Redirect
{
    private const array STATUSES = [301, 302, 307, 308, 410];

    private const int MAX_LENGTH = 2000;

    public function __construct(
        public string $from,
        public string $to,
        public int $status = 301,
        public bool $regex = false,
        public string $source = 'manual',
    ) {
        if (! str_starts_with($from, '/') || preg_match('/\s/', $from) === 1 || strlen($from) > self::MAX_LENGTH) {
            throw InvalidRedirectException::badSource($from);
        }

        if (! in_array($status, self::STATUSES, true)) {
            throw InvalidRedirectException::badStatus($status);
        }

        if ($status !== 410 && ! self::isDestination($to)) {
            throw InvalidRedirectException::badTarget($to);
        }

        if ($regex && (! self::compiles(self::pattern($from)) || self::backtracksTooMuch($from))) {
            throw InvalidRedirectException::badPattern($from);
        }

        if (! $regex && $to === $from) {
            throw InvalidRedirectException::loop($from);
        }
    }

    /**
     * The destination for a path this rule matches, or null when it does not match.
     */
    public function resolve(string $path): ?string
    {
        if (! $this->regex) {
            return self::normalise($path) === self::normalise($this->from) ? $this->to : null;
        }

        if (preg_match(self::pattern($this->from), $path, $matches) !== 1) {
            return null;
        }

        $to = (string) preg_replace_callback('/\$(\d{1,2})/', static fn (array $reference): string => $matches[(int) $reference[1]] ?? '', $this->to);

        // What a visitor's path put into the destination must still be a destination: `/$1` with `/go//evil.com` would leave the site
        return self::isDestination($to) && ! str_starts_with($to, '/\\') ? $to : null;
    }

    /**
     * @return array{from: string, to: string, status: int, regex: bool, source: string}
     */
    public function toArray(): array
    {
        return ['from' => $this->from, 'to' => $this->to, 'status' => $this->status, 'regex' => $this->regex, 'source' => $this->source];
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function tryFromArray(array $data): ?self
    {
        if (! is_string($data['from'] ?? null) || ! is_string($data['to'] ?? null)) {
            return null;
        }

        try {
            return new self($data['from'], $data['to'], is_int($data['status'] ?? null) ? $data['status'] : 301, ($data['regex'] ?? false) === true, is_string($data['source'] ?? null) ? $data['source'] : 'manual');
        } catch (InvalidRedirectException) {
            return null;
        }
    }

    /** A pattern is anchored at both ends: `/blog/(.*)` matches whole paths, never a part of one. */
    private static function pattern(string $from): string
    {
        return '#\A'.str_replace('#', '\#', $from).'\z#Du';
    }

    /** Whether a pattern is valid. PHP warns about one that is not: the warning is the answer, not noise. */
    private static function compiles(string $pattern): bool
    {
        set_error_handler(static fn (): bool => true);

        try {
            return preg_match($pattern, '') !== false;
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Whether a pattern repeats a repetition (`(a+)+`, `(.*)*`): such a pattern can take exponential time on a path that almost matches,
     * and the resolve route runs every pattern on what a visitor sends.
     */
    private static function backtracksTooMuch(string $from): bool
    {
        return preg_match('/[+*}]\)?[+*{]|\([^()]*[+*}]\)[+*{]/', $from) === 1;
    }

    /** A path without its trailing slash: `/about` and `/about/` are the same page. */
    private static function normalise(string $path): string
    {
        return $path === '/' ? '/' : rtrim($path, '/');
    }

    private static function isDestination(string $to): bool
    {
        if (str_starts_with($to, '/') && ! str_starts_with($to, '//')) {
            return preg_match('/\s/', $to) !== 1;
        }

        return preg_match('#\Ahttps?://[^\s/]+\S*\z#i', $to) === 1;
    }
}
