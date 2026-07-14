<?php

declare(strict_types=1);

namespace SlimCors\Support;

use InvalidArgumentException;
use Psr\Http\Message\ResponseFactoryInterface;

trait ResolvesResponseFactory
{
    private function resolveResponseFactory(?ResponseFactoryInterface $configured): ResponseFactoryInterface
    {
        if ($configured !== null) {
            return $configured;
        }

        if (class_exists(\Slim\Psr7\Factory\ResponseFactory::class)) {
            return new \Slim\Psr7\Factory\ResponseFactory();
        }

        throw new InvalidArgumentException(
            'No response_factory provided and slim/psr7 is not installed. '
            . 'Pass a PSR-17 ResponseFactoryInterface via the "response_factory" option.'
        );
    }
}
