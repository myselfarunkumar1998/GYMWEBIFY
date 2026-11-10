import { useState } from 'react';
import { Link, NavLink, Route, Routes, useNavigate } from 'react-router-dom';
import { post } from '../lib/api.js';
import { useStaff, enablePush } from '../lib/session.jsx';
import { useI18n } from '../lib/i18n.jsx';
import { money, moneyShort, mins, time, dateShort, titleCase, today, daysUntil } from '../lib/format.js';
import { Avatar, Bars, Chips, ErrorBox, Icon, Loading, Modal, Pill, useAction, useApi, usePoll, useToast, Empty } from '../components/ui.jsx';
import { statusPill } from '../admin/MembersList.jsx';

function TabBar() {
  const { t } = useI18n();
  const tabs = [['', 'Home', 'grid'], ['members', 'Members', 'users'], ['punches', 'Punches', 'clock'], ['money', 'Money', 'card'], ['reports', 'Reports', 'chart']];
  return (
    <nav className="tabbar" aria-label="Owner app">
      {tabs.map(([p, l, i]) => <NavLink key={p} end={p === ''} to={'/owner' + (p ? '/' + p : '')} className={({ isActive }) => (isActive ? 'active' : '')}><Icon name={i} />{t(l)}</NavLink>)}
    </nav>
  );
}

export default function OwnerApp() {
  return (
    <div className="phone">
      <Routes>
        <Route index element={<Home />} />
        <Route path="members" element={<Members />} />
        <Route path="punches" element={<Punches />} />
        <Route path="money" element={<Money />} />
        <Route path="reports" element={<Reports />} />
      </Routes>
      <TabBar />
    </div>
  );
}

function Header({ sub, title, right }) {
  return (
    <header className="row between">
      <div className="col" style={{ gap: 2 }}><span className="small muted">{sub}</span><h1 style={{ fontSize: 24 }}>{title}</h1></div>
      {right}
    </header>
  );
}

function Home() {
  const s = useStaff();
  const { t } = useI18n();
  const toast = useToast();
  const d = useApi('/dashboard');
  const br = useApi(s.branches.length > 1 ? '/reports/branches' : null);
  const notes = useApi('/notifications');
  const [showNotes, setShowNotes] = useState(false);
  usePoll(() => { d.reload(true); notes.reload(true); }, 20000);
  if (d.loading && !d.data) return <Loading />;
  if (d.error) return <ErrorBox error={d.error} onRetry={d.reload} />;
  const x = d.data;
  return (
    <div className="phone-body">
      <Header sub={`${s.gym.name} · ${s.branches.length > 1 ? 'All branches' : s.branches[0]?.name}`} title={t('Today')}
        right={<button className="iconbtn" aria-label={`Notifications, ${notes.data?.unread || 0} new`} onClick={() => { setShowNotes(true); post('/notifications/read').then(() => notes.reload(true)); }}><Icon name="bell" />{notes.data?.unread > 0 && <span className="badge-dot" />}</button>} />
      <section className="card hero" style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'flex-end', borderRadius: 22 }}>
        <div className="col gap4"><span className="stat-label">{t('Inside right now')}</span><span className="stat-value" style={{ fontSize: 52 }}>{x.inside}</span><span className="small">Avg stay today {mins(x.avg_stay_min)}</span></div>
        {br.data && <div className="col gap4" style={{ alignItems: 'flex-end', fontWeight: 600, fontSize: 13 }}>{br.data.map((b) => <span key={b.id}>{b.name} {b.inside}</span>)}</div>}
      </section>
      <section className="grid2" aria-label="Today's numbers">
        <div className="card tight"><span className="small muted">Collected</span><span className="num" style={{ fontSize: 22, fontWeight: 700 }}>{x.collected ? money(x.collected.total) : '—'}</span></div>
        <div className="card tight"><span className="small muted">Check-ins</span><span className="num" style={{ fontSize: 22, fontWeight: 700 }}>{x.checkins}</span></div>
        <div className="card tight"><span className="small muted">New members</span><span className="num" style={{ fontSize: 22, fontWeight: 700 }}>{x.new_members_today}</span></div>
        <div className={'card tight' + (x.dues?.amount ? ' alert' : '')}><span className="small warn">Dues</span><span className="num" style={{ fontSize: 22, fontWeight: 700 }}>{x.dues ? moneyShort(x.dues.amount) : '—'}</span></div>
      </section>
      <section className="col gap8" aria-labelledby="alerts-title">
        <h2 id="alerts-title" style={{ fontSize: 17 }}>{t('Needs your attention')}</h2>
        {x.churn_risk > 0 && <Alert warn title={`${x.churn_risk} members at churn risk`} sub="Visits dropped or plan ending" to="/admin/insights" act="Act" />}
        {x.missed_out_yesterday > 0 && <Alert warn title={`${x.missed_out_yesterday} missed OUT punches`} sub="Auto-closed at closing time yesterday" to="/owner/punches" act="View" />}
        <Alert title={`${x.renewals.length} renewals this week`} sub={`${x.renewals.filter((r) => r.autopay).length} on AutoPay · reminders automatic`} to="/owner/members?status=expiring" act="List" />
        {x.insights.map((i) => <Alert key={i.kind} title={i.title} sub={i.detail} to={'/admin/' + i.link} act="Open" />)}
      </section>
      <div className="row wrap">
        <Link to="/admin" className="btn sm">Full admin</Link>
        <button className="btn sm" onClick={() => enablePush(s.vapid_public).then(() => toast('Push alerts on')).catch((e) => toast(e.message, 'err'))}>Push alerts</button>
        <button className="btn sm" onClick={s.logout}>Sign out</button>
      </div>
      {showNotes && <Modal title="Notifications" onClose={() => setShowNotes(false)}>
        {notes.data?.items.length === 0 && <Empty>Nothing new.</Empty>}
        {notes.data?.items.map((n) => <div key={n.id} className="listrow"><span className={'dot' + (n.severity === 'warning' ? ' warn' : '')} /><div className="grow col" style={{ gap: 2 }}><b>{n.title}</b>{n.body && <span className="small muted">{n.body}</span>}</div><span className="small muted">{time(n.created_at)}</span></div>)}
      </Modal>}
    </div>
  );
}

