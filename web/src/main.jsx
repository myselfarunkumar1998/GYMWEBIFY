import { lazy, Suspense } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { I18nProvider } from './lib/i18n.jsx';
import { Loading, ToastProvider } from './components/ui.jsx';
import { StaffProvider } from './lib/session.jsx';
import './styles.css';

const Login = lazy(() => import('./public/Login.jsx'));
const AdminApp = lazy(() => import('./admin/AdminApp.jsx'));
const OwnerApp = lazy(() => import('./owner/OwnerApp.jsx'));
const MemberApp = lazy(() => import('./member/MemberApp.jsx'));
const PublicSite = lazy(() => import('./public/PublicSite.jsx'));
const DemoPay = lazy(() => import('./public/DemoPay.jsx'));
const Platform = lazy(() => import('./public/Platform.jsx'));

function App() {
  return (
    <BrowserRouter>
      <Suspense fallback={<Loading />}>
        <Routes>
          <Route path="/" element={<Navigate to="/admin" replace />} />
          <Route path="/login" element={<Login mode="login" />} />
          <Route path="/forgot" element={<Login mode="forgot" />} />
          <Route path="/reset-password" element={<Login mode="reset" />} />
          <Route path="/admin/*" element={<StaffProvider><AdminApp /></StaffProvider>} />
          <Route path="/owner/*" element={<StaffProvider><OwnerApp /></StaffProvider>} />
          <Route path="/platform" element={<StaffProvider platform><Platform /></StaffProvider>} />
          <Route path="/m/:slug/*" element={<MemberApp />} />
          <Route path="/g/:slug" element={<PublicSite />} />
          <Route path="/pay/demo/:id" element={<DemoPay />} />
          <Route path="*" element={<div className="content"><h1>Page not found</h1><a href="/admin">Go to dashboard</a></div>} />
        </Routes>
      </Suspense>
    </BrowserRouter>
  );
}

createRoot(document.getElementById('root')).render(
  <I18nProvider><ToastProvider><App /></ToastProvider></I18nProvider>,
);

if ('serviceWorker' in navigator && import.meta.env.PROD) {
  window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
}
