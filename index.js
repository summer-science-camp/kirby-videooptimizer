/**
 * Panel components of the VideoOptimizer plugin.
 *
 * All API calls go through the plugin's own Kirby API routes, the token stays
 * on the server. Files are uploaded straight to VideoOptimizer storage via
 * presigned URLs, they never pass through Kirby.
 */

const POLL_INTERVAL = 5000;

const path = (...parts) => "videooptimizer/" + parts.map(encodeURIComponent).join("/");
const data = (request) => request.then((response) => response?.data ?? null);

const api = {
  libraries: (vm) => data(vm.$api.get(path("libraries"))),
  createLibrary: (vm, values) => data(vm.$api.post(path("libraries"), values)),
  updateLibrary: (vm, id, values) => data(vm.$api.patch(path("libraries", id), values)),
  deleteLibrary: (vm, id) => vm.$api.delete(path("libraries", id)),
  reprocessLibrary: (vm, id) => data(vm.$api.post(path("libraries", id, "reprocess"))),
  videos: (vm, library) => data(vm.$api.get(path("videos"), library ? { library } : {})),
  video: (vm, uuid) => data(vm.$api.get(path("videos", uuid))),
  renameVideo: (vm, uuid, title) => data(vm.$api.patch(path("videos", uuid), { title })),
  deleteVideo: (vm, uuid) => vm.$api.delete(path("videos", uuid)),
  selectThumbnail: (vm, uuid, index) => data(vm.$api.post(path("videos", uuid, "thumbnail"), { index })),
  initiatePoster: (vm, uuid, values) => data(vm.$api.post(path("videos", uuid, "poster", "initiate"), values)),
  completePoster: (vm, uuid, key) => data(vm.$api.post(path("videos", uuid, "poster", "complete"), { key })),
  selectPoster: (vm, uuid, source) => data(vm.$api.post(path("videos", uuid, "poster", "select"), { source })),
  deletePoster: (vm, uuid) => vm.$api.delete(path("videos", uuid, "poster")),
  initiateUpload: (vm, values) => data(vm.$api.post(path("upload", "initiate"), values)),
  completeUpload: (vm, values) => data(vm.$api.post(path("upload", "complete"), values)),
  importVideo: (vm, values) => data(vm.$api.post(path("import"), values))
};

const format = {
  duration(seconds) {
    if (!seconds && seconds !== 0) return "";
    const total = Math.round(seconds);
    return Math.floor(total / 60) + ":" + String(total % 60).padStart(2, "0");
  },
  bytes(bytes) {
    if (!bytes) return "0 MB";
    const units = ["B", "KB", "MB", "GB", "TB"];
    const exponent = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
    return (bytes / 1024 ** exponent).toFixed(exponent > 1 ? 1 : 0) + " " + units[exponent];
  },
  date(value) {
    return value ? new Date(value).toLocaleString(document.documentElement.lang || undefined) : "";
  }
};

/**
 * PUT to a presigned storage URL with progress, resolves with the ETag
 */
const put = (url, blob, onProgress = () => {}) =>
  new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open("PUT", url);
    if (blob.type) xhr.setRequestHeader("Content-Type", blob.type);
    xhr.upload.onprogress = (event) => event.lengthComputable && onProgress(event.loaded);
    xhr.onload = () =>
      xhr.status >= 200 && xhr.status < 300
        ? resolve(xhr.getResponseHeader("ETag"))
        : reject(new Error("Upload failed (" + xhr.status + ")"));
    xhr.onerror = () => reject(new Error("Upload failed"));
    xhr.send(blob);
  });

/**
 * Uploads a video in parts, returns the UUID of the new video
 */
