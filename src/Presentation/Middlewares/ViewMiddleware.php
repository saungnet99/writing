<?php

declare(strict_types=1);

namespace Presentation\Middlewares;

use Easy\Container\Attributes\Inject;
use Option\Infrastructure\OptionResolver;
use Presentation\Resources\Api\UserResource;
use Presentation\Resources\Api\WorkspaceResource;
use Presentation\Resources\CurrencyResource;
use Presentation\Response\ViewResponse;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Twig\Environment;
use User\Domain\Entities\UserEntity;

class ViewMiddleware implements MiddlewareInterface
{
    /**
     * @param Environment $twig 
     * @param StreamFactoryInterface $streamFactory 
     * @return void 
     */
    public function __construct(
        private Environment $twig,
        private StreamFactoryInterface $streamFactory,
        private OptionResolver $optionResolver,

        #[Inject('version')]
        private string $version,

        #[Inject('license')]
        private string $license,

        #[Inject('config.locale.locales')]
        private array $locales = [],

        #[Inject('option.theme')]
        private string $theme = 'heyaikeedo/default',
    ) {
    }

    /** @inheritDoc */
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        $resp = $handler->handle($request);

        if (!($resp instanceof ViewResponse)) {
            return $resp;
        }

        $data = $resp->getData();
        $data = array_merge($data, $this->optionResolver->getOptionMap());

        if (
            $this->optionResolver->canResolve('option.site.is_secure')
            && $this->optionResolver->canResolve('option.site.domain')
        ) {
            $data['option']['site']['url'] =
                ($this->optionResolver->resolve('option.site.is_secure') ? 'https' : 'http')
                . '://' . $this->optionResolver->resolve('option.site.domain');
        }

        $data['version'] = $this->version;
        $data['license'] = $this->license;
        $data['theme'] = $this->theme;
        $data['environment'] = env('ENVIRONMENT');

        $data['view_namespace'] = $this->getViewNamespace($request);
        $data['currency'] = new CurrencyResource(
            $this->optionResolver->resolve('option.billing.currency') ?: 'USD'
        );

        $user = $request->getAttribute(UserEntity::class);

        if ($user) {
            /**
             * @deprecated
             * `auth_user` twig variable is deprecated. Use `user` instead.
             * Dont remove this variable until themese are updated.
             */
            $data['auth_user'] = new UserResource($user);
            $data['user'] = new UserResource($user, ['workspace']);
            $data['workspace'] = new WorkspaceResource($user->getCurrentWorkspace(), ['user']);
        }

        $data['locale'] = $request->getAttribute('locale');
        $data['theme_locale'] = $request->getAttribute('theme.locale');
        $data['locales'] = $this->locales;

        $colors = ['accent', 'accent_content'];
        foreach ($colors as $color) {
            if (
                !isset($data['option']['color_scheme'][$color])
                || !$data['option']['color_scheme'][$color]
            ) {
                // Remove
                unset($data['option']['color_scheme'][$color]);
                continue;
            }

            // Convert hex to rgb as assoc array
            $data['option']['color_scheme'][$color] = [
                'hex' => $data['option']['color_scheme'][$color],
                'r' => hexdec(substr($data['option']['color_scheme'][$color], 1, 2)),
                'g' => hexdec(substr($data['option']['color_scheme'][$color], 3, 2)),
                'b' => hexdec(substr($data['option']['color_scheme'][$color], 5, 2)),
                'rgb' => implode(" ", sscanf($data['option']['color_scheme'][$color], '#%02x%02x%02x')),
            ];
        }

        $stream = $this->streamFactory->createStream();
        $stream->write(
            $this->twig->render(
                $resp->getTemplate(),
                $data
            )
        );

        return $resp->withBody($stream);
    }

    /**
     * @param ServerRequestInterface $request 
     * @return null|string 
     */
    private function getViewNamespace(ServerRequestInterface $request): ?string
    {
        $path = $request->getUri()->getPath();

        $prefixes = ['admin', 'app'];

        foreach ($prefixes as $prefix) {
            if (strpos($path, "/{$prefix}") !== false) {
                return $prefix;
            }
        }

        return null;
    }
}
