<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\WordPress;

use Pollora\Headless\Infrastructure\Exceptions\ThemeInstallException;

/**
 * The empty theme WordPress needs to run: without a valid theme it falls back to recovery mode for administrators.
 */
final readonly class StubTheme
{
    public const string SLUG = 'headless';

    /**
     * @param  string  $themeRoot  the themes directory (get_theme_root())
     * @param  string  $source  the directory holding the theme files shipped with the package
     */
    public function __construct(
        private string $themeRoot,
        private string $source,
    ) {}

    public function isInstalled(): bool
    {
        return is_file($this->target().'/style.css');
    }

    /**
     * Copies the theme into the themes directory. Returns false when it was already there.
     *
     * @throws ThemeInstallException when the directory cannot be written
     */
    public function install(): bool
    {
        if ($this->isInstalled()) {
            return false;
        }

        $target = $this->target();

        if (! is_dir($this->themeRoot) || ! is_writable($this->themeRoot)) {
            throw ThemeInstallException::notWritable($this->themeRoot);
        }

        if (! is_dir($target) && ! mkdir($target, 0755) && ! is_dir($target)) {
            throw ThemeInstallException::notWritable($target);
        }

        foreach (['style.css', 'index.php'] as $file) {
            if (! is_file($this->source.'/'.$file) || ! copy($this->source.'/'.$file, $target.'/'.$file)) {
                throw ThemeInstallException::notWritable($target.'/'.$file);
            }
        }

        return true;
    }

    private function target(): string
    {
        return rtrim($this->themeRoot, '/').'/'.self::SLUG;
    }
}
