<?php

declare(strict_types=1);

use Amtgard\Denarius\Utilities\Http\PostCsrfMiddleware;
use Amtgard\Denarius\Utilities\Log\CorrelationMiddleware;
use Amtgard\Denarius\Utilities\Session\RedisSessionHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\App;
use Slim\Exception\HttpNotFoundException;
use Slim\Middleware\BodyParsingMiddleware;

return function (App $app): void {
    $app->add(PostCsrfMiddleware::class);

    $bodyParsing = new BodyParsingMiddleware();
    $app->add(function (ServerRequestInterface $request, RequestHandlerInterface $handler) use ($bodyParsing): ResponseInterface {
        if (str_starts_with($request->getUri()->getPath(), '/webhooks/')) {
            return $handler->handle($request);
        }

        return $bodyParsing->process($request, $handler);
    });

    $app->add(function (ServerRequestInterface $request, RequestHandlerInterface $handler) use ($app): ResponseInterface {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $redisHost = $_ENV['SESSION_REDIS_HOST'] ?? '';
            if ($redisHost !== '') {
                session_set_save_handler($app->getContainer()->get(RedisSessionHandler::class), true);
            }
            session_start();
        }

        return $handler->handle($request);
    });

    $errorMiddleware = $app->addErrorMiddleware(($_ENV['APP_DEBUG'] ?? 'false') === 'true', true, true);
    $errorMiddleware->setDefaultErrorHandler(function (ServerRequestInterface $request, Throwable $exception) use ($app) {
        $status = $exception instanceof HttpNotFoundException ? 404 : 500;
        $response = $app->getResponseFactory()->createResponse($status);
        $response->getBody()->write($status === 404 ? 'Not found' : 'Server error');

        return $response->withHeader('Content-Type', 'text/plain');
    });

    $app->add(CorrelationMiddleware::class);
};
