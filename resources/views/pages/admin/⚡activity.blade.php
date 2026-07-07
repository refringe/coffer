<?php

use App\Concerns\InteractsWithAuthenticatedUser;
use App\Models\Activity;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Activity')] class extends Component {
    use InteractsWithAuthenticatedUser;
    use WithPagination;

    /**
     * Restrict the page to administrators.
     */
    public function mount(): void
    {
        $this->ensureAdministrator();
    }

    /**
     * The most recent activity across every share.
     *
     * @return LengthAwarePaginator<int, Activity>
     */
    #[Computed]
    public function activity(): LengthAwarePaginator
    {
        return Activity::query()
            ->with(['actor', 'share'])
            ->latest()
            ->paginate(50);
    }

    private function ensureAdministrator(): void
    {
        abort_unless($this->authenticatedUser()->isAdministrator(), 403);
    }
}; ?>

<div class="flex w-full flex-col gap-6">
    <div>
        <flux:heading size="xl">{{ __('Activity') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Recent activity across every share.') }}</flux:text>
    </div>

    @if ($this->activity->isEmpty())
        <flux:callout icon="clock">
            <flux:callout.heading>{{ __('No activity yet') }}</flux:callout.heading>
            <flux:callout.text>
                {{ __('File and access changes will appear here as users work in their shares.') }}</flux:callout.text
            >
        </flux:callout>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Share') }}</flux:table.column>
                <flux:table.column>{{ __('Who') }}</flux:table.column>
                <flux:table.column>{{ __('What') }}</flux:table.column>
                <flux:table.column>{{ __('When') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->activity as $entry)
                    <flux:table.row :key="$entry->id">
                        <flux:table.cell variant="strong">{{ $entry->share?->name }}</flux:table.cell>
                        <flux:table.cell>{{ $entry->actor?->name ?? __('Someone') }}</flux:table.cell>
                        <flux:table.cell>
                            {{ $entry->action->description() }}
                            @if ($entry->subject)
                                <span class="font-medium">{{ $entry->subject }}</span>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="text-zinc-500">
                            {{ $entry->created_at?->diffForHumans() }}</flux:table.cell
                        >
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
        {{ $this->activity->links() }}
    @endif
</div>
