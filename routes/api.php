<?php

use App\Http\Controllers\Api\V1\GroupAdminController;
use App\Http\Controllers\Api\V1\GroupAdminDrawController;
use App\Http\Controllers\Api\V1\GroupAdminExclusionController;
use App\Http\Controllers\Api\V1\GroupAdminFixedAssignmentController;
use App\Http\Controllers\Api\V1\GroupController;
use App\Http\Controllers\Api\V1\MeAssignmentController;
use App\Http\Controllers\Api\V1\MeAssignmentWishlistController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\ParticipantClaimController;
use App\Http\Controllers\Api\V1\ParticipantSessionController;
use App\Http\Controllers\Api\V1\ProductSearchController;
use App\Http\Controllers\Api\V1\WishlistItemController;
use App\Http\Middleware\AuthenticateParticipant;
use App\Http\Middleware\EnsureGroupAdmin;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('v1.')->group(function (): void {
    Route::post('groups', [GroupController::class, 'store'])
        ->middleware('throttle:groups')
        ->name('groups.store');

    Route::get('groups/{group}', [GroupController::class, 'show'])->name('groups.show');

    Route::middleware(EnsureGroupAdmin::class)->group(function (): void {
        Route::get('groups/{group}/admin', [GroupAdminController::class, 'show'])->name('groups.admin.show');
        Route::put('groups/{group}/admin', [GroupAdminController::class, 'update'])->name('groups.admin.update');
        Route::put('groups/{group}/admin/exclusions', [GroupAdminExclusionController::class, 'update'])->name('groups.admin.exclusions.update');
        Route::put('groups/{group}/admin/fixed-assignments', [GroupAdminFixedAssignmentController::class, 'update'])->name('groups.admin.fixed-assignments.update');
        Route::post('groups/{group}/admin/draw', [GroupAdminDrawController::class, 'store'])->name('groups.admin.draw.store');
    });

    Route::middleware('throttle:pin')->scopeBindings()->group(function (): void {
        Route::post('groups/{group}/participants/{participant}/claim', [ParticipantClaimController::class, 'store'])
            ->name('groups.participants.claim');
        Route::post('groups/{group}/participants/{participant}/session', [ParticipantSessionController::class, 'store'])
            ->name('groups.participants.session');
    });

    Route::middleware(AuthenticateParticipant::class)->group(function (): void {
        Route::get('groups/{group}/me', [MeController::class, 'show'])->name('me.show');
        Route::get('groups/{group}/me/assignment', [MeAssignmentController::class, 'show'])->name('me.assignment.show');
        Route::get('groups/{group}/me/assignment/wishlist', [MeAssignmentWishlistController::class, 'index'])->name('me.assignment.wishlist.index');

        Route::get('groups/{group}/me/products', [ProductSearchController::class, 'index'])
            ->middleware('throttle:product-search')
            ->name('me.products.index');

        Route::get('groups/{group}/me/wishlist', [WishlistItemController::class, 'index'])->name('me.wishlist.index');
        Route::post('groups/{group}/me/wishlist', [WishlistItemController::class, 'store'])->name('me.wishlist.store');
        Route::put('groups/{group}/me/wishlist/{item}', [WishlistItemController::class, 'update'])->name('me.wishlist.update');
        Route::delete('groups/{group}/me/wishlist/{item}', [WishlistItemController::class, 'destroy'])->name('me.wishlist.destroy');
    });
});
