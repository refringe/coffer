<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\GitHubAuthController;
use App\Http\Controllers\Shares\DownloadFileController;
use App\Http\Controllers\Shares\DownloadZipController;
use App\Http\Controllers\Shares\Uploads\AppendUploadController;
use App\Http\Controllers\Shares\Uploads\CancelUploadController;
use App\Http\Controllers\Shares\Uploads\CreateUploadController;
use App\Http\Controllers\Shares\Uploads\UploadStatusController;
use App\Http\Middleware\EnsureTusVersion;
use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login')->name('home');

// Authentication is GitHub-only: the login page offers a single "Sign in with GitHub" action (or a not-configured
// notice), and the OAuth handshake provisions the account and signs the user in.
Route::view('login', 'pages.auth.login')->name('login')->middleware('guest');

Route::get('auth/github/redirect', [GitHubAuthController::class, 'redirect'])->name('auth.github.redirect');
Route::get('auth/github/callback', [GitHubAuthController::class, 'callback'])->name('auth.github.callback');

Route::post('logout', function (Logout $logout) {
    $logout();

    return to_route('home');
})->name('logout')->middleware('auth');

Route::middleware('auth')->group(function (): void {
    Route::redirect('dashboard', '/shares')->name('dashboard');

    Route::livewire('shares', 'pages::shares.index')->name('shares.index');
});

Route::middleware('auth')
    ->prefix('shares/{share}')
    ->name('shares.')
    ->group(function (): void {
        Route::livewire('/', 'pages::shares.show')->name('show');
        Route::livewire('trash', 'pages::shares.trash')->name('trash');

        // Resumable (tus 1.0) uploads: create a session, then chunk bytes into it; the GET route answers the
        // protocol's HEAD offset probes.
        Route::middleware(EnsureTusVersion::class)->group(function (): void {
            Route::post('uploads', CreateUploadController::class)->name('uploads.store');
            Route::get('uploads/{upload}', UploadStatusController::class)->whereUuid('upload')->name('uploads.show');
            Route::patch('uploads/{upload}', AppendUploadController::class)->whereUuid('upload')->name('uploads.append');
            Route::delete('uploads/{upload}', CancelUploadController::class)->whereUuid('upload')->name('uploads.destroy');
        });
        Route::get('download', DownloadFileController::class)
            ->middleware('throttle:downloads')
            ->name('download');
        Route::get('zip', DownloadZipController::class)
            ->middleware('throttle:downloads')
            ->name('zip');
    });

require __DIR__.'/admin.php';
require __DIR__.'/settings.php';