const Alert = ({ title, sub, to, act, warn }) => (
  <div className="card tight" style={{ flexDirection: 'row', alignItems: 'center', gap: 12, borderRadius: 16 }}>
    <span className={'dot' + (warn ? ' warn' : '')} style={{ width: 10, height: 10, borderRadius: 5 }} />
    <div className="grow col" style={{ gap: 2 }}><span style={{ fontWeight: 600 }}>{title}</span><span className="small muted">{sub}</span></div>
    <Link to={to} style={{ fontWeight: 600, textDecoration: 'none', padding: '12px 0' }}>{act}</Link>
  </div>
);

function Members() {
  const nav = useNavigate();
  const [q, setQ] = useState('');
  const [status, setStatus] = useState(new URLSearchParams(window.location.search).get('status') || '');
  const { data, loading } = useApi('/members', { q, status, limit: 40 });
  const c = data?.counts || {};
  return (
    <div className="phone-body">
      <Header sub={`${c.all_count ?? '…'} members · ${c.active ?? '…'} active`} title="Members" right={<Link className="iconbtn" aria-label="Add member" to="/admin/members/new" style={{ background: 'var(--lime)', color: 'var(--bg)', border: 0 }}><Icon name="plus" /></Link>} />
      <label className="sr" htmlFor="om-search">Search members</label>
      <input id="om-search" className="input" type="search" placeholder="Name, phone or member ID" value={q} onChange={(e) => setQ(e.target.value)} />
      <Chips label="Filter" value={status} onChange={setStatus} items={[{ value: '', label: 'All' }, { value: 'inside', label: 'Inside', count: c.inside }, { value: 'dues', label: 'Dues', count: c.dues }, { value: 'expiring', label: 'Expiring', count: c.expiring }, { value: 'churn', label: 'Risk', count: c.churn }]} />
      <section className="card" style={{ padding: '4px 14px', gap: 0 }}>
        {loading && !data ? <Loading /> : data.items.length === 0 ? <Empty>No members.</Empty> : data.items.map((m) => (
          <button key={m.id} className="listrow" style={{ minHeight: 66, background: 'none', border: 0, borderTop: '1px solid var(--line)', color: 'inherit', textAlign: 'left', width: '100%', cursor: 'pointer' }} onClick={() => nav('/admin/members/' + m.id)}>
            <Avatar name={m.name} />
            <div className="grow col" style={{ gap: 2, minWidth: 0 }}><span style={{ fontWeight: 600 }}>{m.name}</span><span className="small muted">{m.plan_name || 'No plan'} · {m.inside ? 'inside now' : m.end_date ? `ends ${dateShort(m.end_date)}` : ''}{m.dues > 0 ? ` · dues ${money(m.dues)}` : ''}</span></div>
            {statusPill(m)}
          </button>
        ))}
      </section>
    </div>
  );
}

