<?php

declare(strict_types=1);

namespace Ai\Infrastruture\Services\xAi;

use Ai\Domain\Completion\CodeCompletionServiceInterface;
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
        'grok-beta',
        'grok-2-1212',
        'grok-2-vision-1212',
        'grok-vision-beta',
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
            'messages' => [
                [
                    'role' => 'system',
                    'content' => "You're $language programming language expert."
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ],
            ],
            'stream' => true,
            'temperature' => (int)($params['temperature'] ?? 1),
        ];

        $resp = $this->client->sendRequest('POST', '/v1/chat/completions', $body);
        $stream = new StreamResponse($resp);

        $inTokens = 0;
        $outTokens = 0;

        foreach ($stream as $data) {
            $inTokens = $data->usage->prompt_tokens ?? $inTokens;
            $outTokens = $data->usage->completion_tokens ?? $outTokens;

            $choice = $data->choices[0] ?? null;

            if (!$choice) {
                continue;
            }

            if (isset($choice->delta->content)) {
                yield new Chunk($choice->delta->content);
            }
        }

        $inputCost = $this->calc->calculate(
            $inTokens,
            $model,
            CostCalculator::INPUT
        );

        $outputCost = $this->calc->calculate(
            $outTokens,
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
