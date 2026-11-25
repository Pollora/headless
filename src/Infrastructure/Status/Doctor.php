<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\Status;

use Pollora\Headless\Domain\Cors\CorsPolicy;
use Pollora\Headless\Domain\Front\Origin;
use Pollora\Headless\Domain\Redirect\Redirect;
use Pollora\Headless\Infrastructure\Config\Settings;

/**
 * The checks of `wp headless doctor`: each reads the settings and the facts of the site and says ok, warning or fail.
 * A fail is something that exposes the site or breaks the front end; a warning is something to know about.
 */
final class Doctor
{
    /**
     * @return list<Check>
     */
    public static function run(Settings $settings, DoctorFacts $facts): array
    {
        return [
            ...self::front($settings),
            ...self::rest($settings, $facts),
            self::permalinks($facts),
            ...self::delivery($settings, $facts),
            ...self::graphql($facts),
            ...self::redirects($facts),
            self::theme($facts),
            self::seo($facts),
            new Check('debug-display', $facts->debugDisplay ? CheckLevel::Warning : CheckLevel::Ok, $facts->debugDisplay ? 'PHP errors are printed in the answers.' : 'PHP errors are not printed in the answers.', $facts->debugDisplay ? 'Set WP_DEBUG_DISPLAY to false: a notice inside a JSON answer breaks the client and leaks paths.' : ''),
        ];
    }

    /**
     * @param  list<Check>  $checks
     */
    public static function failed(array $checks): bool
    {
        return array_filter($checks, static fn (Check $check): bool => $check->level === CheckLevel::Fail) !== [];
    }

    /**
     * @return list<Check>
     */
    private static function front(Settings $settings): array
    {
        if (! $settings->front instanceof Origin) {
            return [new Check('front-url', CheckLevel::Warning, 'No front-end URL: the public front of WordPress is left as it is.', 'Set HEADLESS_FRONTEND_URL.')];
        }

        $checks = [new Check('front-url', CheckLevel::Ok, 'Front end: '.$settings->front->toString())];

        if ($settings->front->sameHostAs($settings->backend)) {
            $checks[] = new Check('front-host', CheckLevel::Fail, 'The front end has the same host as WordPress: the gate cannot redirect without looping, so it lets everything through.', 'Serve the front end from another host or port.');
        }

        foreach ([['front', $settings->front], ['WordPress', $settings->backend]] as [$name, $origin]) {
            if ($origin->scheme !== 'https' && ! self::isLocal($origin->host)) {
                $checks[] = new Check('https', CheckLevel::Warning, sprintf('The %s address is not HTTPS: tokens and sessions travel in clear.', $name), 'Use HTTPS.');
            }
        }

        return $checks;
    }

    /**
     * @return list<Check>
     */
    private static function rest(Settings $settings, DoctorFacts $facts): array
    {
        $checks = [];

        $checks[] = $settings->cors instanceof CorsPolicy
            ? new Check('cors', CheckLevel::Ok, 'CORS answers for the listed origins only.')
            : new Check('cors', CheckLevel::Warning, 'No CORS policy: WordPress reflects any origin and allows credentials.', 'Set the front-end URL, or list `cors.origins`.');

        if ($facts->usersStatus === null) {
            $checks[] = new Check('users-route', CheckLevel::Warning, 'The users route could not be tried.');
        } elseif ($facts->usersStatus === 200) {
            $checks[] = new Check('users-route', CheckLevel::Fail, 'An anonymous visitor can list the users (`/wp/v2/users`): their logins can be guessed and attacked.', 'Keep `restrict_users` on.');
        } else {
            $checks[] = new Check('users-route', CheckLevel::Ok, sprintf('The users route answers %d to a visitor.', $facts->usersStatus));
        }

        $checks[] = match (true) {
            $facts->postsStatus === null => new Check('rest-open', CheckLevel::Warning, 'The posts route could not be tried.'),
            $facts->postsStatus === 200 => new Check('rest-open', CheckLevel::Ok, 'The REST API answers visitors.'),
            default => new Check('rest-open', CheckLevel::Fail, sprintf('The posts route answers %d to a visitor: a plugin locks the REST API, and a front end cannot read the content.', $facts->postsStatus), 'Allow anonymous reads, or give the front end an API token.'),
        };

        if (! $settings->apiTokens) {
            $checks[] = new Check('tokens', CheckLevel::Warning, 'API tokens are off.', 'A front end that reads private content needs one.');
        }

        return $checks;
    }

    private static function permalinks(DoctorFacts $facts): Check
    {
        return $facts->permalinkStructure === ''
            ? new Check('permalinks', CheckLevel::Fail, 'Plain permalinks: `/wp-json/` and the redirects of the front end need pretty ones.', 'Settings, Permalinks: pick any structure but "Plain".')
            : new Check('permalinks', CheckLevel::Ok, 'Pretty permalinks: '.$facts->permalinkStructure);
    }

