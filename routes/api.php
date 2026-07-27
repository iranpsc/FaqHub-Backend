<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\QuestionController;
use App\Http\Controllers\Api\TagController;
use App\Http\Controllers\Api\AnswerController;
use App\Http\Controllers\Api\CommentController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\AuthorController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;


    // Auth routes with stricter rate limiting
    Route::prefix('auth')->group(function () {
        Route::middleware(['guest'])->group(function () {
            Route::post('/redirect', [AuthController::class, 'redirect'])->name('auth.redirect');
        });

        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/me', [AuthController::class, 'me']);
            Route::post('/logout', [AuthController::class, 'logout']);
        });
    });

    // Question recommendation and popular routes (must be before resource routes)
    Route::prefix('questions')->group(function () {
        Route::get('/recommended', [DashboardController::class, 'recommendedQuestions']);
        Route::get('/popular', [DashboardController::class, 'popularQuestions']);
        Route::get('/search', [QuestionController::class, 'search']);
    });

    // Category popular route (must be before resource routes)
    Route::get('categories/popular', [CategoryController::class, 'popular']);

    // Voting routes
    Route::post('questions/{question}/vote', [QuestionController::class, 'vote']);
    Route::post('answers/{answer}/vote', [AnswerController::class, 'vote']);
    Route::post('comments/{comment}/vote', [CommentController::class, 'vote']);

    Route::post('questions/{question}/publish', [QuestionController::class, 'publish']);
    Route::post('questions/{question}/pin', [QuestionController::class, 'pin']);
    Route::delete('questions/{question}/pin', [QuestionController::class, 'unpin']);
    Route::post('questions/{question}/feature', [QuestionController::class, 'feature']);
    Route::delete('questions/{question}/feature', [QuestionController::class, 'unfeature']);

    // Question CRUD with create rate limiting
    Route::get('questions', [QuestionController::class, 'index']);
    Route::post('questions', [QuestionController::class, 'store']);
    Route::get('questions/{question:slug}', [QuestionController::class, 'show']);
    Route::put('questions/{question}', [QuestionController::class, 'update']);
    Route::delete('questions/{question}', [QuestionController::class, 'destroy']);

    Route::get('tags/{tag:slug}/questions', [TagController::class, 'questions']);
    Route::apiResource('tags', TagController::class)->scoped(['tag' => 'slug']);

    // Answers with create rate limiting
    Route::get('questions/{question:id}/answers', [AnswerController::class, 'index']);
    Route::post('questions/{question:id}/answers', [AnswerController::class, 'store']);
    Route::put('answers/{answer}', [AnswerController::class, 'update']);
    Route::delete('answers/{answer}', [AnswerController::class, 'destroy']);

    Route::post('answers/{answer}/publish', [AnswerController::class, 'publish']);
    Route::post('answers/{answer}/toggle-correctness', [AnswerController::class, 'toggleCorrectness']);

    // Comments with create rate limiting
    Route::get('questions/{question}/comments', [CommentController::class, 'index'])->name('questions.comments.index');
    Route::get('answers/{answer}/comments', [CommentController::class, 'index'])->name('answers.comments.index');
    Route::post('questions/{question}/comments', [CommentController::class, 'store'])->name('questions.comments.store');
    Route::post('answers/{answer}/comments', [CommentController::class, 'store'])->name('answers.comments.store');
    Route::put('comments/{comment}', [CommentController::class, 'update']);
    Route::delete('comments/{comment}', [CommentController::class, 'destroy']);
    Route::post('comments/{comment}/publish', [CommentController::class, 'publish']);

    Route::get('categories/{category:slug}/questions', [CategoryController::class, 'questions']);
    Route::apiResource('categories', CategoryController::class)->scoped(['category' => 'slug']);

    // Authors routes
    Route::apiResource('authors', AuthorController::class)
        ->only(['index', 'show'])
        ->scoped(['author' => 'username']);
    Route::get('authors/{user:username}/questions', [AuthorController::class, 'questions']);

    // User profile routes
    Route::middleware('auth:sanctum')->prefix('user')->group(function () {
        Route::get('/profile', [UserController::class, 'profile']);
        Route::get('/stats', [UserController::class, 'stats']);
        Route::get('/activity', [UserController::class, 'activity']);
        Route::post('/update-image', [UserController::class, 'updateImage']);
        Route::post('/settings', [UserController::class, 'updateSettings']);
    });

    // Dashboard routes
    Route::prefix('dashboard')->group(function () {
        Route::get('/stats', [DashboardController::class, 'stats']);
        Route::get('/active-users', [DashboardController::class, 'activeUsers']);
        Route::get('/activity', [DashboardController::class, 'activity']);
    });
