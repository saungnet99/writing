<?php

declare(strict_types=1);

namespace Billing\Domain\ValueObjects;

use JsonSerializable;

enum OrderStatus: string implements JsonSerializable
{
    case FAILED = 'failed'; // Payment failed
    case PENDING = 'pending'; // Paid but not fulfilled
    case COMPLETED = 'completed'; // Paid and fulfilled

    /** @return string  */
    public function jsonSerialize(): string
    {
        return $this->value;
    }
}
