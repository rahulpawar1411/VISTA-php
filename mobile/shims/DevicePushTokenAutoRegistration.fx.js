/**
 * Stub for expo-notifications DevicePushTokenAutoRegistration.
 * Upstream registers addPushTokenListener on import, which crashes Android Expo Go.
 * Remote push token auto-registration is skipped; local notifications are unaffected.
 */
export async function setAutoServerRegistrationEnabledAsync(_enabled) {
  /* no-op in this shim */
}

export async function __handlePersistedRegistrationInfoAsync(_registrationInfo) {
  /* no-op in this shim */
}
