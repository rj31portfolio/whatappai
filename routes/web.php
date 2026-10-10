<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CrmController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'));
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
});
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::post('/business/switch', [DashboardController::class, 'switch'])->name('tenant.switch');
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/admin', [DashboardController::class, 'admin'])->middleware('can:super-admin')->name('admin');
    Route::get('/contacts/export', [CrmController::class, 'export'])->name('contacts.export');
    Route::resource('contacts', CrmController::class)->except('show');
    Route::get('/companies', [CrmController::class, 'companies'])->name('companies.index');
    Route::post('/companies', [CrmController::class, 'storeCompany'])->name('companies.store');
    Route::get('/pipeline', [CrmController::class, 'pipeline'])->name('pipeline');
    Route::post('/deals', [CrmController::class, 'storeDeal'])->name('deals.store');
    Route::patch('/deals/{deal}', [CrmController::class, 'updateDeal'])->name('deals.update');
    Route::get('/tasks', [CrmController::class, 'tasks'])->name('tasks.index');
    Route::post('/tasks', [CrmController::class, 'storeTask'])->name('tasks.store');
    Route::patch('/tasks/{task}', [CrmController::class, 'completeTask'])->name('tasks.complete');
    Route::get('/integrations', [DashboardController::class, 'integrations'])->name('integrations');
    Route::get('/activity', [DashboardController::class, 'activity'])->name('activity');
});
