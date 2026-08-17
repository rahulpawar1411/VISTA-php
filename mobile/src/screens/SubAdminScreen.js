import React, { useCallback, useEffect, useMemo, useState } from 'react';
import {
  View,
  Text,
  Image,
  StyleSheet,
  ScrollView,
  StatusBar,
  SafeAreaView,
  ActivityIndicator,
  RefreshControl,
  FlatList,
  Modal,
  Linking,
  Alert,
  Share,
  Platform,
  BackHandler
} from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import * as FileSystem from 'expo-file-system/legacy';
import FastTouchable from '../components/FastTouchable';
import { dedupeInventoryLots } from '../utils/dedupeInventoryLots';
import { buildReportReadingRows, latestReadingQty } from '../utils/buildReportReadingRows';

const TouchableOpacity = FastTouchable;

const PRODUCTION_API_URL = 'https://reeferon-crm-backend.onrender.com';

function resolveImageUrl(raw, baseUrl, folderHint = 'daily_temp_monitor_images') {
  if (raw == null) return null;
  let value = String(raw).trim();
  if (!value || value === 'null' || value === 'undefined') return null;
  if (/^https?:\/\//i.test(value) || value.startsWith('file://') || value.startsWith('content://')) {
    return value;
  }
  if (value.startsWith('data:')) return value;
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
  value = value.replace(/\\/g, '/').replace(/^\/+/, '');
  if (value.startsWith('uploads/')) return `${base}/${value}`;
  if (!value.includes('/')) return `${base}/uploads/${folderHint}/${value}`;
  return `${base}/${value}`;
}

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

async function downloadImageToDevice(uri) {
  if (!uri) throw new Error('No image URL');

  const baseDir = FileSystem.cacheDirectory || FileSystem.documentDirectory;
  if (!baseDir) throw new Error('Storage not available on this device');

  const fileName = `reeferon_${Date.now()}.jpg`;
  const dest = `${baseDir}${fileName}`;

  let localUri = dest;
  if (uri.startsWith('data:')) {
    const base64 = uri.replace(/^data:image\/\w+;base64,/, '');
    await FileSystem.writeAsStringAsync(dest, base64, {
      encoding: FileSystem.EncodingType.Base64
    });
  } else {
    const result = await FileSystem.downloadAsync(uri, dest);
    localUri = result.uri;
  }

  // Open system share sheet so user can Save / Download / Share
  if (Platform.OS === 'android') {
    try {
      const contentUri = await FileSystem.getContentUriAsync(localUri);
      await Share.share({ url: contentUri, message: 'ReeferON log image', title: 'Download image' });
      return localUri;
    } catch (_) {
      /* fall through */
    }
  }

  await Share.share({
    url: localUri,
    message: Platform.OS === 'ios' ? undefined : 'ReeferON log image',
    title: 'Download image'
  });
  return localUri;
}

function SmallLogImage({ rawPath, apiUrl, folderHint }) {
  const uri = useMemo(() => {
    const primary = resolveImageUrl(rawPath, apiUrl, folderHint);
    if (primary) return primary;
    return resolveImageUrl(rawPath, PRODUCTION_API_URL, folderHint);
  }, [rawPath, apiUrl, folderHint]);
  const [failed, setFailed] = useState(false);
  const [viewerOpen, setViewerOpen] = useState(false);
  const [downloading, setDownloading] = useState(false);

  useEffect(() => {
    setFailed(false);
  }, [uri]);

  const handleDownload = async () => {
    if (!uri || downloading) return;
    setDownloading(true);
    try {
      await downloadImageToDevice(uri);
      Alert.alert('Saved', 'Use Save Image / Downloads from the share sheet to keep the photo.');
    } catch (err) {
      Alert.alert('Download failed', err?.message || 'Could not download image.');
    } finally {
      setDownloading(false);
    }
  };

  if (!uri || failed) {
    return (
      <View style={styles.smallImgEmpty}>
        <Ionicons name="image-outline" size={18} color="#94a3b8" />
        <Text style={styles.smallImgEmptyText}>No image</Text>
      </View>
    );
  }

  return (
    <>
      <TouchableOpacity activeOpacity={0.85} onPress={() => setViewerOpen(true)}>
        <Image
          source={{ uri }}
          style={styles.smallImg}
          resizeMode="cover"
          onError={() => setFailed(true)}
        />
        <Text style={styles.smallImgHint}>Tap to view</Text>
      </TouchableOpacity>

      <Modal
        visible={viewerOpen}
        transparent
        animationType="fade"
        onRequestClose={() => setViewerOpen(false)}
      >
        <View style={styles.imgViewerOverlay}>
          <View style={styles.imgViewerTop}>
            <TouchableOpacity
              style={styles.imgViewerBtn}
              onPress={() => setViewerOpen(false)}
              activeOpacity={0.85}
            >
              <Ionicons name="close" size={20} color="#fff" />
              <Text style={styles.imgViewerBtnText}>Close</Text>
            </TouchableOpacity>
            <TouchableOpacity
              style={[styles.imgViewerBtn, styles.imgViewerDownload]}
              onPress={handleDownload}
              disabled={downloading}
              activeOpacity={0.85}
            >
              {downloading ? (
                <ActivityIndicator size="small" color="#fff" />
              ) : (
                <>
                  <Ionicons name="download-outline" size={18} color="#fff" />
                  <Text style={styles.imgViewerBtnText}>Download</Text>
                </>
              )}
            </TouchableOpacity>
          </View>
          <ScrollView
            maximumZoomScale={3}
            minimumZoomScale={1}
            contentContainerStyle={styles.imgViewerBody}
            centerContent
          >
            <Image source={{ uri }} style={styles.imgViewerImage} resizeMode="contain" />
          </ScrollView>
        </View>
      </Modal>
    </>
  );
}

/**
 * Mobile Sub-Admin (mini-admin) — full data visibility.
 * Tabs: Home (overview) | Logs | Reports | More
 */
