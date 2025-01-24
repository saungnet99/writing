<?php

declare(strict_types=1);

namespace Presentation\Resources\Api;

use Ai\Domain\Entities\ImageEntity;
use JsonSerializable;
use Override;
use Presentation\Resources\DateTimeResource;

class ImageResource implements JsonSerializable
{
    use Traits\TwigResource;

    public function __construct(private ImageEntity $image)
    {
    }

    #[Override]
    public function jsonSerialize(): array
    {
        $i = $this->image;

        return [
            'object' => 'image',
            'id' => $i->getId(),
            'model' => $i->getModel(),
            'visibility' => $i->getVisibility(),
            'cost' => $i->getCost(),
            'created_at' => new DateTimeResource($i->getCreatedAt()),
            'updated_at' => new DateTimeResource($i->getUpdatedAt()),
            'params' => $i->getRequestParams(),
            'output_file' => new ImageFileResource($i->getOutputFile()),
            'user' => new UserResource($i->getUser()),
        ];
    }
}
