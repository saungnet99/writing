<?php

declare(strict_types=1);

namespace Ai\Domain\Image;

use Ai\Domain\Exceptions\ApiException;
use Ai\Domain\Exceptions\DomainException;
use Ai\Domain\Services\AiServiceInterface;
use Ai\Domain\ValueObjects\Height;
use Ai\Domain\ValueObjects\Model;
use Ai\Domain\ValueObjects\Width;

interface ImageServiceInterface extends AiServiceInterface
{
    /**
     * @param Model $model
     * @param null|Width $width
     * @param null|Height $height
     * @param null|array $params
     * @return GenerateImageResponse
     * @throws DomainException
     * @throws ApiException
     */
    public function generateImage(
        Model $model,
        ?Width $width = null,
        ?Height $height = null,
        ?array $params = null
    ): GenerateImageResponse;
}
