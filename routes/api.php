<?php

use App\Http\Controllers\AiController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\MeetingController;
use App\Http\Controllers\MeetingSummaryController;
use App\Http\Controllers\MeetingTranscriptController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectFileController;
use App\Http\Controllers\ProjectMemberController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\TaskCommentController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\ThrottleChatAttachments;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::middleware('throttle:auth-register')->post('/auth/register', [AuthController::class, 'register']);

    Route::middleware('throttle:auth-login')->post('/auth/login', [AuthController::class, 'login']);
    Route::middleware('throttle:auth-login')->post('/auth/google', GoogleAuthController::class);

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
        Route::get('/me/stats', [ProfileController::class, 'stats']);

        Route::get('/users', UserController::class);

        Route::get('/teams', [TeamController::class, 'index']);

        Route::get('/projects', [ProjectController::class, 'index']);
        Route::post('/projects', [ProjectController::class, 'store']);
        Route::get('/projects/{project}', [ProjectController::class, 'show'])->whereNumber('project');
        Route::put('/projects/{project}', [ProjectController::class, 'update'])->whereNumber('project');
        Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])->whereNumber('project');
        Route::get('/projects/{project}/teams', [ProjectController::class, 'teams'])->whereNumber('project');

        Route::get('/projects/{project}/members', [ProjectMemberController::class, 'index'])->whereNumber('project');
        Route::post('/projects/{project}/members', [ProjectMemberController::class, 'store'])->whereNumber('project');
        Route::put('/projects/{project}/members/{user}', [ProjectMemberController::class, 'update'])->whereNumber('project', 'user');
        Route::delete('/projects/{project}/members/{user}', [ProjectMemberController::class, 'destroy'])->whereNumber('project', 'user');

        // Tasks (shallow nesting: create under the project, then by task id).
        Route::get('/projects/{project}/tasks', [TaskController::class, 'index'])->whereNumber('project');
        Route::post('/projects/{project}/tasks', [TaskController::class, 'store'])->whereNumber('project');
        Route::get('/tasks', [TaskController::class, 'indexAll']);
        Route::get('/tasks/{task}', [TaskController::class, 'show'])->whereNumber('task');
        Route::put('/tasks/{task}', [TaskController::class, 'update'])->whereNumber('task');
        Route::delete('/tasks/{task}', [TaskController::class, 'destroy'])->whereNumber('task');
        Route::patch('/tasks/{task}/status', [TaskController::class, 'changeStatus'])->whereNumber('task');

        // Task comments.
        Route::get('/tasks/{task}/comments', [TaskCommentController::class, 'index'])->whereNumber('task');
        Route::post('/tasks/{task}/comments', [TaskCommentController::class, 'store'])->whereNumber('task');
        Route::put('/comments/{comment}', [TaskCommentController::class, 'update'])->whereNumber('comment');
        Route::delete('/comments/{comment}', [TaskCommentController::class, 'destroy'])->whereNumber('comment');

        // Files (R7).
        Route::get('/projects/{project}/files', [ProjectFileController::class, 'index'])->whereNumber('project');
        Route::post('/projects/{project}/files', [ProjectFileController::class, 'store'])
            ->middleware('throttle:uploads')
            ->whereNumber('project');
        Route::get('/files', [ProjectFileController::class, 'indexAll']);
        Route::get('/files/{file}/download', [ProjectFileController::class, 'download'])
            ->name('files.download')
            ->whereNumber('file');
        Route::delete('/files/{file}', [ProjectFileController::class, 'destroy'])->whereNumber('file');

        Route::get('/conversations', [ConversationController::class, 'index']);
        Route::post('/conversations/direct', [ConversationController::class, 'storeDirect']);
        Route::get('/conversations/{conversation}/messages', [MessageController::class, 'index'])->whereNumber('conversation');
        Route::post('/conversations/{conversation}/messages', [MessageController::class, 'store'])
            ->middleware(ThrottleChatAttachments::class)->whereNumber('conversation');
        Route::get('/messages/{message}/attachment', [MessageController::class, 'attachment'])
            ->name('messages.attachment')->whereNumber('message');

        Route::get('/meetings', [MeetingController::class, 'index']);
        Route::post('/meetings', [MeetingController::class, 'store']);
        Route::get('/meetings/{meeting}', [MeetingController::class, 'show'])->whereNumber('meeting');
        Route::put('/meetings/{meeting}', [MeetingController::class, 'update'])->whereNumber('meeting');
        Route::delete('/meetings/{meeting}', [MeetingController::class, 'destroy'])->whereNumber('meeting');
        Route::get('/meetings/{meeting}/transcript', [MeetingTranscriptController::class, 'index'])->whereNumber('meeting');
        Route::post('/meetings/{meeting}/transcript', [MeetingTranscriptController::class, 'store'])->whereNumber('meeting');

        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/read-all', [NotificationController::class, 'readAll']);
        Route::post('/notifications/{id}/read', [NotificationController::class, 'read'])->whereUuid('id');

        Route::get('/dashboard', DashboardController::class);
        Route::get('/reports/overview', [ReportController::class, 'overview']);
        Route::get('/reports', [ReportController::class, 'index']);
        Route::post('/reports', [ReportController::class, 'store']);
        Route::get('/reports/{report}', [ReportController::class, 'show'])->whereNumber('report');
        Route::get('/search', SearchController::class);

        Route::middleware('throttle:ai')->group(function (): void {
            Route::get('/ai/messages', [AiController::class, 'index']);
            Route::post('/ai/messages', [AiController::class, 'store']);
            Route::delete('/ai/messages', [AiController::class, 'destroy']);
            Route::post('/meetings/{meeting}/summary/generate', MeetingSummaryController::class)->whereNumber('meeting');
        });
    });
});
