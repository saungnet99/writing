<?php

declare(strict_types=1);

namespace Ai\Domain\ValueObjects;

use Ai\Domain\Entities\AbstractLibraryItemEntity;
use JsonSerializable;
use Override;

class Chunk implements JsonSerializable
{
    public readonly Token|Call|AbstractLibraryItemEntity $data;

    public function __construct(
        string|Token|Call|AbstractLibraryItemEntity $value = ''
    ) {
        $this->data = is_string($value) ? new Token($value) : $value;
    }

    #[Override]
    public function jsonSerialize(): mixed
    {
        return $this->data;
    }
}
