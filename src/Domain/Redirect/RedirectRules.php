<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Redirect;

/**
 * The redirects of the site, from every source, asked "where does this path go?".
 *
 * An exact rule wins over a pattern, and between rules of the same kind the first one listed wins: the sources
 * are listed from the most deliberate (what an editor typed) to the least (the old slugs WordPress remembers).
 */
final readonly class RedirectRules
{
    /** @var list<Redirect> */
    private array $exact;

    /** @var list<Redirect> */
    private array $patterns;

    /**
     * @param  list<Redirect>  $rules
     */
    public function __construct(private array $rules)
    {
        $this->exact = array_values(array_filter($rules, static fn (Redirect $rule): bool => ! $rule->regex));
        $this->patterns = array_values(array_filter($rules, static fn (Redirect $rule): bool => $rule->regex));
    }

    /**
     * @return list<Redirect>
     */
    public function all(): array
    {
        return $this->rules;
    }

    /**
     * The rule that applies to a path, with the destination it gives, or null.
     *
     * @return array{rule: Redirect, to: string}|null
     */
    public function match(string $path): ?array
    {
        $path = '/'.ltrim(explode('?', $path, 2)[0], '/');

        foreach ([...$this->exact, ...$this->patterns] as $rule) {
            $to = $rule->resolve($path);

            // A pattern that sends a path to itself would loop the visitor
            if ($to !== null && $to !== $path) {
                return ['rule' => $rule, 'to' => $to];
            }
        }

        return null;
    }
}
