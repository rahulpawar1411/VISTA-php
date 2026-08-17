import AsyncStorage from '@react-native-async-storage/async-storage';
import * as FileSystem from 'expo-file-system/legacy';

const DRAFT_PHOTO_DIR = `${FileSystem.documentDirectory}form_draft_photos/`;

async function ensureDraftPhotoDir() {
  const info = await FileSystem.getInfoAsync(DRAFT_PHOTO_DIR);
  if (!info.exists) {
    await FileSystem.makeDirectoryAsync(DRAFT_PHOTO_DIR, { intermediates: true });
  }
}

async function uriExists(uri) {
  if (!uri || typeof uri !== 'string') return false;
  try {
    const info = await FileSystem.getInfoAsync(uri);
    return Boolean(info.exists);
  } catch (_) {
    return false;
  }
}

/**
 * Copy camera/cache URI into app document storage so drafts survive app restart.
 */
export async function stabilizePhotoForDraft(photo, fieldKey, index = 0) {
  if (!photo?.uri) return photo;
  const uri = String(photo.uri);
  if (!uri.startsWith('file')) return photo;
  if (uri.startsWith(DRAFT_PHOTO_DIR)) return photo;

  try {
    await ensureDraftPhotoDir();
    const dest = `${DRAFT_PHOTO_DIR}${fieldKey}-${index}-${Date.now()}.jpg`;
    await FileSystem.copyAsync({ from: uri, to: dest });
    return { ...photo, uri: dest };
  } catch (_) {
    return photo;
  }
}

export async function stabilizePhotosForDraft(photos = {}) {
  if (!photos || typeof photos !== 'object') return photos;
  const next = { ...photos };

  for (const [fieldKey, value] of Object.entries(photos)) {
    if (Array.isArray(value)) {
      const list = [];
      for (let i = 0; i < value.length; i += 1) {
        list.push(await stabilizePhotoForDraft(value[i], fieldKey, i));
      }
      next[fieldKey] = list;
    } else if (value?.uri) {
      next[fieldKey] = await stabilizePhotoForDraft(value, fieldKey, 0);
    }
  }

  return next;
}

export async function pruneMissingDraftPhotos(photos = {}) {
  if (!photos || typeof photos !== 'object') return photos;
  const next = { ...photos };

  for (const [fieldKey, value] of Object.entries(photos)) {
    if (Array.isArray(value)) {
      const list = [];
      for (const item of value) {
        if (item?.uri && (await uriExists(item.uri))) list.push(item);
      }
      next[fieldKey] = list;
    } else if (value?.uri) {
      next[fieldKey] = (await uriExists(value.uri)) ? value : null;
    }
  }

  return next;
}

export async function saveFormDraft(key, payload) {
  if (!key || !payload) return;
  const photos = await stabilizePhotosForDraft(payload.photos);
  await AsyncStorage.setItem(
    key,
    JSON.stringify({
      ...payload,
      photos,
      savedAt: Date.now(),
    })
  );
}

export async function loadFormDraft(key) {
  const raw = await AsyncStorage.getItem(key);
  if (!raw) return null;
  const draft = JSON.parse(raw);
  if (!draft || typeof draft !== 'object') return null;
  if (draft.photos && typeof draft.photos === 'object') {
    draft.photos = await pruneMissingDraftPhotos(draft.photos);
  }
  return draft;
}

export async function clearFormDraft(key) {
  await AsyncStorage.removeItem(key);
}
