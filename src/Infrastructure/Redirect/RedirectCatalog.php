<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\Redirect;

use Pollora\Headless\Domain\Redirect\RedirectRules;
use Pollora\Headless\Domain\Redirect\RedirectSource;

/**
 * Every redirect of the site, from every source, in order of precedence: the first source listed wins.
 */
final readonly class RedirectCatalog
{
    /**
     * @param  list<RedirectSource>  $sources
     */
    public function __construct(private array $sources) {}

    public function rules(): RedirectRules
    {
        $rules = [];

        foreach ($this->sources as $source) {
            array_push($rules, ...$source->redirects());
        }

        return new RedirectRules($rules);
    }
}
