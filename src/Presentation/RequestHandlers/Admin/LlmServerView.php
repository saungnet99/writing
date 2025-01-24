<?php

declare(strict_types=1);

namespace Presentation\RequestHandlers\Admin;

use Easy\Container\Attributes\Inject;
use Easy\Http\Message\RequestMethod;
use Easy\Router\Attributes\Route;
use Presentation\Response\RedirectResponse;
use Presentation\Response\ViewResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramsey\Uuid\Nonstandard\Uuid;
use Symfony\Component\Intl\Exception\MissingResourceException;

#[Route(path: '/settings/llms/[uuid:id]', method: RequestMethod::GET)]
#[Route(path: '/settings/llms', method: RequestMethod::GET)]
class LlmServerView extends AbstractAdminViewRequestHandler implements
    RequestHandlerInterface
{
    public function __construct(
        #[Inject('option.llms')]
        private array $llms  = [],
    ) {}

    /**
     * @throws MissingResourceException 
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $id = $request->getAttribute('id');
        $data = [
            "id" => Uuid::uuid4()->toString(),
        ];

        if ($id) {
            if (!isset($this->llms[$id])) {
                return new RedirectResponse('/admin/settings');
            }

            $data['llms'] = $this->llms[$id];
            $data['id'] = $id;
        }

        return new ViewResponse(
            '/templates/admin/settings/llms.twig',
            $data
        );
    }
}
