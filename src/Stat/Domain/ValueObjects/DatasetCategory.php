<?php

declare(strict_types=1);

namespace Stat\Domain\ValueObjects;

use JsonSerializable;
use Override;

enum DatasetCategory: string implements JsonSerializable
{
    case DATE = 'date';
    case COUNTRY = 'country';

    #[Override]
    public function jsonSerialize(): string
    {
        return $this->value;
    }
}
