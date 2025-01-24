<?php

declare(strict_types=1);

namespace Ai\Infrastruture\Services\OpenAi;

use Ai\Domain\Title\GenerateTitleResponse;
use Ai\Domain\Title\TitleServiceInterface;
use Ai\Domain\ValueObjects\Content;
use Ai\Domain\ValueObjects\Model;
use Ai\Domain\ValueObjects\Title;
use Ai\Infrastruture\Services\CostCalculator;
use Billing\Domain\ValueObjects\CreditCount;
use Gioni06\Gpt3Tokenizer\Gpt3Tokenizer;
use Override;
use Traversable;

class TitleGeneratorService implements TitleServiceInterface
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
    public function generateTitle(Content $content, Model $model): GenerateTitleResponse
    {
        $words = $this->getWords($content);

        if (empty($words)) {
            $title = new Title();
            return new GenerateTitleResponse($title, new CreditCount(0));
        }

        if ($model->value == 'gpt-3.5-turbo-instruct') {
            return $this->generateInstructedCompletion($model, $words);
        }

        return $this->generateChatCompletion($model, $words);
    }

    private function generateInstructedCompletion(
        Model $model,
        string $words
    ): GenerateTitleResponse {
        $resp = $this->client->sendRequest('POST', '/v1/completions', [
            'model' => $model->value,
            'prompt' => 'Your task is to generate a single title for the given content delimited by triple quotes. Identify the language of the content and generate a title that is relevant to the content. The title should be concise and informative. The title should be no more than 64 characters long. Even though the given summary is in list form, the title should not be a list. Generate the title as if it were for a blog post or news article on the topic. Don\'t generate variations of the same title with different tones or styles. """' . $words . '"""',
        ]);

        $data = json_decode($resp->getBody()->getContents());

        if ($this->client->hasCustomKey()) {
            // Cost is not calculated for custom keys,
            $cost = new CreditCount(0);
        } else {
            $inputCost = $this->calc->calculate(
                $data->usage->prompt_tokens ?? 0,
                $model,
                CostCalculator::INPUT
            );

            $outpuitCost = $this->calc->calculate(
                $data->usage->completion_tokens ?? 0,
                $model,
                CostCalculator::OUTPUT
            );

            $cost = new CreditCount($inputCost->value + $outpuitCost->value);
        }

        $title = $data->choices[0]->text ?? '';
        $title = explode("\n", trim($title))[0];
        $title = trim($title, ' "');

        return new GenerateTitleResponse(
            new Title($title ?: null),
            $cost
        );
    }

    private function generateChatCompletion(
        Model $model,
        string $words
    ): GenerateTitleResponse {
        $resp = $this->client->sendRequest('POST', '/v1/chat/completions', [
            'model' => $model->value,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Your task is to generate a single title for the given content. Identify the language of the content and generate a title that is relevant to the content. The title should be concise and informative. The title should be no more than 64 characters long. Even though the given summary is in list form, the title should not be a list. Generate the title as if it were for a blog post or news article on the topic. Don\'t generate variations of the same title with different tones or styles.',
                ],
                [
                    'role' => 'user',
                    'content' => 'Summarize the text delimited by triple quotes in one sentence by using same language. """' . $words . '"""',
                ],
                [
                    'role' => 'assistant',
                    'content' => 'Title:'
                ]
            ]
        ]);

        $data = json_decode($resp->getBody()->getContents());

        if ($this->client->hasCustomKey()) {
            // Cost is not calculated for custom keys,
            $cost = new CreditCount(0);
        } else {
            $inputCost = $this->calc->calculate(
                $data->usage->prompt_tokens ?? 0,
                $model,
                CostCalculator::INPUT
            );

            $outpuitCost = $this->calc->calculate(
                $data->usage->completion_tokens ?? 0,
                $model,
                CostCalculator::OUTPUT
            );

            $cost = new CreditCount($inputCost->value + $outpuitCost->value);
        }

        $title = $data->choices[0]->message->content ?? '';
        $title = explode("\n", trim($title))[0];
        $title = trim($title, ' "');

        return new GenerateTitleResponse(
            new Title($title ?: null),
            $cost
        );
    }

    private function getWords(Content $content, $count = 100): string
    {
        // Split the content into words using Unicode word boundaries
        $words = preg_split('/\b/u', $content->value, -1, PREG_SPLIT_NO_EMPTY);

        // Take the first $count words
        $limitedWords = array_slice($words, 0, $count);

        // Join the words back into a string
        return implode('', $limitedWords);
    }
}
