<?php

declare(strict_types=1);

namespace Ai\Infrastruture\Services\Anthropic;

use Ai\Domain\Completion\MessageServiceInterface;
use Ai\Domain\ValueObjects\Model;
use Ai\Domain\Entities\MessageEntity;
use Ai\Domain\Exceptions\ApiException;
use Ai\Domain\ValueObjects\Call;
use Ai\Domain\ValueObjects\Chunk;
use Ai\Infrastruture\Services\CostCalculator;
use Ai\Infrastruture\Services\Tools\EmbeddingSearch;
use Ai\Infrastruture\Services\Tools\KnowledgeBase;
use Ai\Infrastruture\Services\Tools\ToolCollection;
use Billing\Domain\ValueObjects\CreditCount;
use File\Infrastructure\FileService;
use Generator;
use Gioni06\Gpt3Tokenizer\Gpt3Tokenizer;
use Override;
use Throwable;
use Traversable;

class MessageService implements MessageServiceInterface
{
    private array $models = [
        'claude-3-5-sonnet-latest',
        'claude-3-5-haiku-latest',
        'claude-3-opus-20240229',
        'claude-3-sonnet-20240229',
        'claude-3-haiku-20240307'
    ];

    public function __construct(
        private Client $client,
        private Gpt3Tokenizer $tokenizer,
        private CostCalculator $calc,
        private FileService $fs,
        private ToolCollection $tools,
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
        $toolCost = new CreditCount(0);
        $files = [];

        $messages = $this->buildMessageHistory($message, $files);

        $body = [
            'messages' => $messages,
            'model' => $model->value,
            'max_tokens' => 4096,
            'stream' => true,
        ];

        $tools = $this->getTools($message);
        if ($tools && $model->value !== 'claude-3-opus-20240229') {
            $body['tools'] = $tools;
            $body['tool_choice'] = [
                'type' => 'auto'
            ];
        }

        $assistant = $message->getAssistant();

        if ($assistant) {
            $body['system'] = $assistant->getInstructions()->value;

            if ($assistant->hasDataset()) {
                $body['system'] = $body['system'] . "\n\nKnowledge base is available. Use the " . KnowledgeBase::LOOKUP_KEY . " tool to access the knowledge base.";
            }
        }

        if ($files) {
            $body['system'] = isset($body['system'])
                ? $body['system'] . "\n\nUploaded files are available. Use the " . EmbeddingSearch::LOOKUP_KEY . " tool to access the files."
                : 'Uploaded files are available. Use the ' . EmbeddingSearch::LOOKUP_KEY . ' tool to access the files.';
        }

        $resp = $this->client->sendRequest('POST', '/v1/messages', $body);
        $stream = new StreamResponse($resp);

        $streamContent = true;
        $calls = [];

        foreach ($stream as $data) {
            $type = $data->type ?? null;

            if ($type === 'error') {
                throw new ApiException($data->error->message);
            }

            if ($type == 'message_start') {
                $inputTokensCount += $data->message->usage->input_tokens ?? 0;
                $outputTokensCount += $data->message->usage->output_tokens ?? 0;

                continue;
            }

            if ($type == 'content_block_start') {
                $streamContent = true;

                if ($data->content_block->type == 'tool_use') {
                    if (!isset($calls[$data->index])) {
                        $calls[$data->index] = $data->content_block;
                        $calls[$data->index]->input = '';
                    }
                }
            }

            if ($type == 'content_block_delta') {
                if ($data->delta->type == 'text_delta') {
                    if (!$streamContent) {
                        continue;
                    }

                    $content = $data->delta->text ?? null;

                    if ($content) {
                        if (strpos($content, '<thinking>') === 0) {
                            $streamContent = false;
                        }

                        if ($streamContent) {
                            yield new Chunk($content);
                        }
                    }
                } else if ($data->delta->type == 'input_json_delta') {
                    if (!isset($calls[$data->index]->input)) {
                        continue;
                    }

                    $calls[$data->index]->input .= $data->delta->partial_json;
                }

                continue;
            }

            if ($type == 'message_delta') {
                $inputTokensCount += $data->usage->input_tokens ?? 0;
                $outputTokensCount += $data->usage->output_tokens ?? 0;

                continue;
            }
        }

        if ($calls) {
            // $body['messages'] = []; // Clear messages
            $body['messages'][] = [
                'role' => 'assistant',
                'content' => array_values($calls)
            ];
        }

        $contents = [];

        $embeddings = [];
        if ($message->getAssistant()?->hasDataset()) {
            foreach ($message->getAssistant()->getDataset() as $unit) {
                $embeddings[] = $unit->getEmbedding();
            }
        }

        foreach ($calls as $call) {
            $call->input = json_decode($call->input);
            $tool = $this->tools->find($call->name);

            if (!$tool) {
                continue;
            }

            $arguments = json_decode(json_encode($call->input), true);
            yield new Chunk(new Call($call->name, $arguments));

            $cr = $tool->call(
                $message->getConversation()->getUser(),
                $message->getConversation()->getWorkspace(),
                $arguments,
                $files,
                $embeddings
            );

            $toolCost =  new CreditCount($cr->cost->value + $toolCost->value);

            if ($cr->item) {
                yield new Chunk($cr->item);
            }

            $contents[] = [
                'type' => 'tool_result',
                'tool_use_id' => $call->id,
                'content' => $cr->content,
            ];
        }

        if ($contents) {
            $body['messages'][] = [
                'role' => 'user',
                'content' => array_values($contents)
            ];

            $resp = $this->client->sendRequest('POST', '/v1/messages', $body);

            $stream = new StreamResponse($resp);

            $streamContent = true;
            $calls = [];

            foreach ($stream as $data) {
                $type = $data->type ?? null;

                if ($type === 'error') {
                    throw new ApiException($data->error->message);
                }

                if ($type == 'message_start') {
                    $inputTokensCount += $data->message->usage->input_tokens ?? 0;
                    $outputTokensCount += $data->message->usage->output_tokens ?? 0;

                    continue;
                }

                if ($type == 'content_block_start') {
                    $streamContent = true;
                }

                if ($type == 'content_block_delta') {
                    if ($data->delta->type == 'text_delta') {
                        if (!$streamContent) {
                            continue;
                        }

                        $content = $data->delta->text ?? null;

                        if ($content) {
                            if (strpos($content, '<thinking>') === 0) {
                                $streamContent = false;
                            }

                            if ($streamContent) {
                                yield new Chunk($content);
                            }
                        }
                    }

                    continue;
                }

                if ($type == 'message_delta') {
                    $inputTokensCount += $data->usage->input_tokens ?? 0;
                    $outputTokensCount += $data->usage->output_tokens ?? 0;

                    continue;
                }
            }
        }

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

        return new CreditCount($inputCost->value + $outputCost->value + $toolCost->value);
    }

