<?php

declare(strict_types=1);

namespace Shared\Infrastructure\FileSystem;

interface CdnInterface extends FileSystemInterface
{
    /**
     * Resolve file path at the public domain
     *
     * @param string $path Path (object key) of the file relative to the
     * file system's base domain
     * @return string|null
     */
    public function getUrl(string $path): ?string;

    /**
     * Get the adapter lookup key
     *
     * @return string
     */
    public function getAdapterLookupKey(): string;
}
