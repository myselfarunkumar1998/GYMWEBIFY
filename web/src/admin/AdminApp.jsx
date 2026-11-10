import { lazy, Suspense, useEffect, useState } from 'react';
import { NavLink, Route, Routes, useLocation, Navigate } from 'react-router-dom';
import { useStaff } from '../lib/session.jsx';
import { useI18n, LangToggle } from '../lib/i18n.jsx';
import { Icon, Loading, useApi, usePoll } from '../components/ui.jsx';

const Dashboard = lazy(() => import('./Dashboard.jsx'));
const MembersList = lazy(() => import('./MembersList.jsx'));
const MemberProfile = lazy(() => import('./MemberProfile.jsx'));
const AddMember = lazy(() => import('./AddMember.jsx'));
const Plans = lazy(() => import('./Plans.jsx'));
const Sessions = lazy(() => import('./Sessions.jsx'));
const PunchScreen = lazy(() => import('./PunchScreen.jsx'));
const Billing = lazy(() => import('./Billing.jsx'));
const Leads = lazy(() => import('./Leads.jsx'));
const Classes = lazy(() => import('./Classes.jsx'));
const WorkoutBuilder = lazy(() => import('./WorkoutBuilder.jsx'));
const Staff = lazy(() => import('./Staff.jsx'));
const Store = lazy(() => import('./Store.jsx'));
const Campaigns = lazy(() => import('./Campaigns.jsx'));
const Reports = lazy(() => import('./Reports.jsx'));
const Branches = lazy(() => import('./Branches.jsx'));
const Insights = lazy(() => import('./Insights.jsx'));
const Devices = lazy(() => import('./Devices.jsx'));
const Settings = lazy(() => import('./Settings.jsx'));

const NAV = [
  ['', 'Dashboard', 'dashboard.view', 'grid'],
  ['members', 'Members', 'members.view', 'users'],
  ['sessions', 'Check-ins & sessions', 'sessions.view', 'clock'],
  ['billing', 'Billing & GST', 'billing.view', 'card'],
  ['leads', 'Leads & CRM', 'leads.view', 'phone'],
  ['classes', 'Classes & PT', 'classes.view', 'calendar'],
  ['staff', 'Staff & payroll', 'staff.view', 'user'],
  ['store', 'Store & inventory', 'store.view', 'store'],
  ['campaigns', 'WhatsApp & campaigns', 'campaigns.view', 'msg'],
  ['insights', 'AI insights', 'insights.view', 'spark'],
  ['reports', 'Reports', 'reports.view', 'chart'],
  ['devices', 'Devices & access', 'devices.manage', 'door'],
  ['settings', 'Settings', 'settings.view', 'gear'],
];

export default function AdminApp() {
  const s = useStaff();
  const { t } = useI18n();
  const loc = useLocation();
  const [open, setOpen] = useState(false);
  const notes = useApi('/notifications');
  usePoll(() => notes.reload(true), 60000);
  useEffect(() => setOpen(false), [loc.pathname]);
  const kiosk = loc.pathname.startsWith('/admin/punch');
  const branchName = s.user.branch_id ? s.branches.find((b) => b.id === s.user.branch_id)?.name : (s.branches.length > 1 ? 'All branches' : s.branches[0]?.name);

  if (kiosk) return <Suspense fallback={<Loading />}><PunchScreen /></Suspense>;

  return (
    <div className="shell">
      <nav aria-label="Main" className={'sidebar' + (open ? ' open' : '')}>
        <div className="brand">
          <div className="logo">{s.gym.logo_url ? <img src={s.gym.logo_url} alt="" /> : s.gym.name[0]}</div>
          <div className="col gap4" style={{ gap: 0 }}>
            <span style={{ fontFamily: 'var(--display)', fontWeight: 600, fontSize: 16 }}>{s.gym.name}</span>
            <span className="small muted">{branchName}</span>
          </div>
          <button className="iconbtn menu-btn" style={{ marginLeft: 'auto' }} aria-label="Close menu" onClick={() => setOpen(false)}><Icon name="x" /></button>
        </div>
        {NAV.filter(([, , perm]) => s.can(perm)).map(([path, label, , icon]) => (
          <NavLink key={path} end={path === ''} to={'/admin' + (path ? '/' + path : '')} className={({ isActive }) => 'navlink' + (isActive ? ' active' : '')}>
            <span className="row gap8"><Icon name={icon} size={18} />{t(label)}</span>
            {path === '' && notes.data?.unread > 0 && <span className="pill warn">{notes.data.unread}</span>}
          </NavLink>
        ))}
        <div className="navsec">{t('More')}</div>
        {s.can('sessions.manage') && <NavLink to="/admin/punch" className="navlink"><span className="row gap8"><Icon name="qr" size={18} />{t('Front desk screen')}</span></NavLink>}
        <NavLink to="/owner" className="navlink"><span className="row gap8"><Icon name="phone" size={18} />{t('Owner app')}</span></NavLink>
        <div style={{ marginTop: 'auto', paddingTop: 20 }} className="col gap8">
          <LangToggle compact />
          <div className="small muted" style={{ padding: '0 4px' }}>{s.user.name} · {s.user.role.replace('_', ' ')}</div>
          <button className="btn sm" onClick={s.logout}><Icon name="logout" size={16} />{t('Sign out')}</button>
        </div>
      </nav>
      <main className="content" id="main">
        <div className="row menu-btn" style={{ justifyContent: 'space-between' }}>
          <button className="iconbtn" aria-label="Open menu" onClick={() => setOpen(true)}><Icon name="menu" /></button>
          <span style={{ fontFamily: 'var(--display)', fontWeight: 600 }}>{s.gym.name}</span>
        </div>
        {s.gym.status === 'suspended' && <div className="banner">This account is suspended.</div>}
        <Suspense fallback={<Loading />}>
          <Routes>
            <Route index element={<Dashboard notes={notes} />} />
            <Route path="members" element={<MembersList />} />
            <Route path="members/new" element={<AddMember />} />
            <Route path="members/:id" element={<MemberProfile />} />
            <Route path="members/:id/plans" element={<WorkoutBuilder />} />
            <Route path="plans" element={<Plans />} />
            <Route path="sessions" element={<Sessions />} />
            <Route path="billing" element={<Billing />} />
            <Route path="leads" element={<Leads />} />
            <Route path="classes" element={<Classes />} />
            <Route path="staff" element={<Staff />} />
            <Route path="store" element={<Store />} />
            <Route path="campaigns" element={<Campaigns />} />
            <Route path="insights" element={<Insights />} />
            <Route path="reports" element={<Reports />} />
            <Route path="branches" element={<Branches />} />
            <Route path="devices" element={<Devices />} />
            <Route path="settings" element={<Settings />} />
            <Route path="*" element={<Navigate to="/admin" replace />} />
          </Routes>
        </Suspense>
      </main>
    </div>
  );
}
