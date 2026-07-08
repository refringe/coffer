{{-- Floating upload queue, rendered from the global `uploads` Alpine store and persisted across page navigations. --}}
<div
    x-data
    x-cloak
    x-show="$store.uploads.items.length > 0"
    class="fixed right-4 bottom-4 z-40 flex w-80 flex-col gap-2 rounded-lg bg-white p-4 shadow-xl dark:bg-zinc-800"
    data-test="upload-progress"
>
    <template x-for="item in $store.uploads.items" :key="item.id">
        <div
            class="flex items-center gap-3 rounded-md bg-zinc-100 px-3 py-2 text-sm dark:bg-zinc-700"
            data-test="upload-item"
        >
            <div class="min-w-0 flex-1">
                <div class="flex items-center justify-between gap-3">
                    <span class="truncate" x-text="item.name"></span>
                    <span
                        class="shrink-0 tabular-nums"
                        :class="item.status === 'error' ? 'text-red-500' : 'text-zinc-500'"
                        :title="item.error || ''"
                        x-text="item.status === 'error' ? '{{ __('Failed') }}' : (item.status === 'done' ? '{{ __('Done') }}' : (item.status === 'queued' ? '{{ __('Queued') }}' : item.progress + '%'))"
                    ></span>
                </div>

                {{-- Progress bar (visible while the upload is in flight) --}}
                <div
                    x-show="item.status === 'uploading'"
                    class="mt-1.5 h-1 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-600"
                >
                    <div
                        class="h-full rounded-full bg-accent transition-[width] duration-200 ease-out"
                        :style="`width: ${item.progress}%`"
                    ></div>
                </div>
            </div>

            <button
                type="button"
                x-on:click="$store.uploads.cancel(item.id)"
                class="shrink-0 cursor-pointer rounded p-1 text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200"
                :aria-label="item.status === 'queued' || item.status === 'uploading' ? '{{ __('Cancel') }}' : '{{ __('Dismiss') }}'"
                data-test="dismiss-upload"
            >
                <flux:icon.x-mark class="size-4" />
            </button>
        </div>
    </template>
</div>
