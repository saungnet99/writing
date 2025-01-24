<?php

declare(strict_types=1);

namespace User\Application\Commands;

use DateTime;
use DateTimeInterface;
use Shared\Domain\ValueObjects\CountryCode;
use Shared\Infrastructure\CommandBus\Attributes\Handler;
use User\Application\CommandHandlers\CountUsersCommandHandler;
use User\Domain\ValueObjects\IsEmailVerified;
use User\Domain\ValueObjects\Role;
use User\Domain\ValueObjects\Status;

#[Handler(CountUsersCommandHandler::class)]
class CountUsersCommand
{
    public ?Status $status = null;
    public ?Role $role = null;
    public ?CountryCode $countryCode = null;
    public ?IsEmailVerified $isEmailVerified = null;
    public ?DateTimeInterface $after = null;
    public ?DateTimeInterface $before = null;

    /** Search terms/query */
    public ?string $query = null;

    public function setStatus(int $status): self
    {
        $this->status = Status::from($status);

        return $this;
    }

    public function setRole(int $role): self
    {
        $this->role = Role::from($role);

        return $this;
    }

    public function setCountryCode(string $countryCode): self
    {
        $this->countryCode = CountryCode::from($countryCode);

        return $this;
    }

    public function setIsEmailVerified(bool $isEmailVerified): self
    {
        $this->isEmailVerified = new IsEmailVerified($isEmailVerified);

        return $this;
    }

    public function setAfter(string $after): self
    {
        $this->after = new DateTime($after);

        return $this;
    }

    public function setBefore(string $before): self
    {
        $this->before = new DateTime($before);

        return $this;
    }
}
