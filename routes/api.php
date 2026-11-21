<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectMemberController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::middleware('throttle:auth-register')->post('/auth/register', [AuthController::class, 'register']);

    Route::middleware('throttle:auth-login')->post('/auth/login', [AuthController::class, 'login']);

    Route::middleware('throttle:auth-password')->group(function (): void {
        Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);
    });

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        Route::get('/me', [ProfileController::class, 'show']);
        Route::put('/me', [ProfileController::class, 'update']);
        Route::post('/me/avatar', [ProfileController::class, 'avatar']);
        Route::put('/me/password', [ProfileController::class, 'password']);

        Route::get('/users', UserController::class);

        Route::get('/teams', [TeamController::class, 'index']);

        Route::get('/projects', [ProjectController::class, 'index']);
        Route::post('/projects', [ProjectController::class, 'store']);
        Route::get('/projects/{project}', [ProjectController::class, 'show'])->whereNumber('project');
        Route::put('/projects/{project}', [ProjectController::class, 'update'])->whereNumber('project');
        Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])->whereNumber('project');

        Route::get('/projects/{project}/members', [ProjectMemberController::class, 'index'])->whereNumber('project');
        Route::post('/projects/{project}/members', [ProjectMemberController::class, 'store'])->whereNumber('project');
        Route::put('/projects/{project}/members/{user}', [ProjectMemberController::class, 'update'])->whereNumber('project', 'user');
        Route::delete('/projects/{project}/members/{user}', [ProjectMemberController::class, 'destroy'])->whereNumber('project', 'user');
    });
});
