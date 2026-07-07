<?php

use App\Contracts\ShareStorageResolver;
use App\Models\Share;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Shares')] class extends Component {
    /**
     * Every share. Access is flat, so all signed-in users see them all. File counts and usage are read from each
     * share's storage directory on disk.
     *
     * @return Collection<int, Share>
     */
    #[Computed]
    public function shares(): Collection
    {
        $shares = Share::query()->orderBy('name')->get();

        $resolver = resolve(ShareStorageResolver::class);

        return $shares->each(function (Share $share) use ($resolver): void {
            $stats = $resolver->for($share)->usageStats();
            $share->files_count = $stats['files'];
            $share->usage_bytes = $stats['bytes'];
        });
    }
}; ?>

<div class="flex w-full flex-col gap-6">
    <div>
        <flux:heading size="xl">{{ __('Shares') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Open a share to browse and manage its files.') }}</flux:text>
    </div>

    @if ($this->shares->isEmpty())
        <flux:callout icon="folder">
            <flux:callout.heading>{{ __('No shares yet') }}</flux:callout.heading>
            <flux:callout.text>
                {{ __('No shares have been created yet. An administrator can create one.') }}
            </flux:callout.text>
        </flux:callout>
    @else
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($this->shares as $share)
                <flux:card
                    :href="route('shares.show', $share)"
                    wire:navigate
                    class="flex flex-col gap-3 transition hover:shadow-md"
                    data-test="share-card"
                >
                    <div class="flex items-center gap-3">
                        <flux:icon icon="folder" class="text-zinc-400" />
                        <flux:heading size="lg">{{ $share->name }}</flux:heading>
                    </div>

                    <div class="flex gap-2">
                        <flux:badge size="sm" color="zinc" icon="document">
                            {{ trans_choice(':count file|:count files', $share->files_count, ['count' => $share->files_count]) }}
                        </flux:badge>
                        <flux:badge size="sm" color="zinc" icon="circle-stack">
                            {{ \Illuminate\Support\Number::fileSize((int) ($share->usage_bytes ?? 0)) }}
                        </flux:badge>
                    </div>
                </flux:card>
            @endforeach
        </div>
    @endif
</div>
