import * as tus from "tus-js-client";

/**
 * Resumable (tus) uploader for the share browser.
 *
 * Resolve any name conflicts via a prompt that maps to the server's `on_conflict` strategies, then hand the file to
 * tus-js-client, which streams it to the app in bounded chunks. Each chunk is retried with backoff on transient
 * failures, and an interrupted upload of the same file resumes from the byte offset the server already holds.
 */
document.addEventListener("alpine:init", () => {
    window.Alpine.data("uploader", (endpoint, csrf, chunkSize) => ({
        items: [],
        dragging: false,
        conflict: null,
        nextId: 1,

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

            const existing = await this.$wire.existingNames();

            for (const file of files) {
                await this.uploadOne(file, existing);
            }

            this.$wire.reload();
        },

        async uploadOne(file, existing) {
            let onConflict = "keep_both";

            if (existing.includes(file.name)) {
                onConflict = await this.askConflict(file.name);

                if (onConflict === "skip") {
                    return;
                }
            }

            // Push first, then keep the reactive array element as our handle.
            const index = this.items.push({ id: this.nextId++, name: file.name, progress: 0, status: "uploading" }) - 1;
            const item = this.items[index];

            try {
                await this.tusUpload(file, onConflict, item);
                item.status = "done";
            } catch (error) {
                item.status = "error";
            }

            // Successful uploads clear themselves. Failures stay until dismissed.
            if (item.status === "done") {
                window.setTimeout(() => this.dismiss(item.id), 4000);
            }
        },

        tusUpload(file, onConflict, item) {
            const directory = this.$wire.get("path") || "";

            return new Promise((resolve, reject) => {
                const upload = new tus.Upload(file, {
                    endpoint,
                    chunkSize,
                    headers: { "X-CSRF-TOKEN": csrf, Accept: "application/json" },
                    metadata: { filename: file.name, directory, on_conflict: onConflict },
                    retryDelays: [0, 1000, 3000, 5000],
                    storeFingerprintForResuming: true,
                    removeFingerprintOnSuccess: true,
                    onProgress: (sent, total) => {
                        item.progress = total > 0 ? Math.round((sent / total) * 100) : 100;
                    },
                    onSuccess: () => resolve(),
                    onError: (error) => reject(error),
                });

                // Resume an earlier interrupted upload of this same file (fingerprints cover name, size, and
                // endpoint, so the stored uploads are filtered to the current folder). A stale upload URL is
                // harmless: the server answers 404/410 and the client falls back to a fresh creation.
                upload
                    .findPreviousUploads()
                    .then((previous) => {
                        const match = previous.find((candidate) => (candidate.metadata?.directory ?? "") === directory);

                        if (match) {
                            upload.resumeFromPreviousUpload(match);
                        }
                    })
                    .finally(() => upload.start());
            });
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

        dismiss(id) {
            this.items = this.items.filter((item) => item.id !== id);
        },
    }));
});
