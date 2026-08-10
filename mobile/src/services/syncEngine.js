import NetInfo from '@react-native-community/netinfo';
import AsyncStorage from '@react-native-async-storage/async-storage';
import {
  getPendingInspections,
  markInspectionAsSynced,
  getPendingAssignments,
  markAssignmentSynced
} from '../database/db';

const LAST_SYNC_KEY = 'reeferon_last_sync_at';

/** Prevent overlapping sync runs. */
let isSyncing = false;

/**
 * @typedef {'syncing'|'idle'|'partial'|'failed'} SyncStatus
 * @typedef {{ status: SyncStatus, lastSyncAt?: string, pendingCount?: number, message?: string }} SyncProgress
 */

export async function getLastSyncAt() {
  try {
    return (await AsyncStorage.getItem(LAST_SYNC_KEY)) || null;
  } catch (_) {
    return null;
  }
}

async function persistLastSync(iso) {
  if (!iso) return;
  try {
    await AsyncStorage.setItem(LAST_SYNC_KEY, iso);
  } catch (_) {}
}

function emitProgress(onSyncProgress, payload) {
  if (typeof onSyncProgress === 'function') onSyncProgress(payload);
}

function countPending() {
  return getPendingAssignments().length + getPendingInspections().length;
}

/**
 * Upload pending assignments + inspections.
 * @param {string} apiBaseUrl
 * @param {string} token
 * @param {(p: SyncProgress|string) => void} [onSyncProgress]
 */
