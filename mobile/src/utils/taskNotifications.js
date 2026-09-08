/**
 * Morning / Evening local reminders.
 * - If Morning tasks already completed today → do NOT notify morning (schedule tomorrow only)
 * - If Evening tasks already completed today → do NOT notify evening (schedule tomorrow only)
 */
import * as Notifications from 'expo-notifications';
import { ensureNotificationPermission } from './permissions';

const DATE_TRIGGER =
  Notifications.SchedulableTriggerInputTypes?.DATE || 'date';

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

async function scheduleAt(identifier, content, when) {
  await Notifications.scheduleNotificationAsync({
    identifier,
    content: {
      ...content,
      sound: true
    },
    trigger: {
      type: DATE_TRIGGER,
      date: when,
      channelId: 'task-reminders'
    }
  });
}

/**
 * @param {{ morningCompleted: boolean, eveningCompleted: boolean }} opts
 */
export async function refreshTaskReminders({ morningCompleted = false, eveningCompleted = false } = {}) {
  const granted = await ensureNotificationPermission();
  if (!granted) return;

  try {
    await Notifications.cancelAllScheduledNotificationsAsync();
  } catch (err) {
    console.warn('cancel scheduled notifications failed:', err?.message || err);
  }

  try {
    // Morning 10:00 — skip today if morning already done
    const morningDate = morningCompleted ? tomorrowAt(10, 0) : nextOccurrence(10, 0);
    await scheduleAt(
      MORNING_NOTIF_ID,
      {
        title: 'Morning Tasks Ready',
        body: "Today's Morning Task is active. Open the app to complete assignments.",
        data: { shift: 'Morning' }
      },
      morningDate
    );
    console.log(
      morningCompleted
        ? `🔔 Morning notify skipped today (completed) → next at ${morningDate.toISOString()}`
        : `🔔 Morning notify scheduled at ${morningDate.toISOString()}`
    );

    // Evening 16:00 — skip today if evening already done
    const eveningDate = eveningCompleted ? tomorrowAt(16, 0) : nextOccurrence(16, 0);
    await scheduleAt(
      EVENING_NOTIF_ID,
      {
        title: 'Evening Tasks Ready',
        body: "Today's Evening Task is active. Open the app to complete assignments.",
        data: { shift: 'Evening' }
      },
      eveningDate
    );
    console.log(
      eveningCompleted
        ? `🔔 Evening notify skipped today (completed) → next at ${eveningDate.toISOString()}`
        : `🔔 Evening notify scheduled at ${eveningDate.toISOString()}`
    );
  } catch (err) {
    console.warn('refreshTaskReminders failed:', err?.message || err);
  }
}
