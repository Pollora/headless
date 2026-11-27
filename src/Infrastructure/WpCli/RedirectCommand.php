<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\WpCli;

use Closure;
use Pollora\Headless\Domain\Exceptions\InvalidRedirectException;
use Pollora\Headless\Domain\Redirect\Redirect;
use Pollora\Headless\Domain\Redirect\RedirectStore;
use Pollora\Headless\Domain\Scalar;
use Pollora\Headless\Infrastructure\Redirect\RedirectCatalog;

/**
 * Manage the redirects of the site.
 */
final readonly class RedirectCommand
{
    /**
     * @param  Closure(): RedirectCatalog  $catalog  every redirect, from every source
     */
    public function __construct(
        private RedirectStore $store,
        private Closure $catalog,
        private Output $output,
    ) {}

    /**
     * Add a redirect, or replace the one for the same path.
     *
     * ## OPTIONS
     *
     * <from>
     * : The path to redirect, such as /old-page/ (or a pattern with --regex).
     *
     * [<to>]
     * : A path (/new-page/) or an absolute URL. Not needed with --status=410.
     *
     * [--status=<status>]
     * : 301 (default), 302, 307, 308, or 410 for a page that is gone.
     *
     * [--regex]
     * : <from> is a pattern matched against the whole path; $1, $2… in <to> are its groups.
     *
     * ## EXAMPLES
     *
     *     wp headless redirect add /old-page/ /new-page/
     *     wp headless redirect add '/blog/(\d{4})/(.*)' '/news/$1/$2' --regex
     *     wp headless redirect add /discontinued/ --status=410
     *
     * @param  list<string>  $args
     * @param  array<string, mixed>  $assoc
     */
    public function add(array $args = [], array $assoc = []): void
    {
        try {
            $redirect = new Redirect($args[0] ?? '', $args[1] ?? '', Scalar::int($assoc['status'] ?? 301), ($assoc['regex'] ?? false) === true);
        } catch (InvalidRedirectException $invalidRedirectException) {
            $this->output->error($invalidRedirectException->getMessage());

            return;
        }

        $this->store->save($redirect);
        $this->output->success(sprintf('%s → %s (%d).', $redirect->from, $redirect->status === 410 ? 'gone' : $redirect->to, $redirect->status));
    }

    /**
     * Remove the redirect of a path.
     *
     * ## OPTIONS
     *
     * <from>
     * : The path, as it was added.
     *
     * @param  list<string>  $args
     * @param  array<string, mixed>  $assoc
     */
    public function remove(array $args = [], array $assoc = []): void
    {
        $from = $args[0] ?? '';

        if (! $this->store->remove($from)) {
            $this->output->error(sprintf('No redirect for "%s" among the ones you manage.', $from));

            return;
        }

        $this->output->success('Redirect removed.');
    }

    /**
     * List every redirect of the site, from every source (the ones you added, the old slugs of renamed posts).
     *
     * @param  list<string>  $args
     * @param  array<string, mixed>  $assoc
     */
    public function list_(array $args = [], array $assoc = []): void
    {
        $rules = ($this->catalog)()->rules()->all();

        foreach ($rules as $rule) {
            $this->output->line(sprintf('%-9s %s %s → %s (%d)', $rule->source, $rule->regex ? '~' : ' ', $rule->from, $rule->status === 410 ? 'gone' : $rule->to, $rule->status));
        }

        if ($rules === []) {
            $this->output->line('No redirect.');
        }
    }

    /**
     * Say where a path goes.
     *
     * ## OPTIONS
     *
     * <path>
     * : The path to try, such as /old-page/.
     *
     * @param  list<string>  $args
     * @param  array<string, mixed>  $assoc
     */
    public function test(array $args = [], array $assoc = []): void
    {
        $path = $args[0] ?? '/';
        $match = ($this->catalog)()->rules()->match($path);

        if ($match === null) {
            $this->output->line(sprintf('%s is not redirected.', $path));

            return;
        }

        $this->output->success(sprintf('%s → %s (%d, from the %s rule %s).', $path, $match['rule']->status === 410 ? 'gone' : $match['to'], $match['rule']->status, $match['rule']->source, $match['rule']->from));
    }
}
