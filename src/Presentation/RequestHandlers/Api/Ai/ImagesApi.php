<?php

declare(strict_types=1);

namespace Presentation\RequestHandlers\Api\Ai;

use Ai\Application\Commands\GenerateImageCommand;
use Ai\Domain\Entities\ImageEntity;
use Ai\Domain\Exceptions\ApiException;
use Ai\Domain\Exceptions\InsufficientCreditsException;
use Easy\Http\Message\RequestMethod;
use Easy\Http\Message\StatusCode;
use Easy\Router\Attributes\Route;
use Presentation\Exceptions\HttpException;
use Presentation\Exceptions\UnprocessableEntityException;
use Presentation\Resources\Api\ImageResource;
use Presentation\Response\JsonResponse;
use Presentation\Validation\Validator;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Shared\Infrastructure\CommandBus\Dispatcher;
use User\Domain\Entities\UserEntity;
use Workspace\Domain\Entities\WorkspaceEntity;

#[Route(path: '/images', method: RequestMethod::POST)]
class ImagesApi extends AiServicesApi implements
    RequestHandlerInterface
{
    public function __construct(
        private Validator $validator,
        private Dispatcher $dispatcher,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var UserEntity */
        $user = $request->getAttribute(UserEntity::class);

        /** @var WorkspaceEntity */
        $ws = $request->getAttribute(WorkspaceEntity::class);

        $this->validateRequest($request);
        $payload = (object) $request->getParsedBody();

        $cmd = new GenerateImageCommand(
            $ws,
            $user,
            $payload->model,
        );

        $cmd->param('prompt', $payload->prompt);

        if (isset($payload->width)) {
            $cmd->setWidth((int) $payload->width);
        }

        if (isset($payload->height)) {
            $cmd->setHeight((int) $payload->height);
        }

        if (isset($payload->style)) {
            $cmd->param('style', $payload->style);
        }

        if (isset($payload->negative_prompt)) {
            $cmd->param('negative_prompt', $payload->negative_prompt);
        }

        if (isset($payload->quality)) {
            $cmd->param('quality', $payload->quality);
        }

        if (isset($payload->aspect_ratio)) {
            $cmd->param('aspect_ratio', $payload->aspect_ratio);
        }

        if (isset($payload->size)) {
            $cmd->param('size', $payload->size);
        }

        try {
            /** @var ImageEntity */
            $image = $this->dispatcher->dispatch($cmd);
        } catch (InsufficientCreditsException $th) {
            throw new HttpException(
                'Insufficient credits',
                StatusCode::FORBIDDEN
            );
        } catch (ApiException $th) {
            throw new UnprocessableEntityException(
                $th->getMessage(),
                previous: $th
            );
        }

        return new JsonResponse(
            new ImageResource($image)
        );
    }

    private function validateRequest(ServerRequestInterface $req): void
    {
        $this->validator->validateRequest($req, [
            'model' => 'required|string',
            'prompt' => 'required|string',
            'width' => 'sometimes|integer',
            'height' => 'sometimes|integer',
            'aspect_ratio' => 'sometimes|string',
            'size' => 'sometimes|string',
            'style' => 'string',
            'negative_prompt' => 'string',
            'quality' => 'string',
        ]);
    }
}
