<?php

declare(strict_types=1);

namespace Ai\Infrastruture\Services\Clipdrop;

use Ai\Domain\Exceptions\ApiException;
use Ai\Domain\Exceptions\DomainException;
use Ai\Domain\Exceptions\ModelNotSupportedException;
use Ai\Domain\Image\ImageServiceInterface;
use Ai\Domain\Image\GenerateImageResponse;
use Ai\Domain\ValueObjects\Height;
use Ai\Domain\ValueObjects\Model;
use Ai\Domain\ValueObjects\RequestParams;
use Ai\Domain\ValueObjects\Width;
use Ai\Infrastruture\Services\CostCalculator;
use Easy\Container\Attributes\Inject;
use Override;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Ramsey\Uuid\Nonstandard\Uuid;
use Traversable;

class ImageGeneratorService implements ImageServiceInterface
{
    private const BASE_URL = "https://clipdrop-api.co";

    private array $models = [
        // This is an internal identifier for Clipdrop model. 
        // Currently there is not any official model name.
        'clipdrop',
    ];

    public function __construct(
        private ClientInterface $client,
        private RequestFactoryInterface $factory,
        private StreamFactoryInterface $streamFactory,
        private CostCalculator $calc,

        #[Inject('option.features.imagine.is_enabled')]
        private bool $isToolEnabled = false,

        #[Inject('option.features.imagine.models')]
        private array $enabledModels = [],

        #[Inject('option.clipdrop.api_key')]
        private ?string $apiKey = null
    ) {
        $models = [];

        if ($isToolEnabled) {
            foreach ($enabledModels as $model) {
                if (in_array($model, $this->models)) {
                    $models[] = $model;
                }
            }
        }

        $this->models = $models;
    }

    #[Override]
    public function supportsModel(Model $model): bool
    {
        return in_array($model->value, $this->models);
    }

    #[Override]
    public function getSupportedModels(): Traversable
    {
        foreach ($this->models as $model) {
            yield new Model($model);
        }
    }

    #[Override]
    public function generateImage(
        Model $model,
        ?Width $width = null,
        ?Height $height = null,
        ?array $params = null
    ): GenerateImageResponse {
        if (!$this->supportsModel($model)) {
            throw new ModelNotSupportedException(
                self::class,
                $model
            );
        }

        if (!$params || !array_key_exists('prompt', $params)) {
            throw new DomainException('Missing parameter: prompt');
        }

        $boundary = Uuid::uuid4()->toString();

        $stream = $this->streamFactory->createStream(
            "--" . $boundary . "\r\n" .
                "Content-Disposition: form-data; name=\"prompt\"" . "\r\n" .
                "\r\n" .
                $params['prompt'] . "\r\n" .
                "--" . $boundary . "--"
        );

        $request = $this->factory
            ->createRequest('POST', self::BASE_URL . '/text-to-image/v1')
            ->withHeader('X-Api-Key', $this->apiKey)
            ->withHeader('Content-Type', "multipart/form-data; boundary=\"{$boundary}\"")
            ->withBody($stream);

        $resp = $this->client->sendRequest($request);

        $body = $resp->getBody()->getContents();
        if ($resp->getStatusCode() !== 200) {
            $body = json_decode($body);

            throw new ApiException(
                'Failed to generate image: ' . ($body->error ?? '')
            );
        }

        $cost = $this->calc->calculate(1, $model);
        return new GenerateImageResponse(
            imagecreatefromstring($body),
            $cost,
            RequestParams::fromArray([
                'prompt' => $params['prompt']
            ])
        );
    }
}