export const triggerSync = async (apiBaseUrl, token, onSyncProgress = () => {}) => {
  if (isSyncing) return;
  if (!apiBaseUrl || !token) {
    emitProgress(onSyncProgress, {
      status: 'failed',
      pendingCount: countPending(),
      message: 'Missing API URL or login token.'
    });
    return;
  }

  isSyncing = true;
  emitProgress(onSyncProgress, {
    status: 'syncing',
    pendingCount: countPending(),
    message: 'Uploading offline queue…'
  });

  let syncedCount = 0;
  let failedCount = 0;

  try {
    const pendingAssignments = getPendingAssignments();
    for (const item of pendingAssignments) {
      try {
        const isDelete = item.action === 'delete';
        const response = await fetch(`${apiBaseUrl}/api/chambers/assignments`, {
          method: isDelete ? 'DELETE' : 'POST',
          headers: {
            Authorization: `Bearer ${token}`,
            'Content-Type': 'application/json',
            Accept: 'application/json'
          },
          body: JSON.stringify({
            chamber_id: item.chamber_id,
            client_name: item.client_name,
            remark: item.remark
          })
        });

        if (response.status === 200 || response.status === 201) {
          markAssignmentSynced(item.chamber_id, item.client_name, item.action);
          syncedCount += 1;
        } else {
          const resData = await response.json().catch(() => ({}));
          throw new Error(resData.message || resData.error || `Sync failed (${response.status})`);
        }
      } catch (assignErr) {
        failedCount += 1;
        console.error(`❌ Sync failed for assignment ${item.client_name}:`, assignErr.message || assignErr);
      }
    }

    const pendingInspections = getPendingInspections();
    for (const log of pendingInspections) {
      try {
        const formData = new FormData();
        formData.append('operator_name', log.monitor_supervisor_name);
        formData.append('chamber_id', log.chamber_id.toString());
        formData.append('client_name', log.client_name);
        formData.append('entry_date', log.entry_date);
        formData.append('entry_time', log.inspection_time);
        formData.append('box_temp', String(log.box_temp));
        if (log.box_count != null) formData.append('box_count', log.box_count.toString());
        if (log.chamber_type) formData.append('chamber_type', log.chamber_type);
        if (log.overdue_time) formData.append('overdue_time', log.overdue_time);
        if (log.photo_capture_time) formData.append('photo_capture_time', log.photo_capture_time);
        if (log.created_at) formData.append('created_at', log.created_at);
        formData.append(
          'shift',
          log.shift || (log.inspection_time === '10:00' ? 'Morning' : 'Evening')
        );

        if (log.temp_sensor_image) {
          const filename = log.temp_sensor_image.split('/').pop() || `inspection-${log.id}.jpg`;
          formData.append('sensor_photo', {
            uri: log.temp_sensor_image,
            name: filename,
            type: 'image/jpeg'
          });
        }

        const response = await fetch(`${apiBaseUrl}/api/chambers/inspections`, {
          method: 'POST',
          headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
          body: formData
        });

        const resData = await response.json().catch(() => ({}));

        if (response.status === 200 || response.status === 201) {
          markInspectionAsSynced(log.id, resData.reference_no, resData.logId);
          syncedCount += 1;
        } else if (response.status === 409) {
          markInspectionAsSynced(log.id, resData.reference_no, resData.logId);
          syncedCount += 1;
        } else {
          throw new Error(resData.message || resData.error || `Sync failed (${response.status})`);
        }
      } catch (err) {
        failedCount += 1;
        console.error(`❌ Sync failed for log ${log.id}:`, err.message || err);
        break;
      }
    }
  } catch (error) {
    failedCount += 1;
    console.error('❌ Sync Engine encountered an error:', error);
  } finally {
    isSyncing = false;
    const stillPending = countPending();
    let status = 'idle';
    let message = null;
    let lastSyncAt;

    if (failedCount > 0 && syncedCount > 0) {
      status = 'partial';
      message = `${syncedCount} uploaded · ${stillPending} still on phone`;
    } else if (failedCount > 0 || stillPending > 0) {
      status = stillPending > 0 ? 'failed' : 'idle';
      message =
        stillPending > 0
          ? `${stillPending} item(s) waiting — tap Sync when online`
          : 'Sync failed — data safe on device';
    } else if (syncedCount > 0) {
      message = `${syncedCount} item(s) synced to server`;
    } else {
      message = 'All data synced';
    }

    if (syncedCount > 0 && failedCount === 0 && stillPending === 0) {
      lastSyncAt = new Date().toISOString();
      await persistLastSync(lastSyncAt);
    } else if (syncedCount > 0) {
      lastSyncAt = new Date().toISOString();
      await persistLastSync(lastSyncAt);
    } else {
      lastSyncAt = (await getLastSyncAt()) || undefined;
    }

    emitProgress(onSyncProgress, {
      status,
      lastSyncAt,
      pendingCount: stillPending,
      message
    });
  }
};

export const subscribeToSync = (apiBaseUrl, token, onSyncProgress = () => {}) => {
  let retryTimer = null;

  const runSyncIfOnline = (state) => {
    const isOnline = state.isConnected && state.isInternetReachable !== false;
    if (isOnline) triggerSync(apiBaseUrl, token, onSyncProgress);
  };

  const unsubscribeNetInfo = NetInfo.addEventListener(runSyncIfOnline);

  retryTimer = setInterval(() => {
    NetInfo.fetch()
      .then((state) => {
        const isOnline = state.isConnected && state.isInternetReachable !== false;
        if (isOnline) triggerSync(apiBaseUrl, token, onSyncProgress);
      })
      .catch(() => {});
  }, 30000);

  return () => {
    unsubscribeNetInfo();
    if (retryTimer) clearInterval(retryTimer);
  };
};

/** Format ISO timestamp for DO sync UI. */
export function formatLastSyncLabel(iso) {
  if (!iso) return 'Never synced';
  try {
    const d = new Date(iso);
    const now = new Date();
    const sameDay =
      d.getDate() === now.getDate() &&
      d.getMonth() === now.getMonth() &&
      d.getFullYear() === now.getFullYear();
    const time = d.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' });
    if (sameDay) return `Today ${time}`;
    return `${d.toLocaleDateString('en-IN')} ${time}`;
  } catch (_) {
    return iso;
  }
}
