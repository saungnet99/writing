<?php

declare(strict_types=1);

namespace Ai\Infrastruture;

use Ai\Domain\Repositories\LibraryItemRepositoryInterface;
use Ai\Domain\Services\AiServiceFactoryInterface;
use Ai\Infrastruture\Repositories\DoctrineOrm\LibraryItemRepository;
use Ai\Infrastruture\Services\AiServiceFactory;
use Ai\Infrastruture\Services\OpenAi as OpenAiServices;
use Ai\Infrastruture\Services\Cohere;
use Ai\Infrastruture\Services\xAi;
use Ai\Infrastruture\Services\FalAi;
use Ai\Infrastruture\Services\Aimlapi;
use Ai\Infrastruture\Services\ElevenLabs;
use Ai\Infrastruture\Services\Ollama;
use Ai\Infrastruture\Services\Custom;
use Ai\Infrastruture\Services\StabilityAi as StabilityAiServices;
use Ai\Infrastruture\Services\Clipdrop as ClipdropServices;
use Ai\Infrastruture\Services\Google as GoogleServices;
use Ai\Infrastruture\Services\Anthropic as AnthropicServices;
use Ai\Infrastruture\Services\Azure as AzureServices;
use Ai\Infrastruture\Services\Tools\KnowledgeBase;
use Ai\Infrastruture\Services\Tools\EmbeddingSearch;
use Ai\Infrastruture\Services\Tools\GenerateImage;
use Ai\Infrastruture\Services\Tools\GoogleSearch;
use Ai\Infrastruture\Services\Tools\ToolCollection;
use Ai\Infrastruture\Services\Tools\WebScrap;
use Ai\Infrastruture\Services\Tools\Youtube;
use Application;
use Psr\Container\ContainerInterface;
use Psr\Http\Client\ClientInterface;
use Shared\Infrastructure\BootstrapperInterface;

class AiModuleBootstrapper implements BootstrapperInterface
{
    /**
     * @param Application $app 
     * @return void 
     */
    public function __construct(
        private Application $app,
        private AiServiceFactory $factory,
        private ClientInterface $httpClient,
        private ContainerInterface $container,
    ) {}

    /** @inheritDoc */
    public function bootstrap(): void
    {
        $this->setupAiServiceFactory();
        $this->setupToolCollection();
    }

    /** @return void  */
    private function setupAiServiceFactory(): void
    {
        $this->app->set(
            LibraryItemRepositoryInterface::class,
            LibraryItemRepository::class
        );

        $this->app->set(
            AiServiceFactoryInterface::class,
            $this->factory
        );

        $this->factory
            ->register(OpenAiServices\CompletionService::class)
            ->register(OpenAiServices\TitleGeneratorService::class)
            ->register(OpenAiServices\CodeCompletionService::class)
            ->register(OpenAiServices\ImageService::class)
            ->register(OpenAiServices\TranscriptionService::class)
            ->register(OpenAiServices\SpeechService::class)
            ->register(OpenAiServices\MessageService::class)
            ->register(OpenAiServices\MessageServiceO1::class)
            ->register(OpenAiServices\ClassificationService::class)
            ->register(OpenAiServices\EmbeddingService::class)
            ->register(ElevenLabs\SpeechService::class)
            ->register(ElevenLabs\VoiceIsolatorService::class)
            ->register(GoogleServices\SpeechService::class)
            ->register(StabilityAiServices\ImageGeneratorService::class)
            ->register(ClipdropServices\ImageGeneratorService::class)
            ->register(AnthropicServices\CompletionService::class)
            ->register(AnthropicServices\CodeCompletionService::class)
            ->register(AnthropicServices\TitleGeneratorService::class)
            ->register(AnthropicServices\MessageService::class)
            ->register(AzureServices\SpeechService::class)
            ->register(Cohere\MessageService::class)
            ->register(Cohere\CompletionService::class)
            ->register(Cohere\CodeCompletionService::class)
            ->register(Cohere\TitleGeneratorService::class)
            ->register(FalAi\ImageGeneratorService::class)
            ->register(Aimlapi\CompositionService::class)
            ->register(xAi\MessageService::class)
            ->register(xAi\TitleGeneratorService::class)
            ->register(xAi\CompletionService::class)
            ->register(xAi\CodeCompletionService::class)
            ->register(Ollama\MessageService::class)
            ->register(Custom\MessageService::class)
            ->register(Custom\TitleGeneratorService::class)
            ->register(Custom\CompletionService::class)
            ->register(Custom\CodeCompletionService::class);
    }

    private function setupToolCollection(): void
    {
        $collection = new ToolCollection($this->container);

        $collection->add(
            GoogleSearch::LOOKUP_KEY,
            GoogleSearch::class
        );

        $collection->add(
            WebScrap::LOOKUP_KEY,
            WebScrap::class
        );

        $collection->add(
            GenerateImage::LOOKUP_KEY,
            GenerateImage::class
        );

        $collection->add(
            EmbeddingSearch::LOOKUP_KEY,
            EmbeddingSearch::class
        );

        $collection->add(
            KnowledgeBase::LOOKUP_KEY,
            KnowledgeBase::class
        );

        $collection->add(
            Youtube::LOOKUP_KEY,
            Youtube::class
        );

        $this->app->set(ToolCollection::class, $collection);
    }
}
