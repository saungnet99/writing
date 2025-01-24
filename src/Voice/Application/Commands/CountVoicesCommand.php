<?php

declare(strict_types=1);

namespace Voice\Application\Commands;

use Ai\Domain\ValueObjects\Model;
use Shared\Infrastructure\CommandBus\Attributes\Handler;
use Voice\Application\CommandHandlers\CountVoicesCommandhandler;
use Voice\Domain\ValueObjects\Accent;
use Voice\Domain\ValueObjects\Age;
use Voice\Domain\ValueObjects\Gender;
use Voice\Domain\ValueObjects\LanguageCode;
use Voice\Domain\ValueObjects\Provider;
use Voice\Domain\ValueObjects\Status;
use Voice\Domain\ValueObjects\Tone;
use Voice\Domain\ValueObjects\UseCase;

#[Handler(CountVoicesCommandhandler::class)]
class CountVoicesCommand
{
    public ?Status $status = null;
    public ?Provider $provider = null;
    public ?Tone $tone = null;
    public ?UseCase $useCase = null;
    public ?Gender $gender = null;
    public ?Accent $accent = null;
    public null|LanguageCode|string $languageCode = null;
    public ?Age $age = null;
    public null|array|Model $models = null;

    /** Search terms/query */
    public ?string $query = null;

    public function setStatus(int $status): void
    {
        $this->status = Status::from($status);
    }

    public function setProvider(string $provider): void
    {
        $this->provider = new Provider($provider);
    }

    public function setTone(?string $tone): void
    {
        $this->tone = Tone::from($tone);
    }

    public function setUseCase(?string $useCase): void
    {
        $this->useCase = UseCase::from($useCase);
    }

    public function setGender(string $gender): void
    {
        $this->gender = Gender::from($gender);
    }

    public function setAccent(string $accent): void
    {
        $this->accent = Accent::from($accent);
    }

    public function setLanguageCode(string|LanguageCode $languageCode): void
    {
        $this->languageCode = $languageCode;
    }

    public function setAge(string $age): void
    {
        $this->age = Age::from($age);
    }

    public function setModels(string ...$models): void
    {
        $this->models = array_map(
            static fn (string $model) => new Model($model),
            $models
        );
    }
}
