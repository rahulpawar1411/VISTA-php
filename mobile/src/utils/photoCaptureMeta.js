/**
 * Photo capture timestamp + GPS helpers for DO camera flows.
 */
import { Platform } from 'react-native';
import * as Location from 'expo-location';
import {
  ensureLocationPermission,
  ensureLocationServicesEnabled,
} from './permissions';

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

/** Watch GPS briefly when getCurrentPosition fails (common indoors / cold start). */
function watchForPosition(timeoutMs = 12000) {
  return new Promise((resolve) => {
    let sub = null;
    let settled = false;

    const finish = (value) => {
      if (settled) return;
      settled = true;
      clearTimeout(timer);
      try {
        sub?.remove?.();
      } catch (_) {
        /* ignore */
      }
      resolve(value);
    };

    const timer = setTimeout(() => finish(null), timeoutMs);

    Location.watchPositionAsync(
      {
        accuracy: Location.Accuracy.Lowest,
        distanceInterval: 0,
        timeInterval: 800,
        mayShowUserSettingsDialog: true,
      },
      (pos) => {
        const coords = coordsFromPosition(pos);
        if (coords) finish(coords);
      }
    )
      .then((subscription) => {
        sub = subscription;
        if (settled) {
          try {
            subscription?.remove?.();
          } catch (_) {
            /* ignore */
          }
        }
      })
      .catch((err) => {
        console.warn('watchForPosition failed:', err?.message || err);
        finish(null);
      });
  });
}

/**
 * Read GPS - last-known -> Lowest -> Low -> Balanced -> short watch.
 */
export async function readCaptureLocation() {
  try {
    const allowed = await ensureLocationPermission({ required: false });
    if (!allowed) {
      console.warn('readCaptureLocation: location permission not granted');
      return null;
    }

    if (Platform.OS === 'android' && Location.enableNetworkProviderAsync) {
      try {
        await Location.enableNetworkProviderAsync();
      } catch (_) {
        /* ignore */
      }
    }

    const servicesEnabled = await Location.hasServicesEnabledAsync();
    if (!servicesEnabled) {
      console.warn('readCaptureLocation: device location services are off');
      return null;
    }

    try {
      const lastKnown = await Location.getLastKnownPositionAsync({
        maxAge: 15 * 60 * 1000,
        requiredAccuracy: 2000,
      });
      const cached = coordsFromPosition(lastKnown);
      if (cached) return cached;
    } catch (_) {
      /* ignore */
    }

    const accuracies = [
      Location.Accuracy.Lowest,
      Location.Accuracy.Low,
      Location.Accuracy.Balanced,
    ];
    for (const accuracy of accuracies) {
      try {
        const coords = await readFreshPosition(accuracy);
        if (coords) return coords;
      } catch (err) {
        console.warn(
          `readCaptureLocation accuracy=${accuracy} failed:`,
          err?.message || err
        );
      }
    }

    const watched = await watchForPosition(12000);
    if (watched) return watched;

    return null;
  } catch (err) {
    console.warn('readCaptureLocation skipped:', err?.message || err);
    return null;
  }
}

/**
 * Lightweight GPS while the system camera is open.
 * Avoid full getCurrentPosition / watch here - concurrent GPS + full-res camera
 * often kills the Android process (app appears to "close").
 * Full accuracy fixup still runs in buildPhotoCaptureMeta after return.
 */
export function beginPhotoLocationCapture() {
  return (async () => {
    try {
      const allowed = await ensureLocationPermission({ required: false });
      if (!allowed) {
        console.warn('beginPhotoLocationCapture: permission not granted');
        return null;
      }

      const servicesOk = await ensureLocationServicesEnabled({ required: false });
      if (!servicesOk) {
        console.warn('beginPhotoLocationCapture: location services off');
        return null;
      }

      const lastKnown = await Location.getLastKnownPositionAsync({
        maxAge: 15 * 60 * 1000,
        requiredAccuracy: 2000,
      });
      return coordsFromPosition(lastKnown);
    } catch (err) {
      console.warn('beginPhotoLocationCapture skipped:', err?.message || err);
      return null;
    }
  })();
}

/** Build metadata after photo capture; pass promise from beginPhotoLocationCapture(). */
export async function buildPhotoCaptureMeta(locationPromise = null) {
  const capturedAt = Date.now();
  let location = null;

  try {
    if (locationPromise) {
      location = await locationPromise;
    }
  } catch (err) {
    console.warn('buildPhotoCaptureMeta locationPromise failed:', err?.message || err);
  }

  // Always retry after camera - GPS often unlocks once user is outdoors / after cold start
  if (!location?.latitude || !location?.longitude) {
    try {
      location = await readCaptureLocation();
    } catch (err) {
      console.warn('buildPhotoCaptureMeta retry failed:', err?.message || err);
    }
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
