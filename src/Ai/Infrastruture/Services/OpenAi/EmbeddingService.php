<?php

declare(strict_types=1);

namespace Ai\Infrastruture\Services\OpenAi;

use Ai\Domain\Embedding\EmbeddingResponse;
use Ai\Domain\Embedding\EmbeddingServiceInterface;
use Ai\Domain\Exceptions\ApiException;
use Ai\Domain\ValueObjects\Embedding;
use Ai\Domain\ValueObjects\EmbeddingMap;
use Ai\Domain\ValueObjects\Model;
use Ai\Infrastruture\Services\CostCalculator;
use Billing\Domain\ValueObjects\CreditCount;
use Override;
use Psr\Http\Client\ClientExceptionInterface;
use Traversable;

class EmbeddingService implements EmbeddingServiceInterface
{
    private array $models = [
        'text-embedding-3-large',
        'text-embedding-3-small',
        'text-embedding-ada-002'
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

    public function generateEmbedding(Model $model, string $text): EmbeddingResponse
    {
        $chunks = $this->splitIntoChunks($text);
        $tokens = 0;
        $maps = [];

        // Split chunks array into groups of 10
        $groups = array_chunk($chunks, 1000);

        foreach ($groups as $index => $group) {
            $group = array_values(array_filter($group));

            if (!$group) {
                continue;
            }

            try {
                $resp = $this->client->sendRequest('POST', '/v1/embeddings', [
                    'model' => $model->value,
                    'input' => $group,
                ]);
            } catch (ClientExceptionInterface $th) {
                throw new ApiException($th->getMessage(), previous: $th);
            }

            $json = json_decode($resp->getBody()->getContents());

            if ($resp->getStatusCode() !== 200) {
                throw new ApiException($json->error->message);
            }

            foreach ($json->data as $data) {
                $maps[] = new EmbeddingMap(
                    $group[$data->index],
                    $data->embedding
                );
            }

            $tokens += $json->usage->total_tokens;
        }

        if ($this->client->hasCustomKey()) {
            $cost = new CreditCount(0);
        } else {
            $cost = $this->calc->calculate($tokens, $model);
        }

        return new EmbeddingResponse(
            new Embedding(...$maps),
            $cost
        );
    }

    private function splitIntoChunks($text, $maxTokens = 1024): array
    {
        $words = explode(' ', $text);
        $chunks = [];
        $chunk = "";

        foreach ($words as $word) {
            if (strlen($word) > $maxTokens) {
                // If the current chunk is not empty, add it to chunks
                if ($chunk !== "") {
                    $chunks[] = trim($chunk);
                    $chunk = "";
                }
                // Split the long word into smaller parts
                $chunks = array_merge($chunks, str_split($word, $maxTokens));
            } elseif (strlen($chunk . ' ' . $word) > $maxTokens) {
                $chunks[] = trim($chunk);
                $chunk = $word;
            } else {
                $chunk .= ($chunk === "" ? '' : ' ') . $word;
            }
        }

        if ($chunk !== "") {
            $chunks[] = trim($chunk);
        }

        return $chunks;
    }
}
