<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function (): void {
    Route::redirect('settings', 'settings/appearance');

    Route::livewire('settings/appearance', 'pages::settings.appearance')->name('appearance.edit');
});
