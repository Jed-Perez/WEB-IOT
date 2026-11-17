<?php
declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\LandingController;
use App\Core\Router;

$router = new Router();
$router->get('/', [LandingController::class, 'index']);
$router->get('/login', [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login']);
$router->get('/forgot-password', [AuthController::class, 'showForgotPassword']);
$router->post('/forgot-password', [AuthController::class, 'requestPasswordReset']);
$router->post('/verify-reset-otp', [AuthController::class, 'verifyPasswordResetOtp']);
$router->get('/reset-password', [AuthController::class, 'showPasswordReset']);
$router->post('/reset-password', [AuthController::class, 'resetPassword']);
$router->get('/register', [AuthController::class, 'showRegistration']);
$router->post('/register', [AuthController::class, 'register']);
$router->get('/dashboard', [DashboardController::class, 'index']);
$router->post('/dashboard/thresholds', [DashboardController::class, 'updateThresholds']);
$router->post('/logout', [AuthController::class, 'logout']);

$router->get('/api/dashboard', [App\Controllers\GreenhouseController::class, 'snapshot']);
$router->get('/api/history', [App\Controllers\GreenhouseController::class, 'history']);
$router->get('/api/history/export', [App\Controllers\GreenhouseController::class, 'export']);
$router->get('/api/reports', [App\Controllers\GreenhouseController::class, 'report']);
$router->post('/api/alerts/update', [App\Controllers\GreenhouseController::class, 'alert']);
$router->post('/api/actuators/command', [App\Controllers\GreenhouseController::class, 'command']);
$router->post('/api/devices/key', [App\Controllers\GreenhouseController::class, 'rotateKey']);
$router->post('/api/device/telemetry', [App\Controllers\GreenhouseController::class, 'ingest']);
$router->get('/api/device/commands', [App\Controllers\GreenhouseController::class, 'pending']);
$router->post('/api/device/commands/ack', [App\Controllers\GreenhouseController::class, 'acknowledge']);
$router->post('/api/device/state', [App\Controllers\GreenhouseController::class, 'reportState']);
$router->get('/api/users', [App\Controllers\UserController::class, 'index']);
$router->post('/api/users/save', [App\Controllers\UserController::class, 'save']);
$router->post('/api/users/delete', [App\Controllers\UserController::class, 'delete']);
$router->post('/api/security/confirm', [App\Controllers\SecurityController::class, 'confirm']);
$router->post('/api/security/logout-all', [App\Controllers\SecurityController::class, 'logoutAll']);
$router->get('/api/activity', [App\Controllers\SecurityController::class, 'activity']);
$router->get('/api/operations', [App\Controllers\SecurityController::class, 'operations']);
