<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

/*
 * ARCH-07 for this package. It replaces the harness preset, so it repeats what
 * the preset allows for a WordPress repository.
 *
 * illuminate/support is a dev dependency on purpose: the Pollora service provider
 * extends Laravel's, and Laravel only loads it inside a Laravel application.
 */
$root = getcwd() ?: '.';

return (new Configuration)
    ->disableReportingUnmatchedIgnores()
    ->ignoreUnknownClassesRegex('~^(WP_|Walker_|Requests_|wpdb$)~')
    ->ignoreUnknownFunctionsRegex('~.*~')
    ->ignoreErrorsOnExtensions(['ext-ctype', 'ext-filter', 'ext-json', 'ext-mbstring'], [ErrorType::SHADOW_DEPENDENCY])
    ->ignoreErrorsOnPackage('illuminate/support', [ErrorType::DEV_DEPENDENCY_IN_PROD])
    ->ignoreErrorsOnPath($root.'/tests', [ErrorType::UNKNOWN_CLASS, ErrorType::UNKNOWN_FUNCTION, ErrorType::SHADOW_DEPENDENCY]);
