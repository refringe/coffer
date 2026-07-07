<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function (): void {
        Route::redirect('/', '/admin/users');

        Route::livewire('users', 'pages::admin.users')->name('users.index');

        Route::livewire('shares', 'pages::admin.shares')->name('shares.index');

        Route::livewire('activity', 'pages::admin.activity')->name('activity.index');
    });
