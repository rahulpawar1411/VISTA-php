import * as Notifications from 'expo-notifications';
import { ensureNotificationPermission } from '../utils/permissions';

let handlerSet = false;
let lastPendingPermissions = null;
let lastOverdueTasks = null;
let initialized = false;

function ensureHandler() {
  if (handlerSet) return;
  handlerSet = true;
  Notifications.setNotificationHandler({
    handleNotification: async () => ({
      shouldShowAlert: true,
      shouldPlaySound: true,
      shouldSetBadge: true
    })
  });
}

async function pushLocal(title, body, data = {}) {
  try {
    await Notifications.scheduleNotificationAsync({
      content: { title, body, data, sound: 'default' },
      trigger: null
    });
  } catch (err) {
    console.warn('Sub-admin local alert failed:', err?.message || err);
  }
}

/** Call once when Sub-Admin screen mounts. */
export async function initSubAdminPushAlerts() {
  ensureHandler();
  if (initialized) return;
  initialized = true;
  await ensureNotificationPermission();
}

/**
 * Fire local notifications when pending permissions or overdue tasks increase.
 * (Polling-based — no server FCM required.)
 */
export async function notifySubAdminIfNeeded({ pendingPermissions, overdueTasks }) {
  ensureHandler();

  const pending = Number(pendingPermissions) || 0;
  const overdue = Number(overdueTasks) || 0;

  if (lastPendingPermissions != null && pending > lastPendingPermissions) {
    const delta = pending - lastPendingPermissions;
    await pushLocal(
      'New permission request',
      delta === 1
        ? '1 DO permission needs your review — open Admin'
        : `${delta} new permission requests — open Admin`,
      { screen: 'Admin', section: 'permissions' }
    );
  }

  if (lastOverdueTasks != null && overdue > lastOverdueTasks) {
    const delta = overdue - lastOverdueTasks;
    await pushLocal(
      'Tasks overdue',
      delta === 1
        ? '1 more overdue task — check Dashboard'
        : `${delta} more overdue tasks — check Dashboard`,
      { screen: 'Dashboard' }
    );
  }

  lastPendingPermissions = pending;
  lastOverdueTasks = overdue;
}

/** Reset counters when user logs out. */
export function resetSubAdminPushAlerts() {
  lastPendingPermissions = null;
  lastOverdueTasks = null;
  initialized = false;
}
