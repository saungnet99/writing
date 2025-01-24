<?php

declare(strict_types=1);

namespace Ai\Infrastruture\Services\Cohere;

use Ai\Domain\Completion\CodeCompletionServiceInterface;
use Ai\Domain\Exceptions\ApiException;
use Ai\Domain\ValueObjects\Chunk;
use Ai\Domain\ValueObjects\Model;
use Ai\Infrastruture\Services\CostCalculator;
use Billing\Domain\ValueObjects\CreditCount;
use Generator;
use Override;
use Traversable;

class CodeCompletionService implements CodeCompletionServiceInterface
{
    private array $models = [
        'command-r-plus',
        'command-r',
        'command',
        'command-light',
    ];

    public function __construct(
        private Client $client,
        private CostCalculator $calc
    ) {}

    #[Override]
    public function generateCodeCompletion(
        Model $model,
        string $prompt,
        string $language,
        array $params = []
    ): Generator {
        $prompt = $params['prompt'] ?? '';

        $body = [
            'model' => $model->value,
            'message' => $prompt,
            'stream' => true,
            'preamble' => "You're $language programming language expert."
        ];

        if (isset($params['temperature'])) {
            $body['temperature'] = (float)$params['temperature'] / 2;
        }

        $resp = $this->client->sendRequest('POST', '/chat', $body);

        if ($resp->getStatusCode() !== 200) {
            throw new ApiException('Failed to generate message: ' . $resp->getBody()->getContents());
        }

        $stream = new StreamResponse($resp);

        $inputTokensCount = 0;
        $outputTokensCount = 0;

        foreach ($stream as $data) {
            $type = $data->event_type ?? null;

            if ($type == 'stream-end') {
                $inputTokensCount += $data->response->meta->billed_units->input_tokens ?? 0;
                $outputTokensCount += $data->response->meta->billed_units->output_tokens ?? 0;

                break;
            }

            if ($type == 'text-generation') {
                yield new Chunk($data->text);
            }
        }

        $inputCost = $this->calc->calculate(
            $inputTokensCount,
            $model,
            CostCalculator::INPUT
        );

        $outputCost = $this->calc->calculate(
            $outputTokensCount,
            $model,
            CostCalculator::OUTPUT
        );

        return new CreditCount($inputCost->value + $outputCost->value);
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
}