function Punches() {
  const [filter, setFilter] = useState('live');
  const d = useApi('/dashboard');
  const p = useApi('/punches', { date: today(), result: filter === 'blocked' ? 'denied' : '' });
  const ses = useApi(filter === 'missed' ? '/sessions' : null, { date: new Date(Date.now() - 86400000).toISOString().slice(0, 10), filter: 'flagged' });
  usePoll(() => { d.reload(true); p.reload(true); }, 15000);
  const label = { in: 'IN', out: 'OUT', denied: 'NO', ignored: 'DUP' };
  return (
    <div className="phone-body">
      <Header sub="Live from the scanner" title="Punches" right={<span className="row gap8 small muted"><span className="dot" />Live</span>} />
      <section className="card hero" style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'flex-end', borderRadius: 22 }}>
        <div className="col gap4"><span className="stat-label">Inside right now</span><span className="stat-value" style={{ fontSize: 48 }}>{d.data?.inside ?? '…'}</span></div>
        <div className="col gap4" style={{ alignItems: 'flex-end', fontWeight: 600, fontSize: 13 }}><span>{d.data?.checkins ?? 0} check-ins today</span><span>Avg stay {mins(d.data?.avg_stay_min)}</span></div>
      </section>
      <div className="seg">{[['live', 'Live'], ['missed', 'Missed OUT'], ['blocked', 'Blocked']].map(([v, l]) => <button key={v} className={filter === v ? 'on' : ''} onClick={() => setFilter(v)}>{l}</button>)}</div>
      <section className="card" style={{ padding: '4px 14px', gap: 0 }}>
        {filter === 'missed' ? (ses.data?.items.filter((x) => x.auto_closed).map((x) => <Row key={x.id} k="NO OUT" warn name={x.name} sub={`IN ${time(x.in_at)} · auto-closed`} at={dateShort(x.in_at)} />) || <Loading />)
          : (p.data || []).length === 0 ? <Empty>Nothing yet today.</Empty>
          : p.data.slice(0, 40).map((x) => <Row key={x.id} k={label[x.result]} warn={x.result === 'denied'} grey={x.result === 'out' || x.result === 'ignored'} name={x.member_name || x.staff_name || `ID ${x.device_user_id}`} sub={x.reason || `${titleCase(x.method)}${x.device_name ? ' · ' + x.device_name : ''}`} at={time(x.punched_at)} />)}
      </section>
    </div>
  );
}

const Row = ({ k, name, sub, at, warn, grey }) => (
  <div className="listrow" style={{ minHeight: 60 }}>
    <span style={{ flex: '0 0 48px', padding: '4px 0', borderRadius: 8, textAlign: 'center', fontSize: 11, fontWeight: 700, background: warn ? 'var(--warn-bg)' : grey ? 'var(--grey-bg)' : 'var(--lime-bg)', color: warn ? 'var(--warn-fg)' : grey ? 'var(--text-2)' : 'var(--lime)' }}>{k}</span>
    <div className="grow col" style={{ gap: 2, minWidth: 0 }}><span style={{ fontWeight: 600 }}>{name}</span><span className={'small ' + (warn ? 'warn' : 'muted')}>{sub}</span></div>
    <span className="small t2 num">{at}</span>
  </div>
);

