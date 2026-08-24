/**
 * Shared Expo push token registration / refresh for Sub-Admin + DO.
 * Call on every app open / foreground so the server always has a live token.
 */
import * as Notifications from 'expo-notifications';
import Constants from 'expo-constants';
import { AppState, Platform } from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';
import { ensureNotificationPermission } from '../utils/permissions';

const LAST_TOKEN_KEY = 'reeferon_last_expo_push_token';
const MIN_REFRESH_MS = 30_000;

let lastRegisterAt = 0;
let lastRegisterFingerprint = '';
let appStateSub = null;

function getExpoProjectId() {
  return (
    Constants.expoConfig?.extra?.eas?.projectId ||
    Constants.easConfig?.projectId ||
    null
  );
}

async function ensurePermissionChannel() {
  if (Platform.OS !== 'android') return;
  try {
    await Notifications.setNotificationChannelAsync('permission-alerts', {
      name: 'Permission alerts',
      description: 'Permission request and decision alerts',
      importance: Notifications.AndroidImportance.HIGH,
      vibrationPattern: [0, 250, 250, 250],
      lightColor: '#003580',
      sound: 'default'
    });
  } catch (_) {
    /* ignore */
  }
}

/**
 * Fetch current device Expo token and POST to backend.
 * @returns {Promise<{ ok: boolean, token?: string, refreshed?: boolean }>}
 */
export async function registerExpoPushToken({ apiUrl, authToken, force = false } = {}) {
  if (!apiUrl || !authToken) return { ok: false };

  const now = Date.now();
  const fingerprint = `${apiUrl}|${String(authToken).slice(0, 16)}`;
  if (
    !force &&
    fingerprint === lastRegisterFingerprint &&
    now - lastRegisterAt < MIN_REFRESH_MS
  ) {
    return { ok: true, refreshed: false };
  }

  const granted = await ensureNotificationPermission();
  if (!granted) return { ok: false };

  await ensurePermissionChannel();

  try {
    const projectId = getExpoProjectId();
    const push = await Notifications.getExpoPushTokenAsync(
      projectId ? { projectId } : undefined
    );
    const expoPushToken = push?.data;
    if (!expoPushToken) return { ok: false };

    const prev = await AsyncStorage.getItem(LAST_TOKEN_KEY);
    const changed = prev !== expoPushToken;

    const res = await fetch(`${apiUrl}/api/auth/push-token`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Authorization: `Bearer ${authToken}`
      },
      body: JSON.stringify({ expo_push_token: expoPushToken })
    });
    if (!res.ok) {
      console.warn('registerExpoPushToken failed:', res.status);
      return { ok: false, token: expoPushToken };
    }

    await AsyncStorage.setItem(LAST_TOKEN_KEY, expoPushToken);
    lastRegisterAt = Date.now();
    lastRegisterFingerprint = fingerprint;
    return { ok: true, token: expoPushToken, refreshed: changed || force };
  } catch (err) {
    console.warn('registerExpoPushToken:', err?.message || err);
    return { ok: false };
  }
}

export async function clearExpoPushToken({ apiUrl, authToken } = {}) {
  lastRegisterAt = 0;
  lastRegisterFingerprint = '';
  try {
    await AsyncStorage.removeItem(LAST_TOKEN_KEY);
  } catch (_) {
    /* ignore */
  }
  if (!apiUrl || !authToken) return;
  try {
    await fetch(`${apiUrl}/api/auth/push-token`, {
      method: 'DELETE',
      headers: { Authorization: `Bearer ${authToken}` }
    });
  } catch (_) {
    /* ignore */
  }
}

/**
 * Refresh push token whenever the app returns to foreground.
 * @returns {() => void} unsubscribe
 */
export function subscribePushTokenRefresh({ apiUrl, authToken, onReady } = {}) {
  const run = (force = false) => {
    registerExpoPushToken({ apiUrl, authToken, force })
      .then((result) => {
        if (result?.ok) onReady?.(result);
      })
      .catch(() => {});
  };

  run(true);

  if (appStateSub) {
    try {
      appStateSub.remove();
    } catch (_) {
      /* ignore */
    }
    appStateSub = null;
  }

  appStateSub = AppState.addEventListener('change', (state) => {
    if (state === 'active') run(false);
  });

  return () => {
    try {
      appStateSub?.remove?.();
    } catch (_) {
      /* ignore */
    }
    appStateSub = null;
  };
}