export default function SubAdminScreen({ user, token, apiUrl, onLogout }) {
  const [activeTab, setActiveTab] = useState('Home');
  const [busy, setBusy] = useState(false);

  const [stats, setStats] = useState(null);
  const [todayLogs, setTodayLogs] = useState([]);
  const [warehouseTasks, setWarehouseTasks] = useState([]);
  const [taskSummary, setTaskSummary] = useState(null);
  const [homeLoading, setHomeLoading] = useState(false);
  const [homeRefreshing, setHomeRefreshing] = useState(false);
  const [homeError, setHomeError] = useState('');

  const [warehouseFilter, setWarehouseFilter] = useState('All');
  const [clientFilter, setClientFilter] = useState('All');
  const [logType, setLogType] = useState('chambers'); // chambers | inward | outward
  const [dateFrom, setDateFrom] = useState(() => {
    const d = new Date();
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
  });
  const [dateTo, setDateTo] = useState(() => {
    const d = new Date();
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
  });
  const [openFilter, setOpenFilter] = useState(null);
  const [showCalendarModal, setShowCalendarModal] = useState(false);
  const [calendarPickMode, setCalendarPickMode] = useState('from');
  const [calendarMonth, setCalendarMonth] = useState(new Date());
  const [warehouses, setWarehouses] = useState([]);
  const [clients, setClients] = useState([]);
  const [logs, setLogs] = useState([]);
  const [logsLoading, setLogsLoading] = useState(false);
  const [logsError, setLogsError] = useState('');
  const [logsRefreshing, setLogsRefreshing] = useState(false);
  const [selectedLog, setSelectedLog] = useState(null);

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

  const [notifications, setNotifications] = useState([]);
  const [showNotifications, setShowNotifications] = useState(false);
  const [notifFilter, setNotifFilter] = useState('all'); // all | pending | decided
  const [seenNotifIds, setSeenNotifIds] = useState([]);
  const [notifActionBusy, setNotifActionBusy] = useState(null);

  const displayName = user?.full_name || user?.email?.split('@')[0] || 'Sub-Admin';

  const toLocalYmd = (d = new Date()) => {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
  };

  const authHeaders = useMemo(
    () => ({
      Accept: 'application/json',
      Authorization: `Bearer ${token}`
    }),
    [token]
  );

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
    if (nextFrom !== 'All' && nextTo !== 'All' && nextTo < nextFrom) nextTo = nextFrom;
    setDateFrom(nextFrom || 'All');
    setDateTo(nextTo || 'All');
  };

  const clearAllFilters = () => {
    setWarehouseFilter('All');
    setClientFilter('All');
    setOpenFilter(null);
    // Chambers = daily view → reset to today; other types clear dates
    if (logType === 'chambers') {
      const t = toLocalYmd();
      applyDateRange(t, t);
    } else {
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
    for (let day = 1; day <= totalDays; day += 1) days.push(new Date(year, month, day));
    return days;
  };

  const loadHomeOverview = useCallback(async () => {
    if (!apiUrl || !token) return;
    setHomeLoading(true);
    setHomeError('');
    try {
      const today = toLocalYmd();
      const [statsRes, logsRes, tasksRes] = await Promise.all([
        fetch(`${apiUrl}/api/dashboard`, { headers: authHeaders }),
        fetch(
          `${apiUrl}/api/chamber-temp?${new URLSearchParams({
            page: '1',
            limit: '80',
            fromDate: today,
            toDate: today
          }).toString()}`,
          { headers: authHeaders }
        ),
        fetch(`${apiUrl}/api/dashboard/do-task-overview`, { headers: authHeaders })
      ]);

      const statsData = await statsRes.json().catch(() => ({}));
      if (!statsRes.ok) {
        throw new Error(statsData.message || statsData.error || `Stats failed (${statsRes.status})`);
      }
      setStats(statsData.stats || statsData || {});

      const logsData = await logsRes.json().catch(() => ({}));
      if (!logsRes.ok) {
        throw new Error(logsData.message || logsData.error || `Today logs failed (${logsRes.status})`);
      }
      const items = Array.isArray(logsData?.items)
        ? logsData.items
        : Array.isArray(logsData)
          ? logsData
          : [];
      const scoped = items.filter((row) => {
        const d = String(row.formatted_date || row.entry_date || '').slice(0, 10);
        return d === today;
      });
      setTodayLogs(scoped);

      const tasksData = await tasksRes.json().catch(() => ({}));
      if (!tasksRes.ok) {
        console.warn('DO task overview failed:', tasksData.message || tasksRes.status);
        setWarehouseTasks([]);
        setTaskSummary(null);
      } else {
        setWarehouseTasks(Array.isArray(tasksData.warehouses) ? tasksData.warehouses : []);
        setTaskSummary(tasksData.summary || null);
      }
    } catch (err) {
      const msg =
        err?.message === 'Network request failed'
          ? `Cannot reach server: ${apiUrl}`
          : err.message || 'Failed to load overview.';
      setHomeError(msg);
      setTodayLogs([]);
      setStats(null);
      setWarehouseTasks([]);
      setTaskSummary(null);
    } finally {
      setHomeLoading(false);
      setHomeRefreshing(false);
    }
  }, [apiUrl, token, authHeaders]);

  // Handle Android system back button presses
  useEffect(() => {
    const backAction = () => {
      if (showNotifications) {
        setShowNotifications(false);
        return true;
      }
      if (selectedLog) {
        setSelectedLog(null);
        return true;
      }
      if (selectedReport) {
        setSelectedReport(null);
        return true;
      }
      if (showCalendarModal) {
        setShowCalendarModal(false);
        return true;
      }
      if (activeTab !== 'Home') {
        setActiveTab('Home');
        return true;
      }
      return false;
    };

    const backHandler = BackHandler.addEventListener('hardwareBackPress', backAction);
    return () => backHandler.remove();
  }, [showNotifications, selectedLog, selectedReport, showCalendarModal, activeTab]);

  const loadLogs = useCallback(async () => {
    if (!apiUrl || !token) return;
    setLogsLoading(true);
    setLogsError('');
    try {
      // Chambers always load as daily chamber-temp data (default today if no range)
      let from = dateFrom;
      let to = dateTo;
      if (logType === 'chambers' && (from === 'All' || to === 'All')) {
        const t = toLocalYmd();
        from = from === 'All' ? t : from;
        to = to === 'All' ? t : to;
      }

      const qs = new URLSearchParams({ page: '1', limit: '300' });
      if (warehouseFilter && warehouseFilter !== 'All') qs.set('warehouse', warehouseFilter);
      if (from && from !== 'All') qs.set('fromDate', from);
      if (to && to !== 'All') qs.set('toDate', to);

      const endpoint =
        logType === 'inward'
          ? '/api/inward-logs'
          : logType === 'outward'
            ? '/api/outward-logs'
            : '/api/chamber-temp';

      const res = await fetch(`${apiUrl}${endpoint}?${qs.toString()}`, {
        headers: authHeaders
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        throw new Error(data.message || data.error || `Failed to load logs (${res.status})`);
      }
      let items = Array.isArray(data?.items) ? data.items : Array.isArray(data) ? data : [];
      items = items.map((row) => normalizeLogRow(row, logType));

      if (clientFilter && clientFilter !== 'All') {
        const needle = clientFilter.trim().toLowerCase();
        items = items.filter((r) => String(r.client_name || '').trim().toLowerCase() === needle);
      }

      // Sort chambers daily data: date desc, then chamber, then shift
      if (logType === 'chambers') {
        items.sort((a, b) => {
          const da = String(a.formatted_date || a.entry_date || '').slice(0, 10);
          const db = String(b.formatted_date || b.entry_date || '').slice(0, 10);
          if (da !== db) return db.localeCompare(da);
          const ca = String(a.chamber_name || '').localeCompare(String(b.chamber_name || ''));
          if (ca !== 0) return ca;
          const sa = String(a.shift || '');
          const sb = String(b.shift || '');
          if (sa === sb) return String(a.client_name || '').localeCompare(String(b.client_name || ''));
          if (sa === 'Morning') return -1;
          if (sb === 'Morning') return 1;
          return sa.localeCompare(sb);
        });
      }

      const whSet = new Set();
      const clSet = new Set();
      items.forEach((r) => {
        if (r.warehouse_name) whSet.add(String(r.warehouse_name).trim());
        if (r.client_name) clSet.add(String(r.client_name).trim());
      });
      setWarehouses(Array.from(whSet).sort((a, b) => a.localeCompare(b)));
      setClients(Array.from(clSet).sort((a, b) => a.localeCompare(b)));
      setLogs(items);
    } catch (err) {
      setLogs([]);
      setLogsError(err.message || 'Failed to load logs.');
    } finally {
      setLogsLoading(false);
      setLogsRefreshing(false);
    }
  }, [apiUrl, token, authHeaders, warehouseFilter, clientFilter, dateFrom, dateTo, logType]);

  const loadReports = useCallback(async () => {
    if (!apiUrl || !token) return;
    setReportsLoading(true);
    setReportsError('');
    try {
      const res = await fetch(`${apiUrl}/api/dashboard/inventory-reconciliation`, {
        headers: authHeaders
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        throw new Error(data.message || data.error || `Failed to load inventory (${res.status})`);
      }
      const rows = Array.isArray(data?.items)
        ? data.items
        : Array.isArray(data)
          ? data
          : [];

      const whSet = new Set();
      const clientSet = new Set();
      rows.forEach((r) => {
        if (r.warehouse_name) whSet.add(String(r.warehouse_name).trim());
        if (r.client_name) clientSet.add(String(r.client_name).trim());
      });
      setReportWarehouses(Array.from(whSet).sort((a, b) => a.localeCompare(b)));
      setReportClients(Array.from(clientSet).sort((a, b) => a.localeCompare(b)));
      setReportRows(rows);
    } catch (err) {
      setReportRows([]);
      setReportsError(err.message || 'Failed to load inventory reports.');
    } finally {
      setReportsLoading(false);
      setReportsRefreshing(false);
    }
  }, [apiUrl, token, authHeaders]);

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
    rows = dedupeInventoryLots(rows);
    return [...rows].sort((a, b) => {
      // LIFO: newest audit first on Reports list (before click)
      const dateA = String(a.last_audit_date || a.entry_date || '').slice(0, 10);
      const dateB = String(b.last_audit_date || b.entry_date || '').slice(0, 10);
      if (dateB !== dateA) {
        if (!dateA) return 1;
        if (!dateB) return -1;
        return dateB.localeCompare(dateA);
      }
      const timeA = String(a.updated_at || a.created_at || a.last_audit_date || '')
        .replace('T', ' ')
        .slice(0, 19);
      const timeB = String(b.updated_at || b.created_at || b.last_audit_date || '')
        .replace('T', ' ')
        .slice(0, 19);
      if (timeB !== timeA) {
        if (!timeA) return 1;
        if (!timeB) return -1;
        return timeB.localeCompare(timeA);
      }
      return String(a.client_name || '').localeCompare(String(b.client_name || ''));
    });
  }, [reportRows, reportWarehouseFilter, reportClientFilter, reportView]);

  useEffect(() => {
    if (activeTab === 'Home') loadHomeOverview();
  }, [activeTab, loadHomeOverview]);

  useEffect(() => {
    if (activeTab === 'Logs') loadLogs();
  }, [activeTab, loadLogs]);

  useEffect(() => {
    if (activeTab === 'Reports') loadReports();
  }, [activeTab, loadReports]);

  const loadNotifications = useCallback(async () => {
    if (!apiUrl || !token) return;
    try {
      const res = await fetch(`${apiUrl}/api/permission-requests?_=${Date.now()}`, {
        headers: authHeaders
      });
      if (!res.ok) return;
      const data = await res.json().catch(() => []);
      if (Array.isArray(data)) setNotifications(data);
    } catch (_) {
      // keep last list if offline
    }
  }, [apiUrl, token, authHeaders]);

  useEffect(() => {
    if (!apiUrl || !token) return undefined;
    loadNotifications();
    const timer = setInterval(loadNotifications, 15000);
    return () => clearInterval(timer);
  }, [apiUrl, token, loadNotifications]);

  useEffect(() => {
    if (dateFrom === 'All' || dateTo === 'All') return;
    if (dateTo < dateFrom) setDateTo(dateFrom);
  }, [dateFrom, dateTo]);

  const formatNotifTime = (value) => {
    if (!value) return '';
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) {
      return String(value).replace('T', ' ').slice(0, 16);
    }
    const ymd = toLocalYmd(d);
    const hm = `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
    return `${ymd} ${hm}`;
  };

  const isRolePermissionNotif = (n) => {
    const type = String(n.record_type || '');
    // Same Scope as Super Admin Role & Permission (skip notify-only noise)
    if (type === 'ClientMaster' || type === 'MasterSetup' || type === 'DO_CHANGE' || type === 'activity') {
      return false;
    }
    return ['Chamber', 'Inward', 'Outward', 'ChamberMaster'].includes(type);
  };

  const rolePermissionNotifications = useMemo(
    () => notifications.filter(isRolePermissionNotif),
    [notifications]
  );

  const getNotifMessage = (n) => {
    const msg =
      n.request_description ||
      n.description ||
      n.request_remark ||
      n.remark ||
      '';
    return String(msg)
      .replace(/\s*\(id:\s*\d+\)/gi, '')
      .replace(/Chamber\s*#\s*\d+/gi, (n.chamber_name || 'Chamber').trim())
      .trim() || 'Role & Permission request';
  };

  const getNotifTitle = (n) => {
    const status = String(n.status || 'Pending');
    const type = String(n.record_type || 'Record');
    const action = /DELETE|delete/i.test(String(n.raw_action || n.description || ''))
      ? 'Delete'
      : 'Edit';
    const chamberLabel = String(n.chamber_name || '').trim();
    const typeLabel =
      (type === 'ChamberMaster' || type === 'Chamber') && chamberLabel
        ? chamberLabel
        : type;
    if (status === 'Pending') return `Role & Permission · ${action} · ${typeLabel}`;
    if (status === 'Approved') return `${action} approved · ${typeLabel}`;
    if (status === 'Denied') return `${action} denied · ${typeLabel}`;
    return `${status} · ${typeLabel}`;
  };

  const filteredNotifications = useMemo(() => {
    let list = [...rolePermissionNotifications];
    if (notifFilter === 'pending') {
      list = list.filter((n) => n.status === 'Pending');
    } else if (notifFilter === 'decided') {
      list = list.filter((n) => n.status === 'Approved' || n.status === 'Denied');
    }
    // Pending first, then newest
    list.sort((a, b) => {
      const ap = a.status === 'Pending' ? 0 : 1;
      const bp = b.status === 'Pending' ? 0 : 1;
      if (ap !== bp) return ap - bp;
      return (Number(b.id) || 0) - (Number(a.id) || 0);
    });
    return list.slice(0, 80);
  }, [rolePermissionNotifications, notifFilter]);

  const pendingNotifCount = useMemo(
    () => rolePermissionNotifications.filter((n) => n.status === 'Pending').length,
    [rolePermissionNotifications]
  );

  const unreadNotifCount = useMemo(() => {
    const pending = rolePermissionNotifications.filter((n) => n.status === 'Pending');
    const unseenDecided = rolePermissionNotifications.filter(
      (n) =>
        (n.status === 'Approved' || n.status === 'Denied') &&
        !seenNotifIds.includes(Number(n.id))
    );
    return pending.length + unseenDecided.length;
  }, [rolePermissionNotifications, seenNotifIds]);

  const respondToPermissionRequest = async (notifId, status) => {
    if (!notifId || !apiUrl || !token || notifActionBusy) return;
    setNotifActionBusy(`${notifId}-${status}`);
    try {
      const res = await fetch(`${apiUrl}/api/permission-requests/${notifId}`, {
        method: 'PUT',
        headers: {
          ...authHeaders,
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({ status })
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        throw new Error(data.error || data.message || `Failed to ${status.toLowerCase()}`);
      }
      await loadNotifications();
    } catch (err) {
      Alert.alert('Request update failed', err.message || 'Please try again.');
    } finally {
      setNotifActionBusy(null);
    }
  };

  const openNotifications = () => {
    setShowNotifications(true);
    setNotifFilter('pending');
    loadNotifications();
    const decidedIds = rolePermissionNotifications
      .filter((n) => n.status === 'Approved' || n.status === 'Denied')
      .map((n) => Number(n.id))
      .filter((id) => Number.isFinite(id));
    if (decidedIds.length) {
      setSeenNotifIds((prev) => Array.from(new Set([...prev, ...decidedIds])));
    }
  };

  const handleLogoutPress = async () => {
    if (busy) return;
    setBusy(true);
    try {
      await onLogout?.();
    } finally {
      setBusy(false);
    }
  };

  const overviewCards = useMemo(() => {
    const t = taskSummary || {};
    const clientTotal =
      Number(t.clients) ||
      warehouseTasks.reduce((sum, w) => sum + (Number(w.assignment_count) || 0), 0);
    return [
      {
        key: 'warehouses',
        label: 'Warehouses',
        value: Number(t.warehouses) || warehouseTasks.length || 0,
        icon: 'business-outline',
        color: '#0284c7'
      },
      {
        key: 'clients',
        label: 'Clients',
        value: clientTotal,
        icon: 'briefcase-outline',
        color: '#059669'
      },
      {
        key: 'ops',
        label: "DO's",
        value: Number(t.operators) || 0,
        icon: 'people-outline',
        color: '#003580'
      }
    ];
  }, [taskSummary, warehouseTasks]);

  const reportSummary = useMemo(() => {
    let inward = 0;
    let outward = 0;
    let balance = 0;
    let mismatches = 0;
    filteredReportRows.forEach((r) => {
      inward += Math.max(0, Number(r.total_inward_boxes) || 0);
      outward += Math.max(0, Number(r.total_outward_boxes) || 0);
      balance += Math.max(0, Number(r.calculated_balance) || 0);
      const bal = Math.max(0, Number(r.calculated_balance) || 0);
      const phys = Math.max(0, Number(r.physical_audit_count) || 0);
      if (bal - phys !== 0) mismatches += 1;
    });
    return {
      lots: filteredReportRows.length,
      inward,
      outward,
      balance,
      mismatches
    };
  }, [filteredReportRows]);

  const reportWarehouseOptions = useMemo(
    () => ['All', ...reportWarehouses],
    [reportWarehouses]
  );
  const reportClientOptions = useMemo(() => ['All', ...reportClients], [reportClients]);

  const to24hTime = (value) => {
    if (value == null || String(value).trim() === '') return null;
    const s = String(value).trim();

    // ISO / datetime: 2026-08-10T14:35:22 or 2026-08-10 14:35:22
    const iso = s.match(/(?:T|\s)(\d{1,2}):(\d{2})(?::\d{2})?/);
    if (iso && !/[APMapm]{2}/.test(s)) {
      const hh = String(Math.min(23, parseInt(iso[1], 10))).padStart(2, '0');
      const mm = iso[2];
      return `${hh}:${mm}`;
    }

    // 12h clock: 4:05 PM / 10:00 am
    const ampm = s.match(/(\d{1,2}):(\d{2})(?::\d{2})?\s*([AaPp][Mm])/);
    if (ampm) {
      let h = parseInt(ampm[1], 10);
      const m = ampm[2];
      const ap = ampm[3].toUpperCase();
      if (ap === 'PM' && h < 12) h += 12;
      if (ap === 'AM' && h === 12) h = 0;
      return `${String(h).padStart(2, '0')}:${m}`;
    }

    // Already 24h clock fragment
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
    // Prefer actual submit time (created_at), then capture/submit fields — always 24h
    const candidates = [row.created_at, row.submit_time, row.photo_capture_time, row.inspection_time];
    for (const c of candidates) {
      const t = to24hTime(c);
      if (t) return t;
    }
    return '—';
  };

  const openReportDetail = useCallback(
    async (row) => {
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
        if (row.chamber_id != null && String(row.chamber_id).trim() !== '') {
          qs.set('chamber_id', String(row.chamber_id));
        } else if (row.chamber_name) {
          qs.set('chamber', String(row.chamber_name).trim());
        }

        const res = await fetch(`${apiUrl}/api/chamber-temp?${qs.toString()}`, {
          headers: authHeaders
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
          throw new Error(data.message || data.error || `Failed to load history (${res.status})`);
        }
        let items = Array.isArray(data?.items) ? data.items : Array.isArray(data) ? data : [];

        const clientNeedle = String(row.client_name || '')
          .trim()
          .toLowerCase();
        const whNeedle = String(row.warehouse_name || '')
          .trim()
          .toLowerCase();
        const chamberId = row.chamber_id != null && String(row.chamber_id).trim() !== ''
          ? Number(row.chamber_id)
          : null;
        const chamberNeedle = String(row.chamber_name || '')
          .trim()
          .toLowerCase();
        items = items.filter((r) => {
          const c = String(r.client_name || '')
            .trim()
            .toLowerCase();
          const w = String(r.warehouse_name || '')
            .trim()
            .toLowerCase();
          const clientMatch = !clientNeedle || c === clientNeedle;
          const whMatch = !whNeedle || w === whNeedle;
          const logCid = r.chamber_id != null && String(r.chamber_id).trim() !== ''
            ? Number(r.chamber_id)
            : null;
          const chamberMatch = chamberId != null && Number.isFinite(chamberId)
            ? logCid === chamberId
            : !chamberNeedle ||
              String(r.chamber_name || '').trim().toLowerCase() === chamberNeedle;
          return clientMatch && whMatch && chamberMatch;
        });

        setReportHistory(buildReportReadingRows(items));
      } catch (err) {
        setReportHistory([]);
        setReportHistoryError(err.message || 'Failed to load day-wise qty.');
      } finally {
        setReportHistoryLoading(false);
      }
    },
    [apiUrl, authHeaders]
  );

  const closeReportDetail = () => {
    setSelectedReport(null);
    setReportHistory([]);
    setReportHistoryError('');
    setReportHistoryLoading(false);
  };

  const renderReportItem = ({ item }) => {
    const inward = Math.max(0, Number(item.total_inward_boxes) || 0);
    const outward = Math.max(0, Number(item.total_outward_boxes) || 0);
    const balance = Math.max(0, Number(item.calculated_balance) || 0);
    const physical = Math.max(0, Number(item.physical_audit_count) || 0);
    // Diff = system balance vs physical (never show a minus sign — show gap size)
    const rawDiff = balance - physical;
    const mismatch = rawDiff !== 0;
    const gap = Math.abs(rawDiff);
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
              {` · In ${inward} · Out ${outward} · Bal ${balance} · Phys ${physical}`}
            </Text>
          </View>
          <Text style={[styles.dailyTemp, { color: mismatch ? '#dc2626' : '#059669' }]}>
            {mismatch ? gap : 0}
          </Text>
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
        style={styles.logCard}
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

  const warehouseOptions = useMemo(() => ['All', ...warehouses], [warehouses]);
  const clientOptions = useMemo(() => ['All', ...clients], [clients]);

  return (
    <SafeAreaView style={styles.safe}>
      <StatusBar barStyle="dark-content" backgroundColor="#ffffff" />

      <View style={styles.header}>
        <View style={styles.headerLeft}>
          <Image
            source={require('../../assets/logo-transparent.png')}
            style={styles.headerLogo}
            resizeMode="contain"
          />
          <View>
            <Text style={styles.headerTitle}>Sub-Admin</Text>
            <Text style={styles.headerSub} numberOfLines={1}>
              Welcome, {displayName}
            </Text>
          </View>
        </View>
        <View style={styles.headerRight}>
          <TouchableOpacity
            style={styles.bellBtn}
            onPress={openNotifications}
            activeOpacity={0.85}
          >
            <Ionicons
              name={unreadNotifCount > 0 ? 'notifications' : 'notifications-outline'}
              size={22}
              color="#003580"
            />
            {unreadNotifCount > 0 ? (
              <View style={styles.bellBadge}>
                <Text style={styles.bellBadgeText}>
                  {unreadNotifCount > 99 ? '99+' : unreadNotifCount}
                </Text>
              </View>
            ) : null}
          </TouchableOpacity>
          <View style={styles.badge}>
            <Ionicons name="shield-checkmark" size={12} color="#003580" />
            <Text style={styles.badgeText}>Full access</Text>
          </View>
        </View>
      </View>

      {activeTab === 'Logs' ? (
        <View style={styles.contentArea}>
          <View style={styles.filterPanel}>
            <View style={styles.logTypeRow}>
              {[
                { id: 'chambers', label: 'Chambers' },
                { id: 'inward', label: 'Inward' },
                { id: 'outward', label: 'Outward' }
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

            <View style={styles.filterRow}>
              <TouchableOpacity
                style={[styles.filterChip, warehouseFilter !== 'All' && styles.filterChipActive]}
                onPress={() => setOpenFilter(openFilter === 'warehouse' ? null : 'warehouse')}
              >
                <Text style={styles.filterChipLabel}>WH</Text>
                <Text style={styles.filterChipValue} numberOfLines={1}>
                  {warehouseFilter}
                </Text>
              </TouchableOpacity>
              <TouchableOpacity
                style={[styles.filterChip, clientFilter !== 'All' && styles.filterChipActive]}
                onPress={() => setOpenFilter(openFilter === 'client' ? null : 'client')}
              >
                <Text style={styles.filterChipLabel}>Client</Text>
                <Text style={styles.filterChipValue} numberOfLines={1}>
                  {clientFilter}
                </Text>
              </TouchableOpacity>
              <TouchableOpacity
                style={[styles.filterChip, dateFrom !== 'All' && styles.filterChipActive]}
                onPress={() => openCalendar('from')}
              >
                <Text style={styles.filterChipLabel}>From</Text>
                <Text style={styles.filterChipValue} numberOfLines={1}>
                  {formatDateLabel(dateFrom)}
                </Text>
              </TouchableOpacity>
              <TouchableOpacity
                style={[styles.filterChip, dateTo !== 'All' && styles.filterChipActive]}
                onPress={() => openCalendar('to')}
              >
                <Text style={styles.filterChipLabel}>To</Text>
                <Text style={styles.filterChipValue} numberOfLines={1}>
                  {formatDateLabel(dateTo)}
                </Text>
              </TouchableOpacity>
            </View>

            <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.suggestRow}>
              <TouchableOpacity
                style={styles.suggestChip}
                onPress={() => {
                  const t = toLocalYmd();
                  applyDateRange(t, t);
                }}
              >
                <Text style={styles.suggestText}>Today</Text>
              </TouchableOpacity>
              <TouchableOpacity
                style={styles.suggestChip}
                onPress={() => {
                  const end = new Date();
                  const start = new Date();
                  start.setDate(end.getDate() - 6);
                  applyDateRange(toLocalYmd(start), toLocalYmd(end));
                }}
              >
                <Text style={styles.suggestText}>7 days</Text>
              </TouchableOpacity>
              <TouchableOpacity style={styles.suggestChip} onPress={clearAllFilters}>
                <Text style={styles.suggestText}>Clear</Text>
              </TouchableOpacity>
            </ScrollView>
          </View>

            {logType === 'chambers' ? (
              <View style={styles.dailyBanner}>
                <Ionicons name="thermometer-outline" size={14} color="#003580" />
                <Text style={styles.dailyBannerText}>
                  Daily chamber data · {logs.length} entr
                  {logs.length === 1 ? 'y' : 'ies'}
                  {dateFrom !== 'All' && dateFrom === dateTo
                    ? ` · ${formatDateLabel(dateFrom)}`
                    : ''}
                </Text>
              </View>
            ) : null}

          {logsLoading && !logsRefreshing ? (
            <View style={styles.centerState}>
              <ActivityIndicator size="large" color="#003580" />
              <Text style={styles.stateText}>
                Loading {logType === 'inward' ? 'inward' : logType === 'outward' ? 'outward' : 'chamber'}{' '}
                logs…
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
              contentContainerStyle={styles.listBody}
              refreshControl={
                <RefreshControl
                  refreshing={logsRefreshing}
                  onRefresh={() => {
                    setLogsRefreshing(true);
                    loadLogs();
                  }}
                />
              }
              ListEmptyComponent={
                <View style={styles.centerState}>
                  <Ionicons name="document-text-outline" size={28} color="#94a3b8" />
                  <Text style={styles.stateText}>No logs for selected filters.</Text>
                </View>
              }
            />
          )}
        </View>
      ) : activeTab === 'Reports' ? (
        <View style={styles.contentArea}>
          <View style={styles.filterPanel}>
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

            <View style={styles.filterRow}>
              <TouchableOpacity
                style={[
                  styles.filterChip,
                  reportWarehouseFilter !== 'All' && styles.filterChipActive
                ]}
                onPress={() =>
                  setOpenFilter(openFilter === 'reportWarehouse' ? null : 'reportWarehouse')
                }
              >
                <Text style={styles.filterChipLabel}>WH</Text>
                <Text style={styles.filterChipValue} numberOfLines={1}>
                  {reportWarehouseFilter}
                </Text>
              </TouchableOpacity>
              <TouchableOpacity
                style={[
                  styles.filterChip,
                  reportClientFilter !== 'All' && styles.filterChipActive
                ]}
                onPress={() =>
                  setOpenFilter(openFilter === 'reportClient' ? null : 'reportClient')
                }
              >
                <Text style={styles.filterChipLabel}>Client</Text>
                <Text style={styles.filterChipValue} numberOfLines={1}>
                  {reportClientFilter}
                </Text>
              </TouchableOpacity>
            </View>

            <ScrollView
              horizontal
              showsHorizontalScrollIndicator={false}
              contentContainerStyle={styles.suggestRow}
            >
              <TouchableOpacity
                style={styles.suggestChip}
                onPress={() => setReportView('all')}
              >
                <Text style={styles.suggestText}>All</Text>
              </TouchableOpacity>
              <TouchableOpacity
                style={styles.suggestChip}
                onPress={() => setReportView('mismatch')}
              >
                <Text style={styles.suggestText}>Mismatch only</Text>
              </TouchableOpacity>
              <TouchableOpacity
                style={styles.suggestChip}
                onPress={() => {
                  setReportView('all');
                  setReportWarehouseFilter('All');
                  setReportClientFilter('All');
                }}
              >
                <Text style={styles.suggestText}>Clear</Text>
              </TouchableOpacity>
            </ScrollView>
          </View>

          <View style={styles.dailyBanner}>
            <Ionicons name="cube-outline" size={14} color="#003580" />
            <Text style={styles.dailyBannerText}>
              Inventory · {reportSummary.lots} lot{reportSummary.lots === 1 ? '' : 's'}
              {` · In ${reportSummary.inward} · Out ${reportSummary.outward}`}
              {reportSummary.mismatches ? ` · ${reportSummary.mismatches} mismatch` : ''}
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
              contentContainerStyle={styles.listBody}
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
          contentContainerStyle={[styles.body, activeTab === 'More' && styles.moreBody]}
          showsVerticalScrollIndicator={false}
          refreshControl={
            activeTab === 'Home' ? (
              <RefreshControl
                refreshing={homeRefreshing}
                onRefresh={() => {
                  setHomeRefreshing(true);
                  loadHomeOverview();
                }}
              />
            ) : undefined
          }
        >
          {activeTab === 'Home' && (
            <>
              <View style={styles.hero}>
                <Text style={styles.heroEyebrow}>Operations overview</Text>
                <Text style={styles.heroTitle}>Mini Admin Home</Text>
                <Text style={styles.heroSub}>
                  Watch all warehouses, clients, daily logs and inventory movement in one place.
                </Text>
              </View>

              {homeLoading && !homeRefreshing ? (
                <View style={styles.centerState}>
                  <ActivityIndicator size="large" color="#003580" />
                  <Text style={styles.stateText}>Loading overview…</Text>
                </View>
              ) : homeError ? (
                <View style={styles.centerState}>
                  <Ionicons name="cloud-offline-outline" size={28} color="#dc2626" />
                  <Text style={styles.stateText}>{homeError}</Text>
                  <TouchableOpacity style={styles.retryBtn} onPress={loadHomeOverview}>
                    <Text style={styles.retryText}>Retry</Text>
                  </TouchableOpacity>
                </View>
              ) : (
                <>
                  <View style={styles.statsGrid}>
                    {overviewCards.map((card) => (
                      <View key={card.key} style={styles.statCard}>
                        <View style={[styles.statIcon, { backgroundColor: `${card.color}18` }]}>
                          <Ionicons name={card.icon} size={12} color={card.color} />
                        </View>
                        <Text style={[styles.statValue, { color: card.color }]}>{card.value}</Text>
                        <Text style={styles.statLabel} numberOfLines={1}>
                          {card.label}
                        </Text>
                      </View>
                    ))}
                  </View>

                  <View style={styles.quickRow}>
                    <TouchableOpacity style={styles.quickBtn} onPress={() => setActiveTab('Logs')}>
                      <Ionicons name="list-outline" size={18} color="#003580" />
                      <Text style={styles.quickText}>All logs</Text>
                    </TouchableOpacity>
                    <TouchableOpacity style={styles.quickBtn} onPress={() => setActiveTab('Reports')}>
                      <Ionicons name="stats-chart-outline" size={18} color="#003580" />
                      <Text style={styles.quickText}>Reports</Text>
                    </TouchableOpacity>
                  </View>

                  <View style={styles.doSection}>
                    <View style={styles.doOverviewCard}>
                      <Text style={styles.doOverviewTitle}>DO tasks today</Text>

                      {taskSummary ? (
                        <View style={styles.doOverviewStats}>
                          <Text style={styles.doOverviewStat}>
                            <Text style={{ color: '#059669', fontWeight: '800' }}>
                              {Number(taskSummary.completed) || 0}
                            </Text>{' '}
                            completed
                          </Text>
                          <Text style={styles.doOverviewDot}>·</Text>
                          <Text style={styles.doOverviewStat}>
                            <Text style={{ color: '#d97706', fontWeight: '800' }}>
                              {Number(taskSummary.pending) || 0}
                            </Text>{' '}
                            pending
                          </Text>
                          <Text style={styles.doOverviewDot}>·</Text>
                          <Text style={styles.doOverviewStat}>
                            <Text style={{ color: '#dc2626', fontWeight: '800' }}>
                              {Number(taskSummary.overdue) || 0}
                            </Text>{' '}
                            overdue
                          </Text>
                        </View>
                      ) : null}

                      {warehouseTasks.length === 0 ? (
                        <Text style={styles.cardHintSm}>No warehouse / DO task data yet.</Text>
                      ) : (
                        warehouseTasks.map((wh, idx) => {
                          const done = Number(wh.completed) || 0;
                          const pending = Number(wh.pending) || 0;
                          const overdue = Number(wh.overdue) || 0;
                          const expected = Number(wh.expected_today) || done + pending;
                          const allDone = pending === 0 && expected > 0;
                          const color =
                            overdue > 0 ? '#dc2626' : allDone ? '#059669' : '#d97706';
                          return (
                            <View
                              key={wh.warehouse_name}
                              style={[styles.doOverviewRow, idx > 0 && styles.doOverviewRowBorder]}
                            >
                              <View style={[styles.doOverviewDotSm, { backgroundColor: color }]} />
                              <View style={{ flex: 1, minWidth: 0 }}>
                                <Text style={styles.doOverviewWh} numberOfLines={1}>
                                  {wh.warehouse_name}
                                </Text>
                                <Text style={styles.doOverviewMeta} numberOfLines={1}>
                                  {wh.do_names || 'No DO'}
                                </Text>
                              </View>
                              <View style={styles.doCountPills}>
                                <View style={[styles.doCountPill, styles.doCountPillDone]}>
                                  <Text style={[styles.doCountPillNum, { color: '#059669' }]}>
                                    {done}
                                  </Text>
                                  <Text style={[styles.doCountPillLbl, { color: '#059669' }]}>
                                    Done
                                  </Text>
                                </View>
                                <View style={[styles.doCountPill, styles.doCountPillPend]}>
                                  <Text style={[styles.doCountPillNum, { color: '#d97706' }]}>
                                    {pending}
                                  </Text>
                                  <Text style={[styles.doCountPillLbl, { color: '#d97706' }]}>
                                    Pending
                                  </Text>
                                </View>
                                <View style={[styles.doCountPill, styles.doCountPillOver]}>
                                  <Text style={[styles.doCountPillNum, { color: '#dc2626' }]}>
                                    {overdue}
                                  </Text>
                                  <Text style={[styles.doCountPillLbl, { color: '#dc2626' }]}>
                                    Overdue
                                  </Text>
                                </View>
                              </View>
                            </View>
                          );
                        })
                      )}
                    </View>
                  </View>

                  <View style={styles.card}>
                    <View style={styles.cardTitleRow}>
                      <Text style={styles.cardTitle}>Today’s activity</Text>
                      <TouchableOpacity onPress={() => setActiveTab('Logs')}>
                        <Text style={styles.linkText}>View all →</Text>
                      </TouchableOpacity>
                    </View>
                    {todayLogs.length === 0 ? (
                      <Text style={styles.cardHint}>No temperature logs recorded today yet.</Text>
                    ) : (
                      todayLogs.slice(0, 12).map((item, idx) => (
                        <TouchableOpacity
                          key={String(item.id || item.reference_no || idx)}
                          style={[styles.recentRow, idx > 0 && styles.recentBorder]}
                          onPress={() => setSelectedLog(item)}
                        >
                          <View style={{ flex: 1 }}>
                            <Text style={styles.recentClient} numberOfLines={1}>
                              {item.client_name || 'Client'}
                            </Text>
                            <Text style={styles.recentMeta} numberOfLines={1}>
                              {item.chamber_name || 'Chamber'}
                              {item.warehouse_name ? ` · ${item.warehouse_name}` : ''}
                              {item.shift ? ` · ${item.shift}` : ''}
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
                </>
              )}
            </>
          )}

          {activeTab === 'More' && (
            <>
              <View style={styles.card}>
                <Text style={styles.cardTitle}>Profile</Text>
                <Text style={styles.profileRow}>
                  <Text style={styles.profileKey}>Role: </Text>
                  <Text style={styles.profileVal}>Sub-Admin (full mobile access)</Text>
                </Text>
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
              </View>

              <TouchableOpacity
                style={styles.logoutBtn}
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

              <View style={styles.aboutFooter}>
                <Image
                  source={require('../../assets/logo-transparent.png')}
                  style={styles.aboutLogo}
                  resizeMode="contain"
                />
                <Text style={styles.aboutTitle}>About ReeferON</Text>
                <Text style={styles.aboutBody}>
                  Sub-Admin can monitor all cold-chain logs and inventory reports across the
                  operation — separate from scoped Customer accounts.
                </Text>
                <TouchableOpacity onPress={() => Linking.openURL('tel:+917678047222')}>
                  <Text style={styles.aboutContact}>+91 76780 47222</Text>
                </TouchableOpacity>
              </View>
            </>
          )}
        </ScrollView>
      )}

      {/* Filter pickers */}
      <Modal visible={openFilter != null} transparent animationType="fade" onRequestClose={() => setOpenFilter(null)}>
        <TouchableOpacity style={styles.sheetOverlay} activeOpacity={1} pressBorder={false} onPress={() => setOpenFilter(null)}>
          <View style={styles.sheet}>
            <Text style={styles.sheetTitle}>
              {openFilter === 'warehouse' || openFilter === 'reportWarehouse'
                ? 'Select warehouse'
                : 'Select client'}
            </Text>
            <ScrollView style={{ maxHeight: 320 }}>
              {(openFilter === 'warehouse'
                ? warehouseOptions
                : openFilter === 'reportWarehouse'
                  ? reportWarehouseOptions
                  : openFilter === 'reportClient'
                    ? reportClientOptions
                    : clientOptions
              ).map((opt) => (
                <TouchableOpacity
                  key={opt}
                  style={styles.sheetItem}
                  onPress={() => {
                    if (openFilter === 'warehouse') setWarehouseFilter(opt);
                    else if (openFilter === 'client') setClientFilter(opt);
                    else if (openFilter === 'reportWarehouse') setReportWarehouseFilter(opt);
                    else if (openFilter === 'reportClient') setReportClientFilter(opt);
                    setOpenFilter(null);
                  }}
                >
                  <Text style={styles.sheetItemText}>{opt}</Text>
                </TouchableOpacity>
              ))}
            </ScrollView>
          </View>
        </TouchableOpacity>
      </Modal>

      {/* Calendar */}
      <Modal visible={showCalendarModal} transparent animationType="fade" onRequestClose={() => setShowCalendarModal(false)}>
        <View style={styles.sheetOverlay}>
          <View style={styles.calendarSheet}>
            <View style={styles.calendarHead}>
              <TouchableOpacity
                onPress={() =>
                  setCalendarMonth(new Date(calendarMonth.getFullYear(), calendarMonth.getMonth() - 1, 1))
                }
              >
                <Ionicons name="chevron-back" size={22} color="#003580" />
              </TouchableOpacity>
              <Text style={styles.calendarTitle}>
                {calendarMonth.toLocaleString('en-IN', { month: 'long', year: 'numeric' })}
              </Text>
              <TouchableOpacity
                onPress={() =>
                  setCalendarMonth(new Date(calendarMonth.getFullYear(), calendarMonth.getMonth() + 1, 1))
                }
              >
                <Ionicons name="chevron-forward" size={22} color="#003580" />
              </TouchableOpacity>
            </View>
            <View style={styles.calendarGrid}>
              {getCalendarDays(calendarMonth).map((day, idx) => {
                if (!day) return <View key={`e-${idx}`} style={styles.calCell} />;
                const ymd = toLocalYmd(day);
                const selected =
                  (calendarPickMode === 'from' && dateFrom === ymd) ||
                  (calendarPickMode === 'to' && dateTo === ymd);
                return (
                  <TouchableOpacity
                    key={ymd}
                    style={[styles.calCell, selected && styles.calCellActive]}
                    onPress={() => {
                      if (calendarPickMode === 'from') applyDateRange(ymd, dateTo === 'All' ? ymd : dateTo);
                      else applyDateRange(dateFrom === 'All' ? ymd : dateFrom, ymd);
                      setShowCalendarModal(false);
                    }}
                  >
                    <Text style={[styles.calCellText, selected && styles.calCellTextActive]}>
                      {day.getDate()}
                    </Text>
                  </TouchableOpacity>
                );
              })}
            </View>
            <TouchableOpacity style={styles.retryBtn} onPress={() => setShowCalendarModal(false)}>
              <Text style={styles.retryText}>Close</Text>
            </TouchableOpacity>
          </View>
        </View>
      </Modal>

      {/* Log detail */}
      <Modal visible={!!selectedLog} transparent animationType="slide" onRequestClose={() => setSelectedLog(null)}>
        <View style={styles.detailOverlay}>
          <View style={styles.detailSheet}>
            <View style={styles.detailHead}>
              <Text style={styles.detailTitle}>Log detail</Text>
              <TouchableOpacity onPress={() => setSelectedLog(null)}>
                <Ionicons name="close" size={22} color="#334155" />
              </TouchableOpacity>
            </View>
            {selectedLog && (
              <ScrollView>
                <View style={styles.smallImgWrap}>
                  <Text style={styles.detailLabel}>Image</Text>
                  <SmallLogImage
                    rawPath={pickLogImage(selectedLog)}
                    apiUrl={apiUrl}
                    folderHint={
                      selectedLog._logType === 'inward'
                        ? 'inward_temp_monitor_images'
                        : selectedLog._logType === 'outward'
                          ? 'outward_temp_monitor_images'
                          : 'daily_temp_monitor_images'
                    }
                  />
                </View>
                {(selectedLog._logType === 'inward'
                  ? [
                      ['Client', selectedLog.client_name],
                      ['Vehicle', selectedLog.inward_vehicle_no],
                      ['Warehouse', selectedLog.warehouse_name],
                      ['Date', String(selectedLog.formatted_date || selectedLog.entry_date || '').slice(0, 10)],
                      ['Vehicle temp', selectedLog.inward_vehicle_temp != null ? `${selectedLog.inward_vehicle_temp}°C` : null],
                      ['Material temp', selectedLog.inward_material_temp != null ? `${selectedLog.inward_material_temp}°C` : null],
                      ['Received boxes', selectedLog.inward_received_boxes_qty ?? selectedLog.box_count],
                      ['Dock', selectedLog.inward_dock_no],
                      ['Reference', selectedLog.reference_no],
                      [
                        'DO name',
                        selectedLog.inward_unloading_supervisor_name ||
                          selectedLog.monitor_supervisor_name ||
                          (selectedLog.operator_email
                            ? String(selectedLog.operator_email).split('@')[0]
                            : null)
                      ],
                      [
                        'Time',
                        selectedLog.inward_vehicle_reporting_time ||
                          selectedLog.inward_unloading_start_time ||
                          (selectedLog.inward_created_at
                            ? String(selectedLog.inward_created_at).replace('T', ' ').slice(0, 19)
                            : null) ||
                          (selectedLog.created_at
                            ? String(selectedLog.created_at).replace('T', ' ').slice(0, 19)
                            : null)
                      ]
                    ]
                  : selectedLog._logType === 'outward'
                    ? [
                        ['Client', selectedLog.client_name],
                        ['Vehicle', selectedLog.outward_vehicle_no],
                        ['Warehouse', selectedLog.warehouse_name],
                        ['Date', String(selectedLog.formatted_date || selectedLog.entry_date || '').slice(0, 10)],
                        ['Vehicle temp', selectedLog.outward_vehicle_temp != null ? `${selectedLog.outward_vehicle_temp}°C` : null],
                        ['Material temp', selectedLog.outward_material_temp != null ? `${selectedLog.outward_material_temp}°C` : null],
                        ['Boxes', selectedLog.box_count],
                        ['Dock', selectedLog.outward_dock_no],
                        ['Reference', selectedLog.reference_no],
                        [
                          'DO name',
                          selectedLog.outward_loading_supervisor_name ||
                            selectedLog.monitor_supervisor_name ||
                            (selectedLog.operator_email
                              ? String(selectedLog.operator_email).split('@')[0]
                              : null)
                        ],
                        [
                          'Time',
                          selectedLog.outward_vehicle_reporting_time ||
                            selectedLog.outward_loading_start_time ||
                            (selectedLog.outward_created_at
                              ? String(selectedLog.outward_created_at).replace('T', ' ').slice(0, 19)
                              : null) ||
                            (selectedLog.created_at
                              ? String(selectedLog.created_at).replace('T', ' ').slice(0, 19)
                              : null)
                        ]
                      ]
                    : [
                        ['Client', selectedLog.client_name],
                        ['Chamber', selectedLog.chamber_name],
                        ['Warehouse', selectedLog.warehouse_name],
                        ['Date', String(selectedLog.formatted_date || selectedLog.entry_date || '').slice(0, 10)],
                        ['Shift', selectedLog.shift || selectedLog.inspection_time],
                        ['Box temp', selectedLog.box_temp != null ? `${selectedLog.box_temp}°C` : null],
                        ['Boxes', selectedLog.box_count],
                        ['Reference', selectedLog.reference_no],
                        [
                          'DO name',
                          selectedLog.monitor_supervisor_name ||
                            selectedLog.do_name ||
                            (selectedLog.operator_email
                              ? String(selectedLog.operator_email).split('@')[0]
                              : null) ||
                            selectedLog.created_by
                        ],
                        [
                          'Time',
                          selectedLog.photo_capture_time ||
                            selectedLog.submit_time ||
                            (selectedLog.created_at
                              ? String(selectedLog.created_at).replace('T', ' ').slice(0, 19)
                              : null)
                        ]
                      ]
                ).map(([label, value]) =>
                  value != null && String(value).trim() !== '' ? (
                    <View key={label} style={styles.detailRow}>
                      <Text style={styles.detailLabel}>{label}</Text>
                      <Text style={styles.detailValue}>{String(value)}</Text>
                    </View>
                  ) : null
                )}
              </ScrollView>
            )}
          </View>
        </View>
      </Modal>

      {/* Inventory report detail — Excel-style day history */}
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
                    const dateLabel = String(row.formatted_date || row.entry_date || '').slice(0, 10) || '—';
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
                        <Text style={[styles.excelCell, styles.excelColQty, styles.excelQty]} numberOfLines={1}>
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

      {/* Notifications / incoming requests */}
      <Modal
        visible={showNotifications}
        transparent
        animationType="slide"
        onRequestClose={() => setShowNotifications(false)}
      >
        <View style={styles.notifOverlay}>
          <View style={styles.notifSheet}>
            <View style={styles.notifHead}>
              <View style={{ flex: 1, minWidth: 0 }}>
                <Text style={styles.notifTitle}>Role & Permission</Text>
                <Text style={styles.notifSub}>
                  {pendingNotifCount} pending request{pendingNotifCount === 1 ? '' : 's'}
                </Text>
              </View>
              <TouchableOpacity onPress={() => setShowNotifications(false)}>
                <Ionicons name="close" size={22} color="#334155" />
              </TouchableOpacity>
            </View>

            <View style={styles.notifFilterRow}>
              {[
                { id: 'pending', label: 'Pending' },
                { id: 'all', label: 'All' },
                { id: 'decided', label: 'Approved/Denied' }
              ].map((f) => {
                const active = notifFilter === f.id;
                return (
                  <TouchableOpacity
                    key={f.id}
                    style={[styles.notifFilterChip, active && styles.notifFilterChipActive]}
                    onPress={() => setNotifFilter(f.id)}
                    activeOpacity={0.85}
                  >
                    <Text style={[styles.notifFilterText, active && styles.notifFilterTextActive]}>
                      {f.label}
                    </Text>
                  </TouchableOpacity>
                );
              })}
            </View>

            <ScrollView contentContainerStyle={styles.notifList}>
              {filteredNotifications.length === 0 ? (
                <View style={styles.centerState}>
                  <Ionicons name="notifications-off-outline" size={28} color="#94a3b8" />
                  <Text style={styles.stateText}>No Role & Permission requests here.</Text>
                </View>
              ) : (
                filteredNotifications.map((n) => {
                  const status = String(n.status || 'Pending');
                  const statusStyle =
                    status === 'Pending'
                      ? styles.notifStatusPending
                      : status === 'Approved'
                        ? styles.notifStatusApproved
                        : status === 'Denied'
                          ? styles.notifStatusDenied
                          : styles.notifStatusOther;
                  const approving = notifActionBusy === `${n.id}-Approved`;
                  const denying = notifActionBusy === `${n.id}-Denied`;
                  return (
                    <View
                      key={String(n.id)}
                      style={[styles.notifCard, status === 'Pending' && styles.notifCardPending]}
                    >
                      <View style={styles.notifCardTop}>
                        <Text style={styles.notifCardTitle} numberOfLines={2}>
                          {getNotifTitle(n)}
                        </Text>
                        <Text style={[styles.notifStatus, statusStyle]}>{status}</Text>
                      </View>
                      <Text style={styles.notifMsg}>{getNotifMessage(n)}</Text>
                      <Text style={styles.notifMeta} numberOfLines={2}>
                        {n.operator_email || 'DO'}
                        {n.client_name ? ` · ${n.client_name}` : ''}
                        {n.chamber_name ? ` · ${n.chamber_name}` : ''}
                        {` · ${formatNotifTime(n.created_at)}`}
                      </Text>
                      {status === 'Pending' ? (
                        <View style={styles.notifActions}>
                          <TouchableOpacity
                            style={[styles.notifActionBtn, styles.notifApproveBtn]}
                            disabled={!!notifActionBusy}
                            onPress={() => respondToPermissionRequest(n.id, 'Approved')}
                            activeOpacity={0.85}
                          >
                            {approving ? (
                              <ActivityIndicator size="small" color="#fff" />
                            ) : (
                              <Text style={styles.notifActionText}>Approve</Text>
                            )}
                          </TouchableOpacity>
                          <TouchableOpacity
                            style={[styles.notifActionBtn, styles.notifDenyBtn]}
                            disabled={!!notifActionBusy}
                            onPress={() => respondToPermissionRequest(n.id, 'Denied')}
                            activeOpacity={0.85}
                          >
                            {denying ? (
                              <ActivityIndicator size="small" color="#fff" />
                            ) : (
                              <Text style={styles.notifActionText}>Deny</Text>
                            )}
                          </TouchableOpacity>
                        </View>
                      ) : null}
                    </View>
                  );
                })
              )}
            </ScrollView>
          </View>
        </View>
      </Modal>

      <View style={styles.tabBar}>
        {[
          { id: 'Home', label: 'Home', icon: 'home', iconOutline: 'home-outline' },
          { id: 'Logs', label: 'Logs', icon: 'list', iconOutline: 'list-outline' },
          { id: 'Reports', label: 'Reports', icon: 'stats-chart', iconOutline: 'stats-chart-outline' },
          { id: 'More', label: 'More', icon: 'person', iconOutline: 'person-outline' }
        ].map((tab) => {
          const active = activeTab === tab.id;
          return (
            <TouchableOpacity
              key={tab.id}
              style={styles.tabItem}
              onPress={() => setActiveTab(tab.id)}
              activeOpacity={0.85}
            >
              <Ionicons name={active ? tab.icon : tab.iconOutline} size={22} color={active ? '#003580' : '#94a3b8'} />
              <Text style={[styles.tabLabel, active && styles.tabLabelActive]}>{tab.label}</Text>
            </TouchableOpacity>
          );
        })}
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: '#f8fafc' },
  header: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: 16,
    paddingTop: Platform.OS === 'android' ? (StatusBar.currentHeight || 24) + 4 : 4,
    paddingBottom: 16,
    backgroundColor: '#fff',
    borderBottomWidth: 1,
    borderBottomColor: '#e2e8f0'
  },
  headerLeft: { flexDirection: 'row', alignItems: 'center', gap: 10, flex: 1 },
  headerRight: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  headerLogo: { width: 36, height: 36 },
  headerTitle: { fontSize: 16, fontWeight: '800', color: '#0f172a' },
  headerSub: { fontSize: 12, color: '#64748b', maxWidth: 140 },
  bellBtn: {
    width: 38,
    height: 38,
    borderRadius: 19,
    backgroundColor: '#eff6ff',
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: 1,
    borderColor: '#dbeafe'
  },
  bellBadge: {
    position: 'absolute',
    top: -2,
    right: -4,
    minWidth: 16,
    height: 16,
    borderRadius: 8,
    backgroundColor: '#dc2626',
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: 3,
    borderWidth: 1.5,
    borderColor: '#fff'
  },
  bellBadgeText: { color: '#fff', fontSize: 9, fontWeight: '800' },
  badge: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 4,
    backgroundColor: '#e0f2fe',
    paddingHorizontal: 8,
    paddingVertical: 4,
    borderRadius: 999
  },
  badgeText: { fontSize: 11, fontWeight: '700', color: '#003580' },
  notifOverlay: {
    flex: 1,
    backgroundColor: 'rgba(15,23,42,0.45)',
    justifyContent: 'flex-end'
  },
  notifSheet: {
    backgroundColor: '#fff',
    borderTopLeftRadius: 18,
    borderTopRightRadius: 18,
    maxHeight: '82%',
    paddingBottom: 12
  },
  notifHead: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: 16,
    paddingTop: 14,
    paddingBottom: 10,
    borderBottomWidth: 1,
    borderBottomColor: '#e2e8f0'
  },
  notifTitle: { fontSize: 16, fontWeight: '800', color: '#0f172a' },
  notifSub: { fontSize: 11, color: '#64748b', fontWeight: '600', marginTop: 2 },
  notifFilterRow: {
    flexDirection: 'row',
    gap: 6,
    paddingHorizontal: 12,
    paddingVertical: 10
  },
  notifFilterChip: {
    paddingHorizontal: 12,
    paddingVertical: 6,
    borderRadius: 999,
    backgroundColor: '#f1f5f9',
    borderWidth: 1,
    borderColor: '#e2e8f0'
  },
  notifFilterChipActive: { backgroundColor: '#003580', borderColor: '#003580' },
  notifFilterText: { fontSize: 11, fontWeight: '700', color: '#64748b' },
  notifFilterTextActive: { color: '#fff' },
  notifList: { paddingHorizontal: 12, paddingBottom: 20 },
  notifCard: {
    backgroundColor: '#fff',
    borderRadius: 10,
    padding: 12,
    marginBottom: 8,
    borderWidth: 1,
    borderColor: '#e2e8f0'
  },
  notifCardPending: { borderColor: '#fde68a', backgroundColor: '#fffbeb' },
  notifCardTop: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    justifyContent: 'space-between',
    gap: 8,
    marginBottom: 6
  },
  notifCardTitle: { fontSize: 13, fontWeight: '800', color: '#0f172a', flex: 1 },
  notifStatus: {
    fontSize: 10,
    fontWeight: '800',
    paddingHorizontal: 8,
    paddingVertical: 3,
    borderRadius: 999,
    overflow: 'hidden'
  },
  notifStatusPending: { color: '#d97706', backgroundColor: '#fef3c7' },
  notifStatusApproved: { color: '#059669', backgroundColor: '#d1fae5' },
  notifStatusDenied: { color: '#dc2626', backgroundColor: '#fee2e2' },
  notifStatusOther: { color: '#64748b', backgroundColor: '#f1f5f9' },
  notifMsg: { fontSize: 12, color: '#334155', lineHeight: 18, fontWeight: '600' },
  notifMeta: { fontSize: 10, color: '#94a3b8', fontWeight: '600', marginTop: 8 },
  notifActions: { flexDirection: 'row', gap: 8, marginTop: 10 },
  notifActionBtn: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    paddingVertical: 8,
    borderRadius: 8,
    minHeight: 36
  },
  notifApproveBtn: { backgroundColor: '#059669' },
  notifDenyBtn: { backgroundColor: '#dc2626' },
  notifActionText: { color: '#fff', fontSize: 12, fontWeight: '800' },
  contentArea: { flex: 1, paddingBottom: 64 },
  body: { padding: 16, paddingBottom: 88 },
  moreBody: { paddingBottom: 120 },
  hero: {
    backgroundColor: '#003580',
    borderRadius: 16,
    padding: 18,
    marginBottom: 14
  },
  heroEyebrow: { color: '#7dd3fc', fontSize: 11, fontWeight: '700', letterSpacing: 0.4, marginBottom: 4 },
  heroTitle: { color: '#fff', fontSize: 22, fontWeight: '800', marginBottom: 6 },
  heroSub: { color: '#dbeafe', fontSize: 13, lineHeight: 19 },
  statsGrid: { flexDirection: 'row', gap: 6, marginBottom: 10 },
  statCard: {
    flex: 1,
    minWidth: 0,
    backgroundColor: '#fff',
    borderRadius: 10,
    paddingVertical: 8,
    paddingHorizontal: 6,
    borderWidth: 1,
    borderColor: '#e2e8f0',
    alignItems: 'center'
  },
  statIcon: {
    width: 22,
    height: 22,
    borderRadius: 7,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: 4
  },
  statValue: { fontSize: 15, fontWeight: '800', color: '#0f172a' },
  statLabel: { fontSize: 9, color: '#64748b', marginTop: 1, fontWeight: '700', textAlign: 'center' },
  quickRow: { flexDirection: 'row', gap: 8, marginBottom: 10 },
  quickBtn: {
    flex: 1,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 6,
    backgroundColor: '#fff',
    borderWidth: 1,
    borderColor: '#bfdbfe',
    borderRadius: 10,
    paddingVertical: 9
  },
  quickText: { color: '#003580', fontWeight: '700', fontSize: 12 },
  card: {
    backgroundColor: '#fff',
    borderRadius: 14,
    padding: 14,
    borderWidth: 1,
    borderColor: '#e2e8f0',
    marginBottom: 12
  },
  cardTitleRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 },
  cardTitle: { fontSize: 15, fontWeight: '800', color: '#0f172a', marginBottom: 4 },
  cardHint: { fontSize: 13, color: '#64748b', lineHeight: 19 },
  cardCompact: {
    backgroundColor: '#fff',
    borderRadius: 12,
    paddingHorizontal: 10,
    paddingTop: 10,
    paddingBottom: 8,
    borderWidth: 1,
    borderColor: '#e2e8f0',
    marginBottom: 12
  },
  cardTitleSm: { fontSize: 13, fontWeight: '800', color: '#0f172a', marginBottom: 4 },
  cardHintSm: { fontSize: 11, color: '#94a3b8', marginTop: 4 },
  doSection: { marginBottom: 12 },
  doOverviewCard: {
    backgroundColor: '#fff',
    borderRadius: 12,
    padding: 12,
    borderWidth: 1,
    borderColor: '#e2e8f0'
  },
  doOverviewTitle: {
    fontSize: 14,
    fontWeight: '800',
    color: '#0f172a',
    marginBottom: 8
  },
  doOverviewStats: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    alignItems: 'center',
    gap: 4,
    marginBottom: 10,
    paddingBottom: 10,
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: '#e2e8f0'
  },
  doOverviewStat: { fontSize: 12, color: '#64748b', fontWeight: '600' },
  doOverviewDot: { fontSize: 12, color: '#cbd5e1', fontWeight: '700' },
  doOverviewRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    paddingVertical: 8
  },
  doOverviewRowBorder: {
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: '#e2e8f0'
  },
  doOverviewDotSm: { width: 7, height: 7, borderRadius: 4 },
  doOverviewWh: { fontSize: 13, fontWeight: '700', color: '#0f172a' },
  doOverviewMeta: { fontSize: 11, color: '#64748b', fontWeight: '600', marginTop: 1 },
  doCountPills: { flexDirection: 'row', alignItems: 'center', gap: 4 },
  doCountPill: {
    minWidth: 44,
    alignItems: 'center',
    paddingHorizontal: 5,
    paddingVertical: 4,
    borderRadius: 7
  },
  doCountPillDone: { backgroundColor: '#ecfdf5' },
  doCountPillPend: { backgroundColor: '#fffbeb' },
  doCountPillOver: { backgroundColor: '#fef2f2' },
  doCountPillNum: { fontSize: 12, fontWeight: '800' },
  doCountPillLbl: { fontSize: 8, fontWeight: '700', marginTop: 1 },
  whRow: { paddingVertical: 7 },
  whRowBorder: { borderTopWidth: StyleSheet.hairlineWidth, borderTopColor: '#e2e8f0' },
  whRowMain: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  whDot: { width: 6, height: 6, borderRadius: 3 },
  whRowText: { flex: 1, minWidth: 0 },
  whNameSm: { fontSize: 12, fontWeight: '800', color: '#0f172a' },
  whDoSm: { fontSize: 10, color: '#64748b', marginTop: 1, fontWeight: '600' },
  whPills: { flexDirection: 'row', alignItems: 'center', gap: 3 },
  whPill: {
    minWidth: 22,
    textAlign: 'center',
    fontSize: 10,
    fontWeight: '800',
    paddingHorizontal: 5,
    paddingVertical: 2,
    borderRadius: 5,
    overflow: 'hidden'
  },
  whPillDone: { color: '#059669', backgroundColor: '#ecfdf5' },
  whPillPend: { color: '#d97706', backgroundColor: '#fffbeb' },
  whPillOver: { color: '#dc2626', backgroundColor: '#fef2f2' },
  whDetailLineSm: { fontSize: 10, color: '#64748b', lineHeight: 14, marginTop: 3, paddingLeft: 12 },
  whLegendBar: {
    marginBottom: 2,
    fontSize: 9,
    color: '#94a3b8',
    fontWeight: '600'
  },
  linkText: { color: '#0284c7', fontWeight: '700', fontSize: 12 },
  recentRow: { flexDirection: 'row', alignItems: 'center', paddingVertical: 10, gap: 10 },
  recentBorder: { borderTopWidth: 1, borderTopColor: '#f1f5f9' },
  recentClient: { fontSize: 14, fontWeight: '700', color: '#0f172a' },
  recentMeta: { fontSize: 12, color: '#64748b', marginTop: 2 },
  recentTemp: { fontSize: 15, fontWeight: '800', color: '#003580' },
  filterPanel: {
    backgroundColor: '#fff',
    paddingHorizontal: 10,
    paddingTop: 8,
    paddingBottom: 6,
    borderBottomWidth: 1,
    borderBottomColor: '#e2e8f0'
  },
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
  filterRow: { flexDirection: 'row', gap: 6, marginBottom: 6 },
  filterChip: {
    flex: 1,
    minWidth: 0,
    backgroundColor: '#f8fafc',
    borderWidth: 1,
    borderColor: '#e2e8f0',
    borderRadius: 8,
    paddingHorizontal: 6,
    paddingVertical: 5
  },
  filterChipActive: { borderColor: '#93c5fd', backgroundColor: '#eff6ff' },
  filterChipLabel: { fontSize: 8, color: '#94a3b8', fontWeight: '700', letterSpacing: 0.2 },
  filterChipValue: { fontSize: 11, color: '#0f172a', fontWeight: '700', marginTop: 1 },
  suggestRow: { gap: 6, paddingBottom: 2 },
  suggestChip: {
    backgroundColor: '#f1f5f9',
    borderRadius: 999,
    paddingHorizontal: 10,
    paddingVertical: 4
  },
  suggestClear: { backgroundColor: '#fee2e2' },
  suggestText: { fontSize: 11, fontWeight: '700', color: '#334155' },
  listBody: { padding: 8, paddingBottom: 88 },
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
  logCard: {
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
  logClient: { fontSize: 12, fontWeight: '700', color: '#0f172a', flex: 1 },
  logMeta: { fontSize: 10, color: '#64748b', marginTop: 1 },
  logTemp: { fontSize: 13, fontWeight: '800', color: '#003580' },
  reportWhRow: { gap: 6, paddingVertical: 8 },
  reportWhChip: {
    paddingHorizontal: 10,
    paddingVertical: 5,
    borderRadius: 999,
    backgroundColor: '#f1f5f9',
    borderWidth: 1,
    borderColor: '#e2e8f0'
  },
  reportWhChipActive: { backgroundColor: '#003580', borderColor: '#003580' },
  reportWhChipText: { fontSize: 11, fontWeight: '700', color: '#64748b', maxWidth: 120 },
  reportWhChipTextActive: { color: '#fff' },
  invSummaryGrid: { flexDirection: 'row', gap: 6, marginTop: 2 },
  invSummaryCell: {
    flex: 1,
    backgroundColor: '#f8fafc',
    borderRadius: 8,
    paddingVertical: 6,
    alignItems: 'center',
    borderWidth: 1,
    borderColor: '#e2e8f0'
  },
  invSummaryValue: { fontSize: 13, fontWeight: '800', color: '#0f172a' },
  invSummaryLabel: { fontSize: 9, color: '#94a3b8', fontWeight: '700', marginTop: 1 },
  invCard: {
    backgroundColor: '#fff',
    borderRadius: 10,
    padding: 10,
    marginBottom: 8,
    borderWidth: 1,
    borderColor: '#e2e8f0'
  },
  invCardWarn: { borderColor: '#fecaca', backgroundColor: '#fffafa' },
  invCardTop: { flexDirection: 'row', alignItems: 'center', gap: 8, marginBottom: 8 },
  invClient: { fontSize: 13, fontWeight: '800', color: '#0f172a' },
  invMeta: { fontSize: 10, color: '#64748b', fontWeight: '600', marginTop: 1 },
  invDisc: { fontSize: 14, fontWeight: '800' },
  invMetrics: { flexDirection: 'row', gap: 5 },
  invMetric: {
    flex: 1,
    backgroundColor: '#f8fafc',
    borderRadius: 7,
    paddingVertical: 5,
    alignItems: 'center',
    borderWidth: 1,
    borderColor: '#e2e8f0'
  },
  invMetricValue: { fontSize: 12, fontWeight: '800', color: '#0f172a' },
  invMetricLabel: { fontSize: 9, color: '#94a3b8', fontWeight: '700', marginTop: 1 },
  invAudit: { marginTop: 6, fontSize: 10, color: '#94a3b8', fontWeight: '600' },
  reportSummaryRow: { flexDirection: 'row', gap: 10, marginTop: 12 },
  reportPill: {
    flex: 1,
    backgroundColor: '#eff6ff',
    borderRadius: 12,
    padding: 12
  },
  reportPillAlt: { backgroundColor: '#ecfdf5' },
  reportPillValue: { fontSize: 20, fontWeight: '800', color: '#0f172a' },
  reportPillLabel: { fontSize: 11, color: '#64748b', fontWeight: '600', marginTop: 2 },
  reportCard: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: '#fff',
    borderRadius: 12,
    padding: 12,
    marginBottom: 8,
    borderWidth: 1,
    borderColor: '#e2e8f0',
    gap: 10
  },
  deltaText: { fontSize: 16, fontWeight: '800', color: '#64748b' },
  profileRow: { marginTop: 8, fontSize: 13, lineHeight: 20 },
  profileKey: { color: '#64748b', fontWeight: '600' },
  profileVal: { color: '#0f172a', fontWeight: '700' },
  logoutBtn: {
    marginTop: 8,
    backgroundColor: '#dc2626',
    borderRadius: 12,
    paddingVertical: 14,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8
  },
  logoutText: { color: '#fff', fontWeight: '800', fontSize: 14 },
  aboutFooter: { marginTop: 28, alignItems: 'center', paddingHorizontal: 8 },
  aboutLogo: { width: 72, height: 40, marginBottom: 8, opacity: 0.7 },
  aboutTitle: { fontSize: 14, fontWeight: '800', color: '#64748b', marginBottom: 6 },
  aboutBody: { fontSize: 12, color: '#94a3b8', textAlign: 'center', lineHeight: 18 },
  aboutContact: { marginTop: 10, color: '#0284c7', fontWeight: '700', fontSize: 13 },
  centerState: { alignItems: 'center', justifyContent: 'center', padding: 28, gap: 8 },
  stateText: { color: '#64748b', textAlign: 'center', fontSize: 13, lineHeight: 19 },
  retryBtn: {
    marginTop: 8,
    backgroundColor: '#003580',
    paddingHorizontal: 16,
    paddingVertical: 10,
    borderRadius: 10
  },
  retryText: { color: '#fff', fontWeight: '700', fontSize: 13, textAlign: 'center' },
  tabBar: {
    position: 'absolute',
    left: 0,
    right: 0,
    bottom: 0,
    flexDirection: 'row',
    backgroundColor: '#fff',
    borderTopWidth: 1,
    borderTopColor: '#e2e8f0',
    paddingTop: 8,
    paddingBottom: 10
  },
  tabItem: { flex: 1, alignItems: 'center', gap: 2 },
  tabLabel: { fontSize: 10, color: '#94a3b8', fontWeight: '600' },
  tabLabelActive: { color: '#003580', fontWeight: '800' },
  sheetOverlay: {
    flex: 1,
    backgroundColor: 'rgba(15,23,42,0.45)',
    justifyContent: 'flex-end'
  },
  sheet: {
    backgroundColor: '#fff',
    borderTopLeftRadius: 18,
    borderTopRightRadius: 18,
    padding: 16,
    maxHeight: '60%'
  },
  sheetTitle: { fontSize: 15, fontWeight: '800', color: '#0f172a', marginBottom: 10 },
  sheetItem: { paddingVertical: 12, borderBottomWidth: 1, borderBottomColor: '#f1f5f9' },
  sheetItemText: { fontSize: 14, color: '#0f172a', fontWeight: '600' },
  calendarSheet: {
    backgroundColor: '#fff',
    marginHorizontal: 16,
    marginBottom: 40,
    borderRadius: 16,
    padding: 16
  },
  calendarHead: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    marginBottom: 12
  },
  calendarTitle: { fontSize: 15, fontWeight: '800', color: '#0f172a' },
  calendarGrid: { flexDirection: 'row', flexWrap: 'wrap' },
  calCell: {
    width: `${100 / 7}%`,
    aspectRatio: 1,
    alignItems: 'center',
    justifyContent: 'center'
  },
  calCellActive: { backgroundColor: '#003580', borderRadius: 999 },
  calCellText: { fontSize: 13, color: '#334155', fontWeight: '600' },
  calCellTextActive: { color: '#fff' },
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
  detailTitle: { fontSize: 16, fontWeight: '800', color: '#0f172a' },
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
  smallImgWrap: { marginBottom: 12 },
  smallImg: {
    width: 96,
    height: 96,
    borderRadius: 10,
    marginTop: 6,
    backgroundColor: '#f1f5f9',
    borderWidth: 1,
    borderColor: '#e2e8f0'
  },
  smallImgEmpty: {
    width: 96,
    height: 96,
    borderRadius: 10,
    marginTop: 6,
    backgroundColor: '#f8fafc',
    borderWidth: 1,
    borderColor: '#e2e8f0',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 4
  },
  smallImgEmptyText: { fontSize: 10, color: '#94a3b8', fontWeight: '600' },
  smallImgHint: { marginTop: 4, fontSize: 10, color: '#0284c7', fontWeight: '700' },
  imgViewerOverlay: {
    flex: 1,
    backgroundColor: 'rgba(0,0,0,0.92)',
    paddingTop: Platform.OS === 'android' ? StatusBar.currentHeight || 12 : 48
  },
  imgViewerTop: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    paddingHorizontal: 14,
    paddingBottom: 10
  },
  imgViewerBtn: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    backgroundColor: 'rgba(255,255,255,0.15)',
    paddingHorizontal: 12,
    paddingVertical: 8,
    borderRadius: 10
  },
  imgViewerDownload: { backgroundColor: '#003580' },
  imgViewerBtnText: { color: '#fff', fontWeight: '700', fontSize: 13 },
  imgViewerBody: {
    flexGrow: 1,
    alignItems: 'center',
    justifyContent: 'center',
    padding: 12
  },
  imgViewerImage: {
    width: '100%',
    height: 480,
    maxWidth: 520
  },
  detailRow: { marginBottom: 10 },
  detailLabel: { fontSize: 11, color: '#94a3b8', fontWeight: '700' },
  detailValue: { fontSize: 14, color: '#0f172a', fontWeight: '600', marginTop: 2 }
});
