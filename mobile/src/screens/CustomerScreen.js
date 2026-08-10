 import React, { useCallback, useEffect, useMemo, useState } from 'react';
import {
  View,
  Text,
  Image,
  ImageBackground,
  StyleSheet,
  ScrollView,
  StatusBar,
  SafeAreaView,
  ActivityIndicator,
  RefreshControl,
  FlatList,
  Modal,
  Linking
} from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import FastTouchable from '../components/FastTouchable';
import { dedupeInventoryLots } from '../utils/dedupeInventoryLots';
import { buildReportReadingRows, latestReadingQty } from '../utils/buildReportReadingRows';

const TouchableOpacity = FastTouchable;

const PRODUCTION_API_URL = 'https://reeferon-crm-backend.onrender.com';

/**
 * Build absolute / data URI for chamber sensor photos.
 */
function pickLogImage(log) {
  if (!log) return null;
  if (log._logType === 'inward') {
    return (
      log.inward_material_temp_photo ||
      log.inward_vehicle_temp_photo ||
      log.inward_pod_photo ||
      log.inward_vehicle_back_side_photo ||
      null
    );
  }
  if (log._logType === 'outward') {
    return (
      log.outward_material_temp_photo ||
      log.outward_vehicle_temp_photo ||
      log.outward_pod_photo ||
      log.outward_vehicle_back_side_photo ||
      null
    );
  }
  return log.temp_sensor_image || null;
}

function resolveImageUrl(raw, baseUrl, folderHint = 'daily_temp_monitor_images') {
  if (raw == null) return null;
  let value = String(raw).trim();
  if (!value || value === 'null' || value === 'undefined') return null;

  // Already a usable URI
  if (/^https?:\/\//i.test(value) || value.startsWith('file://') || value.startsWith('content://')) {
    return value;
  }
  if (value.startsWith('data:')) return value;

  // Raw base64 (common when stored without data: prefix)
  if (/^data:image\//i.test(value)) return value;
  const looksBase64 =
    value.length > 200 &&
    !value.includes('/') &&
    !value.includes('\\') &&
    /^[A-Za-z0-9+/=\s]+$/.test(value.slice(0, 200));
  if (looksBase64 || value.startsWith('/9j/') || value.startsWith('iVBOR')) {
    const mime = value.startsWith('iVBOR') ? 'image/png' : 'image/jpeg';
    return `data:${mime};base64,${value.replace(/\s/g, '')}`;
  }

  const base = String(baseUrl || '').replace(/\/$/, '');
  if (!base) return null;

  // Normalize Windows / leading slashes
  value = value.replace(/\\/g, '/').replace(/^\/+/, '');

  // Path already includes uploads/...
  if (value.startsWith('uploads/')) return `${base}/${value}`;

  // Filename only → default sensor folder
  if (!value.includes('/')) {
    return `${base}/uploads/${folderHint}/${value}`;
  }

  return `${base}/${value}`;
}

function buildImageCandidates(raw, apiUrl, folderHint = 'daily_temp_monitor_images') {
  const urls = [];
  const push = (u) => {
    if (u && !urls.includes(u)) urls.push(u);
  };

  push(resolveImageUrl(raw, apiUrl, folderHint));

  // If logged into local server but photo lives on Render (or vice versa)
  const prod = String(PRODUCTION_API_URL || '').replace(/\/$/, '');
  const current = String(apiUrl || '').replace(/\/$/, '');
  if (prod && prod !== current) {
    push(resolveImageUrl(raw, prod, folderHint));
  }

  return urls;
}

function SensorPhotoView({ rawPath, apiUrl, folderHint = 'daily_temp_monitor_images' }) {
  const candidates = useMemo(
    () => buildImageCandidates(rawPath, apiUrl, folderHint),
    [rawPath, apiUrl, folderHint]
  );
  const [idx, setIdx] = useState(0);
  const [failed, setFailed] = useState(false);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    setIdx(0);
    setFailed(false);
    setLoading(true);
  }, [rawPath, apiUrl]);

  if (!candidates.length) {
    return (
      <View style={styles.detailImageEmpty}>
        <Ionicons name="image-outline" size={28} color="#94a3b8" />
        <Text style={styles.stateText}>No photo attached to this log.</Text>
      </View>
    );
  }

  if (failed) {
    return (
      <View style={styles.detailImageEmpty}>
        <Ionicons name="alert-circle-outline" size={28} color="#dc2626" />
        <Text style={styles.stateText}>Could not load sensor photo.</Text>
        <Text style={styles.detailImageHint} numberOfLines={2}>
          {candidates[0]}
        </Text>
      </View>
    );
  }

  const uri = candidates[Math.min(idx, candidates.length - 1)];

  return (
    <View>
      {loading ? (
        <View style={styles.detailImageLoading}>
          <ActivityIndicator color="#003580" />
        </View>
      ) : null}
      <Image
        source={{ uri }}
        style={[styles.detailImage, loading && { opacity: 0.2 }]}
        resizeMode="contain"
        onLoadStart={() => setLoading(true)}
        onLoad={() => setLoading(false)}
        onError={() => {
          if (idx + 1 < candidates.length) {
            setIdx((v) => v + 1);
            setLoading(true);
          } else {
            setLoading(false);
            setFailed(true);
          }
        }}
      />
    </View>
  );
}

/**
 * Customer mobile home — separate from DO Dashboard.
 * Logs tab filters by allowed warehouse + client access.
 */
