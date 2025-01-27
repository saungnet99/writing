<?php

declare(strict_types=1);

namespace Option\Application\Commands;

use Option\Application\CommandHandlers\SaveOptionCommandHandler;
use Option\Domain\ValueObjects\Key;
use Option\Domain\ValueObjects\Value;
use Shared\Infrastructure\CommandBus\Attributes\Handler;

#[Handler(SaveOptionCommandHandler::class)]
class SaveOptionCommand
{
    public Key $key;
    public Value $value;

    public function __construct(string $key, ?string $value = null)
    {
        $this->key = new Key($key);
        $this->value = new Value($value);
    }
}
