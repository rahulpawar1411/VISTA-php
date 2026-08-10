/**
 * Runtime permission helpers — camera & notifications (Android + iOS).
 */
import { Alert, Linking, Platform } from 'react-native';
import * as ImagePicker from 'expo-image-picker';
import * as Notifications from 'expo-notifications';

function openAppSettings() {
  Linking.openSettings().catch(() => {});
}

/**
 * Ask for camera access before DO temperature photo capture.
 * @returns {Promise<boolean>}
 */
export async function ensureCameraPermission() {
  try {
    const current = await ImagePicker.getCameraPermissionsAsync();
    if (current.granted) return true;

    if (current.canAskAgain === false) {
      Alert.alert(
        'Camera permission required',
        'ReeferON needs camera access to capture temperature sensor photos. Please enable Camera in Settings.',
        [
          { text: 'Cancel', style: 'cancel' },
          { text: 'Open Settings', onPress: openAppSettings }
        ]
      );
      return false;
    }

    const requested = await ImagePicker.requestCameraPermissionsAsync();
    if (requested.granted) return true;

    Alert.alert(
      'Camera permission denied',
      'Without camera access you cannot capture verification photos for chamber logs.'
    );
    return false;
  } catch (err) {
    console.warn('ensureCameraPermission failed:', err?.message || err);
    Alert.alert('Camera Error', 'Could not request camera permission.');
    return false;
  }
}

/**
 * Android 8+ notification channel (required before showing notifications).
 */
export async function ensureNotificationChannel() {
  if (Platform.OS !== 'android') return;
  try {
    await Notifications.setNotificationChannelAsync('task-reminders', {
      name: 'Task reminders',
      description: 'Morning and evening chamber task reminders',
      importance: Notifications.AndroidImportance.HIGH,
      vibrationPattern: [0, 200, 200, 200],
      lightColor: '#003580',
      sound: 'default'
    });
  } catch (err) {
    console.warn('Notification channel setup failed:', err?.message || err);
  }
}

/**
 * Ask for notification permission (local scheduled reminders).
 * @returns {Promise<boolean>}
 */
export async function ensureNotificationPermission() {
  try {
    await ensureNotificationChannel();

    const existing = await Notifications.getPermissionsAsync();
    let finalStatus = existing.status;
    let requested = existing;

    if (finalStatus !== 'granted') {
      requested = await Notifications.requestPermissionsAsync({
        ios: {
          allowAlert: true,
          allowBadge: true,
          allowSound: true
        }
      });
      finalStatus = requested.status;
    }

    if (finalStatus !== 'granted') {
      const canAskAgain = requested.canAskAgain !== false;
      if (!canAskAgain) {
        Alert.alert(
          'Notifications disabled',
          'Enable notifications in Settings to get morning/evening task reminders.',
          [
            { text: 'OK', style: 'cancel' },
            { text: 'Open Settings', onPress: openAppSettings }
          ]
        );
      }
      return false;
    }
    return true;
  } catch (err) {
    console.warn('ensureNotificationPermission failed:', err?.message || err);
    return false;
  }
}
