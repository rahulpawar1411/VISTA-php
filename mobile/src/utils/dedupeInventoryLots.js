/**
 * Reports → All lots: one card per client + warehouse.
 * Same-day Morning + Evening audits must not create duplicate lots.
 */
export function dedupeInventoryLots(rows) {
  if (!Array.isArray(rows) || rows.length === 0) return [];
  const map = new Map();
  for (const r of rows) {
    if (!r) continue;
    const key = `${String(r.client_name || '')
      .trim()
      .toLowerCase()}|||${String(r.warehouse_name || '')
      .trim()
      .toLowerCase()}`;
    const prev = map.get(key);
    if (!prev) {
      map.set(key, r);
      continue;
    }
    const da = String(r.last_audit_date || r.formatted_date || r.entry_date || '').slice(0, 10);
    const db = String(prev.last_audit_date || prev.formatted_date || prev.entry_date || '').slice(
      0,
      10
    );
    if (da > db) {
      map.set(key, r);
      continue;
    }
    if (da < db) continue;
    // Same latest day — keep higher id / later physical reading
    const idA = Number(r.id) || 0;
    const idB = Number(prev.id) || 0;
    if (idA > idB) {
      map.set(key, r);
      continue;
    }
    const physA = Math.max(0, Number(r.physical_audit_count) || 0);
    const physB = Math.max(0, Number(prev.physical_audit_count) || 0);
    if (idA === idB && physA >= physB) map.set(key, r);
  }
  return Array.from(map.values());
}
