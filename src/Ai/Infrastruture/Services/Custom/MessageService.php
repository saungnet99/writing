<?php

declare(strict_types=1);

namespace Ai\Infrastruture\Services\Custom;

use Ai\Domain\Completion\MessageServiceInterface;
use Ai\Domain\ValueObjects\Model;
use Ai\Domain\Entities\MessageEntity;
use Ai\Domain\ValueObjects\Call;
use Ai\Domain\ValueObjects\Chunk;
use Ai\Domain\ValueObjects\Quote;
use Ai\Infrastruture\Services\CostCalculator;
use Ai\Infrastruture\Services\Tools\KnowledgeBase;
use Ai\Infrastruture\Services\Tools\ToolCollection;
use Billing\Domain\ValueObjects\CreditCount;
use Easy\Container\Attributes\Inject;
use File\Infrastructure\FileService;
use Generator;
use Gioni06\Gpt3Tokenizer\Gpt3Tokenizer;
use Override;
use Throwable;
use Traversable;

class MessageService implements MessageServiceInterface
{
    public function __construct(
        private Client $client,
        private Gpt3Tokenizer $tokenizer,
        private CostCalculator $calc,
        private FileService $fs,
        private ToolCollection $tools,
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
    public function generateMessage(
        Model $model,
        MessageEntity $message
    ): Generator {
        $inputTokensCount = 0;
        $outputTokensCount = 0;
        $toolCost = new CreditCount(0);
        $files = [];

        $messages = $this->buildMessageHistory(
            $message,
            128000,
            $files
        );

        $modelName = str_contains($model->value, '/')
            ? explode('/', $model->value, 2)[1]
            : $model->value;

        $body = [
            'messages' => $messages,
            'model' => $modelName,
            'stream' => true,
            'stream_options' => [
                'include_usage' => true
            ]
        ];

        foreach ($this->llms as $llm) {
            if (in_array($model->value, array_column($llm['models'], 'key'))) {
                $foundModel = array_values(
                    array_filter($llm['models'], fn($m) => $m['key'] == $model->value)
                );
                if (($foundModel[0]['tools'] ?? false)) {
                    $tools = $this->getTools($message);
                    if ($tools) {
                        $body['tools'] = $tools;
                        $body['tool_choice'] = 'auto';
                    }
                }
            }
        }

        $resp = $this->client->sendRequest(
            $model,
            'POST',
            '/chat/completions',
            $body
        );
        $stream = new StreamResponse($resp);

        $calls = [];
        foreach ($stream as $data) {
            $usage = $this->helper->findUsageObject($data);

            if ($usage) {
                $inputTokensCount += $usage->prompt_tokens ?? 0;
                $outputTokensCount += $usage->completion_tokens ?? 0;
            }

            $choice = $data->choices[0] ?? null;

            if (!$choice) {
                continue;
            }

            if (isset($choice->delta->content)) {
                yield new Chunk($choice->delta->content);
            }

            if (isset($choice->delta->tool_calls)) {
                foreach ($choice->delta->tool_calls as $call) {
                    if (!isset($call->index) || !isset($call->function)) {
                        continue;
                    }

                    if (!isset($calls[$call->index])) {
                        $calls[$call->index] = $call;
                    }

                    if (isset($call->function->arguments)) {
                        $calls[$call->index]->function->arguments .= $call->function->arguments;
                    }
                }
            }
        }

        if ($calls) {
            // $body['messages'] = []; // Clear messages
            $body['messages'][] = [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => $calls
            ];
        }

        $callAgain = false;

        $embeddings = [];
        if ($message->getAssistant()?->hasDataset()) {
            foreach ($message->getAssistant()->getDataset() as $unit) {
                $embeddings[] = $unit->getEmbedding();
            }
        }

        foreach ($calls as $call) {
            $tool = $this->tools->find($call->function->name);

            if (!$tool) {
                continue;
            }

            $arguments = json_decode($call->function->arguments, true);
            yield new Chunk(new Call($call->function->name, $arguments));

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

            $body['messages'][] = [
                'role' => 'tool',
                'content' => $cr->content,
                'tool_call_id' => $call->id
            ];

            $callAgain = true;
        }

        if ($callAgain) {
            $resp = $this->client->sendRequest(
                $model,
                'POST',
                '/chat/completions',
                $body
            );
            $stream = new StreamResponse($resp);

            foreach ($stream as $data) {
                $usage = $this->helper->findUsageObject($data);

                if ($usage) {
                    $inputTokensCount += $usage->prompt_tokens ?? 0;
                    $outputTokensCount += $usage->completion_tokens ?? 0;
                }

                $choice = $data->choices[0] ?? null;

                if (!$choice) {
                    continue;
                }

                if (isset($choice->delta->content)) {
                    yield new Chunk($choice->delta->content);
                }
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

        return new CreditCount($inputCost->value + $outputCost->value + $toolCost->value);
    }

    private function buildMessageHistory(
        MessageEntity $message,
        int $maxContextTokens,
        array &$files = [],
        int $maxMessages = 20,
        int $maxImages = 2
    ): array {
        $messages = [];
        $current = $message;
        $inputTokensCount = 0;

        $imageCount = 0;
        while (true) {
            $file = $current->getFile();
            if ($file) {
                $files[] = $file;
            }

            if ($current->getContent()->value) {
                if ($current->getQuote()->value) {
                    array_unshift(
                        $messages,
                        $this->generateQuoteMessage($current->getQuote())
                    );
                }

                $tokens = 0;

                if ($current->getRole()->value == 'assistant') {
                    $content = $current->getContent()->value;
                } else {
                    $content = [];
                    $img = $current->getImage();

                    if (
                        $current->getRole()->value == 'user'
                        && $img
                        && $imageCount < $maxImages
                    ) {
                        try {
                            $imgContent = $this->fs->getFileContents($img);

                            $content[] = [
                                'type' => 'image_url',
                                'image_url' => [
                                    'url' => 'data:'
                                        . 'image/' .  $img->getExtension()
                                        . ';base64,'
                                        . base64_encode($imgContent)
                                ]
                            ];

                            $imageCount++;
                            $tokens += $this->calcualteImageToken(
                                $img->getWidth()->value,
                                $img->getHeight()->value
                            );
                        } catch (Throwable $th) {
                            // Unable to load image
                        }
                    }

                    $content[] = [
                        'type' => 'text',
                        'text' => $current->getContent()->value
                    ];
                }


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
                    'role' => 'system',
                    'content' => $assistant->getInstructions()->value
                ]);
            }
        }

        if ($files) {
            $messages[] = [
                'role' => 'system',
                'content' => 'The user has uploaded some files.'
            ];
        }

        if ($message->getAssistant()?->hasDataset()) {
            $messages[] = [
                'role' => 'system',
                'content' => 'Knowledge base is available. Use the ' . KnowledgeBase::LOOKUP_KEY . ' tool to access the knowledge base.'
            ];
        }

        return $messages;
    }

    private function generateQuoteMessage(Quote $quote): array
    {
        return [
            'role' => 'system',
            'content' => 'The user is referring to this in particular:\n' . $quote->value
        ];
    }

    private function calcualteImageToken(int $width, int $height): int
    {
        if ($width > 2048) {
            // Scale down to fit 2048x2048
            $width = 2048;
            $height = (int) (2048 / $width * $height);
        }

        if ($height > 2048) {
            // Scale down to fit 2048x2048
            $height = 2048;
            $width = (int) (2048 / $height * $width);
        }

        if ($width <= $height && $width > 768) {
            $width = 768;
            $height = (int) (768 / $width * $height);
        }

        // Calculate how many 512x512 tiles are needed to cover the image
        $tiles = (int) (ceil($width / 512) + ceil($height / 512));
        return 170 * $tiles + 85;
    }

    private function getTools(MessageEntity $message): array
    {
        $tools = [];

        foreach ($this->tools->getToolsForMessage($message) as $key => $tool) {
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => $key,
                    'description' => $tool->getDescription(),
                    'parameters' => $tool->getDefinitions()
                ]
            ];
        }

        return $tools;
    }
}