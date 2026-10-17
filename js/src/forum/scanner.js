/**
 * Reads a barcode through the camera. Browsers with their own reader
 * (BarcodeDetector: Chrome and Edge on Android, macOS and ChromeOS) use it,
 * with nothing to download. Everywhere else a small reader (Quagga2, about
 * 43 KB gzipped) loads the first time Scan is tapped. Either way the field
 * still takes a typed code or a USB/Bluetooth scanner, which types like a
 * keyboard.
 */
const FORMATS = ['ean_13', 'ean_8', 'upc_a', 'upc_e'];

export const canScan = () => typeof window !== 'undefined' && !!navigator.mediaDevices?.getUserMedia && window.isSecureContext !== false;

async function nativeReader() {
  if (!('BarcodeDetector' in window)) return null;
  try {
    const supported = await window.BarcodeDetector.getSupportedFormats();
    const formats = FORMATS.filter((f) => supported.includes(f));
    if (!formats.length) return null;
    const detector = new window.BarcodeDetector({ formats });
    return async (video) => (await detector.detect(video))[0]?.rawValue || null;
  } catch (e) {
    return null;
  }
}

async function fallbackReader() {
  const { default: decode } = await import('./quagga');
  const canvas = document.createElement('canvas');

  return async (video) => {
    if (!video.videoWidth) return null;
    // At most 1280 wide: enough for a barcode held at arm's length, small enough to decode quickly.
    const scale = Math.min(1, 1280 / video.videoWidth);
    canvas.width = Math.round(video.videoWidth * scale);
    canvas.height = Math.round(video.videoHeight * scale);
    canvas.getContext('2d', { willReadFrequently: true }).drawImage(video, 0, 0, canvas.width, canvas.height);
    return decode(canvas);
  };
}

/** Start the camera in `video`; resolves with the first code read. stop() ends it early. */
export function scan(video) {
  let stream = null;
  let stopped = false;
  let timer = null;

  const stop = () => {
    stopped = true;
    clearTimeout(timer);
    stream && stream.getTracks().forEach((track) => track.stop());
  };

  const result = (async () => {
    const read = (await nativeReader()) || (await fallbackReader());
    // The fallback can misread a blurry frame, so its code must be read twice in a row.
    const confirmTwice = !('BarcodeDetector' in window);

    stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment', width: { ideal: 1280 } }, audio: false });
    if (stopped) return (stop(), null);

    video.srcObject = stream;
    await video.play();

    let last = null;
    return new Promise((resolve) => {
      const tick = async () => {
        if (stopped) return resolve(null);
        try {
          const code = await read(video);
          if (code && (!confirmTwice || code === last)) {
            stop();
            return resolve(code);
          }
          last = code;
        } catch (e) {
          // A frame that is not ready yet; try the next one.
        }
        timer = setTimeout(tick, confirmTwice ? 250 : 150);
      };
      tick();
    });
  })();

  return { result, stop };
}
