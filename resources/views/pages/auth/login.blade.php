<x-layouts::auth :title="__('Log in')">
    <div class="flex flex-col gap-6">
        <x-auth-header
            :title="__('Sign in to Coffer')"
            :description="__('Sign in with your GitHub account to continue.')"
        />

        <x-auth-session-status class="text-center" :status="session('status')" />

        @php ($githubEnabled = app(\App\Services\GitHubOrganization::class)->configured())

        @error ('github')
            <flux:callout variant="danger" icon="exclamation-triangle">
                <flux:callout.text>{{ $message }}</flux:callout.text>
            </flux:callout>
        @enderror

        @if ($githubEnabled)
            <flux:button
                variant="primary"
                class="w-full"
                :href="route('auth.github.redirect')"
                data-test="github-login-button"
            >
                {{ __('Sign in with GitHub') }}
            </flux:button>
        @else
            <flux:callout icon="information-circle">
                <flux:callout.heading>{{ __('GitHub sign-in is not configured') }}</flux:callout.heading>
                <flux:callout.text>
                    {{ __('An administrator must set GITHUB_CLIENT_ID, GITHUB_CLIENT_SECRET, and GITHUB_ORG to enable organization sign-in.') }}
                </flux:callout.text>
            </flux:callout>
        @endif
    </div>
</x-layouts::auth>
