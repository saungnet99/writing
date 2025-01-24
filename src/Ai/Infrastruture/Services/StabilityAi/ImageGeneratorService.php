<?php

declare(strict_types=1);

namespace Ai\Infrastruture\Services\StabilityAi;

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
        'sd-ultra',
        'sd-core',
        'sd3-large',
        'sd3-large-turbo',
        'sd3-medium',
        'stable-diffusion-xl-1024-v1-0',
        'stable-diffusion-v1-6',
    ];

    public function __construct(
        private Client $client,
        private CostCalculator $calc,

        #[Inject('option.features.imagine.is_enabled')]
        private bool $isToolEnabled = false,

        #[Inject('option.features.imagine.models')]
        private array $enabledModels = [],

        #[Inject('version')]
        private string $version = '1.0.0',

        #[Inject('option.stabilityai.api_key')]
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

        if (in_array($model->value, ['sd-ultra', 'sd-core'])) {
            return $this->ultra($model, $params);
        }

        if (in_array($model->value, ['sd3-large', 'sd3-large-turbo', 'sd3-medium'])) {
            return $this->sd3($model, $params);
        }

        return $this->legacy($model, $width, $height, $params);
    }

    private function ultra(
        Model $model,
        ?array $params = null
    ): GenerateImageResponse {
        $data = [
            'prompt' => $params['prompt']
        ];

        if (array_key_exists('negative_prompt', $params)) {
            $data['negative_prompt'] = $params['negative_prompt'];
        }

        if (array_key_exists('aspect_ratio', $params)) {
            $data['aspect_ratio'] = $params['aspect_ratio'];
        }

        if (array_key_exists('style', $params)) {
            $data['style_preset'] = $params['style'];
        }

        $resp = $this->client->sendRequest(
            'POST',
            '/v2beta/stable-image/generate/' . substr($model->value, 3),
            $data,
            [
                'Content-Type' => 'multipart/form-data',
            ]
        );

        $body = json_decode($resp->getBody()->getContents());

        if ($resp->getStatusCode() !== 200) {
            throw new ApiException(
                'Failed to generate image: ' . ($body->message ?? '')
            );
        }

        if (!isset($body->image)) {
            throw new DomainException('Failed to generate image');
        }

        $content = base64_decode($body->image);

        $cost = $this->calc->calculate(1, $model);
        return new GenerateImageResponse(
            imagecreatefromstring($content),
            $cost,
            RequestParams::fromArray($params)
        );
    }

    private function sd3(
        Model $model,
        ?array $params = null
    ): GenerateImageResponse {
        $data = [
            'prompt' => $params['prompt'],
            'model' => $model->value,
        ];

        if (array_key_exists('aspect_ratio', $params)) {
            $data['aspect_ratio'] = $params['aspect_ratio'];
        }

        if (array_key_exists('negative_prompt', $params)) {
            $data['negative_prompt'] = $params['negative_prompt'];
        }

        $resp = $this->client->sendRequest(
            'POST',
            '/v2beta/stable-image/generate/sd3',
            $data,
            [
                'Content-Type' => 'multipart/form-data',
            ]
        );

        $body = json_decode($resp->getBody()->getContents());

        if ($resp->getStatusCode() !== 200) {
            throw new ApiException(
                'Failed to generate image: ' . ($body->message ?? '')
            );
        }

        if (!isset($body->image)) {
            throw new DomainException('Failed to generate image');
        }

        $content = base64_decode($body->image);

        $cost = $this->calc->calculate(1, $model);
        return new GenerateImageResponse(
            imagecreatefromstring($content),
            $cost,
            RequestParams::fromArray($params)
        );
    }

    private function legacy(
        Model $model,
        ?Width $width = null,
        ?Height $height = null,
        ?array $params = null
    ): GenerateImageResponse {
        $data = [
            'text_prompts' => [
                [
                    'text' => $params['prompt'],
                    'weight' => 1
                ]
            ]
        ];

        if ($width) {
            $data['width'] = $width->value;
        }

        if ($height) {
            $data['height'] = $height->value;
        }

        foreach (['sampler', 'clip_guidance_preset', 'style'] as $key) {
            if (array_key_exists($key, $params)) {
                $data[$key] = $params[$key];
            }
        }

        if (array_key_exists('negative_prompt', $params)) {
            $data['text_prompts'][] = [
                'text' => $params['negative_prompt'],
                'weight' => -1
            ];
        }

        $resp = $this->client->sendRequest(
            'POST',
            '/v1/generation/' . $model->value . '/text-to-image',
            $data
        );

        $body = json_decode($resp->getBody()->getContents());

        if ($resp->getStatusCode() !== 200) {
            throw new ApiException(
                'Failed to generate image: ' . ($body->message ?? '')
            );
        }

        if (!isset($body->artifacts) || !is_array($body->artifacts) || count($body->artifacts) === 0) {
            throw new DomainException('Failed to generate image');
        }

        $artifact = $body->artifacts[0];
        $content = base64_decode($artifact->base64);

        $cost = $this->calc->calculate(1, $model);
        return new GenerateImageResponse(
            imagecreatefromstring($content),
            $cost,
            RequestParams::fromArray($params)
        );
    }
}
