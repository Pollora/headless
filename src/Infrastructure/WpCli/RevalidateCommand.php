<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\WpCli;

use Closure;
use Pollora\Headless\Application\Revalidation\Revalidator;

/**
 * Check the revalidation setup.
 */
final readonly class RevalidateCommand
{
    /**
     * @param  Closure(): Revalidator  $revalidator  built when a command runs: wp-config.php has been read by then
     */
    public function __construct(
        private Closure $revalidator,
        private Output $output,
    ) {}

    /**
     * Send a signed `ping` event to every target now, and say how each one answered.
     *
     * Nothing is stored and nothing is retried: it is for checking the address and the secret.
     *
     * ## EXAMPLES
     *
     *     wp headless revalidate ping
     *
     * @param  list<string>  $args
     * @param  array<string, mixed>  $assoc
     */
    public function __invoke(array $args = [], array $assoc = []): void
    {
        $revalidator = ($this->revalidator)();

        if (! $revalidator->isActive()) {
            $this->output->error('No revalidation target: set `revalidation.targets` (or HEADLESS_REVALIDATE_URL and HEADLESS_REVALIDATE_SECRET).');

            return;
        }

        $failed = 0;

        foreach ($revalidator->ping() as $name => $result) {
            if ($result->delivered) {
                $this->output->success(sprintf('%s answered HTTP %d.', $name, $result->status));

                continue;
            }

            $failed++;
            $this->output->warning(sprintf('%s did not accept the ping: %s.', $name, $result->error ?? 'HTTP '.$result->status));
        }

        if ($failed > 0) {
            $this->output->error(sprintf('%d target(s) failed.', $failed));
        }
    }
}
