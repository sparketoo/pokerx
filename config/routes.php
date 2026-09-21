<?php

use App\Controller\AuthController;
use App\Controller\Mine\CreditController;
use App\Controller\Mine\EventsController;
use App\Controller\Mine\GamesController;
use App\Controller\Mine\SecurityController;
use App\Controller\Mine\StatsController;
use App\Controller\MineController;
use App\Game\PokerServer;
use Hyperf\HttpServer\Router\Router;

Router::get('/api/health', fn () => ['code' => 'success', 'message' => 'ok', 'data' => ['status' => 'ok']]);
Router::post('/api/auth/login', [AuthController::class, 'login']);
Router::post('/api/auth/logout', [AuthController::class, 'logout']);
Router::get('/api/mine', [MineController::class, 'index']);
Router::post('/api/mine/update_nickname', [MineController::class, 'updateNickname']);
Router::post('/api/mine/update_language', [MineController::class, 'updateLanguage']);
Router::post('/api/mine/security/change_password', [SecurityController::class, 'changePassword']);
Router::post('/api/mine/security/create_two_factor', [SecurityController::class, 'createTwoFactor']);
Router::post('/api/mine/security/confirm_two_factor', [SecurityController::class, 'confirmTwoFactor']);
Router::post('/api/mine/security/cancel_two_factor', [SecurityController::class, 'cancelTwoFactor']);
Router::get('/api/mine/stats/summary', [StatsController::class, 'summary']);
Router::get('/api/mine/stats/trend', [StatsController::class, 'trend']);
Router::get('/api/mine/games', [GamesController::class, 'index']);
Router::get('/api/mine/games/detail', [GamesController::class, 'detail']);
Router::get('/api/mine/games/events', [GamesController::class, 'events']);
Router::get('/api/mine/events', [EventsController::class, 'index']);
Router::get('/api/mine/credit', [CreditController::class, 'index']);
Router::get('/api/mine/credit/record', [CreditController::class, 'record']);
Router::addServer('poker', function () {
    Router::get('/', PokerServer::class);
});
