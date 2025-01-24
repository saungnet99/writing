<?php

declare(strict_types=1);

namespace Ai\Application\Commands;

use Ai\Application\CommandHandlers\GenerateImageCommandHandler;
use Ai\Domain\ValueObjects\Height;
use Ai\Domain\ValueObjects\Model;
use Ai\Domain\ValueObjects\Width;
use Shared\Domain\ValueObjects\Id;
use Shared\Infrastructure\CommandBus\Attributes\Handler;
use User\Domain\Entities\UserEntity;
use Workspace\Domain\Entities\WorkspaceEntity;

#[Handler(GenerateImageCommandHandler::class)]
class GenerateImageCommand
{
    public Id|WorkspaceEntity $workspace;
    public Id|UserEntity $user;
    public Model $model;
    public ?Width $width = null;
    public ?Height $height = null;
    public array $params = [];

    public function __construct(
        string|Id|WorkspaceEntity $workspace,
        string|Id|UserEntity $user,
        string $model,
    ) {
        $this->workspace = is_string($workspace) ? new Id($workspace) : $workspace;
        $this->user = is_string($user) ? new Id($user) : $user;
        $this->model = new Model($model);
    }

    public function setWidth(int $width): void
    {
        $this->width = new Width($width);
    }

    public function setHeight(int $height): void
    {
        $this->height = new Height($height);
    }

    public function param(string $key, mixed $value): self
    {
        $this->params[$key] = $value;
        return $this;
    }
}
