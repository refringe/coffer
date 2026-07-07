<?php

use App\Actions\Shares\PurgeTrashEntry;
use App\Actions\Shares\RestoreEntry;
use App\Concerns\InteractsWithAuthenticatedUser;
use App\Contracts\ShareStorage;
use App\Contracts\ShareStorageResolver;
use App\Enums\ActivityAction;
use App\Models\Activity;
use App\Models\Share;
use App\Support\Entry;
use App\Support\TrashedEntry;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Recycle bin')] class extends Component {
    use InteractsWithAuthenticatedUser;

    #[Locked]
    public Share $share;

    private ?ShareStorage $storageInstance = null;

    /**
     * Authorize access to the share's recycle bin.
     */
    public function mount(Share $share): void
    {
        abort_unless($this->authenticatedUser()->can('viewFiles', $share), 403);

        $this->share = $share;
    }

    /**
     * Restore a trashed item to its original location.
     */
    public function restore(string $id, RestoreEntry $restoreEntry): void
    {
        $this->ensureCanModify();

        $restored = $restoreEntry->handle($this->share, $id);

        if (! $restored instanceof Entry) {
            return;
        }

        Activity::record($this->share, $this->authenticatedUser(), ActivityAction::NodeRestored, $restored->name, $restored->path);

        unset($this->trashed);

        Flux::toast(variant: 'success', text: __('Restored.'));
    }

    /**
     * Permanently delete a trashed item.
     */
    public function purge(string $id, PurgeTrashEntry $purgeTrashEntry): void
    {
        $this->ensureCanModify();

        // Capture the item before it is removed so the irreversible deletion can be attributed in the activity feed.
        $entry = $this->trashed->firstWhere('id', $id);

        $purgeTrashEntry->handle($this->share, $id);

        if ($entry instanceof TrashedEntry) {
            Activity::record($this->share, $this->authenticatedUser(), ActivityAction::NodePurged, $entry->name, $entry->originalPath);
        }

        unset($this->trashed);

        Flux::toast(variant: 'success', text: __('Permanently deleted.'));
    }

    /**
     * Whether the current user may restore or permanently delete items.
     */
    #[Computed]
    public function canModify(): bool
    {
        return $this->authenticatedUser()->can('modifyFiles', $this->share);
    }

    /**
     * The items currently in the recycle bin, most recently deleted first.
     *
     * @return Collection<int, TrashedEntry>
     */
    #[Computed]
    public function trashed(): Collection
    {
        return $this->storage()->trashed();
    }

    /**
     * Resolve (and memoize) this share's storage for the request.
     */
    private function storage(): ShareStorage
    {
        return $this->storageInstance ??= resolve(ShareStorageResolver::class)->for($this->share);
    }

    private function ensureCanModify(): void
    {
        abort_unless($this->authenticatedUser()->can('modifyFiles', $this->share), 403);
    }
}; ?>

<div class="flex w-full flex-col gap-6">
    <div>
        <flux:breadcrumbs>
            <flux:breadcrumbs.item :href="route('shares.index')" wire:navigate icon="rectangle-stack" />
            <flux:breadcrumbs.item :href="route('shares.show', $share)" wire:navigate>
                {{ $share->name }}</flux:breadcrumbs.item
            >
            <flux:breadcrumbs.item>{{ __('Recycle bin') }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>

        <flux:heading size="xl" class="mt-2">{{ __('Recycle bin') }}</flux:heading>
        <flux:text
            class="mt-1"
            >{{ __('Deleted items are kept here until they are restored or permanently removed.') }}</flux:text
        >
    </div>

    @if ($this->trashed->isEmpty())
        <flux:callout icon="trash">
            <flux:callout.heading>{{ __('The recycle bin is empty') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Items you delete from this share will appear here.') }}</flux:callout.text>
        </flux:callout>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column>{{ __('Original location') }}</flux:table.column>
                <flux:table.column>{{ __('Deleted') }}</flux:table.column>
                <flux:table.column />
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->trashed as $item)
                    <flux:table.row :key="$item->id">
                        <flux:table.cell variant="strong">
                            <div class="flex items-center gap-2">
                                <flux:icon
                                    :icon="$item->isFolder() ? 'folder' : 'document'"
                                    class="{{ $item->isFolder() ? 'text-amber-500' : 'text-zinc-400' }}"
                                />
                                {{ $item->name }}
                            </div>
                        </flux:table.cell>
                        <flux:table.cell class="text-zinc-500">{{ $item->originalPath }}</flux:table.cell>
                        <flux:table.cell>
                            {{ \Illuminate\Support\Carbon::createFromTimestamp($item->deletedAt)->diffForHumans() }}</flux:table.cell
                        >
                        <flux:table.cell align="end">
                            @if ($this->canModify)
                                <flux:dropdown>
                                    <flux:button
                                        variant="subtle"
                                        size="sm"
                                        icon="ellipsis-horizontal"
                                        data-test="trash-actions"
                                    />

                                    <flux:menu>
                                        <flux:menu.item
                                            icon="arrow-uturn-left"
                                            wire:click="restore(@js($item->id))"
                                            data-test="restore-action"
                                        >
                                            {{ __('Restore') }}
                                        </flux:menu.item>
                                        <flux:menu.separator />
                                        <flux:menu.item
                                            icon="trash"
                                            variant="danger"
                                            wire:click="purge(@js($item->id))"
                                            wire:confirm="{{ __('Permanently delete this item? This cannot be undone.') }}"
                                            data-test="purge-action"
                                        >
                                            {{ __('Delete forever') }}
                                        </flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
</div>
