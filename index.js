/**
 * Panel components of the VideoOptimizer plugin.
 *
 * All API calls go through the plugin's own Kirby API routes, the token stays
 * on the server. Uploads are sent in parts straight to VideoOptimizer storage
 * via presigned URLs, the file never passes through Kirby.
 */

const POLL_INTERVAL = 5000;

const api = {
  libraries: (vm) => vm.$api.get("videooptimizer/libraries").then((res) => res.data ?? []),
  videos: (vm, library) =>
    vm.$api.get("videooptimizer/videos", library ? { library } : {}).then((res) => res.data ?? []),
  video: (vm, uuid) => vm.$api.get("videooptimizer/videos/" + encodeURIComponent(uuid)).then((res) => res.data ?? null),
  initiate: (vm, data) => vm.$api.post("videooptimizer/upload/initiate", data).then((res) => res.data),
  complete: (vm, data) => vm.$api.post("videooptimizer/upload/complete", data).then((res) => res.data),
  import: (vm, data) => vm.$api.post("videooptimizer/import", data).then((res) => res.data)
};

const formatDuration = (seconds) => {
  if (!seconds && seconds !== 0) return "";
  const total = Math.round(seconds);
  return Math.floor(total / 60) + ":" + String(total % 60).padStart(2, "0");
};

/**
 * Uploads one part with progress, returns the ETag of the stored part
 */
const putPart = (url, blob, onProgress) =>
  new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open("PUT", url);
    xhr.upload.onprogress = (event) => event.lengthComputable && onProgress(event.loaded);
    xhr.onload = () => {
      const etag = xhr.getResponseHeader("ETag");
      if (xhr.status >= 200 && xhr.status < 300 && etag) resolve(etag);
      else reject(new Error("Upload failed (" + xhr.status + ")"));
    };
    xhr.onerror = () => reject(new Error("Upload failed"));
    xhr.send(blob);
  });

const videoCard = {
  props: { video: Object },
  computed: {
    image() {
      return this.video.poster_url || this.video.thumbnail_url || null;
    },
    status() {
      return this.video.status ?? "processing";
    }
  },
  methods: { formatDuration },
  template: `
    <div class="k-videooptimizer-card">
      <div class="k-videooptimizer-card-image">
        <img v-if="image && status === 'ready'" :src="image" alt="" loading="lazy">
        <k-icon v-else type="video" />
      </div>
      <div class="k-videooptimizer-card-text">
        <strong>{{ video.title || video.uuid }}</strong>
        <span>
          <span :data-status="status" class="k-videooptimizer-status">{{ $t('videooptimizer.status.' + status) }}</span>
          <template v-if="video.duration"> · {{ formatDuration(video.duration) }}</template>
          <template v-if="video.resolution"> · {{ video.resolution }}</template>
        </span>
      </div>
    </div>
  `
};