export default function CustomerScreen({ user, token, apiUrl, onLogout }) {
  const [activeTab, setActiveTab] = useState('Dashboard'); // Dashboard | Logs | Reports | More
  const [busy, setBusy] = useState(false);

  const [warehouseFilter, setWarehouseFilter] = useState('All');
  const [clientFilter, setClientFilter] = useState('All');
  const [logType, setLogType] = useState('chambers'); // chambers | inward | outward | inventory
  const [dateFrom, setDateFrom] = useState('All');
  const [dateTo, setDateTo] = useState('All');
  const [openFilter, setOpenFilter] = useState(null); // 'warehouse' | 'client' | null
  const [selectedLog, setSelectedLog] = useState(null);
  const [showCalendarModal, setShowCalendarModal] = useState(false);
  const [calendarPickMode, setCalendarPickMode] = useState('from'); // 'from' | 'to'
  const [calendarMonth, setCalendarMonth] = useState(new Date());
  const [logs, setLogs] = useState([]);
  const [logsLoading, setLogsLoading] = useState(false);
  const [logsError, setLogsError] = useState('');
  const [refreshing, setRefreshing] = useState(false);
  const [dynamicWarehouses, setDynamicWarehouses] = useState([]);
  const [dynamicClients, setDynamicClients] = useState([]);
  const [todayLogItems, setTodayLogItems] = useState([]);
  const [homeLoading, setHomeLoading] = useState(false);
  const [homeRefreshing, setHomeRefreshing] = useState(false);
  const [homeError, setHomeError] = useState('');

  const [inventoryRows, setInventoryRows] = useState([]);
  const [inventoryLoading, setInventoryLoading] = useState(false);
  const [inventoryError, setInventoryError] = useState('');

  const [reportRows, setReportRows] = useState([]);
  const [reportWarehouses, setReportWarehouses] = useState([]);
  const [reportClients, setReportClients] = useState([]);
  const [reportWarehouseFilter, setReportWarehouseFilter] = useState('All');
  const [reportClientFilter, setReportClientFilter] = useState('All');
  const [reportView, setReportView] = useState('all'); // all | mismatch
  const [selectedReport, setSelectedReport] = useState(null);
  const [reportHistory, setReportHistory] = useState([]);
  const [reportHistoryLoading, setReportHistoryLoading] = useState(false);
  const [reportHistoryError, setReportHistoryError] = useState('');
  const [reportsLoading, setReportsLoading] = useState(false);
  const [reportsError, setReportsError] = useState('');
  const [reportsRefreshing, setReportsRefreshing] = useState(false);

  const displayName = user?.full_name || user?.email?.split('@')[0] || 'Customer';

  const authHeaders = useMemo(
    () => ({
      Accept: 'application/json',
      Authorization: `Bearer ${token}`
    }),
    [token]
  );

  const allowedClients = useMemo(() => {
    if (!user?.allowed_clients) return [];
    return String(user.allowed_clients)
      .split(',')
      .map((c) => c.trim())
      .filter(Boolean);
  }, [user?.allowed_clients]);

  const allowedWarehouses = useMemo(() => {
    if (!user?.allowed_warehouses) return [];
    return String(user.allowed_warehouses)
      .split(',')
      .map((w) => w.trim())
      .filter(Boolean);
  }, [user?.allowed_warehouses]);

  const warehouseOptions = useMemo(() => {
    // Customer must only see assigned warehouses when scope is set
    if (allowedWarehouses.length) return ['All', ...allowedWarehouses];
    return ['All', ...dynamicWarehouses];
  }, [allowedWarehouses, dynamicWarehouses]);

  const clientOptions = useMemo(() => {
    if (allowedClients.length) return ['All', ...allowedClients];
    return ['All', ...dynamicClients];
  }, [allowedClients, dynamicClients]);

  const toLocalYmd = (d = new Date()) => {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
  };

  const formatDateLabel = (value) => {
    if (!value || value === 'All') return 'All';
    const today = toLocalYmd();
    const y = new Date();
    y.setDate(y.getDate() - 1);
    const yesterday = toLocalYmd(y);
    if (value === today) return 'Today';
    if (value === yesterday) return 'Yesterday';
    return value;
  };

  const applyDateRange = (from, to) => {
    if (from === 'All' && to === 'All') {
      setDateFrom('All');
      setDateTo('All');
      return;
    }
    let nextFrom = from === 'All' ? to : from;
    let nextTo = to === 'All' ? from : to;
    if (nextFrom !== 'All' && nextTo !== 'All' && nextTo < nextFrom) {
      nextTo = nextFrom;
    }
    setDateFrom(nextFrom || 'All');
    setDateTo(nextTo || 'All');
  };

  const suggestToday = () => {
    const t = toLocalYmd();
    applyDateRange(t, t);
  };

  const suggestYesterday = () => {
    const d = new Date();
    d.setDate(d.getDate() - 1);
    const y = toLocalYmd(d);
    applyDateRange(y, y);
  };

  const suggestLast7 = () => {
    const end = new Date();
    const start = new Date();
    start.setDate(end.getDate() - 6);
    applyDateRange(toLocalYmd(start), toLocalYmd(end));
  };

  const clearDateRange = () => applyDateRange('All', 'All');

  const clearAllFilters = () => {
    setWarehouseFilter('All');
    setClientFilter('All');
    setOpenFilter(null);
    if (logType === 'chambers') {
      const t = toLocalYmd();
      applyDateRange(t, t);
    } else if (logType !== 'inventory') {
      applyDateRange('All', 'All');
    }
  };

  const normalizeLogRow = (row, type) => {
    if (type === 'inward') {
      return {
        ...row,
        _logType: 'inward',
        client_name: row.inward_client_name || row.client_name || null,
        warehouse_name: row.warehouse_name || null,
        chamber_name: row.inward_vehicle_no ? `Vehicle ${row.inward_vehicle_no}` : row.inward_dock_no || 'Inward',
        entry_date: row.inward_entry_date || row.entry_date || null,
        formatted_date: row.inward_entry_date || row.formatted_date || null,
        box_temp: row.inward_material_temp ?? row.inward_vehicle_temp ?? null,
        chamber_temp: row.inward_vehicle_temp ?? null,
        shift: row.inward_material_type || null,
        box_count: row.inward_received_boxes_qty ?? row.inward_received_qty ?? null
      };
    }
    if (type === 'outward') {
      return {
        ...row,
        _logType: 'outward',
        client_name: row.outward_client_name || row.client_name || null,
        warehouse_name: row.warehouse_name || null,
        chamber_name: row.outward_vehicle_no
          ? `Vehicle ${row.outward_vehicle_no}`
          : row.outward_dock_no || 'Outward',
        entry_date: row.outward_entry_date || row.entry_date || null,
        formatted_date: row.outward_entry_date || row.formatted_date || null,
        box_temp: row.outward_material_temp ?? row.outward_vehicle_temp ?? null,
        chamber_temp: row.outward_vehicle_temp ?? null,
        shift: row.outward_material_type || null,
        box_count: row.outward_loaded_boxes_qty ?? row.outward_invoice_qty ?? null
      };
    }
    return { ...row, _logType: 'chambers' };
  };

  // Keep To >= From whenever either side changes
  useEffect(() => {
    if (dateFrom === 'All' || dateTo === 'All') return;
    if (dateTo < dateFrom) setDateTo(dateFrom);
  }, [dateFrom, dateTo]);

  const openCalendar = (mode = 'from') => {
    setOpenFilter(null);
    const seed =
      mode === 'to' && dateTo !== 'All'
        ? dateTo
        : dateFrom !== 'All'
          ? dateFrom
          : toLocalYmd();
    setCalendarMonth(new Date(`${seed}T12:00:00`));
    setCalendarPickMode(mode);
    setShowCalendarModal(true);
  };

  const getCalendarDays = (dateObj) => {
    const year = dateObj.getFullYear();
    const month = dateObj.getMonth();
    const firstDay = new Date(year, month, 1);
    const totalDays = new Date(year, month + 1, 0).getDate();
    const startDayOfWeek = firstDay.getDay();
    const days = [];
    for (let i = 0; i < startDayOfWeek; i += 1) days.push(null);
    for (let day = 1; day <= totalDays; day += 1) {
      days.push(new Date(year, month, day));
    }
    return days;
  };

  const normName = (v) => String(v || '').trim().toLowerCase();

  const applyScope = useCallback(
    (items, whFilter = 'All', clFilter = 'All') => {
      return (items || []).filter((row) => {
        const rowClient = normName(row.client_name);
        const rowWh = normName(row.warehouse_name);

        // Strict: if customer has assigned clients, row must match one of them
        const clientOk =
          allowedClients.length === 0
            ? true
            : !!rowClient && allowedClients.some((c) => normName(c) === rowClient);

        // Strict: if customer has assigned warehouses, row must match one of them
        // (no bypass for missing warehouse_name)
        const whOk =
          allowedWarehouses.length === 0
            ? true
            : !!rowWh && allowedWarehouses.some((w) => normName(w) === rowWh);

        // UI filter chips (must also stay inside assigned scope)
        const whFilterOk =
          whFilter === 'All' ||
          (rowWh === normName(whFilter) &&
            (allowedWarehouses.length === 0 ||
              allowedWarehouses.some((w) => normName(w) === normName(whFilter))));
        const clientFilterOk =
          clFilter === 'All' ||
          (rowClient === normName(clFilter) &&
            (allowedClients.length === 0 ||
              allowedClients.some((c) => normName(c) === normName(clFilter))));

        return clientOk && whOk && whFilterOk && clientFilterOk;
      });
    },
    [allowedClients, allowedWarehouses]
  );

  // Keep selected filters inside assigned scope
  useEffect(() => {
    if (
      warehouseFilter !== 'All' &&
      allowedWarehouses.length > 0 &&
      !allowedWarehouses.some((w) => normName(w) === normName(warehouseFilter))
    ) {
      setWarehouseFilter('All');
    }
    if (
      clientFilter !== 'All' &&
      allowedClients.length > 0 &&
      !allowedClients.some((c) => normName(c) === normName(clientFilter))
    ) {
      setClientFilter('All');
    }
  }, [allowedWarehouses, allowedClients, warehouseFilter, clientFilter]);

  useEffect(() => {
    if (
      reportWarehouseFilter !== 'All' &&
      allowedWarehouses.length > 0 &&
      !allowedWarehouses.some((w) => normName(w) === normName(reportWarehouseFilter))
    ) {
      setReportWarehouseFilter('All');
    }
    if (
      reportClientFilter !== 'All' &&
      allowedClients.length > 0 &&
      !allowedClients.some((c) => normName(c) === normName(reportClientFilter))
    ) {
      setReportClientFilter('All');
    }
  }, [allowedWarehouses, allowedClients, reportWarehouseFilter, reportClientFilter]);

  const handleLogoutPress = async () => {
    if (busy) return;
    setBusy(true);
    try {
      await onLogout?.();
    } finally {
      setBusy(false);
    }
  };

  const loadHomeOverview = useCallback(async () => {
    if (!apiUrl || !token) return;
    setHomeLoading(true);
    setHomeError('');
    try {
      const today = toLocalYmd();
      const qs = new URLSearchParams({
        page: '1',
        limit: '100',
        fromDate: today,
        toDate: today
      });
      const res = await fetch(`${apiUrl}/api/chamber-temp?${qs.toString()}`, {
        method: 'GET',
        headers: {
          Accept: 'application/json',
          Authorization: `Bearer ${token}`
        }
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        if (res.status === 401) {
          throw new Error('Session expired or account not found on this server. Logout and login again.');
        }
        throw new Error(data.message || data.error || `Failed to load overview (${res.status})`);
      }

      const items = Array.isArray(data?.items)
        ? data.items
        : Array.isArray(data)
          ? data
          : [];
      const scoped = applyScope(items).filter((row) => {
        const d = String(row.formatted_date || row.entry_date || '').slice(0, 10);
        return d === today;
      });
      setTodayLogItems(scoped);
    } catch (err) {
      const msg =
        err?.message === 'Network request failed'
          ? `Cannot reach server: ${apiUrl}. Open Login settings → Local server, or use Production.`
          : err.message || 'Failed to load overview.';
      console.warn('Customer home overview failed:', msg);
      setTodayLogItems([]);
      setHomeError(msg);
    } finally {
      setHomeLoading(false);
      setHomeRefreshing(false);
    }
  }, [apiUrl, token, applyScope]);

  const loadLogs = useCallback(async () => {
    if (!apiUrl || !token) return;
    setLogsLoading(true);
    setLogsError('');
    try {
      let from = dateFrom;
      let to = dateTo;
      if (logType === 'chambers' && (from === 'All' || to === 'All')) {
        const t = toLocalYmd();
        from = from === 'All' ? t : from;
        to = to === 'All' ? t : to;
      }

      const qs = new URLSearchParams({
        page: '1',
        limit: '300'
      });
      if (warehouseFilter && warehouseFilter !== 'All') {
        qs.set('warehouse', warehouseFilter);
      }
      if (clientFilter && clientFilter !== 'All') {
        qs.set('client', clientFilter);
      }
      if (from && from !== 'All') {
        qs.set('fromDate', from);
      }
      if (to && to !== 'All') {
        qs.set('toDate', to);
      }

      const endpoint =
        logType === 'inward'
          ? '/api/inward-logs'
          : logType === 'outward'
            ? '/api/outward-logs'
            : '/api/chamber-temp';

      const res = await fetch(`${apiUrl}${endpoint}?${qs.toString()}`, {
        method: 'GET',
        headers: authHeaders
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        if (res.status === 401) {
          throw new Error('Session expired or account not found on this server. Logout and login again.');
        }
        throw new Error(data.message || data.error || `Failed to load logs (${res.status})`);
      }

      let items = Array.isArray(data?.items)
        ? data.items
        : Array.isArray(data)
          ? data
          : [];
      items = items.map((row) => normalizeLogRow(row, logType));
      items = applyScope(items, warehouseFilter, clientFilter);

      if (logType === 'chambers') {
        items = items.filter((row) => {
          const d = String(row.formatted_date || row.entry_date || '').slice(0, 10);
          if (!d) return from === 'All' && to === 'All';
          if (from && from !== 'All' && d < from) return false;
          if (to && to !== 'All' && d > to) return false;
          return true;
        });
        items.sort((a, b) => {
          // LIFO: newest task first (before opening detail)
          const da = String(a.formatted_date || a.entry_date || '').slice(0, 10);
          const db = String(b.formatted_date || b.entry_date || '').slice(0, 10);
          if (db !== da) return db.localeCompare(da);
          const ta = String(a.created_at || a.updated_at || a.photo_capture_time || '')
            .replace('T', ' ')
            .slice(0, 19);
          const tb = String(b.created_at || b.updated_at || b.photo_capture_time || '')
            .replace('T', ' ')
            .slice(0, 19);
          if (tb !== ta) return tb.localeCompare(ta);
          return (Number(b.id) || 0) - (Number(a.id) || 0);
        });
      }

      setLogs(items);

      if (allowedWarehouses.length === 0) {
        const wh = [
          ...new Set(
            items
              .map((r) => r.warehouse_name)
              .filter(Boolean)
              .map((n) => String(n).trim())
          )
        ].sort((a, b) => a.localeCompare(b));
        setDynamicWarehouses(wh);
      }
      if (allowedClients.length === 0) {
        const cl = [
          ...new Set(
            items
              .map((r) => r.client_name)
              .filter(Boolean)
              .map((n) => String(n).trim())
          )
        ].sort((a, b) => a.localeCompare(b));
        setDynamicClients(cl);
      }
    } catch (err) {
      const msg =
        err?.message === 'Network request failed'
          ? `Cannot reach server: ${apiUrl}. Open Login settings → Local server, or use Production.`
          : err.message || 'Failed to load logs.';
      console.warn('Customer logs load failed:', msg);
      setLogs([]);
      setLogsError(msg);
    } finally {
      setLogsLoading(false);
      setRefreshing(false);
    }
  }, [
    apiUrl,
    token,
    authHeaders,
    warehouseFilter,
    clientFilter,
    dateFrom,
    dateTo,
    logType,
    applyScope,
    allowedClients.length,
    allowedWarehouses.length
  ]);

  const loadInventory = useCallback(async () => {
    if (!apiUrl || !token) return;
    setInventoryLoading(true);
    setInventoryError('');
    try {
      const qs = new URLSearchParams();
      if (warehouseFilter && warehouseFilter !== 'All') qs.set('warehouse', warehouseFilter);
      if (clientFilter && clientFilter !== 'All') qs.set('client', clientFilter);
      const res = await fetch(
        `${apiUrl}/api/dashboard/inventory-reconciliation${qs.toString() ? `?${qs}` : ''}`,
        { headers: authHeaders }
      );
      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        if (res.status === 401) {
          throw new Error('Session expired or account not found on this server. Logout and login again.');
        }
        throw new Error(data.message || data.error || `Failed to load inventory (${res.status})`);
      }
      const rows = Array.isArray(data?.items)
        ? data.items
        : Array.isArray(data)
          ? data
          : [];
      // Double-scope on client (backend also scopes for customer role)
      setInventoryRows(applyScope(rows, warehouseFilter, clientFilter));
    } catch (err) {
      const msg =
        err?.message === 'Network request failed'
          ? `Cannot reach server: ${apiUrl}. Open Login settings → Local server, or use Production.`
          : err.message || 'Failed to load inventory.';
      console.warn('Customer inventory load failed:', msg);
      setInventoryRows([]);
      setInventoryError(msg);
    } finally {
      setInventoryLoading(false);
      setRefreshing(false);
    }
  }, [apiUrl, token, authHeaders, warehouseFilter, clientFilter, applyScope]);

  const loadReports = useCallback(async () => {
    if (!apiUrl || !token) return;
    setReportsLoading(true);
    setReportsError('');
    try {
      const qs = new URLSearchParams();
      if (reportWarehouseFilter && reportWarehouseFilter !== 'All') {
        qs.set('warehouse', reportWarehouseFilter);
      }
      if (reportClientFilter && reportClientFilter !== 'All') {
        qs.set('client', reportClientFilter);
      }
      const res = await fetch(
        `${apiUrl}/api/dashboard/inventory-reconciliation${qs.toString() ? `?${qs}` : ''}`,
        { headers: authHeaders }
      );
      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        if (res.status === 401) {
          throw new Error('Session expired or account not found on this server. Logout and login again.');
        }
        throw new Error(data.message || data.error || `Failed to load inventory (${res.status})`);
      }
      const rows = Array.isArray(data?.items)
        ? data.items
        : Array.isArray(data)
          ? data
          : [];
      const scoped = applyScope(rows);

      const whSet = new Set();
      const clientSet = new Set();
      scoped.forEach((r) => {
        if (r.warehouse_name) whSet.add(String(r.warehouse_name).trim());
        if (r.client_name) clientSet.add(String(r.client_name).trim());
      });
      setReportWarehouses(Array.from(whSet).sort((a, b) => a.localeCompare(b)));
      setReportClients(Array.from(clientSet).sort((a, b) => a.localeCompare(b)));
      setReportRows(scoped);
    } catch (err) {
      const msg =
        err?.message === 'Network request failed'
          ? `Cannot reach server: ${apiUrl}. Open Login settings → Local server, or use Production.`
          : err.message || 'Failed to load inventory reports.';
      setReportRows([]);
      setReportsError(msg);
    } finally {
      setReportsLoading(false);
      setReportsRefreshing(false);
    }
  }, [apiUrl, token, authHeaders, applyScope, reportWarehouseFilter, reportClientFilter]);

  /** LIFO: last-in (newest DO audit / update) first on outer Reports list */
  const sortLotsLifo = (rows) =>
    [...(rows || [])].sort((a, b) => {
      const dateA = String(a.last_audit_date || a.formatted_date || a.entry_date || '')
        .slice(0, 10);
      const dateB = String(b.last_audit_date || b.formatted_date || b.entry_date || '')
        .slice(0, 10);
      if (dateB !== dateA) {
        if (!dateA) return 1;
        if (!dateB) return -1;
        return dateB.localeCompare(dateA);
      }
      const timeA = String(
        a.updated_at || a.created_at || a.last_audit_date || ''
      ).replace('T', ' ').slice(0, 19);
      const timeB = String(
        b.updated_at || b.created_at || b.last_audit_date || ''
      ).replace('T', ' ').slice(0, 19);
      if (timeB !== timeA) {
        if (!timeA) return 1;
        if (!timeB) return -1;
        return timeB.localeCompare(timeA);
      }
      const idDiff = (Number(b.id) || 0) - (Number(a.id) || 0);
      if (idDiff !== 0) return idDiff;
      return String(a.client_name || '').localeCompare(String(b.client_name || ''));
    });

  const filteredInventoryRows = useMemo(() => {
    return sortLotsLifo(dedupeInventoryLots(inventoryRows));
  }, [inventoryRows]);

  const filteredReportRows = useMemo(() => {
    let rows = reportRows;
    if (reportWarehouseFilter && reportWarehouseFilter !== 'All') {
      const whLower = reportWarehouseFilter.toLowerCase().trim();
      rows = rows.filter(
        (r) =>
          r.warehouse_name &&
          String(r.warehouse_name).toLowerCase().trim() === whLower
      );
    }
    if (reportClientFilter && reportClientFilter !== 'All') {
      const cLower = reportClientFilter.toLowerCase().trim();
      rows = rows.filter(
        (r) => r.client_name && String(r.client_name).toLowerCase().trim() === cLower
      );
    }
    if (reportView === 'mismatch') {
      rows = rows.filter((r) => {
        const bal = Math.max(0, Number(r.calculated_balance) || 0);
        const phys = Math.max(0, Number(r.physical_audit_count) || 0);
        return bal - phys !== 0;
      });
    }
    return sortLotsLifo(dedupeInventoryLots(rows));
  }, [reportRows, reportWarehouseFilter, reportClientFilter, reportView]);

  const reportSummary = useMemo(() => {
    let inward = 0;
    let outward = 0;
    let mismatches = 0;
    let totalBoxes = 0;
    filteredReportRows.forEach((r) => {
      inward += Math.max(0, Number(r.total_inward_boxes) || 0);
      outward += Math.max(0, Number(r.total_outward_boxes) || 0);
      const bal = Math.max(0, Number(r.calculated_balance) || 0);
      const phys = Math.max(0, Number(r.physical_audit_count) || 0);
      // Total after DO task = physical audit count (latest logged boxes)
      totalBoxes += phys;
      if (bal - phys !== 0) mismatches += 1;
    });
    return {
      lots: filteredReportRows.length,
      inward,
      outward,
      mismatches,
      totalBoxes
    };
  }, [filteredReportRows]);

  const inventorySummary = useMemo(() => {
    let totalBoxes = 0;
    filteredInventoryRows.forEach((r) => {
      totalBoxes += Math.max(0, Number(r.physical_audit_count) || 0);
    });
    return {
      lots: filteredInventoryRows.length,
      totalBoxes
    };
  }, [filteredInventoryRows]);

  /** Latest total boxes after DO task (physical audit). */
  const getLotTotalBoxes = (item) => {
    if (item == null) return 0;
    if (item.physical_audit_count != null && item.physical_audit_count !== '') {
      return Math.max(0, Number(item.physical_audit_count) || 0);
    }
    return Math.max(0, Number(item.calculated_balance) || 0);
  };
  const reportWarehouseOptions = useMemo(
    () => ['All', ...(allowedWarehouses.length ? allowedWarehouses : reportWarehouses)],
    [allowedWarehouses, reportWarehouses]
  );
  const reportClientOptions = useMemo(
    () => ['All', ...(allowedClients.length ? allowedClients : reportClients)],
    [allowedClients, reportClients]
  );

  const to24hTime = (value) => {
    if (value == null || String(value).trim() === '') return null;
    const s = String(value).trim();

    const iso = s.match(/(?:T|\s)(\d{1,2}):(\d{2})(?::\d{2})?/);
    if (iso && !/[APMapm]{2}/.test(s)) {
      const hh = String(Math.min(23, parseInt(iso[1], 10))).padStart(2, '0');
      const mm = iso[2];
      return `${hh}:${mm}`;
    }

    const ampm = s.match(/(\d{1,2}):(\d{2})(?::\d{2})?\s*([AaPp][Mm])/);
    if (ampm) {
      let h = parseInt(ampm[1], 10);
      const m = ampm[2];
      const ap = ampm[3].toUpperCase();
      if (ap === 'PM' && h < 12) h += 12;
      if (ap === 'AM' && h === 12) h = 0;
      return `${String(h).padStart(2, '0')}:${m}`;
    }

    const h24 = s.match(/^(\d{1,2}):(\d{2})(?::\d{2})?$/);
    if (h24) {
      return `${String(Math.min(23, parseInt(h24[1], 10))).padStart(2, '0')}:${h24[2]}`;
    }

    const d = new Date(s);
    if (!Number.isNaN(d.getTime())) {
      return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
    }
    return null;
  };

  const formatReportTime = (row) => {
    const candidates = [row.created_at, row.submit_time, row.photo_capture_time, row.inspection_time];
    for (const c of candidates) {
      const t = to24hTime(c);
      if (t) return t;
    }
    return '—';
  };

  const openReportDetail = useCallback(
    async (row) => {
      // Block opening inventory for out-of-scope client/warehouse
      if (applyScope([row]).length === 0) {
        setSelectedReport(row);
        setReportHistory([]);
        setReportHistoryError('This lot is outside your assigned warehouse / client access.');
        setReportHistoryLoading(false);
        return;
      }
      setSelectedReport(row);
      setReportHistory([]);
      setReportHistoryError('');
      setReportHistoryLoading(true);
      try {
        const qs = new URLSearchParams({
          page: '1',
          limit: '200',
          export: '1'
        });
        if (row.warehouse_name) qs.set('warehouse', String(row.warehouse_name).trim());
        if (row.client_name) qs.set('client', String(row.client_name).trim());

        const res = await fetch(`${apiUrl}/api/chamber-temp?${qs.toString()}`, {
          headers: authHeaders
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
          throw new Error(data.message || data.error || `Failed to load history (${res.status})`);
        }
        let items = Array.isArray(data?.items) ? data.items : Array.isArray(data) ? data : [];
        items = applyScope(items);

        // Client + warehouse history only (keep all shifts/chambers so 65→60 Out calc works)
        const clientNeedle = normName(row.client_name);
        const whNeedle = normName(row.warehouse_name);
        items = items.filter((r) => {
          const clientMatch = !clientNeedle || normName(r.client_name) === clientNeedle;
          const whMatch = !whNeedle || normName(r.warehouse_name) === whNeedle;
          return clientMatch && whMatch;
        });

        setReportHistory(buildReportReadingRows(items));
      } catch (err) {
        setReportHistory([]);
        setReportHistoryError(err.message || 'Failed to load day-wise qty.');
      } finally {
        setReportHistoryLoading(false);
      }
    },
    [apiUrl, authHeaders, applyScope]
  );

  const closeReportDetail = () => {
    setSelectedReport(null);
    setReportHistory([]);
    setReportHistoryError('');
    setReportHistoryLoading(false);
  };

  useEffect(() => {
    if (activeTab === 'Dashboard') loadHomeOverview();
  }, [activeTab, loadHomeOverview]);

  useEffect(() => {
    if (activeTab === 'Logs') {
      if (logType === 'inventory') loadInventory();
      else loadLogs();
    }
  }, [activeTab, logType, loadLogs, loadInventory]);

  useEffect(() => {
    if (activeTab === 'Reports') loadReports();
  }, [activeTab, loadReports]);

  const onRefresh = () => {
    setRefreshing(true);
    if (logType === 'inventory') loadInventory();
    else loadLogs();
  };

  const onHomeRefresh = () => {
    setHomeRefreshing(true);
    loadHomeOverview();
  };

  const renderFilterDropdown = (key, label, options, selected, onSelect, formatOption) => {
    const open = openFilter === key;
    const selectedLabel = formatOption ? formatOption(selected) : selected;
    const isActive = selected && selected !== 'All';
    return (
      <TouchableOpacity
        style={[styles.filterChip, isActive && styles.filterChipActive]}
        onPress={() => setOpenFilter(open ? null : key)}
        activeOpacity={0.85}
      >
        <Ionicons
          name={key === 'warehouse' ? 'business-outline' : 'people-outline'}
          size={14}
          color={isActive ? '#003580' : '#64748b'}
        />
        <View style={styles.filterChipTextWrap}>
          <Text style={styles.filterChipLabel}>{label}</Text>
          <Text style={[styles.filterChipValue, isActive && styles.filterChipValueActive]} numberOfLines={1}>
            {selectedLabel || 'All'}
          </Text>
        </View>
        <Ionicons name="chevron-down" size={14} color={isActive ? '#003580' : '#94a3b8'} />
      </TouchableOpacity>
    );
  };

  const renderFilterPickerModal = () => {
    const isWarehouse = openFilter === 'warehouse';
    const isClient = openFilter === 'client';
    const isReportWarehouse = openFilter === 'reportWarehouse';
    const isReportClient = openFilter === 'reportClient';
    if (!isWarehouse && !isClient && !isReportWarehouse && !isReportClient) return null;

    const title = isWarehouse || isReportWarehouse ? 'Select warehouse' : 'Select client';
    const options = isWarehouse
      ? warehouseOptions
      : isClient
        ? clientOptions
        : isReportWarehouse
          ? reportWarehouseOptions
          : reportClientOptions;
    const selected = isWarehouse
      ? warehouseFilter
      : isClient
        ? clientFilter
        : isReportWarehouse
          ? reportWarehouseFilter
          : reportClientFilter;
    const onSelect = (v) => {
      if (isWarehouse) setWarehouseFilter(v);
      else if (isClient) setClientFilter(v);
      else if (isReportWarehouse) setReportWarehouseFilter(v);
      else setReportClientFilter(v);
      setOpenFilter(null);
    };

    return (
      <Modal
        visible
        transparent
        animationType="slide"
        onRequestClose={() => setOpenFilter(null)}
      >
        <View style={styles.filterModalBackdrop}>
          <TouchableOpacity
            style={StyleSheet.absoluteFill}
            activeOpacity={1}
            pressBorder={false}
            onPress={() => setOpenFilter(null)}
          />
          <View style={styles.filterModalSheet}>
            <View style={styles.filterModalHandle} />
            <Text style={styles.filterModalTitle}>{title}</Text>
            <ScrollView
              style={styles.filterModalList}
              keyboardShouldPersistTaps="handled"
              showsVerticalScrollIndicator={false}
            >
              {options.map((opt) => {
                const active = selected === opt;
                return (
                  <TouchableOpacity
                    key={`picker-${opt}`}
                    style={[styles.filterModalItem, active && styles.filterModalItemActive]}
                    onPress={() => onSelect(opt)}
                    activeOpacity={0.85}
                  >
                    <Text
                      style={[styles.filterModalItemText, active && styles.filterModalItemTextActive]}
                      numberOfLines={2}
                    >
                      {opt}
                    </Text>
                    {active ? <Ionicons name="checkmark-circle" size={18} color="#003580" /> : null}
                  </TouchableOpacity>
                );
              })}
            </ScrollView>
            <TouchableOpacity
              style={styles.filterModalClose}
              onPress={() => setOpenFilter(null)}
              activeOpacity={0.85}
            >
              <Text style={styles.filterModalCloseText}>Close</Text>
            </TouchableOpacity>
          </View>
        </View>
      </Modal>
    );
  };

  const renderDetailRow = (label, value) => {
    if (value == null || value === '') return null;
    return (
      <View style={styles.logDetailRow}>
        <Text style={styles.logDetailLabel}>{label}</Text>
        <Text style={styles.logDetailValue}>{String(value)}</Text>
      </View>
    );
  };

  const renderReportItem = ({ item }) => {
    const inward = Math.max(0, Number(item.total_inward_boxes) || 0);
    const outward = Math.max(0, Number(item.total_outward_boxes) || 0);
    const balance = Math.max(0, Number(item.calculated_balance) || 0);
    const totalBoxes = getLotTotalBoxes(item);
    const outOfStock = totalBoxes === 0;
    return (
      <TouchableOpacity
        style={styles.dailyCard}
        onPress={() => openReportDetail(item)}
        activeOpacity={0.85}
      >
        <View style={styles.dailyTop}>
          <View style={styles.dailyTextCol}>
            <Text style={styles.dailyChamber} numberOfLines={1}>
              {item.client_name || 'Client'}
            </Text>
            <Text style={styles.dailyMetaLine} numberOfLines={1}>
              {item.chamber_name || 'Chamber'}
              {item.warehouse_name ? ` · ${item.warehouse_name}` : ''}
              {` · In ${inward} · Out ${outward} · Bal ${balance}`}
              {` · Total ${totalBoxes}`}
            </Text>
            {outOfStock ? (
              <Text style={styles.outOfStockTag} numberOfLines={1}>
                Out of stock
              </Text>
            ) : null}
          </View>
          <View style={styles.totalBoxesCol}>
            {outOfStock ? (
              <Text style={styles.outOfStockValue}>0</Text>
            ) : (
              <Text style={styles.totalBoxesValue}>{totalBoxes}</Text>
            )}
            <Text style={[styles.totalBoxesLabel, outOfStock && styles.outOfStockLabel]}>
              {outOfStock ? 'Out of stock' : 'boxes'}
            </Text>
          </View>
        </View>
      </TouchableOpacity>
    );
  };

  const renderLogItem = ({ item }) => {
    if (item._logType === 'chambers' || (!item._logType && logType === 'chambers')) {
      const dateLabel = formatDateLabel(
        String(item.formatted_date || item.entry_date || '').slice(0, 10) || 'All'
      );
      const temp =
        item.box_temp != null
          ? `${item.box_temp}°C`
          : item.chamber_temp != null
            ? `${item.chamber_temp}°C`
            : '—';
      return (
        <TouchableOpacity
          style={styles.dailyCard}
          onPress={() => setSelectedLog(item)}
          activeOpacity={0.85}
        >
          <View style={styles.dailyTop}>
            <View style={styles.dailyTextCol}>
              <Text style={styles.dailyChamber} numberOfLines={1}>
                {item.chamber_name || 'Chamber'}
                {item.client_name ? ` · ${item.client_name}` : ''}
              </Text>
              <Text style={styles.dailyMetaLine} numberOfLines={1}>
                {dateLabel}
                {item.shift ? ` · ${item.shift}` : ''}
                {item.box_count != null ? ` · ${item.box_count} boxes` : ''}
                {item.warehouse_name ? ` · ${item.warehouse_name}` : ''}
              </Text>
            </View>
            <Text style={styles.dailyTemp}>{temp}</Text>
          </View>
        </TouchableOpacity>
      );
    }

    const typeLabel =
      item._logType === 'inward' ? 'Inward' : item._logType === 'outward' ? 'Outward' : 'Chamber';
    const rightValue =
      item.box_temp != null
        ? `${item.box_temp}°C`
        : item.chamber_temp != null
          ? `${item.chamber_temp}°C`
          : item.box_count != null
            ? `${item.box_count}`
            : '—';
    return (
      <TouchableOpacity
        style={styles.compactLogCard}
        onPress={() => setSelectedLog(item)}
        activeOpacity={0.85}
      >
        <View style={{ flex: 1 }}>
          <View style={styles.logTitleRow}>
            <Text style={styles.logTypeTag}>{typeLabel}</Text>
            <Text style={styles.logClient} numberOfLines={1}>
              {item.client_name || 'Client'}
            </Text>
          </View>
          <Text style={styles.logMeta} numberOfLines={2}>
            {item.chamber_name || '—'}
            {item.warehouse_name ? ` · ${item.warehouse_name}` : ''}
            {' · '}
            {String(item.formatted_date || item.entry_date || '').slice(0, 10) || '—'}
            {item.shift ? ` · ${item.shift}` : ''}
          </Text>
        </View>
        <Text style={styles.logTemp}>{rightValue}</Text>
      </TouchableOpacity>
    );
  };

  const renderLogDetailScreen = () => {
    if (!selectedLog) return null;
    const item = selectedLog;
    const logTypeKey = item._logType || 'chambers';
    const imageFolder =
      logTypeKey === 'inward'
        ? 'inward_temp_monitor_images'
        : logTypeKey === 'outward'
          ? 'outward_temp_monitor_images'
          : 'daily_temp_monitor_images';
    const imagePath = pickLogImage(item);

    const detailFields =
      logTypeKey === 'inward'
        ? [
            ['Client', item.client_name],
            ['Vehicle', item.inward_vehicle_no],
            ['Warehouse', item.warehouse_name],
            ['Date', String(item.formatted_date || item.entry_date || '').slice(0, 10)],
            [
              'Vehicle temp',
              item.inward_vehicle_temp != null ? `${item.inward_vehicle_temp}°C` : null
            ],
            [
              'Material temp',
              item.inward_material_temp != null ? `${item.inward_material_temp}°C` : null
            ],
            ['Received boxes', item.inward_received_boxes_qty ?? item.box_count],
            ['Dock', item.inward_dock_no],
            ['Reference', item.reference_no],
            [
              'DO name',
              item.inward_unloading_supervisor_name ||
                item.monitor_supervisor_name ||
                (item.operator_email ? String(item.operator_email).split('@')[0] : null)
            ],
            [
              'Time',
              item.inward_vehicle_reporting_time ||
                item.inward_unloading_start_time ||
                (item.inward_created_at
                  ? String(item.inward_created_at).replace('T', ' ').slice(0, 19)
                  : null) ||
                (item.created_at ? String(item.created_at).replace('T', ' ').slice(0, 19) : null)
            ]
          ]
        : logTypeKey === 'outward'
          ? [
              ['Client', item.client_name],
              ['Vehicle', item.outward_vehicle_no],
              ['Warehouse', item.warehouse_name],
              ['Date', String(item.formatted_date || item.entry_date || '').slice(0, 10)],
              [
                'Vehicle temp',
                item.outward_vehicle_temp != null ? `${item.outward_vehicle_temp}°C` : null
              ],
              [
                'Material temp',
                item.outward_material_temp != null ? `${item.outward_material_temp}°C` : null
              ],
              ['Boxes', item.box_count],
              ['Dock', item.outward_dock_no],
              ['Reference', item.reference_no],
              [
                'DO name',
                item.outward_loading_supervisor_name ||
                  item.monitor_supervisor_name ||
                  (item.operator_email ? String(item.operator_email).split('@')[0] : null)
              ],
              [
                'Time',
                item.outward_vehicle_reporting_time ||
                  item.outward_loading_start_time ||
                  (item.outward_created_at
                    ? String(item.outward_created_at).replace('T', ' ').slice(0, 19)
                    : null) ||
                  (item.created_at ? String(item.created_at).replace('T', ' ').slice(0, 19) : null)
              ]
            ]
          : [
              ['Client', item.client_name],
              ['Warehouse', item.warehouse_name],
              ['Chamber', item.chamber_name],
              ['Chamber type', item.chamber_type],
              ['Shift', item.shift],
              ['Inspection time', item.inspection_time],
              ['Date', item.formatted_date || item.entry_date],
              [
                'Temperature',
                item.box_temp != null
                  ? `${item.box_temp}°C`
                  : item.chamber_temp != null
                    ? `${item.chamber_temp}°C`
                    : null
              ],
              [
                'Box qty',
                item.box_count != null && item.box_count !== '' ? `${item.box_count} boxes` : null
              ],
              ['Supervisor', item.monitor_supervisor_name],
              ['Operator', item.operator_email],
              ['Reference', item.reference_no],
              ['Photo time', item.photo_capture_time],
              [
                'Time variance',
                item.time_variance_minutes != null ? `${item.time_variance_minutes} min` : null
              ],
              ['Remarks', item.remarks],
              [
                'Updates',
                item.update_count != null && Number(item.update_count) > 0
                  ? String(item.update_count)
                  : null
              ]
            ];

    const tempText =
      item.box_temp != null
        ? `${item.box_temp}°C`
        : item.chamber_temp != null
          ? `${item.chamber_temp}°C`
          : '—';

    return (
      <Modal
        visible={Boolean(selectedLog)}
        animationType="slide"
        onRequestClose={() => setSelectedLog(null)}
      >
        <SafeAreaView style={styles.detailSafe}>
          <StatusBar barStyle="dark-content" backgroundColor="#ffffff" />
          <View style={styles.detailHeader}>
            <TouchableOpacity
              style={styles.detailBackBtn}
              onPress={() => setSelectedLog(null)}
              activeOpacity={0.85}
            >
              <Ionicons name="arrow-back" size={22} color="#0f172a" />
            </TouchableOpacity>
            <View style={{ flex: 1 }}>
              <Text style={styles.detailTitle} numberOfLines={1}>
                {logTypeKey === 'inward' ? 'Inward log' : logTypeKey === 'outward' ? 'Outward log' : 'Log details'}
              </Text>
              <Text style={styles.detailSub} numberOfLines={1}>
                {item.client_name || 'Client'}
              </Text>
            </View>
          </View>

          <ScrollView contentContainerStyle={styles.detailBody} showsVerticalScrollIndicator={false}>
            {logTypeKey === 'chambers' ? (
              <View style={styles.detailHeroCard}>
                <Text style={styles.detailHeroTemp}>{tempText}</Text>
                <Text style={styles.detailHeroMeta}>
                  {item.box_count != null && item.box_count !== '' ? `${item.box_count} boxes` : 'Box qty —'}
                  {item.shift ? ` · ${item.shift}` : ''}
                </Text>
              </View>
            ) : null}

            <View style={styles.detailCard}>
              {detailFields.map(([label, value]) => renderDetailRow(label, value))}
            </View>

            <View style={styles.detailCard}>
              <Text style={styles.detailSectionTitle}>
                {logTypeKey === 'chambers' ? 'Sensor photo' : 'Log photo'}
              </Text>
              <SensorPhotoView rawPath={imagePath} apiUrl={apiUrl} folderHint={imageFolder} />
            </View>
          </ScrollView>
        </SafeAreaView>
      </Modal>
    );
  };

  const renderInventoryDetailModal = () => {
    const readingTotal = latestReadingQty(reportHistory);
    const totalBoxes =
      readingTotal != null ? readingTotal : getLotTotalBoxes(selectedReport);
    const outOfStock = !!selectedReport && totalBoxes === 0;
    return (
    <Modal
      visible={!!selectedReport}
      transparent
      animationType="slide"
      onRequestClose={closeReportDetail}
    >
      <View style={styles.detailOverlay}>
        <View style={styles.detailSheet}>
          <View style={styles.detailHead}>
            <View style={{ flex: 1, minWidth: 0, paddingRight: 8 }}>
              <Text style={styles.detailTitle} numberOfLines={1}>
                {selectedReport?.client_name || 'Inventory'}
              </Text>
              <Text style={styles.excelSub} numberOfLines={1}>
                {selectedReport?.warehouse_name || 'Warehouse'}
                {selectedReport?.chamber_name ? ` · ${selectedReport.chamber_name}` : ''}
              </Text>
            </View>
            <TouchableOpacity onPress={closeReportDetail}>
              <Ionicons name="close" size={22} color="#334155" />
            </TouchableOpacity>
          </View>

          <View style={[styles.totalBoxesBanner, outOfStock && styles.totalBoxesBannerEmpty]}>
            <Ionicons
              name={outOfStock ? 'alert-circle-outline' : 'cube-outline'}
              size={16}
              color={outOfStock ? '#dc2626' : '#003580'}
            />
            <Text
              style={[
                styles.totalBoxesBannerText,
                outOfStock && styles.totalBoxesBannerTextEmpty
              ]}
            >
              {outOfStock
                ? 'Out of stock · Total boxes 0'
                : `Total boxes · ${totalBoxes}`}
            </Text>
          </View>

          <View style={styles.excelHead}>
            <Text style={[styles.excelHeadCell, styles.excelColDate]}>Date</Text>
            <Text style={[styles.excelHeadCell, styles.excelColTime]}>Time</Text>
            <Text style={[styles.excelHeadCell, styles.excelColTemp]}>Temp</Text>
            <Text style={[styles.excelHeadCell, styles.excelColIn]}>In</Text>
            <Text style={[styles.excelHeadCell, styles.excelColOut]}>Out</Text>
            <Text style={[styles.excelHeadCell, styles.excelColQty]}>Left</Text>
          </View>

          {reportHistoryLoading ? (
            <View style={styles.centerState}>
              <ActivityIndicator size="large" color="#003580" />
              <Text style={styles.stateText}>Loading day records…</Text>
            </View>
          ) : reportHistoryError ? (
            <View style={styles.centerState}>
              <Ionicons name="warning-outline" size={28} color="#dc2626" />
              <Text style={styles.stateText}>{reportHistoryError}</Text>
              <TouchableOpacity
                style={styles.retryBtn}
                onPress={() => selectedReport && openReportDetail(selectedReport)}
              >
                <Text style={styles.retryText}>Retry</Text>
              </TouchableOpacity>
            </View>
          ) : (
            <ScrollView>
              {reportHistory.length === 0 ? (
                <View style={styles.centerState}>
                  <Text style={styles.stateText}>No day-wise qty found for this lot.</Text>
                </View>
              ) : (
                reportHistory.map((row, idx) => {
                  const dateLabel =
                    String(row.formatted_date || row.entry_date || '').slice(0, 10) || '—';
                  const timeLabel = formatReportTime(row);
                  const temp =
                    row.box_temp != null
                      ? `${row.box_temp}°C`
                      : row.chamber_temp != null
                        ? `${row.chamber_temp}°C`
                        : '—';
                  const qty = row._qty != null ? row._qty : null;
                  const inQty = row._inQty != null ? row._inQty : '—';
                  const outQty = row._outQty != null ? row._outQty : '—';

                  return (
                    <View
                      key={String(row.id || `${dateLabel}-${idx}`)}
                      style={[styles.excelRow, idx % 2 === 1 && styles.excelRowAlt]}
                    >
                      <Text style={[styles.excelCell, styles.excelColDate]} numberOfLines={1}>
                        {dateLabel}
                      </Text>
                      <Text style={[styles.excelCell, styles.excelColTime]} numberOfLines={1}>
                        {timeLabel}
                      </Text>
                      <Text style={[styles.excelCell, styles.excelColTemp]} numberOfLines={1}>
                        {temp}
                      </Text>
                      <Text
                        style={[
                          styles.excelCell,
                          styles.excelColIn,
                          inQty !== '—' && inQty !== '0' && styles.excelIn
                        ]}
                        numberOfLines={1}
                      >
                        {inQty}
                      </Text>
                      <Text
                        style={[
                          styles.excelCell,
                          styles.excelColOut,
                          outQty !== '—' && outQty !== '0' && styles.excelOut
                        ]}
                        numberOfLines={1}
                      >
                        {outQty}
                      </Text>
                      <Text
                        style={[styles.excelCell, styles.excelColQty, styles.excelQty]}
                        numberOfLines={1}
                      >
                        {qty == null ? '—' : qty}
                      </Text>
                    </View>
                  );
                })
              )}
            </ScrollView>
          )}
        </View>
      </View>
    </Modal>
  );
  };

  return (
    <SafeAreaView style={styles.safe}>
      <StatusBar barStyle="dark-content" backgroundColor="#ffffff" />
      <View style={styles.header}>
        <Image
          source={require('../../assets/logo-transparent.png')}
          style={styles.headerLogo}
          resizeMode="contain"
        />
        <View style={styles.welcomeBlock}>
          <Text style={styles.welcomeLine} numberOfLines={1}>
            Welcome, <Text style={styles.welcomeName}>{displayName}</Text>
          </Text>
        </View>
      </View>

      <View style={styles.contentArea}>
        {activeTab === 'Logs' ? (
          <View style={styles.logsWrap}>
            <View style={styles.filtersCard}>
              <View style={styles.logTypeRow}>
                {[
                  { id: 'chambers', label: 'Chambers' },
                  { id: 'inward', label: 'Inward' },
                  { id: 'outward', label: 'Outward' },
                  { id: 'inventory', label: 'Inventory' }
                ].map((t) => {
                  const active = logType === t.id;
                  return (
                    <TouchableOpacity
                      key={t.id}
                      style={[styles.logTypeChip, active && styles.logTypeChipActive]}
                      onPress={() => {
                        setLogType(t.id);
                        setWarehouseFilter('All');
                        setClientFilter('All');
                        setOpenFilter(null);
                        if (t.id === 'chambers') {
                          const today = toLocalYmd();
                          applyDateRange(today, today);
                        }
                      }}
                      activeOpacity={0.85}
                    >
                      <Text style={[styles.logTypeChipText, active && styles.logTypeChipTextActive]}>
                        {t.label}
                      </Text>
                    </TouchableOpacity>
                  );
                })}
              </View>

              <View style={styles.filterChipRow}>
                {renderFilterDropdown('warehouse', 'Warehouse', warehouseOptions, warehouseFilter)}
                {renderFilterDropdown('client', 'Client', clientOptions, clientFilter)}
              </View>

              {logType !== 'inventory' ? (
                <View style={styles.dateFilterBlock}>
                  <View style={styles.filterChipRow}>
                    <TouchableOpacity
                      style={[styles.dateChip, dateFrom !== 'All' && styles.filterChipActive]}
                      onPress={() => openCalendar('from')}
                      activeOpacity={0.85}
                    >
                      <Ionicons name="calendar-outline" size={14} color={dateFrom !== 'All' ? '#003580' : '#64748b'} />
                      <View style={styles.filterChipTextWrap}>
                        <Text style={styles.filterChipLabel}>From</Text>
                        <Text
                          style={[styles.filterChipValue, dateFrom !== 'All' && styles.filterChipValueActive]}
                          numberOfLines={1}
                        >
                          {formatDateLabel(dateFrom)}
                        </Text>
                      </View>
                    </TouchableOpacity>
                    <TouchableOpacity
                      style={[styles.dateChip, dateTo !== 'All' && styles.filterChipActive]}
                      onPress={() => openCalendar('to')}
                      activeOpacity={0.85}
                    >
                      <Ionicons name="calendar-outline" size={14} color={dateTo !== 'All' ? '#003580' : '#64748b'} />
                      <View style={styles.filterChipTextWrap}>
                        <Text style={styles.filterChipLabel}>To</Text>
                        <Text
                          style={[styles.filterChipValue, dateTo !== 'All' && styles.filterChipValueActive]}
                          numberOfLines={1}
                        >
                          {formatDateLabel(dateTo)}
                        </Text>
                      </View>
                    </TouchableOpacity>
                  </View>

                  <ScrollView
                    horizontal
                    showsHorizontalScrollIndicator={false}
                    contentContainerStyle={styles.dateSuggestRow}
                  >
                    <TouchableOpacity style={styles.dateSuggestChip} onPress={suggestToday} activeOpacity={0.85}>
                      <Text style={styles.dateSuggestText}>Today</Text>
                    </TouchableOpacity>
                    <TouchableOpacity style={styles.dateSuggestChip} onPress={suggestYesterday} activeOpacity={0.85}>
                      <Text style={styles.dateSuggestText}>Yesterday</Text>
                    </TouchableOpacity>
                    <TouchableOpacity style={styles.dateSuggestChip} onPress={suggestLast7} activeOpacity={0.85}>
                      <Text style={styles.dateSuggestText}>Last 7 days</Text>
                    </TouchableOpacity>
                    <TouchableOpacity
                      style={styles.dateSuggestChip}
                      onPress={clearAllFilters}
                      activeOpacity={0.85}
                    >
                      <Text style={styles.dateSuggestText}>Clear</Text>
                    </TouchableOpacity>
                  </ScrollView>
                </View>
              ) : null}
            </View>

            {logType === 'chambers' ? (
              <View style={styles.dailyBanner}>
                <Ionicons name="thermometer-outline" size={14} color="#003580" />
                <Text style={styles.dailyBannerText}>
                  Daily chamber data · {logs.length} entr{logs.length === 1 ? 'y' : 'ies'}
                  {dateFrom !== 'All' && dateFrom === dateTo ? ` · ${formatDateLabel(dateFrom)}` : ''}
                </Text>
              </View>
            ) : null}

            {logType === 'inventory' ? (
              <View style={styles.dailyBanner}>
                <Ionicons name="cube-outline" size={14} color="#003580" />
                <Text style={styles.dailyBannerText}>
                  Inventory · {inventorySummary.lots} lot
                  {inventorySummary.lots === 1 ? '' : 's'}
                  {` · Total boxes ${inventorySummary.totalBoxes}`}
                  {inventorySummary.totalBoxes === 0 ? ' · Out of stock' : ''}
                </Text>
              </View>
            ) : null}

            {logType === 'inventory' ? (
              inventoryLoading && !refreshing ? (
                <View style={styles.centerState}>
                  <ActivityIndicator size="large" color="#003580" />
                  <Text style={styles.stateText}>Loading inventory…</Text>
                </View>
              ) : inventoryError ? (
                <View style={styles.centerState}>
                  <Ionicons name="warning-outline" size={28} color="#dc2626" />
                  <Text style={styles.stateText}>{inventoryError}</Text>
                  <TouchableOpacity style={styles.retryBtn} onPress={loadInventory}>
                    <Text style={styles.retryText}>Retry</Text>
                  </TouchableOpacity>
                </View>
              ) : (
                <FlatList
                  data={filteredInventoryRows}
                  keyExtractor={(item, idx) =>
                    `${item.client_name || 'c'}-${item.warehouse_name || 'w'}-${item.chamber_name || 'ch'}-${idx}`
                  }
                  renderItem={renderReportItem}
                  contentContainerStyle={styles.listBodyCompact}
                  refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} />}
                  ListEmptyComponent={
                    <View style={styles.centerState}>
                      <Ionicons name="cube-outline" size={28} color="#94a3b8" />
                      <Text style={styles.stateText}>No inventory for selected filters.</Text>
                    </View>
                  }
                />
              )
            ) : logsLoading && !refreshing ? (
              <View style={styles.centerState}>
                <ActivityIndicator size="large" color="#003580" />
                <Text style={styles.stateText}>
                  Loading {logType === 'inward' ? 'inward' : logType === 'outward' ? 'outward' : 'chamber'} logs…
                </Text>
              </View>
            ) : logsError ? (
              <View style={styles.centerState}>
                <Ionicons name="warning-outline" size={28} color="#dc2626" />
                <Text style={styles.stateText}>{logsError}</Text>
                <TouchableOpacity style={styles.retryBtn} onPress={loadLogs}>
                  <Text style={styles.retryText}>Retry</Text>
                </TouchableOpacity>
              </View>
            ) : (
              <FlatList
                data={logs}
                keyExtractor={(item, idx) =>
                  String(item.id || item.inward_id || item.outward_id || item.reference_no || idx)
                }
                renderItem={renderLogItem}
                contentContainerStyle={styles.listBodyCompact}
                refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} />}
                ListEmptyComponent={
                  <View style={styles.centerState}>
                    <Ionicons name="document-text-outline" size={28} color="#94a3b8" />
                    <Text style={styles.stateText}>No logs for the selected filters.</Text>
                  </View>
                }
              />
            )}
          </View>
        ) : activeTab === 'Reports' ? (
          <View style={styles.logsWrap}>
            <View style={styles.filtersCard}>
              <View style={styles.logTypeRow}>
                {[
                  { id: 'all', label: 'All lots' },
                  { id: 'mismatch', label: 'Mismatch' }
                ].map((t) => {
                  const active = reportView === t.id;
                  return (
                    <TouchableOpacity
                      key={t.id}
                      style={[styles.logTypeChip, active && styles.logTypeChipActive]}
                      onPress={() => setReportView(t.id)}
                      activeOpacity={0.85}
                    >
                      <Text style={[styles.logTypeChipText, active && styles.logTypeChipTextActive]}>
                        {t.label}
                      </Text>
                    </TouchableOpacity>
                  );
                })}
              </View>

              <View style={styles.filterChipRow}>
                <TouchableOpacity
                  style={[styles.filterChip, reportWarehouseFilter !== 'All' && styles.filterChipActive]}
                  onPress={() => setOpenFilter(openFilter === 'reportWarehouse' ? null : 'reportWarehouse')}
                  activeOpacity={0.85}
                >
                  <Ionicons
                    name="business-outline"
                    size={14}
                    color={reportWarehouseFilter !== 'All' ? '#003580' : '#64748b'}
                  />
                  <View style={styles.filterChipTextWrap}>
                    <Text style={styles.filterChipLabel}>Warehouse</Text>
                    <Text
                      style={[
                        styles.filterChipValue,
                        reportWarehouseFilter !== 'All' && styles.filterChipValueActive
                      ]}
                      numberOfLines={1}
                    >
                      {reportWarehouseFilter}
                    </Text>
                  </View>
                  <Ionicons
                    name="chevron-down"
                    size={14}
                    color={reportWarehouseFilter !== 'All' ? '#003580' : '#94a3b8'}
                  />
                </TouchableOpacity>
                <TouchableOpacity
                  style={[styles.filterChip, reportClientFilter !== 'All' && styles.filterChipActive]}
                  onPress={() => setOpenFilter(openFilter === 'reportClient' ? null : 'reportClient')}
                  activeOpacity={0.85}
                >
                  <Ionicons
                    name="people-outline"
                    size={14}
                    color={reportClientFilter !== 'All' ? '#003580' : '#64748b'}
                  />
                  <View style={styles.filterChipTextWrap}>
                    <Text style={styles.filterChipLabel}>Client</Text>
                    <Text
                      style={[
                        styles.filterChipValue,
                        reportClientFilter !== 'All' && styles.filterChipValueActive
                      ]}
                      numberOfLines={1}
                    >
                      {reportClientFilter}
                    </Text>
                  </View>
                  <Ionicons
                    name="chevron-down"
                    size={14}
                    color={reportClientFilter !== 'All' ? '#003580' : '#94a3b8'}
                  />
                </TouchableOpacity>
              </View>

              <ScrollView
                horizontal
                showsHorizontalScrollIndicator={false}
                contentContainerStyle={styles.dateSuggestRow}
              >
                <TouchableOpacity
                  style={styles.dateSuggestChip}
                  onPress={() => setReportView('all')}
                  activeOpacity={0.85}
                >
                  <Text style={styles.dateSuggestText}>All</Text>
                </TouchableOpacity>
                <TouchableOpacity
                  style={styles.dateSuggestChip}
                  onPress={() => setReportView('mismatch')}
                  activeOpacity={0.85}
                >
                  <Text style={styles.dateSuggestText}>Mismatch only</Text>
                </TouchableOpacity>
                <TouchableOpacity
                  style={styles.dateSuggestChip}
                  onPress={() => {
                    setReportView('all');
                    setReportWarehouseFilter('All');
                    setReportClientFilter('All');
                  }}
                  activeOpacity={0.85}
                >
                  <Text style={styles.dateSuggestText}>Clear</Text>
                </TouchableOpacity>
              </ScrollView>
            </View>

            <View style={styles.dailyBanner}>
              <Ionicons name="cube-outline" size={14} color="#003580" />
              <Text style={styles.dailyBannerText}>
                Inventory · {reportSummary.lots} lot{reportSummary.lots === 1 ? '' : 's'}
                {` · Total boxes ${reportSummary.totalBoxes}`}
                {` · In ${reportSummary.inward} · Out ${reportSummary.outward}`}
                {reportSummary.totalBoxes === 0 && reportSummary.lots > 0
                  ? ' · Out of stock'
                  : ''}
              </Text>
            </View>

            {reportsLoading && !reportsRefreshing ? (
              <View style={styles.centerState}>
                <ActivityIndicator size="large" color="#003580" />
                <Text style={styles.stateText}>Loading inventory…</Text>
              </View>
            ) : reportsError ? (
              <View style={styles.centerState}>
                <Ionicons name="warning-outline" size={28} color="#dc2626" />
                <Text style={styles.stateText}>{reportsError}</Text>
                <TouchableOpacity style={styles.retryBtn} onPress={loadReports}>
                  <Text style={styles.retryText}>Retry</Text>
                </TouchableOpacity>
              </View>
            ) : (
              <FlatList
                data={filteredReportRows}
                keyExtractor={(item, idx) =>
                  `${item.client_name || 'c'}-${item.warehouse_name || 'w'}-${idx}`
                }
                renderItem={renderReportItem}
                contentContainerStyle={styles.listBodyCompact}
                refreshControl={
                  <RefreshControl
                    refreshing={reportsRefreshing}
                    onRefresh={() => {
                      setReportsRefreshing(true);
                      loadReports();
                    }}
                  />
                }
                ListEmptyComponent={
                  <View style={styles.centerState}>
                    <Ionicons name="cube-outline" size={28} color="#94a3b8" />
                    <Text style={styles.stateText}>No inventory for selected filters.</Text>
                  </View>
                }
              />
            )}
          </View>
        ) : (
          <ScrollView
            contentContainerStyle={[
              styles.body,
              activeTab === 'More' && styles.moreBody
            ]}
            showsVerticalScrollIndicator={false}
            refreshControl={
              activeTab === 'Dashboard' ? (
                <RefreshControl refreshing={homeRefreshing} onRefresh={onHomeRefresh} />
              ) : undefined
            }
          >
            {activeTab === 'Dashboard' && (
              <>
                <ImageBackground
                  source={require('../../assets/warehouse_bg.jpg')}
                  style={styles.homeHero}
                  resizeMode="cover"
                >
                  <View style={styles.homeHeroOverlay}>
                    <Text style={styles.homeHeroTitle}>Stay informed. Stay in control.</Text>
                    <Text style={styles.homeHeroSubtitle}>
                      Get clear visibility into your cold-chain operations and daily activities.
                    </Text>
                  </View>
                </ImageBackground>

                {homeLoading && !homeRefreshing ? (
                  <View style={styles.centerState}>
                    <ActivityIndicator size="large" color="#003580" />
                    <Text style={styles.stateText}>Loading today logs…</Text>
                  </View>
                ) : homeError ? (
                  <View style={styles.centerState}>
                    <Ionicons name="cloud-offline-outline" size={28} color="#dc2626" />
                    <Text style={styles.stateText}>{homeError}</Text>
                    <TouchableOpacity style={styles.retryBtn} onPress={loadHomeOverview}>
                      <Text style={styles.retryText}>Retry</Text>
                    </TouchableOpacity>
                    <TouchableOpacity
                      style={[styles.retryBtn, { backgroundColor: '#64748b', marginTop: 8 }]}
                      onPress={() => onLogout?.()}
                    >
                      <Text style={styles.retryText}>Logout & fix server</Text>
                    </TouchableOpacity>
                  </View>
                ) : (
                  <View style={styles.card}>
                    <View style={styles.cardTitleRow}>
                      <Text style={styles.cardTitle}>Today logs</Text>
                      <TouchableOpacity onPress={() => setActiveTab('Logs')} activeOpacity={0.85}>
                        <Text style={styles.linkText}>View all →</Text>
                      </TouchableOpacity>
                    </View>
                    {todayLogItems.length === 0 ? (
                      <Text style={styles.cardHint}>No temperature logs for today yet.</Text>
                    ) : (
                      todayLogItems.map((item, idx) => (
                        <TouchableOpacity
                          key={String(item.id || item.reference_no || idx)}
                          style={[styles.recentRow, idx > 0 && styles.recentRowBorder]}
                          onPress={() => setSelectedLog(item)}
                          activeOpacity={0.85}
                        >
                          <View style={{ flex: 1 }}>
                            <Text style={styles.recentClient} numberOfLines={1}>
                              {item.client_name || 'Client'}
                            </Text>
                            <Text style={styles.recentMeta} numberOfLines={1}>
                              {item.chamber_name || 'Chamber'} · {item.shift || item.inspection_time || '—'}
                              {item.warehouse_name ? ` · ${item.warehouse_name}` : ''}
                            </Text>
                          </View>
                          <Text style={styles.recentTemp}>
                            {item.box_temp != null
                              ? `${item.box_temp}°C`
                              : item.chamber_temp != null
                                ? `${item.chamber_temp}°C`
                                : '—'}
                          </Text>
                        </TouchableOpacity>
                      ))
                    )}
                  </View>
                )}
              </>
            )}

            {activeTab === 'More' && (
              <>
                <View style={styles.card}>
                  <Text style={styles.cardTitle}>Profile</Text>
                  <Text style={styles.profileRow}>
                    <Text style={styles.profileKey}>Name: </Text>
                    <Text style={styles.profileVal}>{displayName}</Text>
                  </Text>
                  <Text style={styles.profileRow}>
                    <Text style={styles.profileKey}>Email: </Text>
                    <Text style={styles.profileVal}>{user?.email || '—'}</Text>
                  </Text>
                  <Text style={styles.profileRow}>
                    <Text style={styles.profileKey}>Phone: </Text>
                    <Text style={styles.profileVal}>{user?.phone_no || '—'}</Text>
                  </Text>
                  <Text style={styles.profileRow}>
                    <Text style={styles.profileKey}>Clients: </Text>
                    <Text style={styles.profileVal}>
                      {allowedClients.length === 0 ? 'Full access' : allowedClients.join(', ')}
                    </Text>
                  </Text>
                  <Text style={styles.profileRow}>
                    <Text style={styles.profileKey}>Warehouses: </Text>
                    <Text style={styles.profileVal}>
                      {allowedWarehouses.length === 0
                        ? 'Full access'
                        : allowedWarehouses.join(', ')}
                    </Text>
                  </Text>
                </View>

                <TouchableOpacity
                  style={[styles.logoutBtn, { alignSelf: 'stretch', justifyContent: 'center' }]}
                  onPress={handleLogoutPress}
                  disabled={busy}
                  activeOpacity={0.85}
                >
                  {busy ? (
                    <ActivityIndicator color="#fff" size="small" />
                  ) : (
                    <>
                      <Ionicons name="log-out-outline" size={16} color="#fff" />
                      <Text style={styles.logoutText}>Logout</Text>
                    </>
                  )}
                </TouchableOpacity>

                <View style={styles.moreSpacer} />

                <View style={styles.aboutFooter}>
                  <View style={styles.aboutLogoWrap}>
                    <Image
                      source={require('../../assets/logo-transparent.png')}
                      style={styles.aboutLogo}
                      resizeMode="contain"
                    />
                  </View>
                  <Text style={styles.aboutFooterTitle}>About ReeferON</Text>
                  <Text style={styles.aboutBody}>
                    ReeferON is an integrated cold-chain solutions company delivering end-to-end
                    services across cold warehousing, transportation, logistics, storage and
                    supply-chain consulting. With strong industry expertise, ReeferON focuses on
                    efficiency, compliance, innovation and reliable cold-chain operations.
                  </Text>

                  <Text style={styles.aboutSectionTitle}>Our Services</Text>
                  {[
                    'Cold Warehousing',
                    'Transportation',
                    'Logistics',
                    'Supply Chain Consulting',
                    'Flexible Storage',
                    'Reefer Containers',
                    'Packaging Solutions'
                  ].map((item) => (
                    <Text key={item} style={styles.aboutBullet}>
                      • {item}
                    </Text>
                  ))}

                  <Text style={styles.aboutSectionTitle}>Our Values</Text>
                  {[
                    'Customer Excellence',
                    'Compliance',
                    'Cost Optimization',
                    'Collaboration'
                  ].map((item) => (
                    <Text key={item} style={styles.aboutBullet}>
                      • {item}
                    </Text>
                  ))}

                  <Text style={styles.aboutSectionTitle}>Contact Us</Text>
                  <TouchableOpacity
                    onPress={() => Linking.openURL('tel:+917678047222')}
                    activeOpacity={0.85}
                  >
                    <Text style={styles.aboutContactLink}>+91 7678047222</Text>
                  </TouchableOpacity>
                  <TouchableOpacity
                    onPress={() => Linking.openURL('tel:+917678047222')}
                    activeOpacity={0.85}
                  >
                    <Text style={styles.aboutContactLink}>+91 7678047222</Text>
                  </TouchableOpacity>
                  <TouchableOpacity
                    onPress={() => Linking.openURL('mailto:smile@reeferon.com')}
                    activeOpacity={0.85}
                  >
                    <Text style={styles.aboutContactLink}>smile@reeferon.com</Text>
                  </TouchableOpacity>
                </View>
              </>
            )}
          </ScrollView>
        )}
      </View>

      {/* Bottom navigation — same pattern as DO */}
      <View style={styles.tabBarContainer}>
        {[
          { id: 'Dashboard', label: 'Dashboard', icon: 'home', iconOutline: 'home-outline' },
          { id: 'Logs', label: 'Logs', icon: 'thermometer', iconOutline: 'thermometer-outline' },
          { id: 'Reports', label: 'Reports', icon: 'stats-chart', iconOutline: 'stats-chart-outline' },
          { id: 'More', label: 'More', icon: 'ellipsis-horizontal', iconOutline: 'ellipsis-horizontal-outline' }
        ].map((tab) => {
          const active = activeTab === tab.id;
          return (
            <TouchableOpacity
              key={tab.id}
              style={styles.tabBarItem}
              onPress={() => setActiveTab(tab.id)}
              activeOpacity={0.85}
            >
              <Ionicons
                name={active ? tab.icon : tab.iconOutline}
                size={22}
                color={active ? '#003580' : '#64748b'}
              />
              <Text style={[styles.tabBarLabel, active && styles.tabBarLabelActive]}>{tab.label}</Text>
            </TouchableOpacity>
          );
        })}
      </View>

      <Modal
        visible={showCalendarModal}
        transparent
        animationType="fade"
        onRequestClose={() => setShowCalendarModal(false)}
      >
        <View style={styles.calendarOverlay}>
          <View style={styles.calendarCard}>
            <Text style={styles.calendarTitle}>Select date range</Text>
            <Text style={styles.calendarHint}>
              {calendarPickMode === 'from' ? 'Tap start date, then end date' : 'Tap end date to finish'}
            </Text>

            <View style={styles.calendarChipRow}>
              <TouchableOpacity
                style={[styles.calendarModeChip, calendarPickMode === 'from' && styles.calendarModeChipActive]}
                onPress={() => setCalendarPickMode('from')}
              >
                <Text
                  style={[
                    styles.calendarModeChipText,
                    calendarPickMode === 'from' && styles.calendarModeChipTextActive
                  ]}
                >
                  From: {formatDateLabel(dateFrom)}
                </Text>
              </TouchableOpacity>
              <TouchableOpacity
                style={[styles.calendarModeChip, calendarPickMode === 'to' && styles.calendarModeChipActive]}
                onPress={() => setCalendarPickMode('to')}
              >
                <Text
                  style={[
                    styles.calendarModeChipText,
                    calendarPickMode === 'to' && styles.calendarModeChipTextActive
                  ]}
                >
                  To: {formatDateLabel(dateTo)}
                </Text>
              </TouchableOpacity>
            </View>

            <View style={styles.calendarMonthRow}>
              <TouchableOpacity
                onPress={() => {
                  const prev = new Date(calendarMonth);
                  prev.setMonth(prev.getMonth() - 1);
                  setCalendarMonth(prev);
                }}
              >
                <Ionicons name="chevron-back" size={22} color="#003580" />
              </TouchableOpacity>
              <Text style={styles.calendarMonthText}>
                {calendarMonth.toLocaleString('default', { month: 'long', year: 'numeric' })}
              </Text>
              <TouchableOpacity
                onPress={() => {
                  const next = new Date(calendarMonth);
                  next.setMonth(next.getMonth() + 1);
                  setCalendarMonth(next);
                }}
              >
                <Ionicons name="chevron-forward" size={22} color="#003580" />
              </TouchableOpacity>
            </View>

            <View style={styles.calendarWeekRow}>
              {['S', 'M', 'T', 'W', 'T', 'F', 'S'].map((d, i) => (
                <Text key={`${d}-${i}`} style={styles.calendarWeekDay}>
                  {d}
                </Text>
              ))}
            </View>

            <View style={styles.calendarDaysWrap}>
              {getCalendarDays(calendarMonth).map((d, index) => {
                if (!d) {
                  return <View key={`empty-${index}`} style={styles.calendarDayCell} />;
                }
                const dateStr = toLocalYmd(d);
                const effectiveFrom = dateFrom === 'All' ? null : dateFrom;
                const effectiveTo = dateTo === 'All' ? null : dateTo;
                const rangeStart = effectiveFrom;
                const rangeEnd = effectiveTo && effectiveFrom
                  ? effectiveTo < effectiveFrom
                    ? effectiveFrom
                    : effectiveTo
                  : effectiveTo;
                const isStart = rangeStart && dateStr === rangeStart;
                const isEnd = rangeEnd && dateStr === rangeEnd;
                const inRange =
                  rangeStart && rangeEnd && dateStr >= rangeStart && dateStr <= rangeEnd;
                const isToday = dateStr === toLocalYmd();
                // In To mode: dates before From are not selectable
                const isDisabled =
                  calendarPickMode === 'to' && effectiveFrom != null && dateStr < effectiveFrom;

                return (
                  <TouchableOpacity
                    key={dateStr}
                    disabled={isDisabled}
                    style={[
                      styles.calendarDayCell,
                      (isStart || isEnd) && styles.calendarDaySelected,
                      inRange && !isStart && !isEnd && styles.calendarDayInRange,
                      isToday && !isStart && !isEnd && !isDisabled && styles.calendarDayToday,
                      isDisabled && styles.calendarDayDisabled
                    ]}
                    onPress={() => {
                      if (calendarPickMode === 'from') {
                        // From changed: if To is older, bump To up to From
                        applyDateRange(dateStr, dateTo === 'All' ? dateStr : dateTo);
                        setCalendarPickMode('to');
                      } else {
                        // To cannot be less than From
                        if (effectiveFrom && dateStr < effectiveFrom) return;
                        applyDateRange(effectiveFrom || dateStr, dateStr);
                        setCalendarPickMode('from');
                        setShowCalendarModal(false);
                      }
                    }}
                  >
                    <Text
                      style={[
                        styles.calendarDayText,
                        (isStart || isEnd) && styles.calendarDayTextSelected,
                        isDisabled && styles.calendarDayTextDisabled
                      ]}
                    >
                      {d.getDate()}
                    </Text>
                  </TouchableOpacity>
                );
              })}
            </View>

            <View style={styles.calendarSuggestRow}>
              <TouchableOpacity
                style={styles.dateSuggestChip}
                onPress={() => {
                  suggestToday();
                  setShowCalendarModal(false);
                }}
              >
                <Text style={styles.dateSuggestText}>Today</Text>
              </TouchableOpacity>
              <TouchableOpacity
                style={styles.dateSuggestChip}
                onPress={() => {
                  suggestYesterday();
                  setShowCalendarModal(false);
                }}
              >
                <Text style={styles.dateSuggestText}>Yesterday</Text>
              </TouchableOpacity>
              <TouchableOpacity
                style={styles.dateSuggestChip}
                onPress={() => {
                  suggestLast7();
                  setShowCalendarModal(false);
                }}
              >
                <Text style={styles.dateSuggestText}>Last 7 days</Text>
              </TouchableOpacity>
            </View>

            <TouchableOpacity
              style={styles.calendarDoneBtn}
              onPress={() => setShowCalendarModal(false)}
              activeOpacity={0.85}
            >
              <Text style={styles.calendarDoneText}>Done</Text>
            </TouchableOpacity>
          </View>
        </View>
      </Modal>

      {renderLogDetailScreen()}
      {renderInventoryDetailModal()}
      {renderFilterPickerModal()}
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: '#f8fafc' },
  header: {
    flexDirection: 'column',
    alignItems: 'flex-start',
    justifyContent: 'flex-start',
    paddingLeft: 10,
    paddingRight: 14,
    paddingTop: 6,
    paddingBottom: 10,
    backgroundColor: '#ffffff',
    borderBottomWidth: 1,
    borderBottomColor: '#e2e8f0'
  },
  headerLogo: {
    width: 120,
    height: 44
  },
  aboutLogoWrap: {
    alignSelf: 'center',
    marginBottom: 12
  },
  aboutLogo: {
    width: 110,
    height: 48
  },
  welcomeBlock: {
    marginTop: 6,
    paddingRight: 4,
    alignSelf: 'stretch'
  },
  welcomeLine: {
    fontSize: 15,
    fontWeight: '600',
    color: '#64748b'
  },
  welcomeName: {
    fontSize: 15,
    fontWeight: '800',
    color: '#0f172a'
  },
  logoutBtn: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    backgroundColor: '#dc2626',
    paddingHorizontal: 12,
    paddingVertical: 8,
    borderRadius: 8
  },
  logoutText: { color: '#fff', fontWeight: '800', fontSize: 12 },
  contentArea: { flex: 1, paddingBottom: 64 },
  body: { padding: 16, paddingBottom: 40 },
  homeHero: {
    height: 160,
    marginHorizontal: -16,
    marginTop: -16,
    marginBottom: 16,
    justifyContent: 'flex-end'
  },
  homeHeroOverlay: {
    backgroundColor: 'rgba(0, 30, 80, 0.55)',
    paddingHorizontal: 16,
    paddingTop: 20,
    paddingBottom: 14
  },
  homeHeroTitle: {
    color: '#ffffff',
    fontSize: 20,
    fontWeight: '800',
    lineHeight: 26
  },
  homeHeroSubtitle: {
    color: '#e2e8f0',
    fontSize: 12,
    marginTop: 6,
    lineHeight: 18,
    fontWeight: '500'
  },
  moreBody: {
    flexGrow: 1,
    paddingBottom: 24
  },
  moreSpacer: {
    flexGrow: 1,
    minHeight: 24
  },
  aboutFooter: {
    marginTop: 8,
    paddingTop: 18,
    borderTopWidth: 1,
    borderTopColor: '#e2e8f0'
  },
  aboutFooterTitle: {
    fontSize: 15,
    fontWeight: '800',
    color: '#64748b',
    textAlign: 'center'
  },
  card: {
    backgroundColor: '#ffffff',
    borderRadius: 12,
    borderWidth: 1,
    borderColor: '#e2e8f0',
    padding: 16,
    marginBottom: 12
  },
  cardTitle: { fontSize: 15, fontWeight: '800', color: '#0f172a' },
  cardTitleRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    marginBottom: 4
  },
  cardHint: { fontSize: 12, color: '#64748b', marginTop: 6, lineHeight: 18 },
  aboutBrand: {
    fontSize: 16,
    fontWeight: '900',
    color: '#003580',
    marginTop: 4
  },
  aboutBody: {
    fontSize: 13,
    color: '#94a3b8',
    lineHeight: 20,
    marginTop: 8,
    fontWeight: '500'
  },
  aboutSectionTitle: {
    fontSize: 13,
    fontWeight: '800',
    color: '#64748b',
    marginTop: 16,
    marginBottom: 6
  },
  aboutBullet: {
    fontSize: 13,
    color: '#94a3b8',
    lineHeight: 22,
    fontWeight: '600',
    paddingLeft: 2
  },
  aboutContactLink: {
    fontSize: 13,
    color: '#64748b',
    lineHeight: 24,
    fontWeight: '700',
    paddingLeft: 2
  },
  linkText: { fontSize: 12, fontWeight: '800', color: '#0369a1' },
  scopeBlock: { marginTop: 14 },
  scopeLabel: { fontSize: 11, fontWeight: '800', color: '#0369a1', textTransform: 'uppercase' },
  scopeValue: { fontSize: 13, color: '#334155', marginTop: 4, lineHeight: 18, fontWeight: '600' },
  profileRow: {
    marginTop: 10,
    fontSize: 13,
    lineHeight: 20,
    color: '#334155'
  },
  profileKey: {
    fontSize: 13,
    fontWeight: '800',
    color: '#0f172a'
  },
  profileVal: {
    fontSize: 13,
    fontWeight: '600',
    color: '#334155'
  },
  mono: { fontSize: 12, color: '#0f172a', marginTop: 8, fontWeight: '600' },
  statsGrid: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: 10,
    marginBottom: 12
  },
  statCard: {
    width: '48%',
    flexGrow: 1,
    backgroundColor: '#ffffff',
    borderRadius: 12,
    borderWidth: 1,
    borderColor: '#e2e8f0',
    padding: 14
  },
  statPrimary: { borderColor: '#bfdbfe', backgroundColor: '#eff6ff' },
  statWide: { width: '100%' },
  statAlert: { borderColor: '#fecaca', backgroundColor: '#fef2f2' },
  statLabel: { fontSize: 11, fontWeight: '800', color: '#64748b', textTransform: 'uppercase' },
  statValue: { fontSize: 22, fontWeight: '900', color: '#0f172a', marginTop: 6 },
  shortcutRow: {
    flexDirection: 'row',
    gap: 10,
    marginTop: 12
  },
  shortcutBtn: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    gap: 6,
    paddingVertical: 14,
    borderRadius: 12,
    backgroundColor: '#f1f5f9',
    borderWidth: 1,
    borderColor: '#e2e8f0'
  },
  shortcutText: { fontSize: 12, fontWeight: '800', color: '#0f172a' },
  barRow: { flexDirection: 'row', alignItems: 'center', gap: 8, marginTop: 10 },
  barName: { width: 88, fontSize: 12, fontWeight: '700', color: '#334155' },
  barTrack: {
    flex: 1,
    height: 8,
    borderRadius: 999,
    backgroundColor: '#f1f5f9',
    overflow: 'hidden'
  },
  barFill: { height: '100%', backgroundColor: '#003580', borderRadius: 999 },
  barFillAlt: { height: '100%', backgroundColor: '#0284c7', borderRadius: 999 },
  barCount: { width: 28, textAlign: 'right', fontSize: 12, fontWeight: '800', color: '#0f172a' },
  recentRow: { flexDirection: 'row', alignItems: 'center', gap: 10, paddingVertical: 10 },
  recentRowBorder: { borderTopWidth: 1, borderTopColor: '#f1f5f9' },
  recentClient: { fontSize: 13, fontWeight: '800', color: '#0f172a' },
  recentMeta: { fontSize: 11, color: '#64748b', marginTop: 2, fontWeight: '600' },
  recentTemp: { fontSize: 14, fontWeight: '800', color: '#0369a1' },
  logsWrap: { flex: 1 },
  filtersCard: {
    backgroundColor: '#ffffff',
    borderBottomWidth: 1,
    borderBottomColor: '#e2e8f0',
    paddingHorizontal: 12,
    paddingTop: 10,
    paddingBottom: 10,
    gap: 8
  },
  filterChipRow: {
    flexDirection: 'row',
    gap: 8
  },
  filterChip: {
    flex: 1,
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    backgroundColor: '#f8fafc',
    borderWidth: 1,
    borderColor: '#e2e8f0',
    borderRadius: 12,
    paddingHorizontal: 10,
    paddingVertical: 8,
    minHeight: 48
  },
  filterChipActive: {
    backgroundColor: '#eff6ff',
    borderColor: '#93c5fd'
  },
  dateChip: {
    flex: 1,
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    backgroundColor: '#f8fafc',
    borderWidth: 1,
    borderColor: '#e2e8f0',
    borderRadius: 12,
    paddingHorizontal: 10,
    paddingVertical: 8,
    minHeight: 48
  },
  filterChipTextWrap: {
    flex: 1,
    minWidth: 0
  },
  filterChipLabel: {
    fontSize: 9,
    fontWeight: '800',
    color: '#64748b',
    textTransform: 'uppercase',
    letterSpacing: 0.3
  },
  filterChipValue: {
    fontSize: 12,
    fontWeight: '700',
    color: '#334155',
    marginTop: 1
  },
  filterChipValueActive: {
    color: '#003580'
  },
  filterModalBackdrop: {
    flex: 1,
    backgroundColor: 'rgba(15, 23, 42, 0.45)',
    justifyContent: 'flex-end'
  },
  filterModalSheet: {
    backgroundColor: '#ffffff',
    borderTopLeftRadius: 18,
    borderTopRightRadius: 18,
    paddingHorizontal: 16,
    paddingTop: 8,
    paddingBottom: 20,
    maxHeight: '70%'
  },
  filterModalHandle: {
    alignSelf: 'center',
    width: 40,
    height: 4,
    borderRadius: 999,
    backgroundColor: '#cbd5e1',
    marginBottom: 12
  },
  filterModalTitle: {
    fontSize: 16,
    fontWeight: '800',
    color: '#0f172a',
    marginBottom: 10
  },
  filterModalList: {
    maxHeight: 360
  },
  filterModalItem: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingVertical: 12,
    paddingHorizontal: 12,
    borderRadius: 10,
    marginBottom: 4,
    backgroundColor: '#f8fafc'
  },
  filterModalItemActive: {
    backgroundColor: '#eff6ff'
  },
  filterModalItemText: {
    flex: 1,
    fontSize: 14,
    fontWeight: '600',
    color: '#334155',
    marginRight: 8
  },
  filterModalItemTextActive: {
    color: '#003580',
    fontWeight: '800'
  },
  filterModalClose: {
    marginTop: 10,
    alignItems: 'center',
    paddingVertical: 12,
    borderRadius: 10,
    backgroundColor: '#f1f5f9'
  },
  filterModalCloseText: {
    fontSize: 14,
    fontWeight: '800',
    color: '#334155'
  },
  scopeNote: { fontSize: 11, color: '#64748b', fontWeight: '600', marginTop: 2 },
  dateFilterBlock: { gap: 8 },
  dateSuggestRow: {
    gap: 6,
    paddingTop: 6,
    paddingRight: 4
  },
  dateSuggestChip: {
    paddingHorizontal: 8,
    paddingVertical: 4,
    borderRadius: 12,
    backgroundColor: '#eff6ff',
    borderWidth: 1,
    borderColor: '#bfdbfe'
  },
  dateSuggestText: {
    fontSize: 10,
    fontWeight: '800',
    color: '#003580'
  },
  calendarOverlay: {
    flex: 1,
    backgroundColor: 'rgba(15, 23, 42, 0.45)',
    alignItems: 'center',
    justifyContent: 'center',
    padding: 20
  },
  calendarCard: {
    width: '100%',
    maxWidth: 340,
    backgroundColor: '#ffffff',
    borderRadius: 16,
    padding: 16
  },
  calendarTitle: { fontSize: 15, fontWeight: '800', color: '#0f172a' },
  calendarHint: { fontSize: 11, color: '#64748b', marginTop: 4, marginBottom: 12 },
  calendarChipRow: { flexDirection: 'row', gap: 8, marginBottom: 12 },
  calendarModeChip: {
    flex: 1,
    borderRadius: 10,
    borderWidth: 1,
    borderColor: '#e2e8f0',
    backgroundColor: '#f8fafc',
    paddingHorizontal: 8,
    paddingVertical: 8
  },
  calendarModeChipActive: {
    borderColor: '#003580',
    backgroundColor: '#eff6ff'
  },
  calendarModeChipText: { fontSize: 11, fontWeight: '700', color: '#64748b', textAlign: 'center' },
  calendarModeChipTextActive: { color: '#003580' },
  calendarMonthRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    marginBottom: 12
  },
  calendarMonthText: { fontSize: 14, fontWeight: '800', color: '#0f172a' },
  calendarWeekRow: { flexDirection: 'row', marginBottom: 6 },
  calendarWeekDay: {
    width: '14.28%',
    textAlign: 'center',
    fontSize: 11,
    fontWeight: '800',
    color: '#94a3b8'
  },
  calendarDaysWrap: { flexDirection: 'row', flexWrap: 'wrap' },
  calendarDayCell: {
    width: '14.28%',
    height: 36,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: 18
  },
  calendarDaySelected: { backgroundColor: '#003580' },
  calendarDayInRange: { backgroundColor: '#dbeafe' },
  calendarDayToday: { borderWidth: 1, borderColor: '#003580' },
  calendarDayDisabled: { opacity: 0.35 },
  calendarDayText: { fontSize: 12, fontWeight: '600', color: '#0f172a' },
  calendarDayTextSelected: { color: '#ffffff', fontWeight: '800' },
  calendarDayTextDisabled: { color: '#94a3b8' },
  calendarSuggestRow: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: 8,
    marginTop: 14
  },
  calendarDoneBtn: {
    marginTop: 14,
    backgroundColor: '#003580',
    borderRadius: 10,
    paddingVertical: 12,
    alignItems: 'center'
  },
  calendarDoneText: { color: '#ffffff', fontWeight: '800', fontSize: 13 },
  listBody: { padding: 16, paddingBottom: 40, flexGrow: 1 },
  listBodyCompact: { padding: 8, paddingBottom: 88, flexGrow: 1 },
  logTypeRow: { flexDirection: 'row', gap: 6, marginBottom: 6 },
  logTypeChip: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    paddingVertical: 6,
    borderRadius: 8,
    backgroundColor: '#f1f5f9',
    borderWidth: 1,
    borderColor: '#e2e8f0'
  },
  logTypeChipActive: { backgroundColor: '#003580', borderColor: '#003580' },
  logTypeChipText: { fontSize: 11, fontWeight: '700', color: '#64748b' },
  logTypeChipTextActive: { color: '#fff' },
  dailyBanner: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    paddingHorizontal: 10,
    paddingVertical: 4,
    backgroundColor: '#eff6ff',
    borderBottomWidth: 1,
    borderBottomColor: '#dbeafe'
  },
  dailyBannerText: { fontSize: 10, color: '#003580', fontWeight: '700', flex: 1 },
  dailyCard: {
    backgroundColor: '#fff',
    borderRadius: 8,
    paddingVertical: 6,
    paddingHorizontal: 8,
    marginBottom: 5,
    borderWidth: 1,
    borderColor: '#e2e8f0'
  },
  dailyTop: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  dailyTextCol: { flex: 1, minWidth: 0 },
  dailyChamber: { fontSize: 12, fontWeight: '800', color: '#0f172a' },
  dailyTemp: { fontSize: 13, fontWeight: '800', color: '#003580' },
  dailyMetaLine: { fontSize: 10, color: '#64748b', fontWeight: '600', marginTop: 1 },
  totalBoxesCol: { alignItems: 'flex-end', minWidth: 64 },
  totalBoxesValue: { fontSize: 15, fontWeight: '900', color: '#003580' },
  totalBoxesLabel: {
    fontSize: 9,
    fontWeight: '700',
    color: '#64748b',
    marginTop: 1,
    textTransform: 'uppercase'
  },
  outOfStockValue: { fontSize: 15, fontWeight: '900', color: '#dc2626' },
  outOfStockLabel: { color: '#dc2626' },
  outOfStockTag: {
    marginTop: 3,
    alignSelf: 'flex-start',
    fontSize: 10,
    fontWeight: '800',
    color: '#dc2626',
    backgroundColor: '#fee2e2',
    paddingHorizontal: 6,
    paddingVertical: 2,
    borderRadius: 6,
    overflow: 'hidden'
  },
  totalBoxesBanner: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    backgroundColor: '#eff6ff',
    borderRadius: 8,
    paddingHorizontal: 10,
    paddingVertical: 8,
    marginBottom: 10,
    borderWidth: 1,
    borderColor: '#dbeafe'
  },
  totalBoxesBannerEmpty: {
    backgroundColor: '#fef2f2',
    borderColor: '#fecaca'
  },
  totalBoxesBannerText: {
    flex: 1,
    fontSize: 12,
    fontWeight: '800',
    color: '#003580'
  },
  totalBoxesBannerTextEmpty: { color: '#dc2626' },
  compactLogCard: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: '#fff',
    borderRadius: 8,
    paddingVertical: 7,
    paddingHorizontal: 8,
    marginBottom: 5,
    borderWidth: 1,
    borderColor: '#e2e8f0',
    gap: 8
  },
  logTitleRow: { flexDirection: 'row', alignItems: 'center', gap: 5, marginBottom: 1 },
  logTypeTag: {
    fontSize: 8,
    fontWeight: '800',
    color: '#0284c7',
    backgroundColor: '#e0f2fe',
    paddingHorizontal: 4,
    paddingVertical: 1,
    borderRadius: 3,
    overflow: 'hidden'
  },
  logMeta: { fontSize: 10, color: '#64748b', marginTop: 1 },
  detailOverlay: {
    flex: 1,
    backgroundColor: 'rgba(15,23,42,0.45)',
    justifyContent: 'flex-end'
  },
  detailSheet: {
    backgroundColor: '#fff',
    borderTopLeftRadius: 18,
    borderTopRightRadius: 18,
    padding: 16,
    maxHeight: '88%'
  },
  detailHead: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginBottom: 12
  },
  excelSub: { fontSize: 11, color: '#64748b', fontWeight: '600', marginTop: 2 },
  excelHead: {
    flexDirection: 'row',
    backgroundColor: '#003580',
    borderRadius: 6,
    paddingVertical: 8,
    paddingHorizontal: 6,
    marginBottom: 2
  },
  excelHeadCell: {
    fontSize: 10,
    fontWeight: '800',
    color: '#fff',
    textTransform: 'uppercase',
    letterSpacing: 0.3
  },
  excelRow: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingVertical: 8,
    paddingHorizontal: 6,
    borderBottomWidth: 1,
    borderBottomColor: '#e2e8f0'
  },
  excelRowAlt: { backgroundColor: '#f8fafc' },
  excelCell: { fontSize: 10, color: '#0f172a', fontWeight: '600' },
  excelColDate: { flex: 1.2 },
  excelColTime: { flex: 0.85 },
  excelColTemp: { flex: 0.75 },
  excelColIn: { flex: 0.55, textAlign: 'right' },
  excelColOut: { flex: 0.55, textAlign: 'right' },
  excelColQty: { flex: 0.65, textAlign: 'right' },
  excelIn: { color: '#059669', fontWeight: '800' },
  excelOut: { color: '#d97706', fontWeight: '800' },
  excelQty: { fontWeight: '800', color: '#003580' },
  logCard: {
    backgroundColor: '#ffffff',
    borderRadius: 12,
    borderWidth: 1,
    borderColor: '#e2e8f0',
    paddingVertical: 10,
    paddingHorizontal: 12,
    marginBottom: 10
  },
  logCheckRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10
  },
  logCheckCircle: {
    width: 28,
    height: 28,
    borderRadius: 14,
    borderWidth: 2,
    borderColor: '#bfdbfe',
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: '#eff6ff'
  },
  logCheckBody: { flex: 1, minWidth: 0 },
  logClient: { fontSize: 14, fontWeight: '800', color: '#0f172a' },
  logCheckMeta: { fontSize: 11, color: '#64748b', marginTop: 2, fontWeight: '600' },
  logRightStats: { alignItems: 'flex-end', gap: 2 },
  logTemp: { fontSize: 14, fontWeight: '800', color: '#0369a1' },
  logBoxes: { fontSize: 11, fontWeight: '700', color: '#475569' },
  logPhotoBadge: {
    marginTop: 2,
    flexDirection: 'row',
    alignItems: 'center',
    gap: 3,
    backgroundColor: '#e0f2fe',
    paddingHorizontal: 6,
    paddingVertical: 2,
    borderRadius: 8
  },
  logPhotoBadgeText: { fontSize: 9, fontWeight: '800', color: '#0369a1' },
  logDetailRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'flex-start',
    gap: 12,
    paddingVertical: 6,
    borderBottomWidth: 1,
    borderBottomColor: '#f1f5f9'
  },
  logDetailLabel: {
    width: 110,
    fontSize: 11,
    fontWeight: '700',
    color: '#94a3b8',
    textTransform: 'uppercase'
  },
  logDetailValue: {
    flex: 1,
    fontSize: 13,
    fontWeight: '700',
    color: '#0f172a',
    textAlign: 'right'
  },
  detailSafe: { flex: 1, backgroundColor: '#f8fafc' },
  detailHeader: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
    paddingHorizontal: 12,
    paddingVertical: 10,
    backgroundColor: '#ffffff',
    borderBottomWidth: 1,
    borderBottomColor: '#e2e8f0'
  },
  detailBackBtn: {
    width: 40,
    height: 40,
    borderRadius: 10,
    backgroundColor: '#f1f5f9',
    alignItems: 'center',
    justifyContent: 'center'
  },
  detailTitle: { fontSize: 16, fontWeight: '800', color: '#0f172a' },
  detailSub: { fontSize: 12, color: '#64748b', marginTop: 2, fontWeight: '600' },
  detailBody: { padding: 16, paddingBottom: 40 },
  detailHeroCard: {
    backgroundColor: '#003580',
    borderRadius: 14,
    padding: 18,
    marginBottom: 12
  },
  detailHeroTemp: { fontSize: 28, fontWeight: '900', color: '#ffffff' },
  detailHeroMeta: { fontSize: 13, fontWeight: '700', color: '#bfdbfe', marginTop: 6 },
  detailCard: {
    backgroundColor: '#ffffff',
    borderRadius: 12,
    borderWidth: 1,
    borderColor: '#e2e8f0',
    padding: 14,
    marginBottom: 12
  },
  detailSectionTitle: {
    fontSize: 13,
    fontWeight: '800',
    color: '#0f172a',
    marginBottom: 10
  },
  detailImage: {
    width: '100%',
    height: 320,
    borderRadius: 12,
    backgroundColor: '#e2e8f0'
  },
  detailImageLoading: {
    position: 'absolute',
    top: 0,
    left: 0,
    right: 0,
    height: 320,
    alignItems: 'center',
    justifyContent: 'center',
    zIndex: 1
  },
  detailImageEmpty: {
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
    paddingVertical: 28,
    paddingHorizontal: 12,
    backgroundColor: '#f8fafc',
    borderRadius: 12,
    borderWidth: 1,
    borderColor: '#e2e8f0',
    borderStyle: 'dashed'
  },
  detailImageHint: {
    fontSize: 10,
    color: '#94a3b8',
    textAlign: 'center',
    marginTop: 4
  },
  logMeta: { fontSize: 12, color: '#64748b', marginTop: 4, fontWeight: '600' },
  logRef: { fontSize: 11, color: '#94a3b8', marginTop: 6, fontWeight: '700' },
  centerState: { alignItems: 'center', justifyContent: 'center', padding: 32, gap: 8 },
  stateText: { fontSize: 13, color: '#64748b', textAlign: 'center', lineHeight: 18 },
  retryBtn: {
    marginTop: 8,
    backgroundColor: '#003580',
    paddingHorizontal: 16,
    paddingVertical: 8,
    borderRadius: 8
  },
  retryText: { color: '#fff', fontWeight: '800', fontSize: 12 },
  tabBarContainer: {
    position: 'absolute',
    bottom: 0,
    left: 0,
    right: 0,
    backgroundColor: '#ffffff',
    height: 64,
    borderTopWidth: 1,
    borderTopColor: '#e2e8f0',
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-around',
    paddingHorizontal: 8,
    elevation: 8,
    shadowColor: '#000',
    shadowOffset: { width: 0, height: -2 },
    shadowOpacity: 0.08,
    shadowRadius: 5
  },
  tabBarItem: {
    alignItems: 'center',
    justifyContent: 'center',
    paddingVertical: 6,
    flex: 1
  },
  tabBarLabel: {
    fontSize: 10,
    color: '#64748b',
    fontWeight: '600',
    marginTop: 3
  },
  tabBarLabelActive: {
    color: '#003580',
    fontWeight: 'bold'
  }
});
