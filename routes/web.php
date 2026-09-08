<?php

use App\Http\Controllers\BrandBrainController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InvoiceController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::resource('clients', ClientController::class);

    Route::get('clients/{client}/brand-brain', [BrandBrainController::class, 'edit'])
        ->name('clients.brand-brain.edit');
    Route::put('clients/{client}/brand-brain', [BrandBrainController::class, 'update'])
        ->name('clients.brand-brain.update');

    Route::post('clients/{client}/invoices', [InvoiceController::class, 'store'])
        ->name('clients.invoices.store');
    Route::post('invoices/{invoice}/send', [InvoiceController::class, 'send'])
        ->name('invoices.send');
    Route::post('invoices/{invoice}/mark-paid', [InvoiceController::class, 'markPaid'])
        ->name('invoices.mark-paid');
});

require __DIR__.'/settings.php';
