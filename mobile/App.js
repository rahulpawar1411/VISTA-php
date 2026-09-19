// ====================================================================
// ReeferON Mobile Entry (mobile/App.js)
// --------------------------------------------------------------------
// After login, ONE screen per role (do not mix their jobs):
//   do_operator â†’ DashboardScreen  â€” field logs + offline SQLite sync
//   customer    â†’ CustomerScreen   â€” read-only allowed WH/clients
//   sub_admin   â†’ SubAdminScreen   â€” overview, permissions, DO masters
//
// API URL:
//   - Local: getLocalApiUrl() â†’ http://LAN-IP:5080 (PHP backend)
//   - Live:  PRODUCTION_API_URL (Hostinger PHP API)
//   - FALLBACK_LOCAL_IP: update if Wiâ€‘Fi IPv4 changes (ipconfig)
// ====================================================================

import React, { useState, useEffect, useCallback } from 'react';
import { StyleSheet, View, NativeModules, Platform, Linking } from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';
import Constants from 'expo-constants';
import LoginScreen from './src/screens/LoginScreen';
import DashboardScreen from './src/screens/DashboardScreen';
import CustomerScreen from './src/screens/CustomerScreen';
import SubAdminScreen from './src/screens/SubAdminScreen';
import SplashScreen from './src/screens/SplashScreen';
import { clearSyncedInspectionsLocally, initDatabase } from './src/database/db';

/** Live backend (Hostinger PHP). No trailing slash, no /api (app adds /api/...). */
const DEFAULT_PRODUCTION_API_URL =
  'https://darkcyan-octopus-294935.hostingersite.com/backend/public';

function readProductionApiUrl() {
  const raw =
    process.env.EXPO_PUBLIC_API_URL ||
    Constants.expoConfig?.extra?.apiUrl ||
    DEFAULT_PRODUCTION_API_URL;
  return String(raw)
    .trim()
    .replace(/\/$/, '')
    .replace(/\/api$/i, '');
}

export const PRODUCTION_API_URL = readProductionApiUrl();

/** Used only when Metro host IP cannot be detected â€” keep in sync with PC Wiâ€‘Fi IPv4. */
const FALLBACK_LOCAL_IP = '192.168.64.129';

/** Pull first usable LAN IPv4 from a host string / URL. */
function extractLanIp(raw) {
  if (!raw || typeof raw !== 'string') return null;
  const text = raw.trim();
  // Prefer first IPv4 in the string (handles host:port, URLs, debuggerHost)
  const m = text.match(/(\d{1,3}(?:\.\d{1,3}){3})/);
  if (!m) return null;
  const ip = m[1];
  if (ip === '127.0.0.1' || ip === '0.0.0.0' || ip === '10.0.2.2') return null;
  return ip;
}

/**
 * Local backend URL for Expo Go / emulator on the same Wiâ€‘Fi.
 * @returns {string} e.g. http://192.168.x.x:5080
 */
export function getLocalApiUrl() {
  try {
    const scriptURL = NativeModules.SourceCode?.scriptURL || '';
    const fromScript = extractLanIp(scriptURL);
    if (fromScript) return `http://${fromScript}:5080`;

    const address = scriptURL.split('://')[1] || '';
    const host = (address.split('/')[0] || '').split(':')[0];
    if (host === 'localhost' || host === '127.0.0.1' || host === '10.0.2.2' || host === '::1') {
      return Platform.OS === 'android' ? 'http://10.0.2.2:5080' : 'http://localhost:5080';
    }
  } catch (e) {
    console.warn('Failed to detect local API host from SourceCode:', e);
  }

  try {
    const hostUri =
      Constants.expoConfig?.hostUri ||
      Constants.manifest2?.extra?.expoGo?.debuggerHost ||
      Constants.manifest?.debuggerHost ||
      Constants.linkingUri ||
      '';
    const fromExpo = extractLanIp(String(hostUri));
    if (fromExpo) return `http://${fromExpo}:5080`;
  } catch (e) {
    console.warn('Failed to detect local API host from Expo Constants:', e);
  }

  return `http://${FALLBACK_LOCAL_IP}:5080`;
}

