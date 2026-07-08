<?php

declare(strict_types=1);

use App\Models\Share;
use App\Models\User;

test('a signed-in user can download a file', function (): void {
    [$user, $share] = shareWithMember();
    seedFile($share, 'doc.txt', 'hello world');

    $response = $this->actingAs($user)
        ->get(route('shares.download', ['share' => $share, 'path' => 'doc.txt']));

    $response->assertOk()->assertDownload('doc.txt');

    expect($response->streamedContent())->toBe('hello world');
});

test('an administrator can download from any share without a grant', function (): void {
    $admin = User::factory()->admin()->create();
    $share = Share::factory()->create();
    seedFile($share, 'doc.txt', 'hello world');

    $this->actingAs($admin)
        ->get(route('shares.download', ['share' => $share, 'path' => 'doc.txt']))
        ->assertOk()
        ->assertDownload('doc.txt');
});

test('a file can be previewed inline', function (): void {
    [$user, $share] = shareWithMember('viewer');
    seedFile($share, 'doc.txt', 'inline body');

    $response = $this->actingAs($user)
        ->get(route('shares.download', ['share' => $share, 'path' => 'doc.txt', 'preview' => 1]));

    $response->assertOk();

    expect($response->headers->get('content-disposition'))->toContain('inline')
        ->and($response->streamedContent())->toBe('inline body');
});

test('an inline preview is served with a script-neutralizing sandbox policy', function (): void {
    [$user, $share] = shareWithMember('viewer');
    seedFile($share, 'page.html', '<script>alert(1)</script>');

    $response = $this->actingAs($user)
        ->get(route('shares.download', ['share' => $share, 'path' => 'page.html', 'preview' => 1]));

    $response->assertOk();

    expect($response->headers->get('content-security-policy'))->toBe('sandbox')
        ->and($response->headers->get('x-content-type-options'))->toBe('nosniff');
});

test('a download hands the transfer to the web server when sendfile headers are present', function (): void {
    [$user, $share] = shareWithMember();
    seedFile($share, 'reports/annual report.pdf', 'pdf bytes');

    $response = $this->actingAs($user)
        ->withHeaders([
            'X-Sendfile-Type' => 'x-accel-redirect',
            'X-Accel-Mapping' => realpath($share->path).'/=/',
        ])
        ->get(route('shares.download', ['share' => $share, 'path' => 'reports/annual report.pdf']));

    $response->assertOk()
        ->assertDownload('annual report.pdf')
        ->assertHeader('X-Accel-Redirect', rawurlencode('/reports/annual report.pdf'));

    expect($response->streamedContent())->toBe('');
});

test('an inline preview keeps its safety headers when the transfer is handed to the web server', function (): void {
    [$user, $share] = shareWithMember('viewer');
    seedFile($share, 'page.html', '<script>alert(1)</script>');

    $response = $this->actingAs($user)
        ->withHeaders([
            'X-Sendfile-Type' => 'x-accel-redirect',
            'X-Accel-Mapping' => realpath($share->path).'/=/',
        ])
        ->get(route('shares.download', ['share' => $share, 'path' => 'page.html', 'preview' => 1]));

    $response->assertOk()
        ->assertHeader('X-Accel-Redirect', rawurlencode('/page.html'))
        ->assertHeader('Content-Security-Policy', 'sandbox')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($response->streamedContent())->toBe('');
});

test('a download honors http range requests', function (): void {
    [$user, $share] = shareWithMember();
    seedFile($share, 'doc.txt', 'hello world');

    $response = $this->actingAs($user)
        ->withHeaders(['Range' => 'bytes=0-4'])
        ->get(route('shares.download', ['share' => $share, 'path' => 'doc.txt']));

    $response->assertStatus(206)
        ->assertHeader('Content-Range', 'bytes 0-4/11')
        ->assertHeader('Content-Length', '5');

    expect($response->streamedContent())->toBe('hello');
});

test('a reserved area sidecar cannot be downloaded', function (): void {
    [$user, $share] = shareWithMember();
    seedFile($share, '.trash/secret.json', '{"deleted_by":1}');

    $this->actingAs($user)
        ->get(route('shares.download', ['share' => $share, 'path' => '.trash/secret.json']))
        ->assertInvalid('path');
});

test('a guest is redirected to login', function (): void {
    $share = Share::factory()->create();
    seedFile($share, 'doc.txt');

    $this->get(route('shares.download', ['share' => $share, 'path' => 'doc.txt']))
        ->assertRedirect(route('login'));
});

test('downloading a folder returns not found', function (): void {
    [$user, $share] = shareWithMember('viewer');
    seedFolder($share, 'Docs');

    $this->actingAs($user)
        ->get(route('shares.download', ['share' => $share, 'path' => 'Docs']))
        ->assertNotFound();
});

test('downloading a missing path returns not found', function (): void {
    [$user, $share] = shareWithMember('viewer');

    $this->actingAs($user)
        ->get(route('shares.download', ['share' => $share, 'path' => 'nope.txt']))
        ->assertNotFound();
});

test('a traversal path is rejected', function (): void {
    [$user, $share] = shareWithMember('viewer');

    $this->actingAs($user)
        ->get(route('shares.download', ['share' => $share, 'path' => '../secret']))
        ->assertInvalid('path');
});

test('the download endpoint is rate limited', function (): void {
    [$user, $share] = shareWithMember('viewer');
    seedFile($share, 'doc.txt', 'x');

    foreach (range(1, 120) as $ignored) {
        $this->actingAs($user)
            ->get(route('shares.download', ['share' => $share, 'path' => 'doc.txt']))
            ->assertOk();
    }

    $this->actingAs($user)
        ->get(route('shares.download', ['share' => $share, 'path' => 'doc.txt']))
        ->assertStatus(429);
});
