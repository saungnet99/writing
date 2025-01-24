<?php

declare(strict_types=1);

namespace Presentation\Resources\Api;

use Assistant\Domain\Entities\AssistantEntity;
use JsonSerializable;
use Override;
use Presentation\Resources\DateTimeResource;

class AssistantResource implements JsonSerializable
{
    use Traits\TwigResource;

    public function __construct(private AssistantEntity $assistant)
    {
    }

    #[Override]
    public function jsonSerialize(): array
    {
        $res = $this->assistant;

        return [
            'object' => 'assistant',
            'id' => $res->getId(),
            'name' => $res->getName(),
            'expertise' => $res->getExpertise(),
            'description' => $res->getDescription(),
            'avatar' => $res->getAvatar(),
            'created_at' => new DateTimeResource($res->getCreatedAt()),
            'updated_at' => new DateTimeResource($res->getUpdatedAt()),
        ];
    }
}