export function isProductionApiUrl(url) {
  if (!url || typeof url !== 'string') return false;
  const u = url.trim().toLowerCase();
  return u.includes('onrender.com') || u.startsWith('https://');
}

/** True when URL points at a local/LAN backend (IP changes often). */
export function isLocalApiUrl(url) {
  if (!url || typeof url !== 'string') return false;
  const u = url.trim().toLowerCase();
  if (u.includes('onrender.com') || u.startsWith('https://')) return false;
  return (
    u.startsWith('http://') &&
    (/:\s*5080\/?$/.test(u) ||
      /:\s*5000\/?$/.test(u) ||
      u.includes('localhost') ||
      u.includes('127.0.0.1') ||
      u.includes('10.0.2.2') ||
      /^\s*http:\/\/\d+\.\d+\.\d+\.\d+/.test(u))
  );
}

export default function App() {
  const [apiUrl, setApiUrl] = useState(PRODUCTION_API_URL);
  const [user, setUser] = useState(null);
  const [token, setToken] = useState(null);
  const [sessionReady, setSessionReady] = useState(false);
  const [splashDone, setSplashDone] = useState(false);

  const handleSplashComplete = useCallback(() => {
    setSplashDone(true);
  }, []);

  useEffect(() => {
    const restoreSession = async () => {
      let resolvedApiUrl = PRODUCTION_API_URL;
      try {
        const storedToken = await AsyncStorage.getItem('user_token');
        const storedUser = await AsyncStorage.getItem('user_profile');
        const storedApiUrl = await AsyncStorage.getItem('api_url');

        if (storedApiUrl && storedApiUrl.trim()) {
          let nextApi = storedApiUrl.replace(/\/$/, '').replace(/\/api$/i, '');
          // Migrate old Node hosts â†’ current Hostinger PHP API
          if (/railway\.app|onrender\.com/i.test(nextApi)) {
            nextApi = PRODUCTION_API_URL;
            console.log('[api] migrated legacy URL â†’ Hostinger PHP');
          }
          if (isLocalApiUrl(nextApi)) {
            nextApi = getLocalApiUrl();
            await AsyncStorage.setItem('api_url', nextApi);
            console.log('[api] refreshed local server URL â†’', nextApi);
          }
          resolvedApiUrl = nextApi;
          setApiUrl(nextApi);
          await AsyncStorage.setItem('api_url', nextApi);
        } else {
          setApiUrl(PRODUCTION_API_URL);
          await AsyncStorage.setItem('api_url', PRODUCTION_API_URL);
        }

        if (storedToken && storedUser) {
          const parsed = JSON.parse(storedUser);
          const role = parsed?.role;
          const isMobileRole =
            role === 'do_operator' || role === 'customer' || role === 'sub_admin';

          if (!isMobileRole) {
            await AsyncStorage.multiRemove(['user_token', 'user_profile']);
          } else {
            let nextUser = parsed;
            let keepSession = true;
            try {
              const meRes = await fetch(`${resolvedApiUrl}/api/auth/me`, {
                headers: {
                  Authorization: `Bearer ${storedToken}`,
                  Accept: 'application/json'
                }
              });
              if (meRes.status === 401 || meRes.status === 403) {
                keepSession = false;
                await AsyncStorage.multiRemove(['user_token', 'user_profile']);
                console.warn('[auth] Stored session expired or revoked â€” login required.');
              } else if (meRes.ok) {
                const meData = await meRes.json().catch(() => ({}));
                if (meData?.user) {
                  nextUser = {
                    ...parsed,
                    ...meData.user,
                    role: meData.user.role || parsed.role
                  };
                  await AsyncStorage.setItem('user_profile', JSON.stringify(nextUser));
                }
              }
            } catch (networkErr) {
              console.warn('[auth] Session check skipped (offline):', networkErr.message);
            }

            if (keepSession) {
              setToken(storedToken);
              setUser(nextUser);
            }
          }
        }
      } catch (err) {
        console.warn('Failed to restore session:', err);
      } finally {
        // Session ready â€” app still waits until splash animation finishes
        setSessionReady(true);
      }
    };
    restoreSession();
  }, []);

  useEffect(() => {
    const handleUrl = (url) => {
      if (!url) return;
      console.log('[deep-link]', url);
    };

    Linking.getInitialURL().then(handleUrl).catch(() => { });
    const sub = Linking.addEventListener('url', ({ url }) => handleUrl(url));
    return () => sub.remove();
  }, []);

  const handleLoginSuccess = async (sessionData) => {
    const nextUser = sessionData?.user || null;
    const nextToken = sessionData?.token || null;
    const role = nextUser?.role;
    if (role !== 'do_operator' && role !== 'customer' && role !== 'sub_admin') {
      console.warn('Blocked non-mobile role from session:', role);
      return;
    }
    setUser(nextUser);
    setToken(nextToken);
    try {
      await AsyncStorage.setItem('user_token', nextToken);
      await AsyncStorage.setItem('user_profile', JSON.stringify(nextUser));
    } catch (err) {
      console.warn('Failed to cache session data:', err);
    }
  };

  const handleUpdateApiUrl = async (newUrl) => {
    try {
      const clean = String(newUrl || '')
        .trim()
        .replace(/\/$/, '')
        .replace(/\/api$/i, '');
      const next = /railway\.app|onrender\.com/i.test(clean) ? PRODUCTION_API_URL : clean;
      setApiUrl(next);
      await AsyncStorage.setItem('api_url', next);
    } catch (err) {
      console.warn('Failed to save API URL:', err);
    }
  };

  const handleLogout = async () => {
    // Clear in-memory session first so UI returns to login immediately
    setUser(null);
    setToken(null);
    try {
      await AsyncStorage.multiRemove([
        'user_token',
        'user_profile',
        'active_mobile_nav_tab',
        'active_mobile_nav_section'
      ]);
    } catch (err) {
      console.warn('Failed to clear session cache:', err);
    }
    try {
      initDatabase();
      clearSyncedInspectionsLocally();
    } catch (err) {
      console.warn('Failed to clear synced SQLite inspections:', err);
    }
  };

  if (!sessionReady || !splashDone) {
    return (
      <SplashScreen
        onAnimationComplete={handleSplashComplete}
        waitingForSession={!sessionReady}
      />
    );
  }

  const role = user?.role;

  return (
    <View style={styles.container}>
      {!user || !token ? (
        <LoginScreen
          onLoginSuccess={handleLoginSuccess}
          apiUrl={apiUrl}
          onUpdateApiUrl={handleUpdateApiUrl}
          productionApiUrl={PRODUCTION_API_URL}
          localApiUrl={getLocalApiUrl()}
        />
      ) : role === 'do_operator' ? (
        <DashboardScreen
          user={user}
          token={token}
          apiUrl={apiUrl}
          onLogout={handleLogout}
          onUserUpdate={setUser}
        />
      ) : role === 'customer' ? (
        <CustomerScreen
          user={user}
          token={token}
          apiUrl={apiUrl}
          onLogout={handleLogout}
          onUserUpdate={async (nextUser) => {
            setUser(nextUser);
            try {
              await AsyncStorage.setItem('user_profile', JSON.stringify(nextUser));
            } catch (err) {
              console.warn('Failed to cache updated profile:', err);
            }
          }}
        />
      ) : role === 'sub_admin' ? (
        <SubAdminScreen
          user={user}
          token={token}
          apiUrl={apiUrl}
          onLogout={handleLogout}
        />
      ) : (
        <LoginScreen
          onLoginSuccess={handleLoginSuccess}
          apiUrl={apiUrl}
          onUpdateApiUrl={handleUpdateApiUrl}
          productionApiUrl={PRODUCTION_API_URL}
          localApiUrl={getLocalApiUrl()}
        />
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: '#ffffff',
  },
});
