<?php

declare(strict_types=1);

namespace Ai\Infrastruture\Services\OpenAi;

use Ai\Domain\Completion\CompletionServiceInterface;
use Ai\Domain\ValueObjects\Chunk;
use Ai\Domain\ValueObjects\Model;
use Ai\Infrastruture\Services\CostCalculator;
use Billing\Domain\ValueObjects\CreditCount;
use Generator;
use Override;
use RuntimeException;
use Traversable;

class CompletionService implements CompletionServiceInterface
{
    private array $models = [
        'gpt-4o',
        'gpt-4o-mini',
        'gpt-4-turbo',
        'gpt-4-turbo-preview',
        'gpt-4',
        'gpt-3.5-turbo',
        'gpt-3.5-turbo-instruct'
    ];

    public function __construct(
        private Client $client,
        private CostCalculator $calc
    ) {}

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

    /**
     * @throws RuntimeException
     */
    #[Override]
    public function generateCompletion(Model $model, array $params = []): Generator
    {
        if ($model->value == 'gpt-3.5-turbo-instruct') {
            return $this->generateInstructedCompletion($model, $params);
        }

        return $this->generateChatCompletion($model, $params);
    }

    /**
     * @return Generator<int,Chunk,null,CreditCount>
     * @throws RuntimeException
     */
    private function generateInstructedCompletion(
        Model $model,
        array $params = []
    ): Generator {
        $prompt = $params['prompt'] ?? '';

        $resp = $this->client->sendRequest('POST', '/v1/completions', [
            'model' => $model->value,
            'prompt' => $prompt,
            'temperature' => (int)($params['temperature'] ?? 1),
            'max_tokens' => (int)($params['max_tokens'] ?? 2048),
            'stream' => true,
            'stream_options' => [
                'include_usage' => true
            ]
        ]);

        $stream = new StreamResponse($resp);

        $inputTokensCount = 0;
        $outputTokensCount = 0;

        foreach ($stream as $item) {
            if (isset($item->usage)) {
                $inputTokensCount += $item->usage->prompt_tokens ?? 0;
                $outputTokensCount += $item->usage->completion_tokens ?? 0;
            }

            $choice = $item->choices[0] ?? null;

            if (!$choice) {
                continue;
            }

            if ($choice->text) {
                $outputTokensCount++;
                yield new Chunk($choice->text);
            }
        }

        if ($this->client->hasCustomKey()) {
            // Cost is not calculated for custom keys,
            $cost = new CreditCount(0);
        } else {
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

            $cost = new CreditCount($inputCost->value + $outputCost->value);
        }

        return $cost;
    }

    /**
     * @return Generator<int,Chunk,null,CreditCount>
     * @throws RuntimeException
     */
    private function generateChatCompletion(
        Model $model,
        array $params = []
    ): Generator {
        $prompt = $params['prompt'] ?? '';

        $resp = $this->client->sendRequest('POST', '/v1/chat/completions', [
            'model' => $model->value,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt
                ],
            ],
            'temperature' => (int)($params['temperature'] ?? 1),
            'stream' => true,
            'stream_options' => [
                'include_usage' => true
            ]
        ]);

        $inputTokensCount = 0;
        $outputTokensCount = 0;

        $stream = new StreamResponse($resp);
        foreach ($stream as $item) {
            if (isset($item->usage)) {
                $inputTokensCount += $item->usage->prompt_tokens ?? 0;
                $outputTokensCount += $item->usage->completion_tokens ?? 0;
            }

            $choice = $item->choices[0] ?? null;

            if (!$choice) {
                continue;
            }

            if (isset($choice->delta->content)) {
                yield new Chunk($choice->delta->content);
            }
        }

        if ($this->client->hasCustomKey()) {
            // Cost is not calculated for custom keys,
            $cost = new CreditCount(0);
        } else {
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

            $cost = new CreditCount($inputCost->value + $outputCost->value);
        }

        return $cost;
    }
}
