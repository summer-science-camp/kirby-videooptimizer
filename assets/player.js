/**
 * VideoOptimizer player
 *
 * Plays the adaptive HLS stream: natively where the browser supports it
 * (Safari, iOS), with hls.js (loaded on demand) elsewhere, and falls back to
 * the progressive MP4. Facades load the player only after a click.
 */

const reducedMotion = () => window.matchMedia("(prefers-reduced-motion: reduce)").matches;

let hlsLoader = null;

const loadHls = (url) => {
  hlsLoader ??= new Promise((resolve, reject) => {
    const script = document.createElement("script");
    script.src = url;
    script.onload = () => resolve(window.Hls);
    script.onerror = reject;
    document.head.append(script);
  });

  return hlsLoader;
};

const attachSource = async (video, config, play) => {
  const start = () => play && video.play().catch(() => {});

  if (config.hls && video.canPlayType("application/vnd.apple.mpegurl")) {
    video.src = config.hls;
    start();
    return;
  }

  if (config.hls && config.hlsJs && "MediaSource" in window) {
    try {
      const Hls = await loadHls(config.hlsJs);

      if (Hls?.isSupported()) {
        const hls = new Hls();
        hls.loadSource(config.hls);
        hls.attachMedia(video);
        hls.on(Hls.Events.MANIFEST_PARSED, start);
        video.videooptimizerHls = hls;
        return;
      }
    } catch {
      // Fall back to MP4 below
    }
  }

  if (config.mp4) {
    video.src = config.mp4;
    start();
  }
};

const createVideo = (config) => {
  const video = document.createElement("video");
  video.playsInline = true;
  video.controls = config.controls !== false;
  video.loop = Boolean(config.loop);
  video.muted = Boolean(config.muted);
  video.setAttribute("aria-label", config.title ?? "");
  if (config.poster) video.poster = config.poster;
  video.style.cssText = "position:absolute;inset:0;width:100%;height:100%;object-fit:contain";
  return video;
};

const createIframe = (config) => {
  const url = new URL(config.embedUrl);
  // The click is a user gesture, so playback may start right away
  url.searchParams.set("autoplay", "1");

  const iframe = document.createElement("iframe");
  iframe.src = url.toString();
  iframe.title = config.title ?? "";
  iframe.allow = "autoplay; fullscreen; picture-in-picture";
  iframe.allowFullscreen = true;
  iframe.referrerPolicy = "strict-origin-when-cross-origin";
  iframe.setAttribute("sandbox", "allow-scripts allow-same-origin allow-popups allow-presentation");
  iframe.style.cssText = "position:absolute;inset:0;width:100%;height:100%;border:0";
  return iframe;
};

const init = (element) => {
  if (element.videooptimizerReady) return;
  element.videooptimizerReady = true;

  const config = JSON.parse(element.dataset.videooptimizer);
  const facade = element.querySelector("[data-videooptimizer-play]");

  if (facade) {
    // Load the player only when the visitor asks for it
    facade.addEventListener(
      "click",
      () => {
        const player = config.player === "embed" ? createIframe(config) : createVideo(config);

        facade.replaceWith(player);

        if (player instanceof HTMLVideoElement) {
          // The click is a user gesture, so playback with sound is allowed
          attachSource(player, config, true);
        }

        player.focus();
      },
      { once: true }
    );
    return;
  }

  const video = element.querySelector("video");

  if (video) {
    // Browsers only allow autoplay without sound, and visitors who prefer
    // reduced motion start the video themselves
    const autoplay = Boolean(config.autoplay) && !reducedMotion();
    video.muted = video.muted || autoplay;
    attachSource(video, config, autoplay);
  }
};

const initAll = () => document.querySelectorAll("[data-videooptimizer]").forEach(init);

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", initAll);
} else {
  initAll();
}
