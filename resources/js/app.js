/**
 * Through-app uploader for the share browser.
 *
 * Resolve any name conclicts via a prompt that maps to the server's `on_conflict` strategies, then POST the file
 * straight to the app, which streams it to the share's local storage directory. Progress is reported by the browser as
 * the body uploads.
 */
document.addEventListener("alpine:init", () => {
    window.Alpine.data("uploader", (uploadUrl, csrf) => ({
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

            const form = new FormData();
            form.append("file", file);
            form.append("directory", this.$wire.get("path") || "");
            form.append("on_conflict", onConflict);

            try {
                const status = await this.post(uploadUrl, form, item);
                item.status = status === "completed" || status === "skipped" ? "done" : "error";
            } catch (error) {
                item.status = "error";
            }

            // Successful uploads clear themselves. Failures stay until dismissed.
            if (item.status === "done") {
                window.setTimeout(() => this.dismiss(item.id), 4000);
            }
        },

        post(url, form, item) {
            return new Promise((resolve, reject) => {
                const request = new XMLHttpRequest();
                request.open("POST", url, true);
                request.setRequestHeader("X-CSRF-TOKEN", csrf);
                request.setRequestHeader("Accept", "application/json");

                request.upload.onprogress = (event) => {
                    if (event.lengthComputable) {
                        item.progress = Math.round((event.loaded / event.total) * 100);
                    }
                };

                request.onload = () => {
                    // A 2xx is necessary but not sufficient: a session that expired mid-upload is redirected to a 200
                    // login page, so only a JSON body carrying a known status counts as a real upload result.
                    if (request.status >= 200 && request.status < 300) {
                        try {
                            const status = JSON.parse(request.responseText).status;

                            if (typeof status === "string") {
                                resolve(status);

                                return;
                            }
                        } catch (error) {
                            // Falls through to the failure path below.
                        }
                    }

                    reject(new Error("Upload failed"));
                };

                request.onerror = () => reject(new Error("Upload failed"));
                request.send(form);
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
