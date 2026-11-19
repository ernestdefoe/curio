import Quagga from '@ericblade/quagga2';

/**
 * The fallback barcode reader (Quagga2, MIT): only downloaded when someone
 * taps Scan in a browser without its own reader (Safari, Firefox, every
 * iPhone browser, Chrome and Edge on Windows and Linux). One frame at a time.
 */
const READERS = ['ean_reader', 'ean_8_reader', 'upc_reader', 'upc_e_reader'];

/**
 * Greyscale, then stretched so the darkest bars are black and the paper is
 * white: a dim room or a glossy box reads like a clean print.
 */
export function stretch(context, width, height) {
  const image = context.getImageData(0, 0, width, height);
  const px = image.data;
  const histogram = new Uint32Array(256);

  for (let i = 0; i < px.length; i += 4) {
    const y = (px[i] * 77 + px[i + 1] * 150 + px[i + 2] * 29) >> 8;
    px[i] = y;
    histogram[y]++;
  }

  // Ignore the darkest and brightest 1%: glare and shadows.
  const clip = (width * height) / 100;
  let low = 0;
  let high = 255;
  for (let n = 0; low < 255 && (n += histogram[low]) < clip; low++);
  for (let n = 0; high > 0 && (n += histogram[high]) < clip; high--);
  const range = Math.max(1, high - low);

  for (let i = 0; i < px.length; i += 4) {
    const v = Math.max(0, Math.min(255, ((px[i] - low) * 255) / range));
    px[i] = px[i + 1] = px[i + 2] = v;
  }

  context.putImageData(image, 0, 0);
}

function decodeAt(src, size) {
  return new Promise((resolve) => {
    Quagga.decodeSingle({ src, numOfWorkers: 0, locate: true, inputStream: { size }, decoder: { readers: READERS } }, (result) =>
      resolve(result && result.codeResult ? result.codeResult.code : null)
    );
  });
}

/** The middle of the frame (where the guide line is), twice the size: a barcode held far away. */
function centre(canvas) {
  const crop = document.createElement('canvas');
  const w = Math.round(canvas.width / 2);
  const h = Math.round(canvas.height / 2);
  crop.width = w * 2;
  crop.height = h * 2;
  crop.getContext('2d').drawImage(canvas, w / 2, h / 2, w, h, 0, 0, w * 2, h * 2);
  return crop.toDataURL('image/jpeg', 0.9);
}

/** A frame (canvas) → the code on it, or null: the whole frame, then a closer look at its middle. */
export default async function decode(canvas) {
  stretch(canvas.getContext('2d', { willReadFrequently: true }), canvas.width, canvas.height);

  return (await decodeAt(canvas.toDataURL('image/jpeg', 0.9), 800)) || decodeAt(centre(canvas), Math.min(1600, canvas.width));
}
