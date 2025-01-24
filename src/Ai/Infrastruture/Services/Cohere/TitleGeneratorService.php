<?php

declare(strict_types=1);

namespace Ai\Infrastruture\Services\Cohere;

use Ai\Domain\Title\GenerateTitleResponse;
use Ai\Domain\Title\TitleServiceInterface;
use Ai\Domain\ValueObjects\Content;
use Ai\Domain\ValueObjects\Model;
use Ai\Domain\ValueObjects\Title;
use Ai\Infrastruture\Services\CostCalculator;
use Billing\Domain\ValueObjects\CreditCount;
use Override;
use Traversable;

class TitleGeneratorService implements TitleServiceInterface
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
    public function generateTitle(
        Content $content,
        Model $model
    ): GenerateTitleResponse {
        $words = $this->getWords($content);

        if (empty($words)) {
            $title = new Title();
            return new GenerateTitleResponse($title, new CreditCount(0));
        }

        $body = [
            'model' => $model->value,
            'message' => $words,
            'preamble' => 'Your task is to generate a single title for the given content. Identify the language of the content and generate a title that is relevant to the content. The title should be concise and informative. The title should be no more than 64 characters long. Even though the given summary is in list form, the title should not be a list. Generate the title as if it were for a blog post or news article on the topic. Don\'t generate variations of the same title with different tones or styles. Don\'t include any additional information in the title.',
            'max_tokens' => 64,
            "stream" => false
        ];

        $resp = $this->client->sendRequest('POST', '/chat', $body);
        $data = json_decode($resp->getBody()->getContents());

        $inputCost = $this->calc->calculate(
            $data->meta->billed_units->input_tokens ?? 0,
            $model,
            CostCalculator::INPUT
        );

        $outputCost = $this->calc->calculate(
            $data->meta->billed_units->output_tokens ?? 0,
            $model,
            CostCalculator::OUTPUT
        );

        $cost = new CreditCount($inputCost->value + $outputCost->value);

        $title = $data->text ?? '';
        $title = explode("\n", trim($title))[0];
        $title = trim($title, ' "');

        return new GenerateTitleResponse(
            new Title($title ?: null),
            $cost
        );
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
