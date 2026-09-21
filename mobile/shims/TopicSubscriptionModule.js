/**
 * Stub for ExpoTopicSubscriptionModule - not present in Expo Go.
 * Matches expo-notifications TopicSubscriptionModule.js (non-Android default).
 */
const module = {
  addListener: () => {},
  removeListeners: () => {},
  subscribeToTopicAsync: () => Promise.resolve(null),
  unsubscribeFromTopicAsync: () => Promise.resolve(null)
};

export default module;
