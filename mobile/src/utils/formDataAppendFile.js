/**
 * Multipart uploads for Expo SDK 53+.
 * Expo's winter `fetch` rejects RN `{ uri, name, type }` FormData parts.
 * XMLHttpRequest still uses React Native's native multipart encoder.
 */
import * as FileSystem from 'expo-file-system/legacy';

export function normalizeLocalFileUri(uri) {
  const path = String(uri || '').trim();
  if (!path) return '';
  if (
    path.startsWith('file://') ||
    path.startsWith('content://') ||
    path.startsWith('ph://') ||
    path.startsWith('asset:')
  ) {
    return path;
  }
  if (path.startsWith('/')) return `file://${path}`;
  return path;
}

export async function localFileExists(uri) {
  const path = normalizeLocalFileUri(uri);
  if (!path) return false;
  try {
    const info = await FileSystem.getInfoAsync(path);
    return Boolean(info?.exists);
  } catch (_) {
    return false;
  }
}

/**
 * Append a local image for XHR multipart (RN FormData uri part).
 */
export function appendLocalFile(formData, fieldName, uri, opts = {}) {
  if (!formData || !fieldName) return;
  const path = normalizeLocalFileUri(uri);
  if (!path) return;
  const name = opts.name || path.split('/').pop() || 'photo.jpg';
  const type = opts.type || 'image/jpeg';
  formData.append(fieldName, { uri: path, name, type });
}

/**
 * fetch()-like wrapper using XMLHttpRequest so `{ uri }` FormData works on Expo Go.
 * @returns {Promise<{ ok: boolean, status: number, json: () => Promise<any>, text: () => Promise<string> }>}
 */
export function multipartRequest(url, { method = 'POST', headers = {}, body, timeoutMs = 120000 } = {}) {
  return new Promise((resolve, reject) => {
    try {
      const xhr = new XMLHttpRequest();
      xhr.open(method, url);
      xhr.timeout = timeoutMs;

      Object.entries(headers || {}).forEach(([key, value]) => {
        if (value == null) return;
        // Let XHR set multipart boundary automatically
        if (String(key).toLowerCase() === 'content-type') return;
        xhr.setRequestHeader(String(key), String(value));
      });

      xhr.onload = () => {
        const status = xhr.status || 0;
        const responseText = xhr.responseText || '';
        resolve({
          ok: status >= 200 && status < 300,
          status,
          async text() {
            return responseText;
          },
          async json() {
            try {
              return responseText ? JSON.parse(responseText) : {};
            } catch (_) {
              return {};
            }
          },
        });
      };

      xhr.onerror = () => reject(new Error('Network request failed'));
      xhr.ontimeout = () => reject(new Error('Upload timed out'));
      xhr.send(body);
    } catch (err) {
      reject(err instanceof Error ? err : new Error(String(err)));
    }
  });
}
