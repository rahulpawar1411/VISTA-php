// ====================================================================
// ReeferON Mobile Entry (mobile/App.js)
// --------------------------------------------------------------------
// Roles after login:
//   do_operator → DashboardScreen (offline SQLite + sync)
//   customer    → CustomerScreen (scoped logs / inventory)
//   sub_admin   → SubAdminScreen (full mobile admin)
//
// API URL:
//   - Dev: getLocalApiUrl() from Metro LAN IP → http://IP:5000
//   - Prod: PRODUCTION_API_URL (Render)
//   - FALLBACK_LOCAL_IP: update if Wi‑Fi IPv4 changes (ipconfig)
// ====================================================================

import React, { useState, useEffect } from 'react';
import { StyleSheet, View, NativeModules, Platform, Linking } from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';
import Constants from 'expo-constants';
import LoginScreen from './src/screens/LoginScreen';
import DashboardScreen from './src/screens/DashboardScreen';
import CustomerScreen from './src/screens/CustomerScreen';
import SubAdminScreen from './src/screens/SubAdminScreen';
import SplashScreen from './src/screens/SplashScreen';

/** Minimum splash time so the landing screen doesn’t flash away. */
const SPLASH_MIN_MS = 4000;

/** Production backend base URL (no trailing slash, no /api path). */
export const PRODUCTION_API_URL = 'https://reeferon-crm-backend.onrender.com';

/** Used only when Metro host IP cannot be detected — keep in sync with PC Wi‑Fi IPv4. */
const FALLBACK_LOCAL_IP = '192.168.161.129';

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
 * Local backend URL for Expo Go / emulator on the same Wi‑Fi.
 * @returns {string} e.g. http://192.168.x.x:5000
 */
export function getLocalApiUrl() {
  try {
    const scriptURL = NativeModules.SourceCode?.scriptURL || '';
    const fromScript = extractLanIp(scriptURL);
    if (fromScript) return `http://${fromScript}:5000`;

    const address = scriptURL.split('://')[1] || '';
    const host = (address.split('/')[0] || '').split(':')[0];
    if (host === 'localhost' || host === '127.0.0.1' || host === '10.0.2.2' || host === '::1') {
      return Platform.OS === 'android' ? 'http://10.0.2.2:5000' : 'http://localhost:5000';
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
    if (fromExpo) return `http://${fromExpo}:5000`;
  } catch (e) {
    console.warn('Failed to detect local API host from Expo Constants:', e);
  }

  return `http://${FALLBACK_LOCAL_IP}:5000`;
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
    (/:\s*5000\/?$/.test(u) ||
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
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const restoreSession = async () => {
      const startedAt = Date.now();
      try {
        const storedToken = await AsyncStorage.getItem('user_token');
        const storedUser = await AsyncStorage.getItem('user_profile');
        const storedApiUrl = await AsyncStorage.getItem('api_url');
        if (storedToken && storedUser) {
          const parsed = JSON.parse(storedUser);
          const role = parsed?.role;
          // Mobile roles only — keep each role as-is (do not remap sub_admin → customer)
          if (role === 'do_operator' || role === 'customer' || role === 'sub_admin') {
            setToken(storedToken);
            setUser(parsed);
          } else {
            await AsyncStorage.multiRemove(['user_token', 'user_profile']);
          }
        }
        // Restore last chosen server (production or local)
        if (storedApiUrl && storedApiUrl.trim()) {
          let nextApi = storedApiUrl.replace(/\/$/, '');
          // LAN IP changes often — refresh local URL from Metro host
          if (isLocalApiUrl(nextApi)) {
            nextApi = getLocalApiUrl();
            await AsyncStorage.setItem('api_url', nextApi);
            console.log('[api] refreshed local server URL →', nextApi);
          }
          setApiUrl(nextApi);
        } else {
          setApiUrl(PRODUCTION_API_URL);
          await AsyncStorage.setItem('api_url', PRODUCTION_API_URL);
        }
      } catch (err) {
        console.warn('Failed to restore session:', err);
      } finally {
        const elapsed = Date.now() - startedAt;
        const waitMore = Math.max(0, SPLASH_MIN_MS - elapsed);
        if (waitMore > 0) {
          await new Promise((resolve) => setTimeout(resolve, waitMore));
        }
        setLoading(false);
      }
    };
    restoreSession();
  }, []);

  useEffect(() => {
    const handleUrl = (url) => {
      if (!url) return;
      console.log('[deep-link]', url);
    };

    Linking.getInitialURL().then(handleUrl).catch(() => {});
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
      const clean = String(newUrl || '').trim().replace(/\/$/, '');
      setApiUrl(clean);
      await AsyncStorage.setItem('api_url', clean);
    } catch (err) {
      console.warn('Failed to save API URL:', err);
    }
  };

  const handleLogout = async () => {
    setUser(null);
    setToken(null);
    try {
      await AsyncStorage.removeItem('user_token');
      await AsyncStorage.removeItem('user_profile');
    } catch (err) {
      console.warn('Failed to clear session cache:', err);
    }
  };

  if (loading) {
    return <SplashScreen />;
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
