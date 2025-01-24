<?php

declare(strict_types=1);

namespace Ai\Infrastruture\Services\Custom;

use Ai\Domain\Title\GenerateTitleResponse;
use Ai\Domain\Title\TitleServiceInterface;
use Ai\Domain\ValueObjects\Content;
use Ai\Domain\ValueObjects\Model;
use Ai\Domain\ValueObjects\Title;
use Ai\Infrastruture\Services\CostCalculator;
use Billing\Domain\ValueObjects\CreditCount;
use Easy\Container\Attributes\Inject;
use Override;
use Traversable;

class TitleGeneratorService implements TitleServiceInterface
{
    public function __construct(
        private Client $client,
        private CostCalculator $calc,
        private Helper $helper,
        #[Inject('option.llms')]
        private array $llms = [],
    ) {}

    #[Override]
    public function supportsModel(Model $model): bool
    {
        foreach ($this->llms as $llm) {
            if (in_array($model->value, array_column($llm['models'], 'key'))) {
                return true;
            }
        }

        return false;
    }

    #[Override]
    public function getSupportedModels(): Traversable
    {
        foreach ($this->llms as $llm) {
            foreach ($llm['models'] as $model) {
                yield new Model($model['key']);
            }
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

        $modelName = str_contains($model->value, '/')
            ? explode('/', $model->value, 2)[1]
            : $model->value;

        $resp = $this->client->sendRequest($model, 'POST', '/chat/completions', [
            'model' => $modelName,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Your task is to generate a single title for the given content. Identify the language of the content and generate a title that is relevant to the content. The title should be concise and informative. The title should be no more than 64 characters long. Even though the given summary is in list form, the title should not be a list. Generate the title as if it were for a blog post or news article on the topic. Don\'t generate variations of the same title with different tones or styles.',
                ],
                [
                    'role' => 'user',
                    'content' => 'Summarize the text delimited by triple quotes in one sentence by using same language. """' . $words . '"""',
                ]
            ]
        ]);

        $contents = $resp->getBody()->getContents();
        $data = json_decode($contents);

        $usage = $this->helper->findUsageObject($data);

        $inputCost = $this->calc->calculate(
            $usage->prompt_tokens ?? 0,
            $model,
            CostCalculator::INPUT
        );

        $outpuitCost = $this->calc->calculate(
            $usage->completion_tokens ?? 0,
            $model,
            CostCalculator::OUTPUT
        );

        $cost = new CreditCount($inputCost->value + $outpuitCost->value);

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
