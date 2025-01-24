<?php

declare(strict_types=1);

namespace Presentation\RequestHandlers\Api\Voices;

use Easy\Container\Attributes\Inject;
use Easy\Http\Message\RequestMethod;
use Easy\Router\Attributes\Route;
use Presentation\Resources\Api\VoiceResource;
use Presentation\Resources\ListResource;
use Presentation\Response\JsonResponse;
use Presentation\Validation\ValidationException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Shared\Infrastructure\CommandBus\Dispatcher;
use Traversable;
use Voice\Application\Commands\ListVoicesCommand;
use Voice\Domain\Entities\VoiceEntity;
use Voice\Domain\Exceptions\VoiceNotFoundException;
use Voice\Domain\ValueObjects\Status;
use Workspace\Domain\Entities\WorkspaceEntity;

#[Route(path: '/', method: RequestMethod::GET)]
class ListVoicesRequestHandler extends VoiceApi implements
    RequestHandlerInterface
{
    public function __construct(
        private Dispatcher $dispatcher,

        #[Inject('option.features.voiceover.models')]
        private ?array $models = null
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var WorkspaceEntity */
        $ws = $request->getAttribute(WorkspaceEntity::class);

        $params = (object) $request->getQueryParams();

        $cmd = new ListVoicesCommand();
        $cmd->status = Status::from(1);

        if ($this->models) {
            $models = $this->models;

            $config = $ws->getSubscription()?->getPlan()->getConfig();
            if ($config && !isset($params->all)) {
                $models = array_filter(
                    $models,
                    fn($model) => isset($config->models[$model]) && $config->models[$model]
                );
            }

            $cmd->setModels(...$models);
        }

        if (property_exists($params, 'provider')) {
            $cmd->setProvider($params->provider);
        }

        if (property_exists($params, 'tone')) {
            $cmd->setTone($params->tone);
        }

        if (property_exists($params, 'use_case')) {
            $cmd->setUseCase($params->use_case);
        }

        if (property_exists($params, 'gender')) {
            $cmd->setGender($params->gender);
        }

        if (property_exists($params, 'accent')) {
            $cmd->setAccent($params->accent);
        }

        if (property_exists($params, 'language')) {
            $cmd->setLanguageCode($params->language);
        }

        if (property_exists($params, 'age')) {
            $cmd->setAge($params->age);
        }

        if (property_exists($params, 'query') && $params->query) {
            $cmd->query = $params->query;
        }

        if (property_exists($params, 'sort') && $params->sort) {
            $sort = explode(':', $params->sort);
            $orderBy = $sort[0];
            $dir = $sort[1] ?? 'asc';
            $cmd->setOrderBy($orderBy, $dir);
        }

        if (
            property_exists($params, 'starting_after')
            && $params->starting_after
        ) {
            $cmd->setCursor(
                $params->starting_after,
                'starting_after'
            );
        } elseif (
            property_exists($params, 'ending_before')
            && $params->ending_before
        ) {
            $cmd->setCursor(
                $params->ending_before,
                'ending_before'
            );
        }

        if (property_exists($params, 'limit')) {
            $cmd->setLimit((int) $params->limit);
        }

        try {
            /** @var Traversable<int,VoiceEntity> $voices */
            $voices = $this->dispatcher->dispatch($cmd);
        } catch (VoiceNotFoundException $th) {
            throw new ValidationException(
                'Invalid cursor',
                property_exists($params, 'starting_after')
                    ? 'starting_after'
                    : 'ending_before',
                previous: $th
            );
        }

        $res = new ListResource();
        foreach ($voices as $voice) {
            $res->pushData(new VoiceResource($voice));
        }

        return new JsonResponse($res);
    }
}
