<?php

declare(strict_types=1);

namespace Ai\Application\Commands;

use Ai\Application\CommandHandlers\CountLibraryItemsCommandHandler;
use Ai\Domain\Entities\AbstractLibraryItemEntity;
use Ai\Domain\ValueObjects\ItemType;
use Ai\Domain\ValueObjects\Model;
use Shared\Domain\ValueObjects\Id;
use Shared\Infrastructure\CommandBus\Attributes\Handler;
use User\Domain\Entities\UserEntity;
use Workspace\Domain\Entities\WorkspaceEntity;

/**
 * @template T of AbstractLibraryItemEntity
 */
#[Handler(CountLibraryItemsCommandHandler::class)]
class CountLibraryItemsCommand
{
    public null|Id|UserEntity $user = null;
    public null|Id|WorkspaceEntity $workspace = null;
    public ?ItemType $type = null;
    public ?Model $model = null;

    /** Search terms/query */
    public ?string $query = null;

    public function setModel(string $model): void
    {
        $this->model = new Model($model);
    }
}
