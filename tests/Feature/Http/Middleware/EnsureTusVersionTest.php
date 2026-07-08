<?php

declare(strict_types=1);

test('a request without the tus version header is refused with 412 and the supported version', function (): void {
    [$user, $share] = shareWithMember();

    $this->actingAs($user)
        ->withHeaders(['Upload-Length' => '5'])
        ->post(route('shares.uploads.store', $share))
        ->assertStatus(412)
        ->assertHeader('Tus-Version', '1.0.0')
        ->assertHeader('Tus-Resumable', '1.0.0');
});

test('a response passing through the middleware carries the tus version header', function (): void {
    [$user, $share] = shareWithMember();

    $this->actingAs($user);

    tusCreate($share, 'report.txt', 5)
        ->assertCreated()
        ->assertHeader('Tus-Resumable', '1.0.0');
});
