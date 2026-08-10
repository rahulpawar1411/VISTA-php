/**
 * Compress image only — no resize (keeps original dimensions).
 * Used after DO camera capture before local save / sync.
 */
import * as ImageManipulator from 'expo-image-manipulator';

/** JPEG quality 0–1 (lower = smaller file). Sensor text still readable ~0.45–0.55 */
const DEFAULT_COMPRESS = 0.5;

/**
 * @param {string} uri - local file URI from camera
 * @param {number} [quality]
 * @returns {Promise<string>} compressed JPEG URI (or original on failure)
 */
export async function compressImageOnly(uri, quality = DEFAULT_COMPRESS) {
  if (!uri || typeof uri !== 'string') return uri;

  try {
    // Empty actions [] = no resize / no crop — compress + JPEG only
    const result = await ImageManipulator.manipulateAsync(
      uri,
      [],
      {
        compress: Math.min(1, Math.max(0.1, quality)),
        format: ImageManipulator.SaveFormat.JPEG
      }
    );
    return result?.uri || uri;
  } catch (err) {
    console.warn('Image compress skipped:', err?.message || err);
    return uri;
  }
}
