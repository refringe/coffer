<?php

use App\Actions\Shares\CreateShare;
use App\Actions\Shares\UpdateSharePath;
use App\Concerns\InteractsWithAuthenticatedUser;
use App\Contracts\ShareStorageResolver;
use App\Models\Share;
use Flux\Flux;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Manage shares')] class extends Component {
    use InteractsWithAuthenticatedUser;
    use WithPagination;

    public string $name = '';

    public bool $showCreateModal = false;

    public bool $showRenameModal = false;

    public ?int $renameId = null;

    public string $renameName = '';

    public bool $showStorageModal = false;

    public ?int $storageShareId = null;

    public string $storagePath = '';

    /**
     * Guard against direct Livewire updates bypassing the route middleware.
     */
    public function mount(): void
    {
        $this->ensureAdministrator();
    }

    /**
     * Open the create-share modal with a clean form, prefilling the storage path with the configured base directory.
     */
    public function openCreateShare(): void
    {
        $this->ensureAdministrator();

        $this->resetShareForm();
        $this->resetValidation();
        $this->storagePath = mb_rtrim(Config::string('coffer.storage_path'), '/').'/';
        $this->showCreateModal = true;
    }

    /**
     * Create a new share rooted at its own local storage directory.
     */
    public function createShare(CreateShare $createShare): void
    {
        $this->ensureAdministrator();

        $this->storagePath = mb_rtrim(mb_trim($this->storagePath), '/');

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'storagePath' => $this->pathRules(),
        ]);

        $createShare->handle($this->name, $this->resolvedPath());

        $this->resetShareForm();
        $this->showCreateModal = false;

        Flux::toast(variant: 'success', text: __('Share created.'));
    }

    /**
     * Open the storage-location modal for a share, prefilling its current path.
     */
    public function startStorage(int $shareId): void
    {
        $this->ensureAdministrator();

        $share = Share::query()->findOrFail($shareId);

        $this->storageShareId = $share->id;
        $this->storagePath = $share->path;

        $this->resetValidation();
        $this->showStorageModal = true;
    }

    /**
     * Persist the share's storage location. This is purely a configuration change: existing files are never moved.
     */
    public function saveStorage(UpdateSharePath $updateSharePath): void
    {
        $this->ensureAdministrator();

        abort_unless($this->storageShareId !== null, 404);

        $share = Share::query()->findOrFail($this->storageShareId);

        $this->storagePath = mb_rtrim(mb_trim($this->storagePath), '/');

        $this->validate(['storagePath' => $this->pathRules($share->id)]);

        $updateSharePath->handle($share, $this->storagePath);

        $this->reset('storageShareId');
        $this->resetShareForm();
        $this->showStorageModal = false;

        Flux::toast(variant: 'success', text: __('Storage location updated.'));
    }

    /**
     * Validation rules for a share's storage directory, requiring an absolute, unique path.
     *
     * @return array<int, mixed>
     */
    private function pathRules(?int $ignoreShareId = null): array
    {
        return [
            'required',
            'string',
            'max:1024',
            'starts_with:/',
            Rule::unique('shares', 'path')->ignore($ignoreShareId),
        ];
    }

    /**
     * The storage path to persist, defaulting under the configured base when the field was left at its placeholder.
     */
    private function resolvedPath(): string
    {
        $path = mb_rtrim(mb_trim($this->storagePath), '/');
        $base = mb_rtrim(Config::string('coffer.storage_path'), '/');

        return $path === '' || $path === $base
            ? $base.'/'.Str::slug($this->name).'-'.Str::random(6)
            : $path;
    }

    /**
     * Open the rename modal for a share.
     */
    public function startRename(int $shareId): void
    {
        $this->ensureAdministrator();

        $share = Share::query()->findOrFail($shareId);

        $this->renameId = $share->id;
        $this->renameName = $share->name;
        $this->showRenameModal = true;
    }

    /**
     * Rename the share currently being edited.
     */
    public function renameShare(): void
    {
        $this->ensureAdministrator();

        $this->validate(['renameName' => ['required', 'string', 'max:255']]);

        $share = Share::query()->findOrFail($this->renameId);

        // The updating hook regenerates the slug from the new name; SQLSTATE 23000 (MySQL/SQLite) and 23505 (PostgreSQL)
        // are the unique-violation codes, so a concurrent same-name rename retries and regenerates a distinct slug
        // instead of surfacing a 500, mirroring CreateShare.
        retry(3, function () use ($share): void {
            $share->update(['name' => $this->renameName]);
        }, 0, fn (\Throwable $e): bool => $e instanceof QueryException && in_array($e->getCode(), ['23000', '23505'], true));

        $this->reset('renameId', 'renameName');
        $this->showRenameModal = false;

        Flux::toast(variant: 'success', text: __('Share renamed.'));
    }

    /**
     * Soft-delete a share. Its files on disk are retained.
     */
    public function deleteShare(int $shareId): void
    {
        $this->ensureAdministrator();

        Share::query()->findOrFail($shareId)->delete();

        Flux::toast(variant: 'success', text: __('Share deleted.'));
    }

    /**
     * Reset the share form fields to their defaults.
     */
    private function resetShareForm(): void
    {
        $this->reset('name', 'storagePath');
    }

    /**
     * All shares with their file counts and on-disk usage, alphabetically.
     *
     * @return LengthAwarePaginator<int, Share>
     */
    #[Computed]
    public function shares(): LengthAwarePaginator
    {
        $shares = Share::query()->orderBy('name')->paginate(25);

        $resolver = resolve(ShareStorageResolver::class);

        $shares->getCollection()->each(function (Share $share) use ($resolver): void {
            $stats = $resolver->for($share)->usageStats();
            $share->files_count = $stats['files'];
            $share->usage_bytes = $stats['bytes'];
        });

        return $shares;
    }

    /**
     * Total storage used across every share, in bytes, read from disk.
     */
    #[Computed]
    public function totalUsage(): int
    {
        $resolver = resolve(ShareStorageResolver::class);

        return (int) Share::query()->get()->sum(fn (Share $share): int => $resolver->for($share)->usage());
    }

    private function ensureAdministrator(): void
    {
        abort_unless($this->authenticatedUser()->isAdministrator(), 403);
    }
}; ?>

