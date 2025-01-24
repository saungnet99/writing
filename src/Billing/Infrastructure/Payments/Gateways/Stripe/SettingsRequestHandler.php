<?php

declare(strict_types=1);

namespace Billing\Infrastructure\Payments\Gateways\Stripe;

use Easy\Http\Message\RequestMethod;
use Easy\Router\Attributes\Route;
use Presentation\RequestHandlers\Admin\AbstractAdminViewRequestHandler;
use Presentation\Response\ViewResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\Intl\Currencies;
use Twig\Loader\FilesystemLoader;

#[Route(path: '/settings/payments/stripe', method: RequestMethod::GET)]
class SettingsRequestHandler extends AbstractAdminViewRequestHandler implements
    RequestHandlerInterface
{
    public function __construct(
        FilesystemLoader $loader,
    ) {
        $loader->addPath(__DIR__, "stripe");
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $currencies = Currencies::getNames();

        $supported = [
            "USD", "AED", "AFN", "ALL", "AMD", "ANG", "AOA", "ARS", "AUD",
            "AWG", "AZN", "BAM", "BBD", "BDT", "BGN", "BIF", "BMD", "BND",
            "BOB", "BRL", "BSD", "BWP", "BYN", "BZD", "CAD", "CDF", "CHF",
            "CLP", "CNY", "COP", "CRC", "CVE", "CZK", "DJF", "DKK", "DOP",
            "DZD", "EGP", "ETB", "EUR", "FJD", "FKP", "GBP", "GEL", "GIP",
            "GMD", "GNF", "GTQ", "GYD", "HKD", "HNL", "HTG", "HUF", "IDR",
            "ILS", "INR", "ISK", "JMD", "JPY", "KES", "KGS", "KHR", "KMF",
            "KRW", "KWD", "KYD", "KZT", "LAK", "LBP", "LKR", "LRD", "LSL",
            "MAD", "MDL", "MGA", "MKD", "MMK", "MNT", "MOP", "MUR", "MVR",
            "MWK", "MXN", "MYR", "MZN", "NAD", "NGN", "NIO", "NOK", "NPR",
            "NZD", "OMR", "PAB", "PEN", "PGK", "PHP", "PKR", "PLN", "PYG",
            "QAR", "RON", "RSD", "RUB", "RWF", "SAR", "SBD", "SCR", "SEK",
            "SGD", "SHP", "SLE", "SOS", "SRD", "STD", "SZL", "THB", "TJS",
            "TOP", "TRY", "TTD", "TWD", "TZS", "UAH", "UGX", "UYU", "UZS",
            "VND", "VUV", "WST", "XAF", "XCD", "XOF", "XPF", "YER", "ZAR",
            "ZMW"
        ];

        $currencies = array_filter(
            $currencies,
            fn ($key) => in_array($key, $supported),
            ARRAY_FILTER_USE_KEY
        );

        return new ViewResponse(
            '@stripe/settings.twig',
            [
                'currencies' => $currencies,
            ]
        );
    }
}