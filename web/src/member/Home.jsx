import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useI18n } from '../lib/i18n.jsx';
import { money, hm, dayDate, DOW, parseDate } from '../lib/format.js';
import { ErrorBox, Icon, Loading, Progress, useApi, usePoll } from '../components/ui.jsx';
import { useMember } from './MemberApp.jsx';

function Timer({ since }) {
  const [now, setNow] = useState(Date.now());
  useEffect(() => { const id = setInterval(() => setNow(Date.now()), 1000); return () => clearInterval(id); }, []);
  const s = Math.max(0, Math.floor((now - parseDate(since).getTime()) / 1000));
  const p = (n) => String(n).padStart(2, '0');
  return <span className="num" style={{ fontSize: 56, fontWeight: 700, lineHeight: 1 }}>{Math.floor(s / 3600)}:{p(Math.floor(s / 60) % 60)}:{p(s % 60)}</span>;
}

export default function Home() {
  const m = useMember();
  const { t } = useI18n();
  const { data: d, error, loading, reload } = useApi('/m/home');
  usePoll(() => reload(true), 30000);
  if (loading && !d) return <Loading />;
  if (error) return <div className="phone-body"><ErrorBox error={error} onRetry={reload} /></div>;
  const max = Math.max(90, ...d.week.map((w) => w.minutes));
  const total = d.week.reduce((a, w) => a + w.minutes, 0);
  return (
    <div className="phone-body">
      <header className="row between">
        <div className="col" style={{ gap: 2 }}><span className="small muted">{t('Vanakkam,')}</span><h1 style={{ fontSize: 24 }}>{m.member.name.split(' ')[0]}</h1></div>
        <div className="row gap8">
          <Link to={m.base + '/profile#notifications'} className="iconbtn" aria-label={`Notifications${d.unread ? `, ${d.unread} new` : ''}`}><Icon name="bell" />{d.unread > 0 && <span className="badge-dot" />}</Link>
          <Link to={m.base + '/card'} className="btn" style={{ borderRadius: 14 }}><Icon name="qr" size={18} />{t('My QR')}</Link>
        </div>
      </header>
      {!m.member.waiver_signed && <Link to={m.base + '/profile#waiver'} className="banner" style={{ textDecoration: 'none' }}>{t('Sign the waiver')} →</Link>}
      {d.dues > 0 && <Link to={m.base + '/pay'} className="banner" style={{ textDecoration: 'none' }}>{t('Dues to pay')}: {money(d.dues)} · {t('Pay now')} →</Link>}
      {d.session ? (
        <section className="card hero" style={{ borderRadius: 24 }} aria-label="Current session">
          <div className="row gap8" style={{ fontWeight: 600, fontSize: 13 }}><span className="dot" style={{ background: 'var(--bg)' }} />{t("You're in the gym")} · {t('since')} {hm(d.session.in_at.slice(11, 16))}</div>
          <Timer since={d.session.in_at} />
          <span className="small">{t('Punch out at the scanner when you leave. Your time is saved to your history.')}</span>
        </section>
      ) : d.plan && d.plan.status !== 'active' && d.plan.status !== 'upcoming' ? (
        <section className="card alert"><b>{d.plan.status === 'frozen' ? 'Your plan is frozen' : 'Your plan has ended'}</b><Link to={m.base + '/renew'} className="btn primary">{t('Renew')}</Link></section>
      ) : null}
      <section className="card" aria-labelledby="week-title" style={{ borderRadius: 20, padding: 16 }}>
        <div className="row between"><h2 id="week-title" style={{ fontSize: 16 }}>{t('This week')}</h2><Link to={m.base + '/visits'} className="small muted" style={{ textDecoration: 'none' }}>{d.week_visits} {t('visits')} · {Math.floor(total / 60)}h {total % 60}m</Link></div>
        <div className="bars" style={{ height: 96, gap: 10 }} role="img" aria-label="Minutes trained per day this week">
          {d.week.map((w) => <div className="bar" key={w.date}><div className={w.minutes ? 'hi' : ''} style={{ height: w.minutes ? Math.round((w.minutes / max) * 72) : 4 }} /><span>{DOW[parseDate(w.date + ' 12:00').getDay()][0]}</span></div>)}
        </div>
      </section>
      {d.plan && (
        <section className="card" style={{ borderRadius: 20, padding: 16 }} aria-label="Membership">
          <div className="row between">
            <div className="col" style={{ gap: 2 }}><span className="small muted">{d.plan.name}</span><span className="num" style={{ fontSize: 18, fontWeight: 600 }}>{d.plan.status === 'upcoming' ? `${t('Upcoming')} · ${dayDate(d.plan.start_date)}` : `${d.plan.days_left} ${t('days left')}`}</span></div>
            <Link to={m.base + '/renew'} className="btn ghost">{t('Renew')}</Link>
          </div>
          <Progress pct={(d.plan.days_left / d.plan.total_days) * 100} />
          <span className="small muted">{d.pt_left !== null ? `${t('PT sessions left')}: ${d.pt_left}` : ''}{d.pt_left !== null && d.plan.autopay ? ' · ' : ''}{d.plan.autopay ? t('UPI AutoPay on') : ''}</span>
        </section>
      )}
      {d.today_workout ? (
        <Link to={m.base + '/workout'} className="card row between" style={{ borderRadius: 20, padding: 16, textDecoration: 'none', color: 'inherit', flexDirection: 'row' }}>
          <div className="col" style={{ gap: 2 }}><span className="small muted">{t("Today's workout from")} {d.trainer || 'your trainer'}</span><span style={{ fontWeight: 600, fontSize: 15 }}>{d.today_workout.title || 'Workout'} · {d.today_workout.count} {t('exercises')}</span></div>
          <span className="lime" style={{ fontWeight: 600 }}>{t('Open')} →</span>
        </Link>
      ) : <Link to={m.base + '/workout'} className="card" style={{ borderRadius: 20, padding: 16, textDecoration: 'none', color: 'inherit' }}><span className="muted">{t('Rest day')} · {t('Workouts')} →</span></Link>}
      {d.next_class && <Link to={m.base + '/book'} className="card" style={{ borderRadius: 20, padding: 16, textDecoration: 'none', color: 'inherit' }}><span className="small muted">Next class</span><b>{d.next_class.name} · {dayDate(d.next_class.class_date)} {hm(d.next_class.start_time)}</b></Link>}
      <nav aria-label="More" style={{ display: 'grid', gridTemplateColumns: 'repeat(5, minmax(0,1fr))', gap: 6 }}>
        {[['visits', 'My visits', 'clock'], ['diet', 'Diet plan', 'food'], ['progress', 'Progress', 'chart'], ['challenges', 'Challenges', 'trophy'], ['store', 'Store', 'store']].map(([p, l, i]) => (
          <Link key={p} to={m.base + '/' + p} className="card tight" style={{ alignItems: 'center', textDecoration: 'none', padding: 10, gap: 6, borderRadius: 14 }}><Icon name={i} /><span style={{ fontSize: 11, fontWeight: 600, textAlign: 'center' }}>{t(l)}</span></Link>
        ))}
      </nav>
      {d.announcements.map((a, i) => <section key={i} className="card" style={{ borderRadius: 20, padding: 16, gap: 6 }}><span className="small muted">{t('From the gym')}</span><b>{a.title}</b><span className="small t2">{a.body}</span></section>)}
    </div>
  );
}
