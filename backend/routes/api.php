<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CaptionChangeController;
use App\Http\Controllers\Api\CaptionController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\PerformanceController;
use App\Http\Controllers\Api\ReservationController;
use Illuminate\Support\Facades\Route;

// 登录
Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth.token')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);

    // 演出与字幕版本（所有登录角色可查）
    Route::get('/performances', [PerformanceController::class, 'index']);
    Route::get('/performances/{performance}', [PerformanceController::class, 'show']);
    Route::get('/performances/{performance}/captions/{language}/history', [CaptionController::class, 'history']);
    Route::get('/captions/scripts/{script}', [CaptionController::class, 'show']);

    // 观众：预约 / 改签 / 取消
    Route::middleware('role:audience,front_desk,manager')->group(function () {
        Route::get('/reservations', [ReservationController::class, 'index']);
        Route::get('/reservations/{reservation}', [ReservationController::class, 'show']);
        Route::post('/reservations', [ReservationController::class, 'store']);
        Route::post('/reservations/{reservation}/rebook', [ReservationController::class, 'rebook']);
        Route::post('/reservations/{reservation}/cancel', [ReservationController::class, 'cancel']);
    });

    // 设备占用看板：前台/技术员/负责人均可只读查看
    Route::middleware('role:front_desk,technician,manager')->group(function () {
        Route::get('/desk/devices', [DeviceController::class, 'index']);
    });

    // 前台：发放 / 回收
    Route::middleware('role:front_desk,manager')->prefix('desk')->group(function () {
        Route::get('/pickups', [DeviceController::class, 'pickups']);
        Route::get('/performances/{performance}/assignments', [DeviceController::class, 'performanceAssignments']);
        Route::post('/assignments', [DeviceController::class, 'issue']);
        Route::post('/assignments/{assignment}/return', [DeviceController::class, 'returnDevice']);
        Route::post('/assignments/{assignment}/missing', [DeviceController::class, 'markMissing']);
    });

    // 技术员：字幕发布与演出中换稿申请；设备维修复检放行
    Route::middleware('role:technician,manager')->group(function () {
        Route::post('/performances/{performance}/captions', [CaptionController::class, 'publish']);
        Route::post('/performances/{performance}/caption-change-requests', [CaptionChangeController::class, 'store']);
        Route::post('/desk/devices/{device}/release', [DeviceController::class, 'release']);
    });

    // 负责人：审批
    Route::middleware('role:manager')->group(function () {
        Route::get('/caption-change-requests', [CaptionChangeController::class, 'index']);
        Route::post('/caption-change-requests/{changeRequest}/review', [CaptionChangeController::class, 'review']);
    });
});