    /**
     * @return list<Check>
     */
    private static function delivery(Settings $settings, DoctorFacts $facts): array
    {
        $checks = [];

        $checks[] = $settings->previewUrl === null
            ? new Check('preview', CheckLevel::Warning, 'Preview is off: editors cannot see a draft on the front end.', 'Set `preview.url` to the preview route of the front end.')
            : new Check('preview', CheckLevel::Ok, 'Preview goes to '.$settings->previewUrl);

        if ($settings->revalidationTargets === []) {
            $checks[] = new Check('revalidation', CheckLevel::Warning, 'No revalidation target: the front end is not told when content changes.', 'Add one under `revalidation.targets`.');

            return $checks;
        }

        foreach ($settings->revalidationTargets as $target) {
            if (! str_starts_with($target->url, 'https://') && ! self::isLocal((string) parse_url($target->url, PHP_URL_HOST))) {
                $checks[] = new Check('revalidation-https', CheckLevel::Warning, sprintf('The target "%s" is not HTTPS.', $target->name), 'Signed requests still travel in clear: use HTTPS.');
            }
        }

        $checks[] = match (true) {
            $facts->actionSchedulerIsReady => new Check('revalidation', CheckLevel::Ok, 'Revalidation runs on Action Scheduler.'),
            $facts->cronIsDisabled => new Check('revalidation', CheckLevel::Warning, 'Revalidation goes through WP-Cron, which is disabled: a system cron must call wp-cron.php.', 'Install Action Scheduler, or schedule `wp cron event run --due-now`.'),
            default => new Check('revalidation', CheckLevel::Warning, 'Revalidation goes through WP-Cron, which runs only when a page is visited.', 'Install Action Scheduler and run a system cron.'),
        };

        return $checks;
    }

    /**
     * @return list<Check>
     */
    private static function graphql(DoctorFacts $facts): array
    {
        if (! $facts->graphqlActive) {
            return [];
        }

        return [$facts->graphqlIntrospection === true
            ? new Check('graphql', CheckLevel::Fail, 'Anyone can introspect the GraphQL schema.', 'Keep `graphql.introspection` false.')
            : new Check('graphql', CheckLevel::Ok, 'WPGraphQL is active and its schema is closed to visitors.')];
    }

    /**
     * Rules that send a path to itself, or round in a circle, or through several hops.
     *
     * @return list<Check>
     */
    private static function redirects(DoctorFacts $facts): array
    {
        $checks = [];
        $exact = array_filter($facts->redirects->all(), static fn (Redirect $rule): bool => ! $rule->regex && $rule->status !== 410);

        foreach ($exact as $rule) {
            $seen = [$rule->from];
            $path = $rule->from;

            for ($hop = 0; $hop < 10; $hop++) {
                $next = $facts->redirects->match($path);

                if ($next === null) {
                    break;
                }

                $path = '/'.trim((string) parse_url($next['to'], PHP_URL_PATH), '/');

                if (in_array($path, $seen, true) || $path === '/'.trim($rule->from, '/')) {
                    $checks[] = new Check('redirect-loop', CheckLevel::Fail, sprintf('The redirect of %s ends up back where it started.', $rule->from), 'Remove or change one rule of the circle.');

                    continue 2;
                }

                $seen[] = $path;
            }

            if (count($seen) > 2) {
                $checks[] = new Check('redirect-chain', CheckLevel::Warning, sprintf('The redirect of %s takes %d hops.', $rule->from, count($seen) - 1), 'Point it straight to the last address.');
            }
        }

        return $checks === [] ? [new Check('redirects', CheckLevel::Ok, sprintf('%d redirect rule(s), no loop.', count($facts->redirects->all())))] : $checks;
    }

    private static function theme(DoctorFacts $facts): Check
    {
        return $facts->themeIsValid
            ? new Check('theme', CheckLevel::Ok, 'A valid theme is active.')
            : new Check('theme', CheckLevel::Fail, 'No valid theme: WordPress falls back to recovery mode.', 'Run `wp headless theme install --activate`.');
    }

    private static function seo(DoctorFacts $facts): Check
    {
        return $facts->seoPlugin === null
            ? new Check('seo', CheckLevel::Ok, 'No SEO plugin: `headless_seo` is empty.')
            : new Check('seo', CheckLevel::Ok, 'SEO plugin: '.$facts->seoPlugin);
    }

    private static function isLocal(string $host): bool
    {
        return $host === 'localhost' || $host === '127.0.0.1' || str_ends_with($host, '.test') || str_ends_with($host, '.local') || str_ends_with($host, '.ddev.site');
    }
}
