<?php

declare(strict_types=1);

namespace Ai\Infrastruture\Services\OpenAi;

use Ai\Domain\Completion\MessageServiceInterface;
use Ai\Domain\ValueObjects\Model;
use Ai\Domain\Entities\MessageEntity;
use Ai\Domain\Exceptions\ApiException;
use Ai\Domain\ValueObjects\Chunk;
use Ai\Domain\ValueObjects\Quote;
use Ai\Infrastruture\Services\CostCalculator;
use Billing\Domain\ValueObjects\CreditCount;
use Generator;
use Gioni06\Gpt3Tokenizer\Gpt3Tokenizer;
use Override;
use Traversable;

class MessageServiceO1 implements MessageServiceInterface
{
    private array $models = [
        'o1-preview',
        'o1-mini',
    ];

    public function __construct(
        private Client $client,
        private Gpt3Tokenizer $tokenizer,
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

    #[Override]
    public function generateMessage(
        Model $model,
        MessageEntity $message
    ): Generator {
        $inputTokensCount = 0;
        $outputTokensCount = 0;

        $messages = $this->buildMessageHistory($message);

        $body = [
            'messages' => $messages,
            'model' => $model->value,
        ];

        $resp = $this->client->sendRequest('POST', '/v1/chat/completions', $body);
        $data = json_decode($resp->getBody()->getContents());

        if ($resp->getStatusCode() !== 200) {
            throw new ApiException($data->error->message ?? 'Unknown error with code ' . $resp->getStatusCode());
        }

        yield new Chunk($data->choices[0]->message->content);
        $inputTokensCount += $data->usage->prompt_tokens ?? 0;
        $outputTokensCount += $data->usage->completion_tokens ?? 0;

        if ($this->client->hasCustomKey()) {
            // Cost is not calculated for custom keys,
            return new CreditCount(0);
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

    private function buildMessageHistory(
        MessageEntity $message,
        int $maxContextTokens = 128000,
        int $maxMessages = 20,
    ): array {
        $messages = [];
        $current = $message;
        $inputTokensCount = 0;

        while (true) {
            if ($current->getContent()->value) {
                if ($current->getQuote()->value) {
                    array_unshift(
                        $messages,
                        $this->generateQuoteMessage($current->getQuote())
                    );
                }

                $content = [];
                $tokens = 0;

                $content[] = [
                    'type' => 'text',
                    'text' => $current->getContent()->value
                ];

                $tokens += $this->tokenizer->count($current->getContent()->value);

                if ($tokens + $inputTokensCount > $maxContextTokens) {
                    break;
                }

                $inputTokensCount += $tokens;

                array_unshift($messages, [
                    'role' => $current->getRole()->value,
                    'content' => $content
                ]);
            }

            if (count($messages) >= $maxMessages) {
                break;
            }

            if ($current->getParent()) {
                $current = $current->getParent();
                continue;
            }

            break;
        }

        $assistant = $message->getAssistant();
        if ($assistant) {
            if ($assistant->getInstructions()->value) {
                array_unshift($messages, [
                    'role' => 'user',
                    'content' => $assistant->getInstructions()->value
                ]);
            }
        }

        return $messages;
    }

    private function generateQuoteMessage(Quote $quote): array
    {
        return [
            'role' => 'user',
            'content' => 'The user is referring to this in particular:\n' . $quote->value
        ];
    }
}
