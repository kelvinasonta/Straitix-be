<?php

use Illuminate\Support\Facades\Route;
use Modules\Admin\app\Http\Controllers\AdminAuditLogController;
use Modules\Admin\app\Http\Controllers\AdminAuthController;
use Modules\Admin\app\Http\Controllers\AdminDashboardController;
use Modules\Admin\app\Http\Controllers\AdminEventModerationController;
use Modules\Admin\app\Http\Controllers\AdminFinanceController;
use Modules\Admin\app\Http\Controllers\AdminOrganizerController;
use Modules\Admin\app\Http\Controllers\AdminUserController;
use Modules\Admin\app\Http\Controllers\AdminVerificationController;
use Modules\Admin\app\Http\Controllers\FeatureFlagController;
use Modules\Shared\app\Http\Controllers\AppSettingsController;

Route::prefix('v1/admin')->group(function () {
    // Public administrative authentication (Rate-limited: 5 attempts per minute)
    Route::middleware(['throttle:5,1'])->group(function () {
        Route::post('auth/login', [AdminAuthController::class, 'login']);
    });

    // Protected administrative routes (Requires Sanctum + Active Administrator)
    Route::middleware(['auth:sanctum', 'admin.auth'])->group(function () {
        Route::get('auth/me', [AdminAuthController::class, 'me']);
        Route::post('auth/logout', [AdminAuthController::class, 'logout']);

        // Platform Dashboard KPIs & Analytics
        Route::prefix('dashboard')->group(function () {
            Route::get('stats', [AdminDashboardController::class, 'stats'])
                ->middleware('admin.auth:dashboard.view');
            Route::get('charts', [AdminDashboardController::class, 'charts'])
                ->middleware('admin.auth:dashboard.view');
        });

        // Tenant & Organizer Governance
        Route::prefix('organizers')->group(function () {
            Route::get('/', [AdminOrganizerController::class, 'index'])
                ->middleware('admin.auth:organizers.view');
            Route::get('/{organizerUuid}', [AdminOrganizerController::class, 'show'])
                ->middleware('admin.auth:organizers.view');
            Route::post('/{organizerUuid}/suspend', [AdminOrganizerController::class, 'suspend'])
                ->middleware('admin.auth:organizers.verify');
            Route::post('/{organizerUuid}/unsuspend', [AdminOrganizerController::class, 'unsuspend'])
                ->middleware('admin.auth:organizers.verify');
            Route::post('/{organizerUuid}/plan', [AdminOrganizerController::class, 'updatePlan'])
                ->middleware('admin.auth:organizers.verify');
        });

        // Subscription Plans Pricing & Commission Tiers
        Route::prefix('subscription-plans')->group(function () {
            Route::get('/', [AdminOrganizerController::class, 'plans'])
                ->middleware('admin.auth:system_settings.view');
            Route::put('/{slug}', [AdminOrganizerController::class, 'updateSubscriptionPlan'])
                ->middleware('admin.auth:system_settings.manage');
        });

        // Global User Management & Trust/Safety
        Route::prefix('users')->group(function () {
            Route::get('/', [AdminUserController::class, 'index'])
                ->middleware('admin.auth:users.view');
            Route::get('/{userUuid}', [AdminUserController::class, 'show'])
                ->middleware('admin.auth:users.view');
            Route::post('/{userUuid}/suspend', [AdminUserController::class, 'suspend'])
                ->middleware('admin.auth:users.suspend');
            Route::post('/{userUuid}/unsuspend', [AdminUserController::class, 'unsuspend'])
                ->middleware('admin.auth:users.suspend');
            Route::post('/{userUuid}/revoke-sessions', [AdminUserController::class, 'revokeSessions'])
                ->middleware('admin.auth:users.suspend');
            Route::post('/{userUuid}/verify-email', [AdminUserController::class, 'verifyEmail'])
                ->middleware('admin.auth:users.suspend');
        });

        // Global Event Moderation & Content Safety
        Route::prefix('events')->group(function () {
            Route::get('/', [AdminEventModerationController::class, 'index'])
                ->middleware('admin.auth:events.moderate');
            Route::get('/{eventUuid}', [AdminEventModerationController::class, 'show'])
                ->middleware('admin.auth:events.moderate');
            Route::post('/{eventUuid}/delist', [AdminEventModerationController::class, 'delist'])
                ->middleware('admin.auth:events.moderate');
            Route::post('/{eventUuid}/cancel', [AdminEventModerationController::class, 'cancel'])
                ->middleware('admin.auth:events.moderate');
            Route::post('/{eventUuid}/feature', [AdminEventModerationController::class, 'feature'])
                ->middleware('admin.auth:events.moderate');
            Route::get('/{eventUuid}/attendees', [AdminEventModerationController::class, 'attendees'])
                ->middleware('admin.auth:events.moderate');
        });

        // Fintech, Orders & Payout Governance
        Route::prefix('finance')->group(function () {
            Route::get('orders', [AdminFinanceController::class, 'orders'])
                ->middleware('admin.auth:orders.view');
            Route::get('orders/{orderUuid}', [AdminFinanceController::class, 'showOrder'])
                ->middleware('admin.auth:orders.view');
            Route::get('payouts', [AdminFinanceController::class, 'payouts'])
                ->middleware('admin.auth:payouts.view');
            Route::post('payouts/{payoutUuid}/approve', [AdminFinanceController::class, 'approvePayout'])
                ->middleware('admin.auth:payouts.approve');
            Route::post('payouts/{payoutUuid}/reject', [AdminFinanceController::class, 'rejectPayout'])
                ->middleware('admin.auth:payouts.approve');
        });

        // System feature flags (Super-admin or system_settings.manage)
        Route::prefix('feature-flags')->group(function () {
            Route::get('/', [FeatureFlagController::class, 'index'])
                ->middleware('admin.auth:system_settings.view');
            Route::post('{feature}/toggle', [FeatureFlagController::class, 'toggle'])
                ->middleware('admin.auth:system_settings.manage');
        });

        // Application Settings & Platform Fee configuration
        Route::prefix('settings')->group(function () {
            Route::get('/', [AppSettingsController::class, 'index'])
                ->middleware('admin.auth:system_settings.view');
            Route::post('/', [AppSettingsController::class, 'store'])
                ->middleware('admin.auth:system_settings.manage');
            Route::put('/', [AppSettingsController::class, 'store'])
                ->middleware('admin.auth:system_settings.manage');
        });

        // KYC & Organizer verifications (Requires organizers.verify permission)
        Route::prefix('verifications')->group(function () {
            Route::get('/', [AdminVerificationController::class, 'index'])
                ->middleware('admin.auth:organizers.view');
            Route::get('/{uuid}', [AdminVerificationController::class, 'show'])
                ->middleware('admin.auth:organizers.view');
            Route::post('/{uuid}/review', [AdminVerificationController::class, 'review'])
                ->middleware('admin.auth:organizers.verify');
        });

        // Audit Trail (Requires audit_logs.view permission)
        Route::prefix('audit-logs')->group(function () {
            Route::get('/', [AdminAuditLogController::class, 'index'])
                ->middleware('admin.auth:audit_logs.view');
            Route::get('/{id}', [AdminAuditLogController::class, 'show'])
                ->middleware('admin.auth:audit_logs.view');
        });
    });
});
