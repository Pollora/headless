<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\Status;

use Pollora\Headless\Domain\Redirect\RedirectRules;

/**
 * What the doctor learned from the running site, gathered in one place so that the checks stay plain logic.
 */
final readonly class DoctorFacts
{
    public function __construct(
        /** HTTP status of an anonymous GET on `/wp/v2/users`, null when it could not be tried */
        public ?int $usersStatus,
        /** HTTP status of an anonymous GET on `/wp/v2/posts`, null when it could not be tried */
        public ?int $postsStatus,
        public string $permalinkStructure,
        public bool $debugDisplay,
        public bool $themeIsValid,
        public bool $cronIsDisabled,
        public bool $actionSchedulerIsReady,
        /** Whether WPGraphQL is active */
        public bool $graphqlActive,
        /** Whether a visitor may introspect the GraphQL schema, null when WPGraphQL is not active */
        public ?bool $graphqlIntrospection,
        public RedirectRules $redirects,
        public ?string $seoPlugin,
    ) {}
}
