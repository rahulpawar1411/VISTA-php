export const DOCK_REPORT_PAGE_SIZE = 20;

export function splitLogPhotoPaths(value) {
  if (!value) return [];
  return String(value)
    .split(',')
    .map((s) => s.trim())
    .filter((s) => s && s !== 'null' && s !== 'undefined');
}

export function parsePhotoCaptureMetadata(raw) {
  if (!raw) return null;
  if (typeof raw === 'object') return raw;
  try {
    const parsed = JSON.parse(String(raw));
    return parsed && typeof parsed === 'object' ? parsed : null;
  } catch {
    return null;
  }
}

export function formatPhotoGps(lat, lng, accuracy) {
  const latitude = parseFloat(lat);
  const longitude = parseFloat(lng);
  if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) return '';
  const acc =
    accuracy != null && Number.isFinite(parseFloat(accuracy))
      ? ` (±${Math.round(parseFloat(accuracy))}m)`
      : '';
  return `${latitude.toFixed(5)}, ${longitude.toFixed(5)}${acc}`;
}

export function formatPhotoCaptureMetadataLines(raw) {
  const meta = parsePhotoCaptureMetadata(raw);
  if (!meta || typeof meta !== 'object') return [];
  return Object.entries(meta).flatMap(([field, val]) => {
    const entries = Array.isArray(val) ? val : val && typeof val === 'object' ? [val] : [];
    return entries
      .map((entry, idx) => {
        if (!entry || typeof entry !== 'object') return null;
        const label = field.replace(/^(inward_|outward_)/, '').replace(/_/g, ' ');
        const time = entry.capturedAt ? String(entry.capturedAt) : '';
        const gps = formatPhotoGps(entry.latitude, entry.longitude, entry.accuracy);
        const parts = [`${label}${entries.length > 1 ? ` ${idx + 1}` : ''}`];
        if (time) parts.push(time);
        if (gps) parts.push(gps);
        return parts.length > 1 ? parts.join(' · ') : null;
      })
      .filter(Boolean);
  });
}

export function resolveDockImageUrl(raw, apiUrl, productionApiUrl, folderHint) {
  if (raw == null) return null;
  let value = String(raw).trim();
  if (!value || value === 'null' || value === 'undefined') return null;
  if (/^https?:\/\//i.test(value) || value.startsWith('file://') || value.startsWith('content://')) {
    return value;
  }
  if (value.startsWith('data:')) return value;

  const bases = [apiUrl, productionApiUrl].filter(Boolean).map((b) => String(b).replace(/\/$/, ''));
  const path = value.startsWith('uploads/') ? value : `uploads/${folderHint}/${value.replace(/^\/+/, '')}`;

  for (const base of bases) {
    if (base) return `${base}/${path.replace(/^\/+/, '')}`;
  }
  return null;
}
