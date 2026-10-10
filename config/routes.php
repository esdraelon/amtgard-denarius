<?php

declare(strict_types=1);

use Amtgard\Denarius\Controller\AdminController;
use Amtgard\Denarius\Controller\HomeController;
use Amtgard\Denarius\Controller\KingdomPageController;
use Amtgard\Denarius\Controller\ManagerController;
use Amtgard\Denarius\Controller\SimpleFinReturnController;
use Amtgard\Denarius\Controller\WebhookController;
use Amtgard\Denarius\Utilities\Http\SyncPrincipalMiddleware;
use Amtgard\IdpClient\Slim\IdpAuthController;
use Amtgard\IdpClient\Slim\SessionMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app): void {
    $app->get('/', [HomeController::class, 'home'])->setName('home');
    $app->get('/version', [HomeController::class, 'version'])->setName('version');
    $app->get('/privacy-policy', [HomeController::class, 'privacyPolicy'])->setName('privacy-policy');
    $app->post('/webhooks/teller', [WebhookController::class, 'teller']);
    $app->post('/webhooks/stripe', [WebhookController::class, 'stripe']);
    $app->post('/webhooks/plaid', [WebhookController::class, 'plaid']);

    $app->group('', function (RouteCollectorProxy $group): void {
        $group->get('/login', [IdpAuthController::class, 'login'])->setName('auth.login');
        $group->get('/oauth/callback', [IdpAuthController::class, 'callback'])->setName('auth.callback');
        $group->get('/logout', [IdpAuthController::class, 'logout'])->setName('auth.logout');
    })->add(SessionMiddleware::class);

    $app->group('', function (RouteCollectorProxy $group): void {
        $group->get('/admin', [AdminController::class, 'index']);
        $group->get('/admin/kingdoms', [AdminController::class, 'kingdoms']);
        $group->post('/admin/kingdoms/sync', [AdminController::class, 'syncKingdoms']);
        $group->get('/admin/principal-suggestions', [AdminController::class, 'principalSuggestions']);
        $group->post('/admin/grant', [AdminController::class, 'grant']);
        $group->get('/manage/{slug}', [ManagerController::class, 'show']);
        $group->post('/manage/{slug}/settings', [ManagerController::class, 'settings']);
        $group->get('/manage/{slug}/connect', [ManagerController::class, 'connectGet']);
        $group->post('/manage/{slug}/connect', [ManagerController::class, 'connect']);
        $group->post('/manage/{slug}/enrollment', [ManagerController::class, 'enrollment']);
        $group->post('/manage/{slug}/disconnect', [ManagerController::class, 'disconnectBank']);
        $group->post('/manage/{slug}/accounts', [ManagerController::class, 'accounts']);
        $group->post('/manage/{slug}/refresh', [ManagerController::class, 'refresh']);
        $group->post('/manage/{slug}/transactions/publish', [ManagerController::class, 'publishTransaction']);
        $group->post('/manage/{slug}/transactions/withhold', [ManagerController::class, 'withholdTransaction']);
        $group->post('/manage/{slug}/transactions/update', [ManagerController::class, 'updateTransaction']);
        $group->post('/manage/{slug}/transactions/review', [ManagerController::class, 'updateTransactionReview']);
        $group->get('/manage/{slug}/taxonomy/categories', [ManagerController::class, 'categorySearch']);
        $group->get('/manage/{slug}/patterns', [ManagerController::class, 'patterns']);
        $group->get('/manage/{slug}/patterns/new', [ManagerController::class, 'patternNew']);
        $group->post('/manage/{slug}/patterns/bulk', [ManagerController::class, 'patternBulk']);
        $group->post('/manage/{slug}/patterns/preview', [ManagerController::class, 'patternPreview']);
        $group->post('/manage/{slug}/pattern-revisions/apply', [ManagerController::class, 'applyPatternAutomaticCategories']);
        $group->post('/manage/{slug}/patterns', [ManagerController::class, 'patternCreate']);
        $group->post('/manage/{slug}/patterns/{ruleId}', [ManagerController::class, 'patternUpdate']);
        $group->post('/manage/{slug}/patterns/{ruleId}/delete', [ManagerController::class, 'patternDelete']);
        $group->get('/bank/simplefin/return', [SimpleFinReturnController::class, 'show']);
        $group->post('/bank/simplefin/return', [SimpleFinReturnController::class, 'submit']);
    })->add(SessionMiddleware::class)->add(SyncPrincipalMiddleware::class);

    $app->get('/{slug}', [KingdomPageController::class, 'show'])
        ->add(SessionMiddleware::class)
        ->add(SyncPrincipalMiddleware::class);
};
