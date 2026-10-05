<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\ApprovalController;
use App\Http\Controllers\Api\FrontDeskController;
use App\Http\Controllers\Api\ReservationController;
use App\Http\Controllers\Api\ScreeningController;
use App\Http\Controllers\Api\SubtitleController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

// —— 观众端：选场次、看余量、预约、改签、取消（无需登录）——
Route::get('/screenings', [ScreeningController::class, 'index']);
Route::get('/screenings/{id}', [ScreeningController::class, 'show']);
Route::post('/reservations', [ReservationController::class, 'store']);
Route::get('/reservations/{code}', [ReservationController::class, 'show']);
Route::post('/reservations/{code}/reschedule', [ReservationController::class, 'reschedule']);
Route::post('/reservations/{code}/cancel', [ReservationController::class, 'cancel']);

// 字幕版本/历史对所有人只读（观众端可查看当前字幕稿）
Route::get('/screenings/{screeningId}/subtitles/{language}', [SubtitleController::class, 'versions']);
Route::get('/subtitle-versions/{versionId}/content', [SubtitleController::class, 'content']);

// —— 前台：发放、回收、复检 ——
Route::middleware('staff:'.User::ROLE_FRONTDESK)->group(function () {
    Route::get('/frontdesk/reservations/{code}', [FrontDeskController::class, 'lookup']);
    Route::post('/frontdesk/reservations/{code}/issue', [FrontDeskController::class, 'issue']);
    Route::post('/frontdesk/return', [FrontDeskController::class, 'returnDevice']);
    Route::get('/frontdesk/outstanding', [FrontDeskController::class, 'outstanding']);
});

// —— 技术员：发布字幕、演出中发起换稿 ——
Route::middleware('staff:'.User::ROLE_TECHNICIAN)->group(function () {
    Route::post('/screenings/{screeningId}/subtitles', [SubtitleController::class, 'publish']);
    Route::post('/screenings/{screeningId}/subtitle-switch', [SubtitleController::class, 'requestSwitch']);
});

// 技术员与负责人都可查看换稿申请
Route::middleware('staff:'.User::ROLE_TECHNICIAN.','.User::ROLE_MANAGER)->group(function () {
    Route::get('/subtitle-switch', [SubtitleController::class, 'switchRequests']);
    Route::get('/screenings/{screeningId}/subtitle-switch', [SubtitleController::class, 'switchRequests']);
});

// —— 负责人：审批演出中换稿 ——
Route::middleware('staff:'.User::ROLE_MANAGER)->group(function () {
    Route::post('/subtitle-switch/{requestId}/approve', [ApprovalController::class, 'approve']);
    Route::post('/subtitle-switch/{requestId}/reject', [ApprovalController::class, 'reject']);
});

// —— 后台：员工登录身份、设备占用/检查、通知、维修登记 ——
Route::get('/staff', [AdminController::class, 'staff']);
Route::middleware('staff:'.User::ROLE_FRONTDESK.','.User::ROLE_TECHNICIAN.','.User::ROLE_MANAGER)->group(function () {
    Route::get('/admin/devices', [AdminController::class, 'devices']);
    Route::get('/admin/checks', [AdminController::class, 'checks']);
    Route::get('/admin/outstanding', [AdminController::class, 'outstanding']);
    Route::get('/admin/notifications', [AdminController::class, 'notifications']);
});
Route::middleware('staff:'.User::ROLE_TECHNICIAN.','.User::ROLE_MANAGER)->group(function () {
    Route::post('/admin/devices/{deviceId}/repair', [AdminController::class, 'repairDevice']);
});
