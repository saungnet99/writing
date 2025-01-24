<?php

declare(strict_types=1);

namespace Ai\Infrastruture\Services\FalAi;

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
use Traversable;

class ImageGeneratorService implements ImageServiceInterface
{
    private array $models = [
        'flux/dev',
        'flux/schnell',
        'flux-pro',
        'flux-realism',
    ];

    public function __construct(
        private Client $client,
        private CostCalculator $calc,

        #[Inject('option.features.is_safety_enabled')]
        private bool $checkSafety = true,

        #[Inject('option.features.imagine.is_enabled')]
        private bool $isToolEnabled = false,

        #[Inject('option.features.imagine.models')]
        private array $enabledModels = [],

        #[Inject('option.falai.api_key')]
        private ?string $apiKey = null,
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

        return $this->generate($model, $params);
    }

    private function generate(
        Model $model,
        ?array $params = null
    ): GenerateImageResponse {
        $data = [
            'prompt' =>  $params['prompt'],
            'sync_mode' => true,
            'num_images' => 1
        ];

        if ($model->value === 'flux-pro') {
            // Default is 2. 1 is the most strict. 6 is the least strict.
            $data['safety_tolerance'] = $this->checkSafety ? 2 : 6;
        } else if ($model->value === 'flux-realism' || $model->value === 'flux/schnell' || $model->value === 'flux/dev') {
            $data['enable_safety_checker'] = $this->checkSafety ? true : false;
        }

        if (isset($params['size'])) {
            $data['image_size'] = $params['size'];
        }

        $resp = $this->client->sendRequest(
            'POST',
            '/fal-ai/' . $model->value,
            $data
        );

        $body = json_decode($resp->getBody()->getContents());

        if ($resp->getStatusCode() !== 200) {
            throw new ApiException(
                'Failed to generate image: ' . ($body->message ?? '')
            );
        }

        if (!isset($body->images) || !is_array($body->images) || count($body->images) === 0) {
            throw new DomainException('Failed to generate image');
        }

        $image = $body->images[0];
        $type = isset($image->content_type) ? $image->content_type : 'image/png';
        $url = $image->url;

        if (filter_var($url, FILTER_VALIDATE_URL)) {
            $resp = $this->client->sendRequest('GET', $url);
            $content = $resp->getBody()->getContents();
        } else {
            $data = str_replace('data:' . $type . ';base64,', '', $url);
            $content = base64_decode($data);
        }

        $mp = isset($image->width) && isset($image->height) ? $image->width * $image->height / (1024 * 1024) : 1;
        $cost = $this->calc->calculate(ceil($mp), $model);
        return new GenerateImageResponse(
            imagecreatefromstring($content),
            $cost,
            RequestParams::fromArray($params)
        );
    }
}