<div class="flex w-full flex-col gap-6">
    <div class="flex items-end justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Manage shares') }}</flux:heading>
            <flux:text class="mt-1">
                {{ __('Create shares and control which users can access them.') }} {{ __('Total storage used: :size.', ['size' => \Illuminate\Support\Number::fileSize($this->totalUsage)]) }}
            </flux:text>
        </div>

        <flux:button variant="primary" icon="plus" wire:click="openCreateShare" data-test="add-share-button">
            {{ __('New share') }}
        </flux:button>
    </div>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Files') }}</flux:table.column>
            <flux:table.column>{{ __('Usage') }}</flux:table.column>
            <flux:table.column>{{ __('Created') }}</flux:table.column>
            <flux:table.column />
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($this->shares as $share)
                <flux:table.row :key="$share->id">
                    <flux:table.cell variant="strong">
                        <flux:link :href="route('shares.show', $share)" wire:navigate>{{ $share->name }}</flux:link>
                    </flux:table.cell>
                    <flux:table.cell>{{ $share->files_count }}</flux:table.cell>
                    <flux:table.cell>
                        {{ \Illuminate\Support\Number::fileSize((int) ($share->usage_bytes ?? 0)) }}</flux:table.cell
                    >
                    <flux:table.cell>{{ $share->created_at?->format('Y-m-d') }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:dropdown align="end">
                            <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" inset="right" />

                            <flux:menu>
                                <flux:menu.item icon="pencil-square" wire:click="startRename({{ $share->id }})">
                                    {{ __('Rename') }}
                                </flux:menu.item>

                                <flux:menu.item icon="users" :href="route('shares.show', $share)" wire:navigate>
                                    {{ __('Manage access') }}
                                </flux:menu.item>

                                <flux:menu.item icon="circle-stack" wire:click="startStorage({{ $share->id }})">
                                    {{ __('Storage location') }}
                                </flux:menu.item>

                                <flux:menu.separator />

                                <flux:menu.item
                                    variant="danger"
                                    icon="trash"
                                    wire:click="deleteShare({{ $share->id }})"
                                    wire:confirm="{{ __('Delete this share? Its files are retained but become inaccessible.') }}"
                                >
                                    {{ __('Delete') }}
                                </flux:menu.item>
                            </flux:menu>
                        </flux:dropdown>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    {{ $this->shares->links() }}

    {{-- Create share --}}
    <flux:modal wire:model="showCreateModal" class="md:w-[32rem]">
        <form wire:submit="createShare" class="flex flex-col gap-6">
            <div>
                <flux:heading size="lg">{{ __('New share') }}</flux:heading>
                <flux:text
                    class="mt-2"
                    >{{ __('Give the share a name and the directory on this server where its files are stored.') }}</flux:text
                >
            </div>

            <flux:input wire:model="name" :label="__('Name')" type="text" required autocomplete="off" />

            <flux:input
                wire:model="storagePath"
                :label="__('Storage location')"
                :description="__('An absolute path on the server (e.g. a mounted volume). Created if it does not exist.')"
                placeholder="/data/shares/marketing"
                autocomplete="off"
                required
            />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button
                    variant="primary"
                    type="submit"
                    data-test="create-share-button"
                    >{{ __('Create share') }}</flux:button
                >
            </div>
        </form>
    </flux:modal>

    {{-- Rename share --}}
    <flux:modal wire:model="showRenameModal" class="md:w-96">
        <form wire:submit="renameShare" class="flex flex-col gap-6">
            <flux:heading size="lg">{{ __('Rename share') }}</flux:heading>

            <flux:input wire:model="renameName" :label="__('Name')" type="text" required autocomplete="off" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button
                    variant="primary"
                    type="submit"
                    data-test="save-rename-button"
                    >{{ __('Save') }}</flux:button
                >
            </div>
        </form>
    </flux:modal>

    {{-- Storage location --}}
    <flux:modal wire:model="showStorageModal" class="md:w-[32rem]">
        <form wire:submit="saveStorage" class="flex flex-col gap-6">
            <div>
                <flux:heading size="lg">{{ __('Storage location') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('The directory on this server where this share stores its files. Changing it does not move existing files.') }}
                </flux:text>
            </div>

            <flux:input
                wire:model="storagePath"
                :label="__('Storage location')"
                placeholder="/data/shares/marketing"
                autocomplete="off"
                required
            />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button
                    variant="primary"
                    type="submit"
                    data-test="save-storage-button"
                    >{{ __('Save location') }}</flux:button
                >
            </div>
        </form>
    </flux:modal>
</div>
