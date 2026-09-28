<?php

declare(strict_types=1);

use Amtgard\Denarius\Controller\AdminController;
use Amtgard\Denarius\Controller\HomeController;
use Amtgard\Denarius\Controller\KingdomPageController;
use Amtgard\Denarius\Controller\ManagerController;
use Amtgard\Denarius\Controller\WebhookController;
use Amtgard\Denarius\Utilities\Http\SyncPrincipalMiddleware;
use Amtgard\IdpClient\Slim\IdpAuthController;
use Amtgard\IdpClient\Slim\SessionMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app): void {
    $app->get('/', [HomeController::class, 'home'])->setName('home');
    $app->get('/version', [HomeController::class, 'version'])->setName('version');
    $app->post('/webhooks/teller', [WebhookController::class, 'teller']);
    $app->post('/webhooks/stripe', [WebhookController::class, 'stripe']);
    $app->post('/webhooks/plaid', [WebhookController::class, 'plaid']);

    $app->group('', function (RouteCollectorProxy $group): void {
        $group->get('/login', [IdpAuthController::class, 'login'])->setName('auth.login');
        $group->get('/oauth/callback', [IdpAuthController::class, 'callback'])->setName('auth.callback');
        $group->get('/logout', [IdpAuthController::class, 'logout'])->setName('auth.logout');
        $group->get('/admin', [AdminController::class, 'index']);
        $group->post('/admin/grant', [AdminController::class, 'grant']);
        $group->get('/manage/{slug}', [ManagerController::class, 'show']);
        $group->post('/manage/{slug}/settings', [ManagerController::class, 'settings']);
        $group->post('/manage/{slug}/connect', [ManagerController::class, 'connect']);
        $group->post('/manage/{slug}/enrollment', [ManagerController::class, 'enrollment']);
        $group->post('/manage/{slug}/accounts', [ManagerController::class, 'accounts']);
        $group->post('/manage/{slug}/refresh', [ManagerController::class, 'refresh']);
    })->add(SessionMiddleware::class)->add(SyncPrincipalMiddleware::class);

    $app->get('/{slug}', [KingdomPageController::class, 'show'])
        ->add(SessionMiddleware::class)
        ->add(SyncPrincipalMiddleware::class);
};
