/** First Reports page: 50 rows, then load 20 more at a time. */
export const INVENTORY_REPORT_FIRST_PAGE = 50;
export const INVENTORY_REPORT_MORE_PAGE = 20;

export function buildInventoryReconciliationQuery({
  offset = 0,
  limit = INVENTORY_REPORT_FIRST_PAGE,
  warehouse,
  client,
  view
} = {}) {
  const qs = new URLSearchParams({
    offset: String(Math.max(0, Number(offset) || 0)),
    limit: String(limit)
  });
  if (warehouse && warehouse !== 'All') qs.set('warehouse', String(warehouse).trim());
  if (client && client !== 'All') qs.set('client', String(client).trim());
  if (view === 'mismatch') qs.set('view', 'mismatch');
  return qs;
}

export function parseInventoryReconciliationPayload(data) {
  const rows = Array.isArray(data?.items)
    ? data.items
    : Array.isArray(data)
      ? data
      : [];
  const total = Number(data?.total ?? rows.length) || 0;
  return { rows, total };
}

export function inventoryReportHasMore(offset, fetchedCount, total) {
  const nextOffset = Number(offset) + Number(fetchedCount);
  return nextOffset < Number(total || 0);
}
