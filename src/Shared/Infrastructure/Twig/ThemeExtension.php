<?php

declare(strict_types=1);

namespace Shared\Infrastructure\Twig;

use Easy\Container\Attributes\Inject;
use stdClass;
use Twig\Extension\AbstractExtension;
use Twig\Extension\ExtensionInterface;
use Twig\TwigFilter;

class ThemeExtension extends AbstractExtension implements ExtensionInterface
{
    private object $manifest;
    private string $rootDir;

    public function __construct(
        #[Inject('version')]
        private string $version,

        #[Inject('config.dirs.webroot')]
        private string $webroot,

        #[Inject('option.theme')]
        private string $theme = 'heyaikeedo/default',
    ) {
        $this->rootDir = $webroot . '/content/plugins/' . $this->theme;

        $this->manifest = file_exists($this->rootDir . '/.vite/manifest.json')
            ? json_decode(file_get_contents($this->rootDir . '/.vite/manifest.json'))
            : new stdClass();
    }

    public function getFilters()
    {
        $funcs = [];

        // Custom functions
        $funcs[] = new TwigFilter(
            'asset_url',
            $this->getAssetUrl(...)
        );

        return $funcs;
    }

    private function getAssetUrl(string $asset): string
    {
        $asset = ltrim($asset, '/');

        if (env('THEME_ASSETS_SERVER')) {
            return rtrim(env('THEME_ASSETS_SERVER'), '/') . '/' . $asset;
        }

        if (isset($this->manifest->{$asset})) {
            return '/content/plugins/' . $this->theme . '/' . $this->manifest->{$asset}->file;
        }

        if (!str_starts_with($asset, 'assets/')) {
            $asset = 'assets/' . $asset;
        }

        return '/content/plugins/' . $this->theme . '/' . $asset . '?v=' . $this->version;
    }
}
