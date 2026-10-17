/**
 * Reads a barcode through the camera with the browser's own BarcodeDetector
 * (Chrome and Edge on Android, ChromeOS, macOS and Windows): no download. In
 * browsers without it the Scan button is not shown, and the field still takes
 * a typed code or a USB/Bluetooth scanner, which types like a keyboard.
 */
const FORMATS = ['ean_13', 'ean_8', 'upc_a', 'upc_e'];

export const canScan = () => typeof window !== 'undefined' && 'BarcodeDetector' in window && !!navigator.mediaDevices?.getUserMedia;

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
    const supported = await window.BarcodeDetector.getSupportedFormats();
    const detector = new window.BarcodeDetector({ formats: FORMATS.filter((f) => supported.includes(f)) });
    stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false });
    if (stopped) return (stop(), null);

    video.srcObject = stream;
    await video.play();

    return new Promise((resolve) => {
      const tick = async () => {
        if (stopped) return resolve(null);
        try {
          const codes = await detector.detect(video);
          if (codes.length) {
            stop();
            return resolve(codes[0].rawValue);
          }
        } catch (e) {
          // A frame that is not ready yet; try the next one.
        }
        timer = setTimeout(tick, 150);
      };
      tick();
    });
  })();

  return { result, stop };
}
