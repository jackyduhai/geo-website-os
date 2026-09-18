<?php

use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\GeoflowController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| GEOFlow 对接 API（bootstrap/app.php 已统一加 api 中间件、api/v1 前缀）
|--------------------------------------------------------------------------
| 设计原则：官网不依赖 GEOFlow。开关关闭时接口可达但拒绝写入，
| 所有写入路径与后台手动发布共用同一套 ContentGate 门禁。
*/

Route::get('health', [HealthController::class, 'health'])->name('health');

Route::middleware('geoflow.token')->group(function () {
    Route::post('geoflow/contents', [GeoflowController::class, 'upsert'])->name('geoflow.upsert');
    Route::get('geoflow/contents/{externalId}', [GeoflowController::class, 'status'])->name('geoflow.status');
    Route::post('geoflow/check', [GeoflowController::class, 'check'])->name('geoflow.check');
    Route::post('geoflow/unpublish', [GeoflowController::class, 'unpublish'])->name('geoflow.unpublish');
});