const uploadVideo = async (vm, libraryId, file, onProgress) => {
  const init = await api.initiateUpload(vm, {
    libraryId,
    filename: file.name,
    contentType: file.type || "video/mp4",
    fileSize: file.size
  });

  const partSize = init.partSize || Math.ceil(file.size / init.parts.length);
  const loaded = {};
  const parts = [];

  for (const part of init.parts) {
    const start = (part.partNumber - 1) * partSize;
    const etag = await put(part.url, file.slice(start, start + partSize), (bytes) => {
      loaded[part.partNumber] = bytes;
      const sum = Object.values(loaded).reduce((a, b) => a + b, 0);
      onProgress(Math.min(99, Math.round((sum / file.size) * 100)));
    });

    if (!etag) throw new Error("Upload failed (missing ETag)");
    parts.push({ partNumber: part.partNumber, etag });
  }

  await api.completeUpload(vm, {
    libraryId,
    uuid: init.uuid,
    key: init.key,
    uploadId: init.uploadId,
    title: file.name.replace(/\.[^.]+$/, ""),
    parts
  });

  return init.uuid;
};

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
  methods: { format: (seconds) => format.duration(seconds) },
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
          <template v-if="video.duration"> · {{ format(video.duration) }}</template>
          <template v-if="video.resolution"> · {{ video.resolution }}</template>
        </span>
      </div>
    </div>
  `
};

/**
 * Library filter, search, upload and URL import
 */
const toolbar = {
  props: {
    libraries: { type: Array, default: () => [] },
    library: String,
    search: String,
    disabled: Boolean
  },
  data() {
    return { progress: null, importUrl: "", importing: false };
  },
  computed: {
    // Uploads need a library that manages its own media
    uploadLibrary() {
      return this.libraries.find((library) => library.id === this.library && library.media_managed !== false) ?? null;
    }
  },
  methods: {
    requireLibrary() {
      if (this.uploadLibrary) return true;
      this.$panel.notification.error(this.$t("videooptimizer.library.required"));
      return false;
    },
    selectFile() {
      if (this.requireLibrary()) this.$refs.file.click();
    },
    async upload(event) {
      const file = event.target.files[0];
      event.target.value = "";

      if (!file || !this.requireLibrary()) return;

      this.progress = 0;

      try {
        const uuid = await uploadVideo(this, this.library, file, (value) => (this.progress = value));
        this.$emit("added", uuid);
      } catch (error) {
        this.$panel.notification.error(error);
      } finally {
        this.progress = null;
      }
    },
    async importVideo() {
      if (!this.importUrl || !this.requireLibrary()) return;

      this.importing = true;

      try {
        const video = await api.importVideo(this, { libraryId: this.library, sourceUrl: this.importUrl });
        this.importUrl = "";
        this.$emit("added", video.uuid);
      } catch (error) {
        this.$panel.notification.error(error);
      } finally {
        this.importing = false;
      }
    }
  },
  template: `
    <div class="k-videooptimizer-toolbar-group">
      <div class="k-videooptimizer-toolbar">
        <select :value="library" :aria-label="$t('videooptimizer.library')" @change="$emit('update:library', $event.target.value)">
          <option value="">{{ $t('videooptimizer.library.all') }}</option>
          <option v-for="item in libraries" :key="item.id" :value="item.id">{{ item.name }}</option>
        </select>
        <input :value="search" type="search" :placeholder="$t('videooptimizer.search')" :aria-label="$t('videooptimizer.search')" @input="$emit('update:search', $event.target.value)">
        <k-button icon="upload" size="sm" variant="filled" :text="$t('videooptimizer.upload')" :disabled="disabled || progress !== null" @click="selectFile" />
        <input ref="file" type="file" accept="video/*" hidden @change="upload">
      </div>
      <div class="k-videooptimizer-toolbar">
        <input v-model="importUrl" type="url" :placeholder="$t('videooptimizer.import.url')" :aria-label="$t('videooptimizer.import.url')">
        <k-button icon="download" size="sm" variant="filled" :text="$t('videooptimizer.import')" :disabled="disabled || importing || !importUrl" @click="importVideo" />
      </div>
      <div v-if="progress !== null" class="k-videooptimizer-progress">
        <span>{{ $t('videooptimizer.uploading') }} {{ progress }} %</span>
        <k-progress :value="progress" />
      </div>
    </div>
  `
};

/**
 * Reloads the view while videos or posters are being processed
 */
const polling = {
  data() {
    return { pollTimer: null };
  },
  watch: {
    isProcessing: {
      immediate: true,
      handler(processing) {
        clearTimeout(this.pollTimer);
        if (processing) this.pollTimer = setTimeout(() => this.$panel.view.reload(), POLL_INTERVAL);
      }
    }
  },
  destroyed() {
    clearTimeout(this.pollTimer);
  }
};

const components = {
  "k-videooptimizer-card": videoCard,
  "k-videooptimizer-toolbar": toolbar
};

panel.plugin("circus-circuli/videooptimizer", {
  components: {
    "k-videooptimizer-videos-view": {
      components,
      mixins: [polling],
      props: {
        libraries: { type: Array, default: () => [] },
        videos: { type: Array, default: () => [] },
        library: String,
        configured: Boolean,
        error: String
      },
      data() {
        return { search: "" };
      },
      computed: {
        filtered() {
          const query = this.search.trim().toLowerCase();
          return query === "" ? this.videos : this.videos.filter((video) => (video.title ?? "").toLowerCase().includes(query));
        },
        isProcessing() {
          return this.videos.some((video) => video.status === "processing");
        }
      },
      methods: {
        filter(library) {
          this.$go("videos", { query: library ? { library } : {} });
        },
        added(uuid) {
          this.$go("videos/" + uuid);
        }
      },
      template: `
        <k-panel-inside class="k-videooptimizer-view">
          <k-header>
            {{ $t('videooptimizer.area') }}
            <template #buttons>
              <k-button icon="folder" size="sm" variant="filled" :text="$t('videooptimizer.libraries')" link="videos/libraries" />
            </template>
          </k-header>

          <k-box v-if="!configured" theme="notice" :text="$t('videooptimizer.notConfigured')" />
          <k-box v-else-if="error" theme="negative" :text="error" />

          <template v-else>
            <k-videooptimizer-toolbar :libraries="libraries" :library="library" :search.sync="search" @update:library="filter" @added="added" />

            <ul v-if="filtered.length" class="k-videooptimizer-grid">
              <li v-for="video in filtered" :key="video.uuid">
                <k-link :to="'videos/' + video.uuid">
                  <k-videooptimizer-card :video="video" />
                </k-link>
              </li>
            </ul>
            <k-empty v-else icon="video" :text="$t('videooptimizer.empty')" />
          </template>
        </k-panel-inside>
      `
    },

    "k-videooptimizer-video-view": {
      components,
      mixins: [polling],
      props: {
        uuid: String,
        video: Object,
        library: Object,
        thumbnails: { type: Array, default: () => [] },
        embedUrl: String,
        error: String
      },
      data() {
        return { posterUploading: false };
      },
      computed: {
        title() {
          return this.video?.title || this.uuid;
        },
        isProcessing() {
          return this.video?.status === "processing" || this.video?.poster?.custom_status === "processing";
        },
        canEditPoster() {
          return this.video?.status === "ready" && this.library?.media_managed !== false;
        },
        poster() {
          return this.video?.poster ?? { source: "thumbnail", custom_status: "none" };
        },
        details() {
          return [
            [this.$t("videooptimizer.library"), this.library?.name],
            [this.$t("videooptimizer.duration"), format.duration(this.video?.duration)],
            [this.$t("videooptimizer.resolution"), this.video?.resolution],
            [this.$t("videooptimizer.views"), this.video?.views],
            [this.$t("videooptimizer.created"), format.date(this.video?.created_at)],
            [this.$t("videooptimizer.uuid"), this.uuid]
          ].filter(([, value]) => value !== undefined && value !== null && value !== "");
        }
      },
      methods: {
        async run(action, message = this.$t("videooptimizer.saved")) {
          try {
            await action();
            this.$panel.notification.success(message);
            this.$panel.view.reload();
          } catch (error) {
            this.$panel.notification.error(error);
          }
        },
        rename() {
          this.$panel.dialog.open({
            component: "k-form-dialog",
            props: {
              fields: { title: { label: this.$t("videooptimizer.title"), type: "text", required: true } },
              value: { title: this.video.title },
              submitButton: this.$t("videooptimizer.rename")
            },
            on: {
              submit: async (value) => {
                this.$panel.dialog.close();
                await this.run(() => api.renameVideo(this, this.uuid, value.title));
              }
            }
          });
        },
        remove() {
          this.$panel.dialog.open({
            component: "k-remove-dialog",
            props: { text: this.$t("videooptimizer.delete.confirm", { title: this.title }) },
            on: {
              submit: async () => {
                try {
                  await api.deleteVideo(this, this.uuid);
                  this.$panel.dialog.close();
                  this.$panel.notification.success(this.$t("videooptimizer.deleted"));
                  this.$go("videos");
                } catch (error) {
                  this.$panel.notification.error(error);
                }
              }
            }
          });
        },
        selectFrame(index) {
          this.run(() => api.selectThumbnail(this, this.uuid, index));
        },
        selectPoster(source) {
          this.run(() => api.selectPoster(this, this.uuid, source));
        },
        deletePoster() {
          this.run(() => api.deletePoster(this, this.uuid));
        },
        async uploadPoster(event) {
          const file = event.target.files[0];
          event.target.value = "";
          if (!file) return;

          if (!["image/jpeg", "image/png", "image/webp"].includes(file.type)) {
            this.$panel.notification.error(this.$t("videooptimizer.poster.type"));
            return;
          }

          this.posterUploading = true;

          await this.run(async () => {
            const init = await api.initiatePoster(this, this.uuid, { contentType: file.type, fileSize: file.size });
            await put(init.uploadUrl, file);
            await api.completePoster(this, this.uuid, init.key);
          });

          this.posterUploading = false;
        }
      },
      template: `
        <k-panel-inside class="k-videooptimizer-view">
          <k-header :editable="!!video" @edit="rename">
            {{ title }}
            <template v-if="video" #buttons>
              <k-button icon="trash" size="sm" variant="filled" theme="negative" :text="$t('videooptimizer.delete')" @click="remove" />
            </template>
          </k-header>

          <k-box v-if="error" theme="negative" :text="error" />

          <div v-else class="k-videooptimizer-detail">
            <section class="k-videooptimizer-section">
              <h2 class="k-label">{{ $t('videooptimizer.preview') }}</h2>
              <div class="k-videooptimizer-player">
                <iframe v-if="video.status === 'ready'" :src="embedUrl" :title="title" allow="fullscreen; picture-in-picture" allowfullscreen></iframe>
                <k-box v-else :theme="video.status === 'failed' ? 'negative' : 'info'" :text="video.status === 'failed' ? (video.error || $t('videooptimizer.status.failed')) : $t('videooptimizer.processing.hint')" />
              </div>
            </section>

            <section class="k-videooptimizer-section">
              <h2 class="k-label">{{ $t('videooptimizer.details') }}</h2>
              <dl class="k-videooptimizer-details">
                <div>
                  <dt>Status</dt>
                  <dd><span :data-status="video.status" class="k-videooptimizer-status">{{ $t('videooptimizer.status.' + video.status) }}</span></dd>
                </div>
                <div v-for="[label, value] in details" :key="label">
                  <dt>{{ label }}</dt>
                  <dd>{{ value }}</dd>
                </div>
              </dl>
            </section>

            <section class="k-videooptimizer-section k-videooptimizer-section-wide">
              <h2 class="k-label">{{ $t('videooptimizer.poster') }}</h2>

              <k-box v-if="library && library.media_managed === false" theme="info" :text="$t('videooptimizer.library.delivery')" />
              <k-box v-else-if="!canEditPoster" theme="info" :text="$t('videooptimizer.poster.notReady')" />

              <template v-else>
                <h3 class="k-videooptimizer-subheading">{{ $t('videooptimizer.poster.frames') }}</h3>
                <ul class="k-videooptimizer-frames">
                  <li v-for="frame in thumbnails" :key="frame.index">
                    <button type="button" :aria-pressed="String(poster.source === 'thumbnail' && frame.url === video.thumbnail_url)" @click="selectFrame(frame.index)">
                      <img :src="frame.url" :alt="$t('videooptimizer.poster') + ' ' + (frame.index + 1)" loading="lazy">
                    </button>
                  </li>
                </ul>

                <h3 class="k-videooptimizer-subheading">{{ $t('videooptimizer.poster.custom') }}</h3>
                <div class="k-videooptimizer-custom-poster">
                  <img v-if="poster.custom_status === 'ready' && poster.custom_url" :src="poster.custom_url" alt="" :data-active="poster.source === 'custom'">
                  <p v-else-if="poster.custom_status === 'processing'" class="k-videooptimizer-hint">{{ $t('videooptimizer.poster.processing') }}</p>

                  <k-button-group>
                    <k-button icon="upload" size="sm" variant="filled" :text="$t('videooptimizer.poster.upload')" :disabled="posterUploading" @click="$refs.poster.click()" />
                    <k-button v-if="poster.custom_status === 'ready' && poster.source !== 'custom'" icon="check" size="sm" variant="filled" :text="$t('videooptimizer.poster.useCustom')" @click="selectPoster('custom')" />
                    <k-button v-if="poster.source === 'custom'" icon="image" size="sm" variant="filled" :text="$t('videooptimizer.poster.useFrame')" @click="selectPoster('thumbnail')" />
                    <k-button v-if="poster.custom_status === 'ready'" icon="trash" size="sm" variant="filled" theme="negative" :text="$t('videooptimizer.poster.deleteCustom')" @click="deletePoster" />
                  </k-button-group>
                  <input ref="poster" type="file" accept="image/jpeg,image/png,image/webp" hidden @change="uploadPoster">
                </div>
              </template>
            </section>
          </div>
        </k-panel-inside>
      `
    },

    "k-videooptimizer-libraries-view": {
      props: {
        libraries: { type: Array, default: () => [] },
        encodings: { type: Object, default: () => ({ codecs: [], resolutions: [] }) },
        configured: Boolean,
        error: String
      },
      methods: {
        format: (bytes) => format.bytes(bytes),
        options(list) {
          return (list ?? []).map((option) => ({
            value: option.key,
            text: option.label + (option.access === "addon" ? " (" + this.$t("videooptimizer.library.addon") + ")" : ""),
            disabled: option.available === false
          }));
        },
        split(value) {
          return value ? value.split(",").map((item) => item.trim()).filter(Boolean) : [];
        },
        async run(action, message = this.$t("videooptimizer.saved")) {
          try {
            await action();
            this.$panel.dialog.close();
            if (message) this.$panel.notification.success(message);
            this.$panel.view.reload();
          } catch (error) {
            this.$panel.notification.error(error);
          }
        },
        edit(library = null) {
          this.$panel.dialog.open({
            component: "k-form-dialog",
            props: {
              size: "large",
              fields: {
                name: { label: this.$t("videooptimizer.library.name"), type: "text", required: true },
                description: { label: this.$t("videooptimizer.library.description"), type: "textarea", buttons: false },
                codec: {
                  label: this.$t("videooptimizer.library.codecs"),
                  type: "checkboxes",
                  columns: 3,
                  options: this.options(this.encodings.codecs),
                  help: library ? this.$t("videooptimizer.library.ladder.help") : null
                },
                resolutions: {
                  label: this.$t("videooptimizer.library.resolutions"),
                  type: "checkboxes",
                  columns: 4,
                  options: this.options(this.encodings.resolutions)
                }
              },
              value: {
                name: library?.name ?? "",
                description: library?.description ?? "",
                codec: this.split(library?.codec),
                resolutions: this.split(library?.resolutions)
              },
              submitButton: library ? this.$t("save") : this.$t("videooptimizer.library.add")
            },
            on: {
              submit: (value) =>
                this.run(() => (library ? api.updateLibrary(this, library.id, value) : api.createLibrary(this, value)))
            }
          });
        },
        reprocess(library) {
          this.$panel.dialog.open({
            component: "k-text-dialog",
            props: {
              text: this.$t("videooptimizer.library.reprocess.confirm", { name: library.name }),
              submitButton: this.$t("videooptimizer.library.reprocess")
            },
            on: {
              submit: () =>
                this.run(async () => {
                  const result = await api.reprocessLibrary(this, library.id);
                  this.$panel.notification.success(this.$t("videooptimizer.library.reprocess.done", { count: result?.queued ?? 0 }));
                }, null)
            }
          });
        },
        remove(library) {
          this.$panel.dialog.open({
            component: "k-remove-dialog",
            props: { text: this.$t("videooptimizer.library.delete.confirm", { name: library.name, count: library.video_count ?? 0 }) },
            on: { submit: () => this.run(() => api.deleteLibrary(this, library.id)) }
          });
        },
        dropdown(library) {
          return [
            { icon: "edit", text: this.$t("videooptimizer.library.edit"), click: () => this.edit(library) },
            ...(library.media_managed !== false
              ? [{ icon: "refresh", text: this.$t("videooptimizer.library.reprocess"), click: () => this.reprocess(library) }]
              : []),
            "-",
            { icon: "trash", text: this.$t("videooptimizer.delete"), click: () => this.remove(library) }
          ];
        }
      },
      template: `
        <k-panel-inside class="k-videooptimizer-view">
          <k-header>
            {{ $t('videooptimizer.libraries') }}
            <template v-if="configured && !error" #buttons>
              <k-button icon="add" size="sm" variant="filled" :text="$t('videooptimizer.library.add')" @click="edit()" />
            </template>
          </k-header>

          <k-box v-if="!configured" theme="notice" :text="$t('videooptimizer.notConfigured')" />
          <k-box v-else-if="error" theme="negative" :text="error" />
          <k-empty v-else-if="libraries.length === 0" icon="folder" :text="$t('videooptimizer.libraries.empty')" @click="edit()" />

          <ul v-else class="k-videooptimizer-libraries">
            <li v-for="library in libraries" :key="library.id">
              <div class="k-videooptimizer-library-text">
                <k-link :to="'videos?library=' + library.id"><strong>{{ library.name }}</strong></k-link>
                <span v-if="library.description">{{ library.description }}</span>
                <span class="k-videooptimizer-hint">
                  {{ $t('videooptimizer.library.videos', { count: library.video_count ?? 0 }) }} · {{ format(library.storage_usage) }}
                  <template v-if="library.codec"> · {{ library.codec }}</template>
                  <template v-if="library.resolutions"> · {{ library.resolutions }}</template>
                </span>
                <span v-if="library.media_managed === false" class="k-videooptimizer-hint">{{ $t('videooptimizer.library.delivery') }}</span>
              </div>
              <k-options-dropdown :options="dropdown(library)" />
            </li>
          </ul>
        </k-panel-inside>
      `
    }
  },

  fields: {
    videooptimizer: {
      components,
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
          timer: null
        };
      },
      computed: {
        filteredVideos() {
          const query = this.search.trim().toLowerCase();
          return query === "" ? this.videos : this.videos.filter((video) => (video.title ?? "").toLowerCase().includes(query));
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
              this.libraries = (await api.libraries(this)) ?? [];
            } catch (error) {
              this.error = error.message;
            }
          }

          await this.loadVideos();
        },
        async loadVideos() {
          this.loading = true;

          try {
            this.videos = (await api.videos(this, this.selectedLibrary)) ?? [];
            this.error = null;
          } catch (error) {
            this.error = error.message;
          } finally {
            this.loading = false;
          }
        },
        filter(library) {
          this.selectedLibrary = library;
          this.loadVideos();
        },
        choose(video) {
          this.picking = false;
          this.video = video;
          this.$emit("input", video.uuid);
        },
        added(uuid) {
          this.picking = false;
          this.$emit("input", uuid);
        },
        remove() {
          this.video = null;
          this.$emit("input", "");
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
              <k-videooptimizer-toolbar :libraries="libraries" :library="selectedLibrary" :search.sync="search" :disabled="disabled" @update:library="filter" @added="added" />

              <p v-if="loading" class="k-videooptimizer-hint">{{ $t('videooptimizer.loading') }}</p>
              <p v-else-if="filteredVideos.length === 0" class="k-videooptimizer-hint">{{ $t('videooptimizer.empty') }}</p>
              <ul v-else class="k-videooptimizer-list">
                <li v-for="item in filteredVideos" :key="item.uuid">
                  <button type="button" :aria-pressed="String(item.uuid === value)" @click="choose(item)">
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
      components,
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
        <div class="k-block-type-videooptimizer-preview" @dblclick="open">
          <k-videooptimizer-card v-if="video" :video="video" />
          <k-empty v-else icon="video" :text="$t('videooptimizer.select')" @click="open" />
        </div>
      `
    }
  }
});