    private function buildMessageHistory(
        MessageEntity $message,
        array &$files = [],
        int $maxContextTokens = 200000,
        int $maxMessages = 20,
        int $maxImages = 2
    ): array {
        $inputTokensCount = 0;
        $messages = [];
        $current = $message;
        $maxMessages = 20;

        $imageCount = 0;
        while (true) {
            $file = $current->getFile();
            if ($file) {
                $files[] = $file;
            }

            if ($current->getContent()->value) {
                $content = [];
                $tokens = 0;
                $img = $current->getImage();

                if (
                    $current->getRole()->value == 'user'
                    && $img
                    && $imageCount < $maxImages
                ) {
                    try {
                        $imgContent = $this->fs->getFileContents($img);

                        $ext = $img->getExtension();
                        if ($ext == 'jpeg') {
                            $ext = 'jpg';
                        }

                        $content[] = [
                            'type' => 'image',
                            'source' => [
                                'type' => 'base64',
                                'media_type' => 'image/' .  $ext,
                                'data' => base64_encode($imgContent)
                            ]
                        ];

                        $imageCount++;
                    } catch (Throwable $th) {
                        // Unable to load image
                    }
                }

                $text = $current->getContent()->value;

                if ($current->getQuote()->value) {
                    $text
                        .= "\n\nThe user is referring to this in particular:\n"
                        . $current->getQuote()->value;
                }

                $content[] = [
                    'type' => 'text',
                    'text' => $text
                ];

                $tokens = $this->tokenizer->count($text);

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

        if ($messages && $messages[0]['role'] !== 'user') {
            array_unshift($messages, [
                'role' => 'user',
                'content' => '-'
            ]);
        }

        return $messages;
    }

    private function getTools(MessageEntity $message): array
    {
        $tools = [];

        foreach ($this->tools->getToolsForMessage($message) as $key => $tool) {
            $tools[] = [
                'name' => $key,
                'description' => $tool->getDescription(),
                'input_schema' => $tool->getDefinitions()
            ];
        }

        return $tools;
    }
}
