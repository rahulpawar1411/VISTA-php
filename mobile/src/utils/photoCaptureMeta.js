/**
 * Photo capture timestamp + GPS helpers for DO camera flows.
 */
import * as Location from 'expo-location';
import { ensureLocationPermission } from './permissions';

export function formatCaptureDateTime(timestamp = Date.now()) {
  const dateObj = new Date(timestamp);
  if (Number.isNaN(dateObj.getTime())) return '';
  const yyyy = dateObj.getFullYear();
  const mm = String(dateObj.getMonth() + 1).padStart(2, '0');
  const dd = String(dateObj.getDate()).padStart(2, '0');
  const hh = String(dateObj.getHours()).padStart(2, '0');
  const min = String(dateObj.getMinutes()).padStart(2, '0');
  const ss = String(dateObj.getSeconds()).padStart(2, '0');
  return `${yyyy}-${mm}-${dd} ${hh}:${min}:${ss}`;
}

function coordsFromPosition(position) {
  if (!position?.coords) return null;
  const { latitude, longitude, accuracy } = position.coords;
  if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) return null;
  return {
    latitude,
    longitude,
    accuracy: Number.isFinite(accuracy) ? accuracy : null,
  };
}

async function readFreshPosition(accuracy) {
  const position = await Location.getCurrentPositionAsync({
    accuracy,
    mayShowUserSettingsDialog: true,
  });
  return coordsFromPosition(position);
}

/**
 * Read GPS — cached last-known first, then fresh fix (low → balanced accuracy).
 */
export async function readCaptureLocation() {
  try {
    const allowed = await ensureLocationPermission({ required: false });
    if (!allowed) {
      console.warn('readCaptureLocation: location permission not granted');
      return null;
    }

    const servicesEnabled = await Location.hasServicesEnabledAsync();
    if (!servicesEnabled) {
      console.warn('readCaptureLocation: device location services are off');
      return null;
    }

    const lastKnown = await Location.getLastKnownPositionAsync({
      maxAge: 600000,
      requiredAccuracy: 1000,
    });
    const cached = coordsFromPosition(lastKnown);
    if (cached) return cached;

    try {
      return await readFreshPosition(Location.Accuracy.Low);
    } catch (lowErr) {
      console.warn('readCaptureLocation low accuracy failed:', lowErr?.message || lowErr);
    }

    try {
      return await readFreshPosition(Location.Accuracy.Balanced);
    } catch (balancedErr) {
      console.warn('readCaptureLocation balanced accuracy failed:', balancedErr?.message || balancedErr);
      return null;
    }
  } catch (err) {
    console.warn('readCaptureLocation skipped:', err?.message || err);
    return null;
  }
}

/**
 * Call BEFORE opening the camera so GPS can resolve while the user takes the photo.
 * Returns a promise — await it after the photo is captured/compressed.
 */
export function beginPhotoLocationCapture() {
  return (async () => {
    const allowed = await ensureLocationPermission({ required: false });
    if (!allowed) {
      console.warn('beginPhotoLocationCapture: permission not granted');
      return null;
    }

    try {
      const servicesEnabled = await Location.hasServicesEnabledAsync();
      if (!servicesEnabled) {
        console.warn('beginPhotoLocationCapture: location services off');
        return null;
      }
    } catch (_) {
      return null;
    }

    return readCaptureLocation();
  })();
}

/** Build metadata after photo capture; pass promise from beginPhotoLocationCapture(). */
export async function buildPhotoCaptureMeta(locationPromise = null) {
  const capturedAt = Date.now();
  let location = null;

  try {
    if (locationPromise) {
      location = await locationPromise;
    } else {
      location = await readCaptureLocation();
    }
  } catch (err) {
    console.warn('buildPhotoCaptureMeta location failed:', err?.message || err);
  }

  return {
    capturedAt,
    capturedAtStr: formatCaptureDateTime(capturedAt),
    latitude: location?.latitude ?? null,
    longitude: location?.longitude ?? null,
    accuracy: location?.accuracy ?? null,
  };
}

export function serializePhotoMetaEntry(item) {
  if (!item) return null;
  const capturedAt =
    item.capturedAtStr ||
    (item.capturedAt ? formatCaptureDateTime(item.capturedAt) : null);
  if (!capturedAt) return null;

  const entry = { capturedAt };
  const lat = item.latitude != null ? parseFloat(item.latitude) : null;
  const lng = item.longitude != null ? parseFloat(item.longitude) : null;
  if (Number.isFinite(lat) && Number.isFinite(lng)) {
    entry.latitude = lat;
    entry.longitude = lng;
    const acc = item.accuracy != null ? parseFloat(item.accuracy) : null;
    if (Number.isFinite(acc)) entry.accuracy = acc;
  }
  return entry;
}

export function buildPhotoMetadataPayload(photos, fieldDefs) {
  const meta = {};
  for (const { key, multi } of fieldDefs) {
    const val = photos[key];
    if (multi && Array.isArray(val) && val.length) {
      const entries = val.map(serializePhotoMetaEntry).filter(Boolean);
      if (entries.length) meta[key] = entries;
    } else if (val?.uri) {
      const entry = serializePhotoMetaEntry(val);
      if (entry) meta[key] = entry;
    }
  }
  return Object.keys(meta).length ? meta : null;
}
