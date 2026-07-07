<?php

declare(strict_types=1);

use App\Models\Share;

test('a symbol-only name still produces a non-empty, reachable slug', function (): void {
    $share = Share::query()->create(['name' => '!!!', 'path' => testSharePath()]);

    expect($share->slug)->not->toBe('')
        ->and(route('shares.show', $share))->not->toEndWith('/shares/');
});

test('an explicitly provided slug of zero is preserved', function (): void {
    $share = Share::query()->create(['name' => 'Zero', 'slug' => '0', 'path' => testSharePath()]);

    expect($share->slug)->toBe('0');
});

test('shares with the same name receive distinct slugs', function (): void {
    $first = Share::query()->create(['name' => 'Duplicate', 'path' => testSharePath()]);
    $second = Share::query()->create(['name' => 'Duplicate', 'path' => testSharePath()]);

    expect($first->slug)->toBe('duplicate')
        ->and($second->slug)->toBe('duplicate-1');
});

test('a free base slug is used even when a numbered-style sibling exists', function (): void {
    Share::query()->create(['name' => 'Photos Special', 'path' => testSharePath()]);

    $share = Share::query()->create(['name' => 'Photos', 'path' => testSharePath()]);

    expect($share->slug)->toBe('photos');
});

test('renaming a share keeps its slug when the base is otherwise free', function (): void {
    $first = Share::query()->create(['name' => 'Photos', 'path' => testSharePath()]);
    Share::query()->create(['name' => 'Photos', 'path' => testSharePath()]);

    $first->update(['name' => 'Photos!']);

    expect($first->slug)->toBe('photos');
});
