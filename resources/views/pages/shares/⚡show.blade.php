<?php

use App\Actions\Shares\CreateFolder;
use App\Actions\Shares\MoveEntry;
use App\Actions\Shares\RenameEntry;
use App\Actions\Shares\TrashEntry;
use App\Concerns\InteractsWithAuthenticatedUser;
use App\Concerns\ValidatesNodeNames;
use App\Contracts\ShareStorage;
use App\Contracts\ShareStorageResolver;
use App\Enums\ActivityAction;
use App\Models\Activity;
use App\Models\Share;
use App\Support\Entry;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component {
    use InteractsWithAuthenticatedUser;
    use ValidatesNodeNames;

    #[Locked]
    public Share $share;

    #[Url(as: 'path')]
    public string $path = '';

    public string $sortBy = 'name';

    public string $sortDirection = 'asc';

    public string $search = '';

    /** @var array<int, string> */
    public array $selected = [];

    public bool $showNewFolder = false;

    public string $newFolderName = '';

    public bool $showRename = false;

    #[Locked]
    public ?string $renamePath = null;

    public string $renameName = '';

    public bool $showMove = false;

    #[Locked]
    public ?string $movePath = null;

    public bool $moveBulk = false;

    public bool $showPreview = false;

    #[Locked]
    public ?string $previewPath = null;

    public bool $showActivity = false;

    private ?ShareStorage $storageInstance = null;

    /**
     * Authorize access to this share when the component first loads.
     */
    public function mount(Share $share): void
    {
        abort_unless($this->authenticatedUser()->can('viewFiles', $share), 403);

        $this->share = $share;
    }

    /**
     * Normalize the URL-supplied path on every request, after it has been hydrated, so a malformed `path` query
     * (traversal or a reserved area) collapses to the share root instead of reaching the storage guard and throwing.
     */
    public function booted(): void
    {
        $this->path = $this->clean($this->path);
    }

    /**
     * Open a folder within this share.
     */
    public function open(string $path): void
    {
        $path = $this->clean($path);

        if ($path === '' || $this->storage()->isDirectory($path)) {
            $this->path = $path;
            $this->reset('search', 'selected');
        }
    }

    /**
     * Return to the share root.
     */
    public function openRoot(): void
    {
        $this->path = '';
        $this->reset('search', 'selected');
    }

    /**
     * Sort the listing by a column, toggling direction on repeat.
     */
    public function sort(string $column): void
    {
        if (! in_array($column, ['name', 'size', 'modified'], true)) {
            return;
        }

        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';

            return;
        }

        $this->sortBy = $column;
        $this->sortDirection = 'asc';
    }

    /**
     * Create a folder in the current directory.
     */
    public function createFolder(CreateFolder $createFolder): void
    {
        $this->ensureCanModify();

        $this->validate([
            'newFolderName' => $this->nodeNameRules(),
        ]);

        if ($this->existsHere($this->newFolderName)) {
            $this->addError('newFolderName', __('An item with this name already exists here.'));

            return;
        }

        $entry = $createFolder->handle($this->share, $this->path, $this->newFolderName);

        Activity::record($this->share, $this->authenticatedUser(), ActivityAction::FolderCreated, $entry->name, $entry->path);

        $this->reset('newFolderName', 'showNewFolder');
        unset($this->entries);

        Flux::toast(variant: 'success', text: __('Folder created.'));
    }

    /**
     * Open the rename dialog for an entry.
     */
    public function startRename(string $path): void
    {
        $path = $this->clean($path);

        if ($path === '' || ! $this->storage()->exists($path)) {
            return;
        }

        $this->renamePath = $path;
        $this->renameName = basename($path);
        $this->showRename = true;
    }

    /**
     * Rename the selected entry.
     */
    public function rename(RenameEntry $renameEntry): void
    {
        $this->ensureCanModify();

        if ($this->renamePath === null || ! $this->storage()->exists($this->renamePath)) {
            return;
        }

        $this->validate([
            'renameName' => $this->nodeNameRules(),
        ]);

        $directory = str_contains($this->renamePath, '/') ? Str::beforeLast($this->renamePath, '/') : '';

        // Compare case-insensitively so a case-only rename (e.g. "Photo.jpg" to "photo.jpg") is not blocked as a
        // collision against itself on a case-insensitive filesystem.
        $renamesItself = mb_strtolower($this->renameName) === mb_strtolower(basename($this->renamePath));

        if (! $renamesItself && $this->existsHere($this->renameName, $directory)) {
            $this->addError('renameName', __('An item with this name already exists here.'));

            return;
        }

        $newPath = $renameEntry->handle($this->share, $this->renamePath, $this->renameName);

        Activity::record($this->share, $this->authenticatedUser(), ActivityAction::NodeRenamed, basename($newPath), $newPath);

        $this->reset('renamePath', 'renameName', 'showRename');
        unset($this->entries);

        Flux::toast(variant: 'success', text: __('Renamed.'));
    }

    /**
     * Open the move dialog for an entry.
     */
    public function startMove(string $path): void
    {
        $path = $this->clean($path);

        if ($path === '' || ! $this->storage()->exists($path)) {
            return;
        }

        $this->movePath = $path;
        $this->moveBulk = false;
        $this->showMove = true;
        unset($this->moveTargets);
    }

    /**
     * Open the move dialog for every selected entry at once.
     */
    public function startBulkMove(): void
    {
        if ($this->selected === []) {
            return;
        }

        $this->movePath = null;
        $this->moveBulk = true;
        $this->showMove = true;
        unset($this->moveTargets);
    }

    /**
     * Move the selected entry into the chosen folder (or the share root).
     */
    public function moveTo(string $destination, MoveEntry $moveEntry): void
    {
        $this->ensureCanModify();

        $destination = $this->clean($destination);

        $paths = $this->moveBulk
            ? $this->selectedPaths()
            : array_filter([$this->movePath], fn (?string $path): bool => $path !== null && $this->storage()->exists($path));

        if ($paths === []) {
            return;
        }

        $moved = 0;

        foreach ($paths as $path) {
            $newPath = $moveEntry->handle($this->share, $path, $destination);

            // A move into the item's current folder returns the path unchanged; only count a real relocation.
            if ($newPath !== null && $newPath !== $path) {
                Activity::record($this->share, $this->authenticatedUser(), ActivityAction::NodeMoved, basename($newPath), $newPath);
                $moved++;
            }
        }

        if ($moved === 0) {
            Flux::toast(variant: 'warning', text: __('That destination is not valid.'));

            return;
        }

        if ($this->moveBulk) {
            $this->reset('selected');
        }

        $this->reset('movePath', 'showMove', 'moveBulk');
        unset($this->entries);

        Flux::toast(variant: 'success', text: __('Moved.'));
    }

    /**
     * Send an entry to the recycle bin.
     */
    public function delete(string $path, TrashEntry $trashEntry): void
    {
        $this->ensureCanModify();

        $path = $this->clean($path);

        if ($path === '' || ! $this->storage()->exists($path)) {
            return;
        }

        $trashEntry->handle($this->share, $path, $this->authenticatedUser()->id);

        Activity::record($this->share, $this->authenticatedUser(), ActivityAction::NodeDeleted, basename($path), $path);

        $this->deselect($path);
        unset($this->entries);

        Flux::toast(variant: 'success', text: __('Moved to the recycle bin.'));
    }

    /**
     * Send every selected entry to the recycle bin, then clear the selection.
     */
    public function deleteSelected(TrashEntry $trashEntry): void
    {
        $this->ensureCanModify();

        $paths = $this->selectedPaths();

        foreach ($paths as $path) {
            $trashEntry->handle($this->share, $path, $this->authenticatedUser()->id);

            Activity::record($this->share, $this->authenticatedUser(), ActivityAction::NodeDeleted, basename($path), $path);
        }

        $count = count($paths);
        $this->reset('selected');
        unset($this->entries);

        Flux::toast(variant: 'success', text: __(':count item(s) moved to the recycle bin.', ['count' => $count]));
    }

    /**
     * Open the preview dialog for a file.
     */
    public function preview(string $path): void
    {
        $path = $this->clean($path);

        if ($this->storage()->exists($path) && ! $this->storage()->isDirectory($path)) {
            $this->previewPath = $path;
            $this->showPreview = true;
        }
    }

    /**
     * The file names already present in the current folder, used by the browser uploader to detect conflicts before
     * uploading.
     *
     * @return array<int, string>
     */
    public function existingNames(): array
    {
        return $this->entries
            ->filter(fn (Entry $entry): bool => $entry->isFile())
            ->map(fn (Entry $entry): string => $entry->name)
            ->values()
            ->all();
    }

    /**
     * Refresh the listing after a client-side upload completes.
     */
    public function reload(): void
    {
        unset($this->entries);
    }

    /**
     * The entries in the current folder: folders first, then the chosen sort.
     *
     * @return Collection<int, Entry>
     */
    #[Computed]
    public function entries(): Collection
    {
        $descending = $this->sortDirection === 'desc';

        return $this->storage()->entries($this->path)
            ->sortBy(fn (Entry $entry): mixed => match ($this->sortBy) {
                'size' => $entry->size ?? 0,
                'modified' => $entry->modifiedAt,
                default => mb_strtolower($entry->name),
            }, SORT_REGULAR, $descending)
            ->sortBy(fn (Entry $entry): int => $entry->isFolder() ? 0 : 1)
            ->values();
    }

    /**
     * Whether a filename search is currently active.
     */
    #[Computed]
    public function isSearching(): bool
    {
        return mb_trim($this->search) !== '';
    }

    /**
     * Entries across the whole share whose name matches the search term.
     *
     * @return Collection<int, Entry>
     */
    #[Computed]
    public function searchResults(): Collection
    {
        $term = mb_trim($this->search);

        return $term === '' ? new Collection() : $this->storage()->search($term);
    }

    /**
     * Breadcrumb trail from the share root to the current folder.
     *
     * @return array<int, array{path: string, name: string}>
     */
    #[Computed]
    public function breadcrumbs(): array
    {
        $crumbs = [];
        $accumulated = '';

        foreach (array_filter(explode('/', $this->path), fn (string $segment): bool => $segment !== '') as $segment) {
            $accumulated = $accumulated === '' ? $segment : $accumulated.'/'.$segment;
            $crumbs[] = ['path' => $accumulated, 'name' => $segment];
        }

        return $crumbs;
    }

    /**
     * The display name for the current folder (the share name at the root).
     */
    #[Computed]
    public function currentName(): string
    {
        return $this->path === '' ? $this->share->name : basename($this->path);
    }

    /**
     * Whether the current user may modify files in this share.
     */
    #[Computed]
    public function canModify(): bool
    {
        return $this->authenticatedUser()->can('modifyFiles', $this->share);
    }

    /**
     * Folders the selected entry may be moved into: every folder in the share except the entry itself and its own
     * descendants.
     *
     * @return Collection<int, Entry>
     */
    #[Computed]
    public function moveTargets(): Collection
    {
        $moving = $this->moveBulk ? $this->selected : ($this->movePath === null ? [] : [$this->movePath]);

        return $this->storage()->folders()
            ->reject(fn(Entry $folder): bool => array_any($moving, fn($path): bool => $folder->path === $path || str_starts_with($folder->path, $path.'/')))
            ->sortBy(fn (Entry $entry): string => $entry->path)
            ->values();
    }

    /**
     * The file entry currently being previewed, if any.
     */
    #[Computed]
    public function previewEntry(): ?Entry
    {
        if ($this->previewPath === null) {
            return null;
        }

        $entry = $this->storage()->entry($this->previewPath);

        return $entry instanceof Entry && $entry->isFile() ? $entry : null;
    }

    /**
     * A URL that streams the previewed file inline.
     */
    #[Computed]
    public function previewUrl(): ?string
    {
        $entry = $this->previewEntry();

        if (! $entry instanceof Entry) {
            return null;
        }

        return route('shares.download', $this->share).'?'.http_build_query(['path' => $entry->path, 'preview' => 1]);
    }

    /**
     * Recent activity for this share, newest first.
     *
     * @return EloquentCollection<int, Activity>
     */
    #[Computed]
    public function activity(): EloquentCollection
    {
        return $this->share->activities()
            ->with('actor')
            ->latest()
            ->limit(50)
            ->get();
    }

    /**
     * The selected relative paths that still exist in storage.
     *
     * @return array<int, string>
     */
    private function selectedPaths(): array
    {
        return array_values(array_filter(
            array_map($this->clean(...), $this->selected),
            fn (string $path): bool => $path !== '' && $this->storage()->exists($path),
        ));
    }

    /**
     * Remove a relative path from the current selection.
     */
    private function deselect(string $path): void
    {
        $this->selected = array_values(
            array_filter($this->selected, fn (string $selected): bool => $selected !== $path),
        );
    }

    /**
     * Determine if a name is already taken within a directory of this share.
     */
    private function existsHere(string $name, ?string $directory = null): bool
    {
        $directory ??= $this->path;
        $target = $directory === '' ? $name : $directory.'/'.$name;

        return $this->storage()->exists($target);
    }

    /**
     * Normalize a browser-supplied relative path, collapsing traversal or a reserved internal area to root.
     */
    private function clean(string $path): string
    {
        $path = mb_trim(str_replace('\\', '/', $path), '/');

        $segments = explode('/', $path);

        foreach ($segments as $segment) {
            // Empty interior segments (a double slash) or a control character would reach the storage guard and throw,
            // so collapse to root.
            if (in_array($segment, ['', '.', '..'], true) || preg_match('/[\x00-\x1F]/', $segment) === 1) {
                return '';
            }
        }

        if (in_array($segments[0], ShareStorage::RESERVED, true)) {
            return '';
        }

        return $path;
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

<div
    class="flex w-full flex-col gap-6"
    @if ($this->canModify)
        x-data="uploader(@js(route('shares.uploads.store', $share)), @js(csrf_token()), @js(config('coffer.upload_chunk_size')))"
        x-on:dragover.prevent="dragging = true"
        x-on:dragleave.prevent="dragging = false"
        x-on:drop.prevent="onDrop($event)"
        x-on:coffer:upload-finished.window="onFinished($event)"
    @endif
>
    <div class="flex items-end justify-between gap-4">
        <div>
            <flux:breadcrumbs>
                <flux:breadcrumbs.item :href="route('shares.index')" wire:navigate icon="rectangle-stack" />
                <flux:breadcrumbs.item wire:click="openRoot" class="cursor-pointer">
                    {{ $share->name }}</flux:breadcrumbs.item
                >
                @foreach ($this->breadcrumbs as $crumb)
                    <flux:breadcrumbs.item wire:click="open(@js($crumb['path']))" class="cursor-pointer">
                        {{ $crumb['name'] }}
                    </flux:breadcrumbs.item>
                @endforeach
            </flux:breadcrumbs>

            <flux:heading size="xl" class="mt-2">{{ $this->currentName }}</flux:heading>
        </div>

        <div class="flex items-center gap-2">
            @if ($this->canModify)
                <flux:button icon="folder-plus" wire:click="$set('showNewFolder', true)" data-test="new-folder-button">
                    {{ __('New folder') }}
                </flux:button>
                <flux:button
                    variant="primary"
                    icon="arrow-up-tray"
                    x-on:click="$refs.fileInput.click()"
                    data-test="upload-button"
                >
                    {{ __('Upload') }}
                </flux:button>
                <input type="file" multiple class="hidden" x-ref="fileInput" x-on:change="onSelect($event)" />
            @endif

            <flux:button
                icon="clock"
                variant="subtle"
                wire:click="$set('showActivity', true)"
                data-test="activity-button"
            >
                {{ __('Activity') }}
            </flux:button>

            <flux:button
                icon="trash"
                variant="subtle"
                :href="route('shares.trash', $share)"
                wire:navigate
                data-test="trash-button"
            >
                {{ __('Recycle bin') }}
            </flux:button>
        </div>
    </div>

    @if ($this->canModify)
        {{-- Drag-and-drop hint --}}
        <div
            x-show="dragging"
            x-cloak
            class="rounded-lg border-2 border-dashed border-accent p-6 text-center text-sm text-zinc-500"
        >
            {{ __('Drop files to upload to this folder') }}
        </div>
    @endif

    {{-- Search + bulk actions --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <flux:input
            icon="magnifying-glass"
            wire:model.live.debounce.300ms="search"
            :placeholder="__('Search this share')"
            class="max-w-xs"
            data-test="share-search"
        />

        @if (count($selected) > 0)
            <div class="flex flex-wrap items-center gap-2" data-test="bulk-actions">
                <flux:text class="text-sm">{{ __(':count selected', ['count' => count($selected)]) }}</flux:text>

                <flux:button
                    icon="arrow-down-tray"
                    size="sm"
                    :href="route('shares.zip', $share).'?'.http_build_query(['paths' => $selected])"
                    target="_blank"
                    data-test="download-selected"
                >
                    {{ __('Download') }}
                </flux:button>

                @if ($this->canModify)
                    <flux:button
                        icon="arrows-pointing-out"
                        size="sm"
                        wire:click="startBulkMove"
                        data-test="move-selected"
                    >
                        {{ __('Move') }}
                    </flux:button>
                    <flux:button
                        icon="trash"
                        size="sm"
                        variant="danger"
                        wire:click="deleteSelected"
                        wire:confirm="{{ __('Move the selected items to the recycle bin?') }}"
                        data-test="delete-selected"
                    >
                        {{ __('Delete') }}
                    </flux:button>
                @endif

                <flux:button variant="ghost" size="sm" wire:click="$set('selected', [])" data-test="clear-selection">
                    {{ __('Clear') }}
                </flux:button>
            </div>
        @endif
    </div>

    @if ($this->isSearching)
        @if ($this->searchResults->isEmpty())
            <flux:callout icon="magnifying-glass">
                <flux:callout.heading>{{ __('No matches') }}</flux:callout.heading>
                <flux:callout.text>{{ __('Nothing in this share matches your search.') }}</flux:callout.text>
            </flux:callout>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Name') }}</flux:table.column>
                    <flux:table.column>{{ __('Location') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->searchResults as $result)
                        <flux:table.row :key="'search-'.$result->path">
                            <flux:table.cell variant="strong">
                                <div class="flex items-center gap-2">
                                    @if ($result->isFolder())
                                        <button
                                            type="button"
                                            wire:click="open(@js($result->path))"
                                            class="flex cursor-pointer items-center gap-2 text-start"
                                            data-test="search-folder"
                                        >
                                            <flux:icon icon="folder" class="text-amber-500" />
                                            {{ $result->name }}
                                        </button>
                                    @else
                                        <button
                                            type="button"
                                            wire:click="preview(@js($result->path))"
                                            class="flex cursor-pointer items-center gap-2 text-start"
                                            data-test="search-file"
                                        >
                                            <flux:icon icon="document" class="text-zinc-400" />
                                            {{ $result->name }}
                                        </button>
                                    @endif
                                </div>
                            </flux:table.cell>
                            <flux:table.cell class="text-zinc-500">{{ $result->path }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    @elseif ($this->entries->isEmpty())
        <flux:callout icon="folder-open">
            <flux:callout.heading>{{ __('This folder is empty') }}</flux:callout.heading>
            <flux:callout.text>
                @if ($this->canModify)
                    {{ __('Upload files or drag them here to get started.') }}
                @else
                    {{ __('No files or folders here yet.') }}
                @endif
            </flux:callout.text>
        </flux:callout>
    @else
        <flux:table data-test="entries">
            <flux:table.columns>
                <flux:table.column />
                <flux:table.column
                    sortable
                    :sorted="$this->sortBy === 'name'"
                    :direction="$this->sortDirection"
                    wire:click="sort('name')"
                >
                    {{ __('Name') }}
                </flux:table.column>
                <flux:table.column
                    sortable
                    :sorted="$this->sortBy === 'size'"
                    :direction="$this->sortDirection"
                    wire:click="sort('size')"
                >
                    {{ __('Size') }}
                </flux:table.column>
                <flux:table.column
                    sortable
                    :sorted="$this->sortBy === 'modified'"
                    :direction="$this->sortDirection"
                    wire:click="sort('modified')"
                >
                    {{ __('Modified') }}
                </flux:table.column>
                <flux:table.column />
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->entries as $entry)
                    <flux:table.row :key="$entry->path">
                        <flux:table.cell>
                            <flux:checkbox
                                wire:model.live="selected"
                                value="{{ $entry->path }}"
                                data-test="select-node"
                            />
                        </flux:table.cell>
                        <flux:table.cell variant="strong">
                            <div class="flex items-center gap-2">
                                @if ($entry->isFolder())
                                    <button
                                        type="button"
                                        wire:click="open(@js($entry->path))"
                                        class="flex cursor-pointer items-center gap-2 text-start"
                                        data-test="folder-link"
                                    >
                                        <flux:icon icon="folder" class="text-amber-500" />
                                        {{ $entry->name }}
                                    </button>
                                @else
                                    <button
                                        type="button"
                                        wire:click="preview(@js($entry->path))"
                                        class="flex cursor-pointer items-center gap-2 text-start"
                                        data-test="file-link"
                                    >
                                        <flux:icon icon="document" class="text-zinc-400" />
                                        {{ $entry->name }}
                                    </button>
                                @endif
                            </div>
                        </flux:table.cell>
                        <flux:table.cell>
                            {{ $entry->isFile() ? \Illuminate\Support\Number::fileSize($entry->size ?? 0) : '—' }}
                        </flux:table.cell>
                        <flux:table.cell>
                            {{ \Illuminate\Support\Carbon::createFromTimestamp($entry->modifiedAt)->diffForHumans() }}</flux:table.cell
                        >
                        <flux:table.cell align="end">
                            <flux:dropdown>
                                <flux:button
                                    variant="subtle"
                                    size="sm"
                                    icon="ellipsis-horizontal"
                                    data-test="node-actions"
                                />

                                <flux:menu>
                                    @if ($entry->isFile())
                                        <flux:menu.item
                                            icon="arrow-down-tray"
                                            :href="route('shares.download', $share).'?'.http_build_query(['path' => $entry->path])"
                                            target="_blank"
                                        >
                                            {{ __('Download') }}
                                        </flux:menu.item>
                                        <flux:menu.item icon="eye" wire:click="preview(@js($entry->path))">
                                            {{ __('Preview') }}
                                        </flux:menu.item>
                                    @else
                                        <flux:menu.item
                                            icon="arrow-down-tray"
                                            :href="route('shares.zip', $share).'?'.http_build_query(['paths' => [$entry->path]])"
                                            target="_blank"
                                            data-test="folder-zip"
                                        >
                                            {{ __('Download as zip') }}
                                        </flux:menu.item>
                                    @endif

                                    @if ($this->canModify)
                                        <flux:menu.separator />
                                        <flux:menu.item
                                            icon="pencil-square"
                                            wire:click="startRename(@js($entry->path))"
                                            data-test="rename-action"
                                        >
                                            {{ __('Rename') }}
                                        </flux:menu.item>
                                        <flux:menu.item
                                            icon="arrows-pointing-out"
                                            wire:click="startMove(@js($entry->path))"
                                            data-test="move-action"
                                        >
                                            {{ __('Move') }}
                                        </flux:menu.item>
                                        <flux:menu.separator />
                                        <flux:menu.item
                                            icon="trash"
                                            variant="danger"
                                            wire:click="delete(@js($entry->path))"
                                            wire:confirm="{{ __('Move this item to the recycle bin?') }}"
                                            data-test="delete-action"
                                        >
                                            {{ __('Delete') }}
                                        </flux:menu.item>
                                    @endif
                                </flux:menu>
                            </flux:dropdown>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

    {{-- New folder --}}
    @if ($this->canModify)
        <flux:modal wire:model="showNewFolder" class="md:w-96">
            <form wire:submit="createFolder" class="flex flex-col gap-6">
                <flux:heading size="lg">{{ __('New folder') }}</flux:heading>
                <flux:input
                    wire:model="newFolderName"
                    :label="__('Folder name')"
                    data-test="new-folder-name"
                    autofocus
                />
                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button
                        type="submit"
                        variant="primary"
                        data-test="create-folder-button"
                        >{{ __('Create') }}</flux:button
                    >
                </div>
            </form>
        </flux:modal>
        {{-- Rename --}}
        <flux:modal wire:model="showRename" class="md:w-96">
            <form wire:submit="rename" class="flex flex-col gap-6">
                <flux:heading size="lg">{{ __('Rename') }}</flux:heading>
                <flux:input wire:model="renameName" :label="__('Name')" data-test="rename-name" autofocus />
                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button
                        type="submit"
                        variant="primary"
                        data-test="rename-button"
                        >{{ __('Rename') }}</flux:button
                    >
                </div>
            </form>
        </flux:modal>
        {{-- Move --}}
        <flux:modal wire:model="showMove" class="md:w-[32rem]">
            <div class="flex flex-col gap-4">
                <flux:heading size="lg">{{ __('Move to') }}</flux:heading>
                <div class="flex flex-col gap-1">
                    <flux:button
                        variant="subtle"
                        class="justify-start"
                        icon="home"
                        wire:click="moveTo('')"
                        data-test="move-target-root"
                    >
                        {{ $share->name }} ({{ __('root') }})
                    </flux:button>
                    @foreach ($this->moveTargets as $target)
                        <flux:button
                            variant="subtle"
                            class="justify-start"
                            icon="folder"
                            wire:click="moveTo(@js($target->path))"
                            data-test="move-target"
                        >
                            {{ $target->path }}
                        </flux:button>
                    @endforeach
                </div>
            </div>
        </flux:modal>
    @endif

    {{-- Preview --}}
    <flux:modal wire:model="showPreview" class="md:w-[48rem]">
        <div class="flex flex-col gap-4">
            <flux:heading size="lg" class="truncate">{{ $this->previewEntry?->name }}</flux:heading>

            @php ($entry = $this->previewEntry)
            @php ($mime = $entry?->mimeType ?? '')

            @if ($entry && $this->previewUrl)
                @if (str_starts_with($mime, 'image/'))
                    <img
                        src="{{ $this->previewUrl }}"
                        alt="{{ $entry->name }}"
                        class="max-h-[60vh] w-full rounded-md object-contain"
                    />
                @elseif ($mime === 'application/pdf' || str_starts_with($mime, 'text/'))
                    {{-- Sandboxed: a user-uploaded text/html file must never execute scripts. --}}
                    <iframe
                        src="{{ $this->previewUrl }}"
                        sandbox
                        class="h-[60vh] w-full rounded-md border border-zinc-200 dark:border-zinc-700"
                    ></iframe>
                @else
                    <flux:callout icon="document">
                        <flux:callout.text>{{ __('This file type cannot be previewed.') }}</flux:callout.text>
                    </flux:callout>
                    <flux:button
                        icon="arrow-down-tray"
                        :href="route('shares.download', $share).'?'.http_build_query(['path' => $entry->path])"
                        target="_blank"
                    >
                        {{ __('Download') }}
                    </flux:button>
                @endif
            @elseif ($entry)
                <flux:callout variant="danger" icon="exclamation-triangle">
                    <flux:callout.text> {{ __('This file is no longer available.') }} </flux:callout.text>
                </flux:callout>
            @endif
        </div>
    </flux:modal>

    {{-- Activity feed --}}
    <flux:modal wire:model="showActivity" class="md:w-[36rem]">
        {{-- Only query the feed while the modal is open, not on every re-render. --}}
        @if ($showActivity)
            <div class="flex flex-col gap-6">
                <div>
                    <flux:heading size="lg">{{ __('Activity') }}</flux:heading>
                    <flux:text
                        class="mt-2"
                        >{{ __('Recent activity in :share.', ['share' => $share->name]) }}</flux:text
                    >
                </div>

                <div class="flex flex-col gap-3" data-test="activity-feed">
                    @forelse ($this->activity as $entry)
                        <div class="flex items-start gap-3" wire:key="activity-{{ $entry->id }}">
                            <flux:icon icon="clock" class="mt-0.5 shrink-0 text-zinc-400" />
                            <div class="flex flex-col">
                                <flux:text size="sm">
                                    <span class="font-medium">{{ $entry->actor?->name ?? __('Someone') }}</span>
                                    {{ $entry->action->description() }}
                                    @if ($entry->subject)
                                        <span class="font-medium">{{ $entry->subject }}</span>
                                    @endif
                                </flux:text>
                                <flux:text
                                    size="sm"
                                    class="text-zinc-500"
                                    >{{ $entry->created_at?->diffForHumans() }}</flux:text
                                >
                            </div>
                        </div>
                    @empty
                        <flux:text class="text-zinc-500">{{ __('No activity yet.') }}</flux:text>
                    @endforelse
                </div>
            </div>
        @endif
    </flux:modal>

    {{-- Upload conflict prompt (Alpine-driven; resolves the uploader's pending choice) --}}
    @if ($this->canModify)
        <div
            x-show="conflict !== null"
            x-cloak
            class="fixed inset-0 z-50 flex items-center justify-center bg-zinc-900/50 p-4"
            data-test="upload-conflict"
        >
            <div class="flex w-full max-w-sm flex-col gap-6 rounded-lg bg-white p-6 shadow-xl dark:bg-zinc-800">
                <div>
                    <flux:heading size="lg">{{ __('File already exists') }}</flux:heading>
                    <flux:text class="mt-2">
                        {{ __('A file named') }}
                        <span class="font-medium" x-text="conflict?.name"></span> {{ __('already exists here.') }}
                    </flux:text>
                </div>
                <div class="flex flex-col gap-2">
                    <flux:button x-on:click="choose('keep_both')">{{ __('Keep both') }}</flux:button>
                    <flux:button variant="danger" x-on:click="choose('replace')">{{ __('Replace') }}</flux:button>
                    <flux:button variant="ghost" x-on:click="choose('skip')">{{ __('Skip') }}</flux:button>
                </div>
            </div>
        </div>
    @endif
</div>
