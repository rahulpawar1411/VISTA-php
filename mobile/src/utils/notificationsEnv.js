import { Platform } from 'react-native';
import { isRunningInExpoGo } from 'expo';

/** Android Expo Go has broken / incomplete notification channel + remote push APIs. */
export function isExpoGoAndroid() {
  return Platform.OS === 'android' && isRunningInExpoGo();
}
