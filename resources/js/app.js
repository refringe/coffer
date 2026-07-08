import * as tus from "tus-js-client";

const MAX_CONCURRENT_UPLOADS = 3;

const DONE_DISMISS_DELAY = 4000;

// In-flight tus.Upload instances, keyed by item id, kept outside Alpine's reactive state.
const transports = new Map();

let nextId = 1;

/**
 * Resumable (tus) uploads for the share browser.
 *
 * The global `uploads` store owns the queue: items enter as `queued`, up to MAX_CONCURRENT_UPLOADS stream
 * concurrently in bounded chunks via tus-js-client, and each chunk is retried with backoff on transient failures.
 * The store lives in module state and is rendered by the persistent panel in the app layouts, so the queue survives
 * wire:navigate page swaps while uploads keep running. The share page mounts the `uploader` intake component, which
 * resolves name conflicts via a prompt and feeds files into the store.
 */
document.addEventListener("alpine:init", () => {
    window.Alpine.store("uploads", {
        items: [],

        enqueue({ file, endpoint, csrf, chunkSize, directory, onConflict }) {
            this.items.push({
                id: nextId++,
                name: file.name,
                progress: 0,
                status: "queued",
                file,
                endpoint,
                csrf,
                chunkSize,
                directory,
                onConflict,
            });

            this.pump();
        },

        pump() {
            let active = this.items.filter((item) => item.status === "uploading").length;

            for (const item of this.items) {
                if (active >= MAX_CONCURRENT_UPLOADS) {
                    break;
                }

                if (item.status === "queued") {
                    this.start(item);
                    active++;
                }
            }
        },

        start(item) {
            item.status = "uploading";

            const upload = new tus.Upload(item.file, {
                endpoint: item.endpoint,
                chunkSize: item.chunkSize,
                headers: { "X-CSRF-TOKEN": item.csrf, Accept: "application/json" },
                metadata: { filename: item.name, directory: item.directory, on_conflict: item.onConflict },
                retryDelays: [0, 1000, 3000, 5000],
                storeFingerprintForResuming: true,
                removeFingerprintOnSuccess: true,
                onProgress: (sent, total) => {
                    item.progress = total > 0 ? Math.round((sent / total) * 100) : 100;
                },
                onSuccess: () => this.finish(item),
                onError: () => this.fail(item),
            });

            transports.set(item.id, upload);

            // Resume an earlier interrupted upload of this same file (fingerprints cover name, size, and endpoint,
            // so the stored uploads are filtered to the folder the file was dropped in). A stale upload URL is
            // harmless: the server answers 404/410 and the client falls back to a fresh creation.
            upload
                .findPreviousUploads()
                .then((previous) => {
                    const match = previous.find(
                        (candidate) => (candidate.metadata?.directory ?? "") === item.directory,
                    );

                    if (match) {
                        upload.resumeFromPreviousUpload(match);
                    }
                })
                .finally(() => upload.start());
        },

        finish(item) {
            transports.delete(item.id);

            // A late callback for an item that was cancelled while its final chunk was in flight is ignored.
            if (!this.items.includes(item)) {
                return;
            }

            item.status = "done";

            // Finished uploads clear themselves. Failures stay until dismissed.
            window.setTimeout(() => this.dismiss(item.id), DONE_DISMISS_DELAY);

            window.dispatchEvent(
                new CustomEvent("coffer:upload-finished", {
                    detail: { endpoint: item.endpoint, directory: item.directory },
                }),
            );

            this.pump();
        },

        fail(item) {
            transports.delete(item.id);

            if (!this.items.includes(item)) {
                return;
            }

            item.status = "error";

            this.pump();
        },

        cancel(id) {
            // abort(true) terminates the upload server-side and clears the stored fingerprint; a failed termination
            // is swallowed because the scheduled stalled-upload purge sweeps the leftover partial.
            transports
                .get(id)
                ?.abort(true)
                .catch(() => {});
            transports.delete(id);

            this.dismiss(id);
            this.pump();
        },

        dismiss(id) {
            this.items = this.items.filter((item) => item.id !== id);
        },

        get hasActive() {
            return this.items.some((item) => item.status === "queued" || item.status === "uploading");
        },
    });

    // Closing or refreshing the tab kills in-flight uploads; wire:navigate swaps never trigger a real unload.
    window.addEventListener("beforeunload", (event) => {
        if (window.Alpine.store("uploads").hasActive) {
            event.preventDefault();
        }
    });

    window.Alpine.data("uploader", (endpoint, csrf, chunkSize) => ({
        dragging: false,
        conflict: null,

        async onDrop(event) {
            this.dragging = false;
            await this.queue(event.dataTransfer.files);
        },

        async onSelect(event) {
            await this.queue(event.target.files);
            event.target.value = "";
        },

        async queue(fileList) {
            const files = Array.from(fileList);

            if (files.length === 0) {
                return;
            }

            // The target folder is captured once at drop time, so a queued file lands where it was dropped even if
            // the user browses elsewhere before its upload slot opens.
            const directory = this.$wire.get("path") || "";
            const existing = await this.$wire.existingNames();

            files
                .filter((file) => !existing.includes(file.name))
                .forEach((file) => this.enqueue(file, directory, "keep_both"));

            for (const file of files.filter((file) => existing.includes(file.name))) {
                const choice = await this.askConflict(file.name);

                if (choice !== "skip") {
                    this.enqueue(file, directory, choice);
                }
            }
        },

        enqueue(file, directory, onConflict) {
            this.$store.uploads.enqueue({ file, endpoint, csrf, chunkSize, directory, onConflict });
        },

        onFinished(event) {
            if (event.detail.endpoint === endpoint) {
                this.$wire.reload();
            }
        },

        askConflict(name) {
            return new Promise((resolve) => {
                this.conflict = { name, resolve };
            });
        },

        choose(choice) {
            const resolve = this.conflict.resolve;
            this.conflict = null;
            resolve(choice);
        },
    }));
});
