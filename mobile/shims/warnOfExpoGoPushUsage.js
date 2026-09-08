import { isRunningInExpoGo } from 'expo';
import { Platform } from 'expo-modules-core';

let didWarn = false;

/**
 * Upstream throws on Android Expo Go, which crashes the app as soon as
 * expo-notifications is imported (DevicePushTokenAutoRegistration side effect).
 * Warn once instead so local notifications still work in Expo Go.
 */
export const warnOfExpoGoPushUsage = () => {
  if (isRunningInExpoGo() && !didWarn) {
    didWarn = true;
    const message =
      'expo-notifications: Android remote push is not available in Expo Go (SDK 53+). ' +
      'Local notifications still work. Use a development build for remote push: ' +
      'https://docs.expo.dev/develop/development-builds/introduction/';
    if (Platform.OS === 'android' || __DEV__) {
      console.warn(message);
    }
  }
};
