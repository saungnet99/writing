<?php

declare(strict_types=1);

namespace Presentation\RequestHandlers\App\Billing;

use Billing\Application\Commands\ReadPlanCommand;
use Billing\Domain\Entities\PlanEntity;
use Billing\Domain\Exceptions\PlanNotFoundException;
use Billing\Infrastructure\Payments\OffsitePaymentGatewayInterface;
use Billing\Infrastructure\Payments\PaymentGatewayFactoryInterface;
use Easy\Http\Message\RequestMethod;
use Easy\Router\Attributes\Route;
use Presentation\Resources\Api\PlanResource;
use Presentation\Response\RedirectResponse;
use Presentation\Response\ViewResponse;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Shared\Infrastructure\CommandBus\Dispatcher;
use Symfony\Component\Intl\Countries;
use User\Domain\Entities\UserEntity;

#[Route(path: '/checkout/[uuid:id]', method: RequestMethod::GET)]
class ViewCheckoutRequestHandler extends BillingView implements
    RequestHandlerInterface
{
    public function __construct(
        private Dispatcher $dispatcher,
        private PaymentGatewayFactoryInterface $factory
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $id = $request->getAttribute('id');

        /** @var UserEntity */
        $user = $request->getAttribute(UserEntity::class);

        try {
            $command = new ReadPlanCommand($id);

            /** @var PlanEntity */
            $plan = $this->dispatcher->dispatch($command);
        } catch (PlanNotFoundException $th) {
            return new RedirectResponse('/app/billing/plans');
        }

        if (!$plan->isActive()) {
            return new RedirectResponse('/app/billing/plans');
        }

        if ($plan->getPrice()->value <= 0) {
            $ws = $user->getCurrentWorkspace();

            if (!in_array($plan->getBillingCycle()->value, ['monthly', 'yearly'])) {
                // Only recurring plans can be free
                return new RedirectResponse('/app/billing/plans');
            }

            if ($ws->getSubscription() && $ws->getSubscription()->getPlan()->getPrice()->value <= 0) {
                return new RedirectResponse('/app/billing/plans');
            }
        }

        $gateways = [];

        try {
            $cardGateway = $this->factory->create(
                PaymentGatewayFactoryInterface::CARD_PAYMENT_GATEWAY_KEY
            );

            if (!$cardGateway->isEnabled()) {
                $cardGateway = null;
            }
        } catch (\Throwable $th) {
            $cardGateway = null;
        }

        foreach ($this->factory as $key => $gateway) {
            if (!$gateway->isEnabled()) {
                continue;
            }

            if ($cardGateway && $cardGateway === $gateway) {
                continue;
            }

            if (!($gateway instanceof OffsitePaymentGatewayInterface)) {
                continue;
            }

            $gateways[$key] = $gateway;
        }

        return new ViewResponse(
            '/templates/app/billing/checkout.twig',
            [
                'plan' => new PlanResource($plan),
                'gateways' => $gateways,
                'card_gateway' => $cardGateway,
                'countries' => Countries::getNames(),
                'states' => [
                    'AL' => 'Alabama',
                    'AK' => 'Alaska',
                    'AZ' => 'Arizona',
                    'AR' => 'Arkansas',
                    'CA' => 'California',
                    'CO' => 'Colorado',
                    'CT' => 'Connecticut',
                    'DE' => 'Delaware',
                    'FL' => 'Florida',
                    'GA' => 'Georgia',
                    'HI' => 'Hawaii',
                    'ID' => 'Idaho',
                    'IL' => 'Illinois',
                    'IN' => 'Indiana',
                    'IA' => 'Iowa',
                    'KS' => 'Kansas',
                    'KY' => 'Kentucky',
                    'LA' => 'Louisiana',
                    'ME' => 'Maine',
                    'MD' => 'Maryland',
                    'MA' => 'Massachusetts',
                    'MI' => 'Michigan',
                    'MN' => 'Minnesota',
                    'MS' => 'Mississippi',
                    'MO' => 'Missouri',
                    'MT' => 'Montana',
                    'NE' => 'Nebraska',
                    'NV' => 'Nevada',
                    'NH' => 'New Hampshire',
                    'NJ' => 'New Jersey',
                    'NM' => 'New Mexico',
                    'NY' => 'New York',
                    'NC' => 'North Carolina',
                    'ND' => 'North Dakota',
                    'OH' => 'Ohio',
                    'OK' => 'Oklahoma',
                    'OR' => 'Oregon',
                    'PA' => 'Pennsylvania',
                    'RI' => 'Rhode Island',
                    'SC' => 'South Carolina',
                    'SD' => 'South Dakota',
                    'TN' => 'Tennessee',
                    'TX' => 'Texas',
                    'UT' => 'Utah',
                    'VT' => 'Vermont',
                    'VA' => 'Virginia',
                    'WA' => 'Washington',
                    'WV' => 'West Virginia',
                    'WI' => 'Wisconsin',
                    'WY' => 'Wyoming',
                ]
            ]
        );
    }
}
