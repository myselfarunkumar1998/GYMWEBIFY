import { createContext, lazy, Suspense, useContext, useEffect, useState } from 'react';
import { NavLink, Route, Routes, useParams } from 'react-router-dom';
import { get, post, setUnauthorizedHandler } from '../lib/api.js';
import { useI18n } from '../lib/i18n.jsx';
import { Icon, Loading } from '../components/ui.jsx';
import MemberLogin from './MemberLogin.jsx';

const Home = lazy(() => import('./Home.jsx'));
const Card = lazy(() => import('./Card.jsx'));
const Visits = lazy(() => import('./Visits.jsx'));
const Book = lazy(() => import('./Book.jsx'));
const Workout = lazy(() => import('./Workout.jsx'));
const Diet = lazy(() => import('./Diet.jsx'));
const Progress = lazy(() => import('./Progress.jsx'));
const Challenges = lazy(() => import('./Challenges.jsx'));
const Store = lazy(() => import('./Store.jsx'));
const Renew = lazy(() => import('./Renew.jsx'));
const Profile = lazy(() => import('./Profile.jsx'));

const Ctx = createContext(null);
export const useMember = () => useContext(Ctx);

export default function MemberApp() {
  const { slug } = useParams();
  const { setLang } = useI18n();
  const [me, setMe] = useState(undefined);
  const load = async () => {
    try {
      const d = await get('/m/me');
      if (d.gym.slug !== slug) { setMe(null); return; }
      setMe(d);
      setLang(d.member.language);
    } catch { setMe(null); }
  };
  useEffect(() => {
    load();
    setUnauthorizedHandler(() => setMe(null));
    const link = document.getElementById('manifest-link');
    if (link) link.href = `/manifest-member.webmanifest`;
    document.title = 'Member app';
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [slug]);
  if (me === undefined) return <Loading />;
  if (me === null) return <MemberLogin slug={slug} onLogin={load} />;
  const base = `/m/${slug}`;
  return (
    <Ctx.Provider value={{ ...me, base, reload: load, logout: async () => { await post('/m/auth/logout').catch(() => {}); setMe(null); } }}>
      <div className="phone">
        <Suspense fallback={<Loading />}>
          <Routes>
            <Route index element={<Home />} />
            <Route path="card" element={<Card />} />
            <Route path="visits" element={<Visits />} />
            <Route path="book" element={<Book />} />
            <Route path="workout" element={<Workout />} />
            <Route path="diet" element={<Diet />} />
            <Route path="progress" element={<Progress />} />
            <Route path="challenges" element={<Challenges />} />
            <Route path="store" element={<Store />} />
            <Route path="renew" element={<Renew />} />
            <Route path="pay" element={<Renew />} />
            <Route path="profile" element={<Profile />} />
            <Route path="*" element={<Home />} />
          </Routes>
        </Suspense>
        <TabBar base={base} />
      </div>
    </Ctx.Provider>
  );
}

function TabBar({ base }) {
  const { t } = useI18n();
  const tabs = [['', 'Home', 'home'], ['book', 'Book', 'calendar'], ['workout', 'Workouts', 'dumbbell'], ['renew', 'Pay', 'card'], ['profile', 'Profile', 'user']];
  return (
    <nav className="tabbar" aria-label="Member app">
      {tabs.map(([p, l, i]) => <NavLink key={p} end={p === ''} to={base + (p ? '/' + p : '')} className={({ isActive }) => (isActive ? 'active' : '')}><Icon name={i} />{t(l)}</NavLink>)}
    </nav>
  );
}

export function BackHeader({ title, sub, to, right }) {
  const m = useMember();
  return (
    <header className="row gap16">
      <NavLink to={to || m.base} aria-label="Back" className="iconbtn" style={{ textDecoration: 'none' }}><Icon name="back" /></NavLink>
      <div className="grow col" style={{ gap: 2 }}><h1 style={{ fontSize: 22 }}>{title}</h1>{sub && <span className="small muted">{sub}</span>}</div>
      {right}
    </header>
  );
}

/** Razorpay Checkout, or the demo flow when the gym has no keys in demo mode. */
export async function payOrder(res, onPaid) {
  const { order, invoice, member } = res;
  if (!order) { onPaid(); return; }
  if (order.demo) {
    if (!window.confirm(`Demo payment: pay ₹${order.amount} by UPI?`)) return;
    await post('/m/pay/verify', { invoice_id: invoice.id, demo: true });
    onPaid();
    return;
  }
  await new Promise((resolve, reject) => {
    if (window.Razorpay) return resolve();
    const sc = document.createElement('script');
    sc.src = 'https://checkout.razorpay.com/v1/checkout.js';
    sc.onload = resolve; sc.onerror = () => reject(new Error('Could not load the payment page. Check your internet.'));
    document.body.appendChild(sc);
  });
  await new Promise((resolve, reject) => {
    const rz = new window.Razorpay({
      key: order.key_id, amount: order.amount, currency: 'INR', order_id: order.order_id, name: document.title,
      description: invoice.invoice_no, prefill: { name: member?.name, contact: member?.phone ? '+91' + member.phone : undefined, email: member?.email || undefined },
      theme: { color: '#C8F031' },
      handler: async (r) => {
        try { await post('/m/pay/verify', { invoice_id: invoice.id, razorpay_order_id: r.razorpay_order_id, razorpay_payment_id: r.razorpay_payment_id, razorpay_signature: r.razorpay_signature }); onPaid(); resolve(); }
        catch (e) { reject(e); }
      },
      modal: { ondismiss: () => resolve() },
    });
    rz.on('payment.failed', (e) => reject(new Error(e.error?.description || 'Payment failed')));
    rz.open();
  });
}
