/**
 * Morning / Evening local reminders.
 * - If Morning tasks already completed today → do NOT notify morning (schedule tomorrow only)
 * - If Evening tasks already completed today → do NOT notify evening (schedule tomorrow only)
 */
import * as Notifications from 'expo-notifications';
import { ensureNotificationPermission } from './permissions';

const { SchedulableTriggerInputTypes } = Notifications;

export const MORNING_NOTIF_ID = 'reeferon-morning-task';
export const EVENING_NOTIF_ID = 'reeferon-evening-task';

function nextOccurrence(hour, minute) {
  const when = new Date();
  when.setSeconds(0, 0);
  when.setHours(hour, minute, 0, 0);
  if (when.getTime() <= Date.now()) {
    when.setDate(when.getDate() + 1);
  }
  return when;
}

function tomorrowAt(hour, minute) {
  const when = new Date();
  when.setDate(when.getDate() + 1);
  when.setHours(hour, minute, 0, 0);
  when.setSeconds(0, 0);
  return when;
}

/**
 * @param {{ morningCompleted: boolean, eveningCompleted: boolean }} opts
 */
export async function refreshTaskReminders({ morningCompleted = false, eveningCompleted = false } = {}) {
  const granted = await ensureNotificationPermission();
  if (!granted) return;

  await Notifications.cancelAllScheduledNotificationsAsync();

  // Morning 10:00 — skip today if morning already done
  const morningDate = morningCompleted ? tomorrowAt(10, 0) : nextOccurrence(10, 0);
  await Notifications.scheduleNotificationAsync({
    identifier: MORNING_NOTIF_ID,
    content: {
      title: 'Morning Tasks Ready',
      body: "Today's Morning Task is active. Open the app to complete assignments.",
      sound: true,
      data: { shift: 'Morning' }
    },
    trigger: {
      type: SchedulableTriggerInputTypes.DATE,
      date: morningDate,
      channelId: 'task-reminders'
    }
  });

  if (morningCompleted) {
    console.log('🔔 Morning notify skipped today (completed) → next at', morningDate.toISOString());
  } else {
    console.log('🔔 Morning notify scheduled at', morningDate.toISOString());
  }

  // Evening 16:00 — skip today if evening already done
  const eveningDate = eveningCompleted ? tomorrowAt(16, 0) : nextOccurrence(16, 0);
  await Notifications.scheduleNotificationAsync({
    identifier: EVENING_NOTIF_ID,
    content: {
      title: 'Evening Tasks Ready',
      body: "Today's Evening Task is active. Open the app to complete assignments.",
      sound: true,
      data: { shift: 'Evening' }
    },
    trigger: {
      type: SchedulableTriggerInputTypes.DATE,
      date: eveningDate,
      channelId: 'task-reminders'
    }
  });

  if (eveningCompleted) {
    console.log('🔔 Evening notify skipped today (completed) → next at', eveningDate.toISOString());
  } else {
    console.log('🔔 Evening notify scheduled at', eveningDate.toISOString());
  }
}
