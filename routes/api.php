<?php

use App\Http\Controllers\Api\OmsCatalogController;
use Illuminate\Support\Facades\Route;

Route::prefix('oms/v1')
    ->middleware(['oms.catalog', 'throttle:600,1'])
    ->group(function () {
        Route::get('/ping', [OmsCatalogController::class, 'ping']);
        Route::get('/taxonomy', [OmsCatalogController::class, 'taxonomy']);
        Route::get('/products', [OmsCatalogController::class, 'products']);
        Route::get('/products/{sku}', [OmsCatalogController::class, 'show'])->where('sku', '.*');
        Route::put('/products/{sku}', [OmsCatalogController::class, 'upsert'])->where('sku', '.*');
        Route::patch('/products/{sku}/stock', [OmsCatalogController::class, 'stock'])->where('sku', '.*');
        Route::post('/products/{sku}/unpublish', [OmsCatalogController::class, 'unpublish'])->where('sku', '.*');
    });