panel.plugin("summer-science-camp/videooptimizer", {
  fields: {
    videooptimizer: {
      components: { "k-videooptimizer-card": videoCard },
      props: {
        label: String,
        help: String,
        name: String,
        required: Boolean,
        disabled: Boolean,
        value: String,
        library: String,
        configured: Boolean
      },
      data() {
        return {
          video: null,
          picking: false,
          libraries: [],
          videos: [],
          selectedLibrary: this.library ?? "",
          search: "",
          loading: false,
          error: null,
          progress: null,
          importUrl: "",
          importing: false,
          timer: null
        };
      },
      computed: {
        filteredVideos() {
          const query = this.search.trim().toLowerCase();
          return query === ""
            ? this.videos
            : this.videos.filter((video) => (video.title ?? "").toLowerCase().includes(query));
        }
      },
      watch: {
        value: {
          immediate: true,
          handler() {
            this.loadSelected();
          }
        }
      },
      destroyed() {
        clearTimeout(this.timer);
      },
      methods: {
        async loadSelected() {
          clearTimeout(this.timer);

          if (!this.value || !this.configured) {
            this.video = null;
            return;
          }

          try {
            this.video = await api.video(this, this.value);
            this.error = null;
          } catch (error) {
            this.error = error.message;
            return;
          }

          // Keep the status current while the video is being encoded
          if (this.video?.status === "processing") {
            this.timer = setTimeout(() => this.loadSelected(), POLL_INTERVAL);
          }
        },
        async openPicker() {
          this.picking = true;
          this.error = null;

          if (this.libraries.length === 0) {
            try {
              this.libraries = await api.libraries(this);
            } catch (error) {
              this.error = error.message;
            }
          }

          await this.loadVideos();
        },
        async loadVideos() {
          this.loading = true;

          try {
            this.videos = await api.videos(this, this.selectedLibrary);
            this.error = null;
          } catch (error) {
            this.error = error.message;
          } finally {
            this.loading = false;
          }
        },
        choose(video) {
          this.picking = false;
          this.video = video;
          this.$emit("input", video.uuid);
        },
        remove() {
          this.video = null;
          this.$emit("input", "");
        },
        requireLibrary() {
          if (!this.selectedLibrary) {
            this.error = this.$t("videooptimizer.library.required");
            return false;
          }
          return true;
        },
        selectFile() {
          if (this.requireLibrary()) this.$refs.file.click();
        },
        async upload(event) {
          const file = event.target.files[0];
          event.target.value = "";

          if (!file || !this.requireLibrary()) return;

          this.error = null;
          this.progress = 0;

          try {
            const init = await api.initiate(this, {
              libraryId: this.selectedLibrary,
              filename: file.name,
              contentType: file.type || "video/mp4",
              fileSize: file.size
            });

            const partSize = init.partSize || Math.ceil(file.size / init.parts.length);
            const loaded = {};
            const parts = [];

            for (const part of init.parts) {
              const start = (part.partNumber - 1) * partSize;
              const etag = await putPart(part.url, file.slice(start, start + partSize), (bytes) => {
                loaded[part.partNumber] = bytes;
                const sum = Object.values(loaded).reduce((a, b) => a + b, 0);
                this.progress = Math.min(99, Math.round((sum / file.size) * 100));
              });
              parts.push({ partNumber: part.partNumber, etag });
            }

            await api.complete(this, {
              libraryId: this.selectedLibrary,
              uuid: init.uuid,
              key: init.key,
              uploadId: init.uploadId,
              title: file.name.replace(/\.[^.]+$/, ""),
              parts
            });

            this.progress = null;
            this.picking = false;
            this.$emit("input", init.uuid);
          } catch (error) {
            this.progress = null;
            this.error = error.message;
          }
        },
        async importVideo() {
          if (!this.requireLibrary() || !this.importUrl) return;

          this.importing = true;
          this.error = null;

          try {
            const video = await api.import(this, { libraryId: this.selectedLibrary, sourceUrl: this.importUrl });
            this.importUrl = "";
            this.picking = false;
            this.$emit("input", video.uuid);
          } catch (error) {
            this.error = error.message;
          } finally {
            this.importing = false;
          }
        }
      },
      template: `
        <k-field v-bind="$props" class="k-videooptimizer-field">
          <template #options>
            <k-button-group v-if="configured && !disabled" layout="collapsed">
              <k-button v-if="value" icon="cancel" size="xs" variant="filled" :text="$t('videooptimizer.remove')" @click="remove" />
              <k-button icon="video" size="xs" variant="filled" :text="value ? $t('videooptimizer.change') : $t('videooptimizer.select')" @click="picking ? (picking = false) : openPicker()" />
            </k-button-group>
          </template>

          <k-box v-if="!configured" theme="notice" :text="$t('videooptimizer.notConfigured')" />

          <template v-else>
            <k-box v-if="error" theme="negative" :text="error" class="k-videooptimizer-error" />

            <k-videooptimizer-card v-if="video" :video="video" />
            <k-box v-if="video && video.status === 'processing'" theme="info" :text="$t('videooptimizer.processing.hint')" />
            <k-empty v-else-if="!video && !picking" icon="video" :text="$t('videooptimizer.select')" @click="disabled || openPicker()" />

            <div v-if="picking" class="k-videooptimizer-picker">
              <div class="k-videooptimizer-toolbar">
                <select v-model="selectedLibrary" :aria-label="$t('videooptimizer.library')" @change="loadVideos">
                  <option value="">{{ $t('videooptimizer.library.all') }}</option>
                  <option v-for="library in libraries" :key="library.id" :value="library.id">{{ library.name }}</option>
                </select>
                <input v-model="search" type="search" :placeholder="$t('videooptimizer.search')" :aria-label="$t('videooptimizer.search')">
                <k-button icon="upload" size="sm" variant="filled" :text="$t('videooptimizer.upload')" :disabled="progress !== null" @click="selectFile" />
                <input ref="file" type="file" accept="video/*" hidden @change="upload">
              </div>

              <div class="k-videooptimizer-toolbar">
                <input v-model="importUrl" type="url" :placeholder="$t('videooptimizer.import.url')" :aria-label="$t('videooptimizer.import.url')">
                <k-button icon="download" size="sm" variant="filled" :text="$t('videooptimizer.import')" :disabled="importing || !importUrl" @click="importVideo" />
              </div>

              <div v-if="progress !== null" class="k-videooptimizer-progress">
                <span>{{ $t('videooptimizer.uploading') }} {{ progress }} %</span>
                <k-progress :value="progress" />
              </div>

              <p v-if="loading" class="k-videooptimizer-hint">{{ $t('videooptimizer.loading') }}</p>
              <p v-else-if="filteredVideos.length === 0" class="k-videooptimizer-hint">{{ $t('videooptimizer.empty') }}</p>
              <ul v-else class="k-videooptimizer-list">
                <li v-for="item in filteredVideos" :key="item.uuid">
                  <button type="button" :aria-pressed="item.uuid === value" @click="choose(item)">
                    <k-videooptimizer-card :video="item" />
                  </button>
                </li>
              </ul>
            </div>
          </template>
        </k-field>
      `
    }
  },

  blocks: {
    videooptimizer: {
      components: { "k-videooptimizer-card": videoCard },
      data() {
        return { video: null };
      },
      watch: {
        "content.video": {
          immediate: true,
          async handler(uuid) {
            this.video = null;
            if (!uuid) return;
            try {
              this.video = await api.video(this, uuid);
            } catch {
              this.video = { uuid, title: uuid, status: "failed" };
            }
          }
        }
      },
      template: `
        <div class="k-block-type-videooptimizer" @dblclick="open">
          <k-videooptimizer-card v-if="video" :video="video" />
          <k-empty v-else icon="video" :text="$t('videooptimizer.select')" @click="open" />
        </div>
      `
    }
  }
});
