<?php

declare(strict_types=1);

use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\Loyalty\Presentation\Controllers\EarningRuleController;
use App\Modules\Loyalty\Presentation\Controllers\LoyaltyMemberController;
use App\Modules\Loyalty\Presentation\Controllers\LoyaltyPOSController;
use App\Modules\Loyalty\Presentation\Controllers\LoyaltyProgramController;
use App\Modules\Loyalty\Presentation\Controllers\RewardController;
use App\Modules\Loyalty\Presentation\Controllers\StampCardController;
use App\Modules\Loyalty\Presentation\Controllers\TierController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Loyalty Module API Routes
|--------------------------------------------------------------------------
|
| Loyalty program management and POS operations.
|
*/

Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class])->group(function () {
    // Loyalty Programs (Admin)
    Route::prefix('loyalty/programs')->group(function () {
        Route::get('/', [LoyaltyProgramController::class, 'index'])
            ->middleware('can:loyalty.manage')
            ->name('loyalty.programs.index');

        Route::get('/active', [LoyaltyProgramController::class, 'active'])
            ->middleware('can:loyalty.view')
            ->name('loyalty.programs.active');

        Route::get('/{id}', [LoyaltyProgramController::class, 'show'])
            ->middleware('can:loyalty.view')
            ->name('loyalty.programs.show');

        Route::post('/', [LoyaltyProgramController::class, 'store'])
            ->middleware('can:loyalty.manage')
            ->name('loyalty.programs.store');

        Route::patch('/{id}', [LoyaltyProgramController::class, 'update'])
            ->middleware('can:loyalty.manage')
            ->name('loyalty.programs.update');

        Route::delete('/{id}', [LoyaltyProgramController::class, 'destroy'])
            ->middleware('can:loyalty.manage')
            ->name('loyalty.programs.destroy');

        Route::post('/{id}/activate', [LoyaltyProgramController::class, 'activate'])
            ->middleware('can:loyalty.manage')
            ->name('loyalty.programs.activate');

        Route::post('/{id}/deactivate', [LoyaltyProgramController::class, 'deactivate'])
            ->middleware('can:loyalty.manage')
            ->name('loyalty.programs.deactivate');

        // Earning Rules (nested under programs)
        Route::prefix('{programId}/earning-rules')->group(function () {
            Route::get('/', [EarningRuleController::class, 'index'])
                ->middleware('can:loyalty.view')
                ->name('loyalty.programs.earning-rules.index');

            Route::post('/', [EarningRuleController::class, 'store'])
                ->middleware('can:loyalty.manage')
                ->name('loyalty.programs.earning-rules.store');
        });

        // Rewards (nested under programs)
        Route::prefix('{programId}/rewards')->group(function () {
            Route::get('/', [RewardController::class, 'index'])
                ->middleware('can:loyalty.view')
                ->name('loyalty.programs.rewards.index');

            Route::post('/', [RewardController::class, 'store'])
                ->middleware('can:loyalty.manage')
                ->name('loyalty.programs.rewards.store');
        });

        // Tiers (nested under programs)
        Route::prefix('{programId}/tiers')->group(function () {
            Route::get('/', [TierController::class, 'index'])
                ->middleware('can:loyalty.view')
                ->name('loyalty.programs.tiers.index');

            Route::post('/', [TierController::class, 'store'])
                ->middleware('can:loyalty.manage')
                ->name('loyalty.programs.tiers.store');
        });

        // Stamp Cards (nested under programs)
        Route::prefix('{programId}/stamp-cards')->group(function () {
            Route::get('/', [StampCardController::class, 'index'])
                ->middleware('can:loyalty.view')
                ->name('loyalty.programs.stamp-cards.index');

            Route::post('/', [StampCardController::class, 'store'])
                ->middleware('can:loyalty.manage')
                ->name('loyalty.programs.stamp-cards.store');
        });
    });

    // Earning Rules (individual operations)
    Route::prefix('loyalty/earning-rules')->group(function () {
        Route::get('/{id}', [EarningRuleController::class, 'show'])
            ->middleware('can:loyalty.view')
            ->name('loyalty.earning-rules.show');

        Route::patch('/{id}', [EarningRuleController::class, 'update'])
            ->middleware('can:loyalty.manage')
            ->name('loyalty.earning-rules.update');

        Route::delete('/{id}', [EarningRuleController::class, 'destroy'])
            ->middleware('can:loyalty.manage')
            ->name('loyalty.earning-rules.destroy');

        Route::post('/{id}/activate', [EarningRuleController::class, 'activate'])
            ->middleware('can:loyalty.manage')
            ->name('loyalty.earning-rules.activate');

        Route::post('/{id}/deactivate', [EarningRuleController::class, 'deactivate'])
            ->middleware('can:loyalty.manage')
            ->name('loyalty.earning-rules.deactivate');
    });

    // Rewards (individual operations)
    Route::prefix('loyalty/rewards')->group(function () {
        Route::get('/{id}', [RewardController::class, 'show'])
            ->middleware('can:loyalty.view')
            ->name('loyalty.rewards.show');

        Route::patch('/{id}', [RewardController::class, 'update'])
            ->middleware('can:loyalty.manage')
            ->name('loyalty.rewards.update');

        Route::delete('/{id}', [RewardController::class, 'destroy'])
            ->middleware('can:loyalty.manage')
            ->name('loyalty.rewards.destroy');

        Route::post('/{id}/activate', [RewardController::class, 'activate'])
            ->middleware('can:loyalty.manage')
            ->name('loyalty.rewards.activate');

        Route::post('/{id}/deactivate', [RewardController::class, 'deactivate'])
            ->middleware('can:loyalty.manage')
            ->name('loyalty.rewards.deactivate');
    });

    // Tiers (individual operations)
    Route::prefix('loyalty/tiers')->group(function () {
        Route::get('/{id}', [TierController::class, 'show'])
            ->middleware('can:loyalty.view')
            ->name('loyalty.tiers.show');

        Route::patch('/{id}', [TierController::class, 'update'])
            ->middleware('can:loyalty.manage')
            ->name('loyalty.tiers.update');

        Route::delete('/{id}', [TierController::class, 'destroy'])
            ->middleware('can:loyalty.manage')
            ->name('loyalty.tiers.destroy');
    });

    // Stamp Cards (individual operations)
    Route::prefix('loyalty/stamp-cards')->group(function () {
        Route::get('/{id}', [StampCardController::class, 'show'])
            ->middleware('can:loyalty.view')
            ->name('loyalty.stamp-cards.show');

        Route::patch('/{id}', [StampCardController::class, 'update'])
            ->middleware('can:loyalty.manage')
            ->name('loyalty.stamp-cards.update');

        Route::delete('/{id}', [StampCardController::class, 'destroy'])
            ->middleware('can:loyalty.manage')
            ->name('loyalty.stamp-cards.destroy');
    });

    // POS Loyalty Operations
    Route::prefix('loyalty/pos')->group(function () {
        Route::post('/member-lookup', [LoyaltyPOSController::class, 'memberLookup'])
            ->middleware('can:pos.operate_terminal')
            ->name('loyalty.pos.member-lookup');

        Route::post('/preview-earning', [LoyaltyPOSController::class, 'previewEarning'])
            ->middleware('can:pos.operate_terminal')
            ->name('loyalty.pos.preview-earning');

        Route::get('/rewards/{enrollmentId}', [LoyaltyPOSController::class, 'rewards'])
            ->middleware('can:pos.operate_terminal')
            ->name('loyalty.pos.rewards');

        Route::post('/redeem', [LoyaltyPOSController::class, 'redeem'])
            ->middleware('can:pos.operate_terminal')
            ->name('loyalty.pos.redeem');

        Route::post('/earn', [LoyaltyPOSController::class, 'earn'])
            ->middleware('can:pos.operate_terminal')
            ->name('loyalty.pos.earn');
    });

    // Loyalty Members (Admin)
    Route::prefix('loyalty/members')->group(function () {
        Route::get('/', [LoyaltyMemberController::class, 'index'])
            ->middleware('can:loyalty.view')
            ->name('loyalty.members.index');

        Route::post('/lookup', [LoyaltyMemberController::class, 'lookupByPhone'])
            ->middleware('can:loyalty.view')
            ->name('loyalty.members.lookup');

        Route::get('/{id}', [LoyaltyMemberController::class, 'show'])
            ->middleware('can:loyalty.view')
            ->name('loyalty.members.show');

        Route::post('/', [LoyaltyMemberController::class, 'store'])
            ->middleware('can:loyalty.manage')
            ->name('loyalty.members.store');

        Route::patch('/{id}', [LoyaltyMemberController::class, 'update'])
            ->middleware('can:loyalty.manage')
            ->name('loyalty.members.update');

        Route::get('/{id}/enrollments', [LoyaltyMemberController::class, 'enrollments'])
            ->middleware('can:loyalty.view')
            ->name('loyalty.members.enrollments');

        Route::post('/{id}/enroll', [LoyaltyMemberController::class, 'enroll'])
            ->middleware('can:loyalty.manage')
            ->name('loyalty.members.enroll');

        Route::post('/{memberId}/enrollments/{enrollmentId}/opt-out', [LoyaltyMemberController::class, 'optOut'])
            ->middleware('can:loyalty.manage')
            ->name('loyalty.members.enrollments.opt-out');

        Route::post('/{memberId}/enrollments/{enrollmentId}/reactivate', [LoyaltyMemberController::class, 'reactivate'])
            ->middleware('can:loyalty.manage')
            ->name('loyalty.members.enrollments.reactivate');

        Route::get('/{memberId}/enrollments/{enrollmentId}/transactions', [LoyaltyMemberController::class, 'transactions'])
            ->middleware('can:loyalty.view')
            ->name('loyalty.members.enrollments.transactions');

        Route::post('/{memberId}/enrollments/{enrollmentId}/adjust', [LoyaltyMemberController::class, 'adjust'])
            ->middleware('can:loyalty.manage')
            ->name('loyalty.members.enrollments.adjust');
    });
});
