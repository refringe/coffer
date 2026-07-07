<?php

declare(strict_types=1);

use App\Models\Share;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Number;
use Livewire\Livewire;

test('non-administrators cannot access share management', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('admin.shares.index'))->assertForbidden();
});

test('administrators can view share management', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get(route('admin.shares.index'))->assertOk();
});

test('an administrator can create a share with its storage location', function (): void {
    $admin = User::factory()->admin()->create();
    $path = testSharePath();

    Livewire::actingAs($admin)
        ->test('pages::admin.shares')
        ->set('name', 'Quarterly Plan')
        ->set('storagePath', $path)
        ->call('createShare')
        ->assertHasNoErrors();

    $share = Share::query()->where('name', 'Quarterly Plan')->firstOrFail();

    expect($share->path)->toBe($path)
        ->and(File::isDirectory($path))->toBeTrue();
});

test('an administrator can rename a share', function (): void {
    $admin = User::factory()->admin()->create();
    $share = Share::factory()->create(['name' => 'Old name']);

    Livewire::actingAs($admin)
        ->test('pages::admin.shares')
        ->call('startRename', $share->id)
        ->set('renameName', 'New name')
        ->call('renameShare')
        ->assertHasNoErrors();

    expect($share->refresh()->name)->toBe('New name');
});

test('renaming a share to a name whose slug is already taken still succeeds with a distinct slug', function (): void {
    $admin = User::factory()->admin()->create();
    Share::factory()->create(['name' => 'Reports', 'slug' => 'reports']);
    $share = Share::factory()->create(['name' => 'Old name']);

    Livewire::actingAs($admin)
        ->test('pages::admin.shares')
        ->call('startRename', $share->id)
        ->set('renameName', 'Reports')
        ->call('renameShare')
        ->assertHasNoErrors();

    expect($share->refresh()->name)->toBe('Reports')
        ->and($share->slug)->not->toBe('reports');
});

test('an administrator can delete a share while its files are retained', function (): void {
    $admin = User::factory()->admin()->create();
    $share = Share::factory()->create();
    seedFile($share, 'keep.txt');

    Livewire::actingAs($admin)
        ->test('pages::admin.shares')
        ->call('deleteShare', $share->id);

    expect(Share::query()->whereKey($share->id)->exists())->toBeFalse()
        ->and(Share::withTrashed()->whereKey($share->id)->exists())->toBeTrue()
        ->and(File::exists($share->path.'/keep.txt'))->toBeTrue();
});

test('an administrator creates a share with its storage location', function (): void {
    $admin = User::factory()->admin()->create();
    $path = testSharePath();

    Livewire::actingAs($admin)->test('pages::admin.shares')
        ->set('name', 'External')
        ->set('storagePath', $path)
        ->call('createShare')
        ->assertHasNoErrors();

    $share = Share::query()->where('name', 'External')->firstOrFail();

    expect($share->path)->toBe($path)
        ->and(File::isDirectory($path))->toBeTrue();
});

test('creating a share requires a storage location', function (): void {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test('pages::admin.shares')
        ->set('name', 'External')
        ->set('storagePath', '')
        ->call('createShare')
        ->assertHasErrors(['storagePath']);
});

test('the storage location must be an absolute path', function (): void {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test('pages::admin.shares')
        ->set('name', 'External')
        ->set('storagePath', 'relative/path')
        ->call('createShare')
        ->assertHasErrors(['storagePath']);
});

test('two shares cannot share the same storage location', function (): void {
    $admin = User::factory()->admin()->create();
    $path = testSharePath();
    Share::factory()->withPath($path)->create();

    Livewire::actingAs($admin)->test('pages::admin.shares')
        ->set('name', 'Second')
        ->set('storagePath', $path)
        ->call('createShare')
        ->assertHasErrors(['storagePath']);
});

test('a trailing slash cannot bypass the storage location uniqueness check', function (): void {
    $admin = User::factory()->admin()->create();
    $path = testSharePath();
    Share::factory()->withPath($path)->create();

    Livewire::actingAs($admin)->test('pages::admin.shares')
        ->set('name', 'Second')
        ->set('storagePath', $path.'/')
        ->call('createShare')
        ->assertHasErrors(['storagePath']);

    expect(Share::query()->where('path', $path)->count())->toBe(1);
});

test('opening the create modal prefills the storage base path', function (): void {
    $admin = User::factory()->admin()->create();

    $component = Livewire::actingAs($admin)->test('pages::admin.shares')
        ->call('openCreateShare');

    expect($component->get('storagePath'))->toStartWith(config('coffer.storage_path'));
});

test('editing a share prefills its current storage location', function (): void {
    $admin = User::factory()->admin()->create();
    $path = testSharePath();
    $share = Share::factory()->withPath($path)->create();

    Livewire::actingAs($admin)->test('pages::admin.shares')
        ->call('startStorage', $share->id)
        ->assertSet('storagePath', $path);
});

test('an administrator can change a share storage location without moving files', function (): void {
    $admin = User::factory()->admin()->create();
    $share = Share::factory()->create();
    seedFile($share, 'stays.txt');
    $original = $share->path;
    $newPath = testSharePath();

    Livewire::actingAs($admin)->test('pages::admin.shares')
        ->call('startStorage', $share->id)
        ->set('storagePath', $newPath)
        ->call('saveStorage')
        ->assertHasNoErrors();

    expect($share->refresh()->path)->toBe($newPath)
        ->and(File::isDirectory($newPath))->toBeTrue()
        ->and(File::exists($original.'/stays.txt'))->toBeTrue();
});

test('changing a storage location to one already in use is rejected', function (): void {
    $admin = User::factory()->admin()->create();
    $taken = testSharePath();
    Share::factory()->withPath($taken)->create();
    $share = Share::factory()->create();

    Livewire::actingAs($admin)->test('pages::admin.shares')
        ->call('startStorage', $share->id)
        ->set('storagePath', $taken)
        ->call('saveStorage')
        ->assertHasErrors(['storagePath']);
});

test('the storage location is stored as a plain path column', function (): void {
    $path = testSharePath();
    $share = Share::factory()->withPath($path)->create();

    $raw = (string) DB::table('shares')->where('id', $share->id)->value('path');

    expect($raw)->toBe($path);
});

test('the admin shares page shows usage and a grand total', function (): void {
    $admin = User::factory()->admin()->create();

    $alpha = Share::factory()->create(['name' => 'Alpha']);
    $beta = Share::factory()->create(['name' => 'Beta']);

    seedFile($alpha, 'a.bin', str_repeat('a', 1024));
    seedFile($beta, 'b.bin', str_repeat('b', 3072));

    Livewire::actingAs($admin)
        ->test('pages::admin.shares')
        ->assertSee(Number::fileSize(1024))
        ->assertSee(Number::fileSize(3072))
        ->assertSee(Number::fileSize(4096));
});
