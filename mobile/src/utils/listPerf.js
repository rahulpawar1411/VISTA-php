/**
 * Shared FlatList tuning so large CRM lists stay scrollable without UI freezes.
 */
export const FLATLIST_PERF_PROPS = {
  initialNumToRender: 12,
  maxToRenderPerBatch: 8,
  windowSize: 7,
  updateCellsBatchingPeriod: 50,
  removeClippedSubviews: true,
};

/** Full-screen spinner only on first load (no rows yet). */
export function isBlockingListLoad(loading, refreshing, itemCount) {
  return Boolean(loading && !refreshing && !(itemCount > 0));
}

/** Soft overlay while refreshing in place (keeps list mounted - no blink). */
export function isSoftListLoad(loading, refreshing, itemCount) {
  return Boolean(loading && !refreshing && itemCount > 0);
}