function Money() {
  const d = useApi('/dashboard');
  const dues = useApi('/dues');
  const { run, busy } = useAction();
  const total = d.data?.collected?.total || 0;
  const by = Object.fromEntries((d.data?.collected?.by_mode || []).map((x) => [x.mode, Number(x.amt)]));
  const dueTotal = (dues.data || []).reduce((a, x) => a + Number(x.balance), 0);
  return (
    <div className="phone-body">
      <Header sub={`All branches · ${dateShort(today())}`} title="Money" />
      <section className="card" style={{ borderRadius: 22 }}>
        <div className="row between" style={{ alignItems: 'flex-end' }}><div className="col gap4"><span className="small muted">Collected today</span><span className="num" style={{ fontSize: 40, fontWeight: 700 }}>{money(total)}</span></div></div>
        <div style={{ display: 'flex', gap: 3, height: 10 }} role="img" aria-label="Collection by payment mode">
          {[['upi', 'var(--lime)'], ['cash', '#8A9199'], ['card', '#4A505A'], ['online', '#E2FF7A'], ['autopay', '#9DBE1F']].filter(([m]) => by[m]).map(([m, c]) => <div key={m} style={{ flex: `${by[m]} 1 0`, background: c, borderRadius: 5 }} />)}
          {!total && <div style={{ flex: 1, background: 'var(--line)', borderRadius: 5 }} />}
        </div>
        <div className="row between small t2 wrap">{Object.entries(by).map(([m, v]) => <span key={m}>{titleCase(m)} {money(v)}</span>)}</div>
      </section>
      <section className="card alert" style={{ borderRadius: 22, gap: 4 }}>
        <div className="row between" style={{ paddingBottom: 6 }}><h2 style={{ fontSize: 17 }}>Dues · {money(dueTotal)}</h2><span className="small warn">{dues.data?.length ?? '…'} members</span></div>
        {(dues.data || []).slice(0, 5).map((x) => (
          <div key={x.member_id} className="listrow" style={{ minHeight: 58 }}>
            <div className="grow col" style={{ gap: 2 }}><span style={{ fontWeight: 600 }}>{x.name} · {money(x.balance)}</span><span className="small muted">{x.days_overdue} days overdue</span></div>
            <button className="btn sm ghost" disabled={busy} onClick={() => run(() => post('/dues/remind', { member_ids: [x.member_id] }), 'Pay link sent on WhatsApp')}>Pay link</button>
          </div>
        ))}
        {dues.data?.length > 0 && <button className="btn primary block" style={{ marginTop: 6, height: 48 }} disabled={busy} onClick={() => run(() => post('/dues/remind', {}), 'Pay links queued to everyone with dues')}>WhatsApp pay links to all {dues.data.length}</button>}
      </section>
      <section className="card tight" style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' }}>
        <div className="col" style={{ gap: 2 }}><span className="small muted">Renewals due this week</span><span className="num" style={{ fontSize: 18, fontWeight: 600 }}>{d.data?.renewals.length ?? '…'} members · {money((d.data?.renewals || []).reduce((a, r) => a + Number(r.price), 0))}</span>
          <span className="small muted">{(d.data?.renewals || []).filter((r) => r.autopay).length} on UPI AutoPay</span></div>
        <Link to="/owner/members?status=expiring" style={{ fontWeight: 600, textDecoration: 'none' }}>List →</Link>
      </section>
    </div>
  );
}

function Reports() {
  const [m, setM] = useState(today().slice(0, 7));
  const from = m + '-01';
  const to = m === today().slice(0, 7) ? today() : m + '-' + new Date(+m.slice(0, 4), +m.slice(5), 0).getDate();
  const r = useApi('/reports', { from, to });
  const d = useApi('/dashboard');
  const ins = useApi('/insights');
  const peak = Math.max(1, ...(d.data?.occupancy || []).map((o) => o.count));
  return (
    <div className="phone-body">
      <Header sub="All branches" title="Reports" right={<input type="month" className="input" style={{ width: 150 }} aria-label="Month" value={m} max={today().slice(0, 7)} onChange={(e) => e.target.value && setM(e.target.value)} />} />
      {!r.data ? <Loading /> : <section className="grid2">
        <div className="card tight"><span className="small muted">Collected</span><span className="num" style={{ fontSize: 22, fontWeight: 700 }}>{moneyShort(r.data.collected)}</span></div>
        <div className="card tight"><span className="small muted">Renewal rate</span><span className="num" style={{ fontSize: 22, fontWeight: 700 }}>{r.data.members.renewal_rate ?? '—'}%</span></div>
        <div className="card tight"><span className="small muted">New joins</span><span className="num" style={{ fontSize: 22, fontWeight: 700 }}>{r.data.members.new_members}</span></div>
        <div className="card tight"><span className="small muted">Average stay</span><span className="num" style={{ fontSize: 22, fontWeight: 700 }}>{mins(r.data.attendance.avg_min)}</span></div>
      </section>}
      <section className="card">
        <div className="row between"><h2 style={{ fontSize: 16 }}>Members inside by hour</h2><span className="small muted">Today</span></div>
        <Bars height={100} label="Members inside by hour today" hiAt={peak * 0.75} data={(d.data?.occupancy || []).map((o) => ({ label: o.hour % 3 === 0 ? (o.hour > 12 ? o.hour - 12 + 'p' : o.hour + 'a') : '', value: o.count }))} />
      </section>
      <section className="col gap8">
        <h2 style={{ fontSize: 17 }}>AI insights</h2>
        {(ins.data?.feed || []).slice(0, 3).map((f) => <div key={f.kind} className="card tight"><b>{f.title}</b><span className="small muted">{f.detail}</span></div>)}
        {ins.data?.at_risk > 0 && <div className="card tight" style={{ flexDirection: 'row', alignItems: 'center' }}><div className="grow col" style={{ gap: 2 }}><b>{ins.data.at_risk} members may leave</b><span className="small muted">{money(ins.data.renewals_at_risk)} in renewals at risk.</span></div><Link className="btn sm ghost" to="/admin/insights">Win back</Link></div>}
      </section>
    </div>
  );
}
