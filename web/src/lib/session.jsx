import { createContext, useContext, useEffect, useState } from 'react';
import { Navigate, useLocation } from 'react-router-dom';
import { get, post, setUnauthorizedHandler } from './api.js';
import { useI18n } from './i18n.jsx';
import { Loading } from '../components/ui.jsx';

const Ctx = createContext(null);

export function StaffProvider({ children, platform = false }) {
  const [me, setMe] = useState(undefined);
  const loc = useLocation();
  const { setLang } = useI18n();
  const load = async () => {
    try {
      const d = await get('/auth/me');
      setMe(d);
      try { if (!localStorage.getItem('lang')) setLang(d.user.language || d.gym?.default_language || 'en'); } catch { /* ignore */ }
    } catch { setMe(null); }
  };
  useEffect(() => { load(); setUnauthorizedHandler(() => setMe(null)); }, []);
  if (me === undefined) return <Loading />;
  if (me === null) return <Navigate to={'/login?next=' + encodeURIComponent(loc.pathname + loc.search)} replace />;
  if (platform && me.user.role !== 'platform_admin') return <Navigate to="/admin" replace />;
  if (!platform && me.user.role === 'platform_admin') return <Navigate to="/platform" replace />;
  const can = (perm) => me.permissions.includes('*') ? perm !== 'platform' : me.permissions.includes(perm);
  const logout = async () => { await post('/auth/logout').catch(() => {}); window.location.href = '/login'; };
  return <Ctx.Provider value={{ ...me, can, logout, reload: load }}>{children}</Ctx.Provider>;
}

export const useStaff = () => useContext(Ctx);

export function Can({ perm, children, fallback = null }) {
  const s = useStaff();
  return s?.can(perm) ? children : fallback;
}

/** Push notifications (owners): subscribe this browser so alerts arrive even when the app is closed. */
export async function enablePush(vapidPublic, endpoint = '/push/subscribe') {
  if (!('serviceWorker' in navigator) || !('PushManager' in window) || !vapidPublic) throw new Error('Push notifications are not available on this device.');
  const perm = await Notification.requestPermission();
  if (perm !== 'granted') throw new Error('Notifications were blocked in the browser.');
  const reg = await navigator.serviceWorker.ready;
  const key = Uint8Array.from(atob(vapidPublic.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((vapidPublic.length + 3) % 4)), (c) => c.charCodeAt(0));
  const sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key });
  await post(endpoint, { subscription: sub.toJSON() });
}
