<?php

declare(strict_types=1);

namespace Ai\Infrastruture\Services\Cohere;

use Ai\Domain\Completion\MessageServiceInterface;
use Ai\Domain\ValueObjects\Model;
use Ai\Domain\Entities\MessageEntity;
use Ai\Domain\Exceptions\ApiException;
use Ai\Domain\ValueObjects\Call;
use Ai\Domain\ValueObjects\Chunk;
use Ai\Infrastruture\Services\CostCalculator;
use Ai\Infrastruture\Services\Tools\KnowledgeBase;
use Ai\Infrastruture\Services\Tools\ToolCollection;
use Billing\Domain\ValueObjects\CreditCount;
use Easy\Container\Attributes\Inject;
use Generator;
use Override;
use Traversable;

class MessageService implements MessageServiceInterface
{
    private array $models = [
        'command-r-plus',
        'command-r',
        'command',
        'command-light',
    ];

    public function __construct(
        private Client $client,
        private CostCalculator $calc,
        private ToolCollection $tools,

        #[Inject('option.features.tools.cohere_web_search.is_enabled')]
        private ?bool $webSearchEnabled = false,
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
            'message' => $message->getContent()->value,
            'model' => $model->value,
            'stream' => true,
            'prompt_truncation' => 'AUTO',
            'chat_history' => $messages,
        ];

        $connectors = $this->getConnectors($message);
        if ($connectors) {
            $body['connectors'] = $connectors;
        } else if (in_array($model->value, ['command-r-plus', 'command-r'])) {
            $tools = $this->getTools($message);

            if ($tools) {
                $body['tools'] = $tools;
            }
        }

        $assistant = $message->getAssistant();
        if ($assistant) {
            $body['preamble'] = $assistant->getInstructions()->value;

            if ($assistant->hasDataset()) {
                $body['preamble'] = $body['preamble'] . "\n\nKnowledge base is available. Use the " . KnowledgeBase::LOOKUP_KEY . " tool to access the knowledge base.";
            }
        }

        if ($files) {
            $body['preamble'] = isset($body['preamble'])
                ? $body['preamble'] . "\n\nYou have an access to the embeddings of the user uploaded files."
                : 'You have an access to the embeddings of the user uploaded files.';
        }

        $resp = $this->client->sendRequest('POST', '/chat', $body);

        if ($resp->getStatusCode() !== 200) {
            throw new ApiException('Failed to generate message: ' . $resp->getBody()->getContents());
        }

        $stream = new StreamResponse($resp);

        $calls = [];
        foreach ($stream as $data) {
            $type = $data->event_type ?? null;

            if ($type == 'stream-end') {
                $inputTokensCount += $data->response->meta->billed_units->input_tokens ?? 0;
                $outputTokensCount += $data->response->meta->billed_units->output_tokens ?? 0;

                if (isset($data->response->tool_calls)) {
                    $body['chat_history'] = $data->response->chat_history;
                    $calls = $data->response->tool_calls;
                }

                break;
            }

            if ($type == 'text-generation') {
                yield new Chunk($data->text);
            }

            if ($type == 'search-queries-generation') {
                yield new Chunk(new Call('web_search', [
                    'query' => $data->search_queries[0]->text
                ]));
            }
        }

        if ($calls) {
            $body['tool_results'] = [];

            $embeddings = [];
            if ($message->getAssistant()?->hasDataset()) {
                foreach ($message->getAssistant()->getDataset() as $unit) {
                    $embeddings[] = $unit->getEmbedding();
                }
            }

            foreach ($calls as $call) {
                $tool = $this->tools->find($call->name);

                if (!$tool) {
                    continue;
                }

                $arguments = json_decode(json_encode($call->parameters ?? []), true);
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

                $body['tool_results'][] = [
                    'call' => $call,
                    'outputs' => [
                        ['call_response' => $cr->content]
                    ],
                ];
            }

            if ($body['tool_results']) {
                $body['force_single_step'] = true;
                $resp = $this->client->sendRequest('POST', '/chat', $body);

                if ($resp->getStatusCode() !== 200) {
                    throw new ApiException('Failed to generate message: ' . $resp->getBody()->getContents());
                }

                $stream = new StreamResponse($resp);

                foreach ($stream as $data) {
                    $type = $data->event_type ?? null;

                    if ($type == 'stream-end') {
                        $inputTokensCount += $data->response->meta->billed_units->input_tokens ?? 0;
                        $outputTokensCount += $data->response->meta->billed_units->output_tokens ?? 0;

                        break;
                    }

                    if ($type == 'text-generation') {
                        yield new Chunk($data->text);
                    }
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
        array &$files = [],
        int $maxMessages = 20
    ): array {
        $messages = [];
        $current = $message->getParent();

        while ($current) {
            $file = $current->getFile();
            if ($file) {
                $files[] = $file;
            }

            if ($current->getContent()->value) {
                $text = $current->getContent()->value;

                if ($current->getQuote()->value) {
                    $text
                        .= "\n\nThe user is referring to this in particular:\n"
                        . $current->getQuote()->value;
                }

                $role = strtoupper($current->getRole()->value);
                if ($role == 'ASSISTANT') {
                    $role = 'CHATBOT';
                }

                array_unshift($messages, [
                    'role' => $role,
                    'message' => $text
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

        return $messages;
    }

    private function getConnectors(MessageEntity $message): array
    {
        $sub = $message->getConversation()->getWorkspace()->getSubscription();
        if (!$sub) {
            return [];
        }

        $plan = $sub->getPlan();
        $config = $plan->getConfig();

        $connectors = [];

        if (
            $this->webSearchEnabled
            && isset($config->tools['cohere_web_search'])
            && $config->tools['cohere_web_search']
        ) {
            $connectors[] = [
                'id' => 'web-search',
            ];
        }

        return $connectors;
    }

    private function getTools(MessageEntity $message): array
    {
        $tools = [];

        foreach ($this->tools->getToolsForMessage($message) as $key => $tool) {
            $defs = $tool->getDefinitions();

            $convertedDefs = [];
            $required = $defs['required'] ?? [];

            $types = [
                'string' => 'str',
                'integer' => 'int',
                'number' => 'float',
                'boolean' => 'bool',
                'object' => 'Dict',
                'array' => 'List'
            ];

            if (isset($defs['properties'])) {
                foreach ($defs['properties'] as $name => $rules) {
                    $convertedDefs[$name] = [];

                    if (isset($rules['description'])) {
                        $convertedDefs[$name]['description'] = $rules['description'];
                    }

                    if (in_array($name, $required)) {
                        $convertedDefs[$name]['required'] = true;
                    }

                    if (isset($rules['type'])) {
                        $type = $rules['type'];
                        $convertedDefs[$name]['type'] = $types[$type] ?? $type;
                    }

                    if (isset($rules['enum'])) {
                        $convertedDefs[$name]['description'] .= ' Possible enum values: ' . implode(', ', $rules['enum']) . '.';
                    }
                }
            }


            $tools[] = [
                'name' => $key,
                'description' => $tool->getDescription(),
                'parameter_definitions' => $convertedDefs
            ];
        }

        return $tools;
    }
}
