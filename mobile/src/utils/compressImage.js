/**
 * Compress + mild downscale for DO camera captures.
 * Keeps sensor text readable while lowering RAM / upload size.
 */
import * as ImageManipulator from 'expo-image-manipulator';
import { Image } from 'react-native';

/** JPEG quality 0-1. Sensor text still readable ~0.5-0.6 */
const DEFAULT_COMPRESS = 0.55;

/** Cap long edge so full-res phone photos do not blow memory on Android. */
const MAX_EDGE = 1600;

function getImageSize(uri) {
  return new Promise((resolve) => {
    if (!uri) {
      resolve({ width: 0, height: 0 });
      return;
    }
    Image.getSize(
      uri,
      (width, height) => resolve({ width: width || 0, height: height || 0 }),
      () => resolve({ width: 0, height: 0 })
    );
  });
}

/**
 * @param {string} uri - local file URI from camera
 * @param {number} [quality]
 * @param {{ width?: number, height?: number }} [knownSize]
 * @returns {Promise<string>} compressed JPEG URI (or original on failure)
 */
export async function compressImageOnly(uri, quality = DEFAULT_COMPRESS, knownSize = null) {
  if (!uri || typeof uri !== 'string') return uri;

  const compress = Math.min(1, Math.max(0.1, quality));

  try {
    let width = knownSize?.width || 0;
    let height = knownSize?.height || 0;
    if (!width || !height) {
      const size = await getImageSize(uri);
      width = size.width;
      height = size.height;
    }

    const longEdge = Math.max(width, height);
    const actions =
      longEdge > MAX_EDGE
        ? width >= height
          ? [{ resize: { width: MAX_EDGE } }]
          : [{ resize: { height: MAX_EDGE } }]
        : [];

    const result = await ImageManipulator.manipulateAsync(uri, actions, {
      compress,
      format: ImageManipulator.SaveFormat.JPEG,
    });
    return result?.uri || uri;
  } catch (err) {
    try {
      const result = await ImageManipulator.manipulateAsync(uri, [], {
        compress,
        format: ImageManipulator.SaveFormat.JPEG,
      });
      return result?.uri || uri;
    } catch (err2) {
      console.warn('Image compress skipped:', err2?.message || err?.message || err2);
      return uri;
    }
  }
}
