<?php

declare(strict_types=1);

namespace Ai\Infrastruture\Services\Tools;

use Ai\Domain\Embedding\EmbeddingServiceInterface;
use Ai\Domain\Services\AiServiceFactoryInterface;
use Ai\Domain\ValueObjects\Model;
use Override;
use User\Domain\Entities\UserEntity;
use Workspace\Domain\Entities\WorkspaceEntity;

class KnowledgeBase implements ToolInterface
{
    public const LOOKUP_KEY = 'knowledge_base';

    public function __construct(
        private AiServiceFactoryInterface $factory,
    ) {}


    #[Override]
    public function isEnabled(): bool
    {
        return true;
    }

    #[Override]
    public function getDescription(): string
    {
        return 'Retrieves the information for the search query based on the knowledge base. Returns the most relevant results in JSON-encoded format. Always prioritize this call.';
    }

    #[Override]
    public function getDefinitions(): array
    {
        return [
            "type" => "object",
            "properties" => [
                "query" => [
                    "type" => "string",
                    "description" => "Query to search the knowledge base for."
                ],
            ],
            "required" => ["query"]
        ];
    }

    #[Override]
    public function call(
        UserEntity $user,
        WorkspaceEntity $workspace,
        array $params = [],
        array $files = [],
        array $knowledgeBase = [],
    ): CallResponse {
        $query = $params['query'];
        $results = [];

        $model = new Model('text-embedding-ada-002'); // Default model
        $sub = $workspace->getSubscription();
        if ($sub) {
            $model = $sub->getPlan()->getConfig()->embeddingModel;
        }

        $service = $this->factory->create(
            EmbeddingServiceInterface::class,
            $model
        );

        $resp = $service->generateEmbedding($model, $query);

        foreach ($knowledgeBase as $embeddings) {
            if (!$embeddings->value) {
                continue;
            }

            foreach ($embeddings->value as $em) {
                $similarity = $this->cosineSimilarity(
                    $em['embedding'],
                    $resp->embedding->value[0]['embedding']
                );

                $results[] = [
                    'content' => $em['content'],
                    'similarity' => $similarity
                ];
            }
        }

        usort($results, function ($a, $b) {
            return $b['similarity'] <=> $a['similarity'];
        });

        $results = array_slice($results, 0, 5);
        $texts = array_map(function ($r) {
            return $r['content'];
        }, $results);

        $content = json_encode($texts, JSON_INVALID_UTF8_SUBSTITUTE);
        if ($content === false) {
            $content = 'Failed to encode results: ' . json_last_error_msg();
        }

        return new CallResponse(
            $content,
            $resp->cost
        );
    }

    private function cosineSimilarity($vec1, $vec2)
    {
        $dot_product = 0.0;
        $vec1_magnitude = 0.0;
        $vec2_magnitude = 0.0;

        for ($i = 0; $i < count($vec1); $i++) {
            $dot_product += $vec1[$i] * $vec2[$i];
            $vec1_magnitude += $vec1[$i] * $vec1[$i];
            $vec2_magnitude += $vec2[$i] * $vec2[$i];
        }

        $vec1_magnitude = sqrt($vec1_magnitude);
        $vec2_magnitude = sqrt($vec2_magnitude);

        if ($vec1_magnitude == 0.0 || $vec2_magnitude == 0.0) {
            return 0.0; // to handle division by zero
        }

        return $dot_product / ($vec1_magnitude * $vec2_magnitude);
    }
}
