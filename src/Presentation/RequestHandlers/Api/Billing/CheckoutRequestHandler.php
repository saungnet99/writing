<?php

declare(strict_types=1);

namespace Presentation\RequestHandlers\Api\Billing;

use Billing\Application\Commands\CancelSubscriptionCommand;
use Billing\Application\Commands\CreateOrderCommand;
use Billing\Application\Commands\FulfillOrderCommand;
use Billing\Application\Commands\PayOrderCommand;
use Billing\Domain\Entities\OrderEntity;
use Billing\Infrastructure\Payments\PaymentGatewayFactoryInterface;
use Billing\Infrastructure\Payments\Exceptions\PaymentException;
use Billing\Infrastructure\Payments\PurchaseToken;
use Easy\Http\Message\RequestMethod;
use Easy\Http\Message\StatusCode;
use Easy\Router\Attributes\Route;
use Presentation\AccessControls\Permission;
use Presentation\AccessControls\WorkspaceAccessControl;
use Presentation\Exceptions\UnprocessableEntityException;
use Presentation\Resources\Api\OrderResource;
use Presentation\Response\JsonResponse;
use Presentation\Validation\Validator;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Shared\Infrastructure\CommandBus\Dispatcher;
use User\Domain\Entities\UserEntity;
use Workspace\Domain\Entities\WorkspaceEntity;

#[Route(path: '/checkout', method: RequestMethod::POST)]
class CheckoutRequestHandler extends BillingApi implements
    RequestHandlerInterface
{
    public function __construct(
        private Validator $validator,
        private WorkspaceAccessControl $ac,
        private Dispatcher $dispatcher,
        private PaymentGatewayFactoryInterface $factory
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->validateRequest($request);
        $payload = (object) $request->getParsedBody();

        /** @var WorkspaceEntity */
        $ws = $request->getAttribute(WorkspaceEntity::class);

        // Current subscription, cancel after new subscription is created
        $sub = $ws->getSubscription();

        // Create an order...
        $cmd = new CreateOrderCommand($ws, $payload->id);

        if (property_exists($payload, 'coupon') && $payload->coupon) {
            $cmd->setCoupon($payload->coupon);
        }

        /** @var OrderEntity */
        $order = $this->dispatcher->dispatch($cmd);

        if ($order->getTotalPrice()->value > 0 && !$order->isPaid()) {
            // Pay for order...
            $gateway = $this->factory->create($payload->gateway);

            try {
                $resp = $gateway->purchase($order);
            } catch (PaymentException $th) {
                throw new UnprocessableEntityException(
                    previous: $th,
                );
            }

            if ($resp instanceof UriInterface) {
                return new JsonResponse(
                    [
                        'redirect' => (string) $resp
                    ]
                );
            }

            if ($resp instanceof PurchaseToken) {
                return new JsonResponse([
                    'id' => $order->getId()->getValue()->toString(),
                    'purchase_token' => $resp->value
                ]);
            }

            $cmd = new PayOrderCommand($order, $payload->gateway, $resp);
            $this->dispatcher->dispatch($cmd);
        }

        $cmd = new FulfillOrderCommand($order);
        $resp = $this->dispatcher->dispatch($cmd);

        // Cancel current subscription
        if ($sub) {
            $cmd = new CancelSubscriptionCommand($sub);
            $this->dispatcher->dispatch($cmd);
        }

        return new JsonResponse(new OrderResource($order), StatusCode::CREATED);
    }

    private function validateRequest(ServerRequestInterface $req): void
    {
        $this->validator->validateRequest($req, [
            'id' => 'required|uuid',
            'gateway' => 'string',
            'coupon' => 'string|nullable'
        ]);

        /** @var UserEntity */
        $user = $req->getAttribute(UserEntity::class);

        /** @var WorkspaceEntity */
        $workspace = $req->getAttribute(WorkspaceEntity::class);

        $this->ac->denyUnlessGranted(
            Permission::WORKSPACE_MANAGE,
            $user,
            $workspace
        );
    }
}
