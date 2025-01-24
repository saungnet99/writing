<?php

declare(strict_types=1);

namespace Ai\Domain\Image;

use Ai\Domain\ValueObjects\RequestParams;
use Billing\Domain\ValueObjects\CreditCount;
use GdImage;

class GenerateImageResponse
{
    public function __construct(
        public readonly GdImage $image,
        public readonly CreditCount $cost,
        public readonly ?RequestParams $params = null
    ) {}
}
