import { useState } from 'react';
import { Link } from 'react-router-dom';
import { download } from '../lib/api.js';
import { useStaff } from '../lib/session.jsx';
import { money, moneyShort, mins, today, titleCase, DOW } from '../lib/format.js';
import { Bars, ErrorBox, Loading, Stat, useApi } from '../components/ui.jsx';

const EXPORTS = [['collections', 'Collections', true], ['invoices', 'Invoices', true], ['dues', 'Dues', true], ['expenses', 'Expenses', true], ['attendance', 'Member attendance'],
  ['staff_attendance', 'Staff attendance'], ['expiring', 'Plans expiring in range'], ['leads', 'Leads']];

export default function Reports() {
  const s = useStaff();
  const [from, setFrom] = useState(today().slice(0, 8) + '01');
  const [to, setTo] = useState(today());
  const [branch, setBranch] = useState('');
  const { data, error, loading, reload } = useApi('/reports', { from, to, branch_id: branch });
  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox error={error} onRetry={reload} />;
  const d = data;
  const heat = {};
  d.heatmap.forEach((h) => { heat[`${h.dow}-${h.h}`] = Number(h.n); });
  const hmax = Math.max(1, ...Object.values(heat));
  const fin = s.can('reports.finance');
  return (
    <>
      <header className="topbar">
        <div className="col gap4"><h1>Reports</h1><span className="muted">Revenue, members, attendance, leads, trainers and classes</span></div>
        <div className="row wrap">
          <input type="date" className="input" style={{ width: 150 }} aria-label="From" value={from} max={to} onChange={(e) => setFrom(e.target.value)} />
          <input type="date" className="input" style={{ width: 150 }} aria-label="To" value={to} max={today()} onChange={(e) => setTo(e.target.value)} />
          {s.branches.length > 1 && !s.user.branch_id && <select className="input" style={{ width: 150 }} aria-label="Branch" value={branch} onChange={(e) => setBranch(e.target.value)}><option value="">All branches</option>{s.branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}</select>}
          {s.branches.length > 1 && <Link className="btn" to="/admin/branches">Compare branches</Link>}
        </div>
      </header>
      <section className="grid4">
        {fin && <Stat label="Collected" value={money(d.collected)} sub={`Expenses ${money(d.expenses)}`} />}
        {fin && <Stat tone={d.profit < 0 ? 'alert' : undefined} label="Collected minus expenses" value={money(d.profit)} />}
        <Stat label="Active members" value={d.members.active} sub={`${d.members.new_members} new in range`} />
        <Stat label="Renewal rate" value={d.members.renewal_rate === null ? '—' : `${d.members.renewal_rate}%`} sub={`${d.members.renewed} of ${d.members.due_to_renew} plans that ended renewed · churn ${d.members.churn_rate ?? '—'}%`} />
        {!fin && <Stat label="Visits" value={d.attendance.visits} />}
      </section>
      <div className="split">
        {fin && <section className="card main">
          <h2>Revenue by month</h2>
          <Bars label="Revenue by month" height={190} data={d.revenue_by_month.map((r, i) => ({ label: new Date(r.ym + '-01').toLocaleDateString('en-IN', { month: 'short' }), value: Number(r.amount), title: money(r.amount), hi: i === d.revenue_by_month.length - 1 }))} />
          <div className="row wrap">{d.revenue_by_kind.map((k) => <span key={k.kind} className="pill grey">{titleCase(k.kind)} {moneyShort(k.amount)}</span>)}{fin && d.dues > 0 && <span className="pill warn">Dues {moneyShort(d.dues)}</span>}</div>
        </section>}
        <section className="card side">
          <h2>Attendance</h2>
          <div className="row between"><span className="muted">Visits</span><b>{d.attendance.visits}</b></div>
          <div className="row between"><span className="muted">Unique members</span><b>{d.attendance.unique_members}</b></div>
          <div className="row between"><span className="muted">Average stay</span><b>{mins(d.attendance.avg_min)}</b></div>
          <div className="row between"><span className="muted">Missed OUT punches</span><b className={Number(d.attendance.missed_out) ? 'warn' : ''}>{d.attendance.missed_out || 0}</b></div>
        </section>
      </div>
      <section className="card">
        <h2>Peak hours heatmap (check-ins)</h2>
        <div className="tablebox"><div className="heat" role="img" aria-label="Check-ins by weekday and hour">
          <span />{Array.from({ length: 18 }, (_, i) => <span key={i} className="muted" style={{ textAlign: 'center' }}>{((i + 5) % 12) || 12}</span>)}
          {[1, 2, 3, 4, 5, 6, 0].map((dow) => [<span key={'l' + dow} className="muted">{DOW[dow]}</span>, ...Array.from({ length: 18 }, (_, i) => {
            const n = heat[`${dow}-${i + 5}`] || 0;
            return <div key={dow + '-' + i} title={`${DOW[dow]} ${i + 5}:00 · ${n}`} style={{ background: n ? `rgba(200,240,49,${0.12 + 0.88 * (n / hmax)})` : 'var(--panel-2)' }} />;
          })])}
        </div></div>
      </section>
      <div className="grid2">
        <section className="card"><h2>Lead conversion by source</h2>
          <table className="t"><thead><tr><th>Source</th><th className="r">Leads</th><th className="r">Joined</th><th className="r">Rate</th></tr></thead>
            <tbody>{d.leads_by_source.map((r) => <tr key={r.source}><td>{titleCase(r.source)}</td><td className="r">{r.total}</td><td className="r">{r.joined}</td><td className="r">{Math.round((r.joined / r.total) * 100)}%</td></tr>)}</tbody></table>
          {d.leads_by_staff.length > 0 && <table className="t"><thead><tr><th>Salesperson</th><th className="r">Leads</th><th className="r">Joined</th></tr></thead><tbody>{d.leads_by_staff.map((r) => <tr key={r.name}><td>{r.name}</td><td className="r">{r.total}</td><td className="r">{r.joined}</td></tr>)}</tbody></table>}
        </section>
        <section className="card"><h2>Trainers</h2>
          <table className="t"><thead><tr><th>Trainer</th><th className="r">Clients</th><th className="r">PT done</th><th className="r">No-shows</th></tr></thead>
            <tbody>{d.trainers.map((r) => <tr key={r.name}><td>{r.name}</td><td className="r">{r.clients}</td><td className="r">{r.done || 0}</td><td className="r">{r.no_show || 0}</td></tr>)}</tbody></table>
          <h2>Classes</h2>
          <table className="t"><thead><tr><th>Class</th><th className="r">Bookings</th><th className="r">Fill</th><th className="r">No-shows</th></tr></thead>
            <tbody>{d.classes.map((r, i) => <tr key={i}><td>{r.name}</td><td className="r">{r.bookings}</td><td className="r">{r.fill_pct}%</td><td className="r">{r.no_show || 0}</td></tr>)}</tbody></table>
        </section>
      </div>
      <section className="card">
        <h2>Download (CSV for Excel)</h2>
        <div className="row wrap">{EXPORTS.filter(([, , f]) => !f || fin).map(([k, l]) => <button key={k} className="btn sm" onClick={() => download('/reports/export/' + k, { from, to })}>{l}</button>)}
          {fin && <button className="btn sm" onClick={() => download('/billing/gst', { month: to.slice(0, 7), format: 'csv' })}>GST report ({to.slice(0, 7)})</button>}</div>
      </section>
    </>
  );
}
