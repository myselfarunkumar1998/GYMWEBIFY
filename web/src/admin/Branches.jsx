import { Link } from 'react-router-dom';
import { money, mins, hm } from '../lib/format.js';
import { ErrorBox, Loading, Progress, useApi } from '../components/ui.jsx';

export default function Branches() {
  const { data, error, loading, reload } = useApi('/reports/branches');
  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox error={error} onRetry={reload} />;
  const rows = [['Active members', 'active_members'], ['New joins this month', 'new_joins'], ['Collected this month', 'collected_month', money], ['Dues pending', 'dues', money, true],
    ['Average stay', 'avg_stay', mins], ['Members at churn risk', 'churn_risk', null, true], ['Missed OUT punches', 'missed_out', null, true]];
  return (
    <>
      <Link to="/admin/reports" className="small" style={{ textDecoration: 'none' }}>← Reports</Link>
      <header className="topbar"><div className="col gap4"><h1>Branches</h1><span className="muted">{data.length} branches · live</span></div><Link className="btn primary" to="/admin/settings">+ Add branch</Link></header>
      <section className="grid2">{data.map((b) => (
        <div key={b.id} className="card">
          <div className="row between wrap"><div className="col" style={{ gap: 2 }}><h2 style={{ fontSize: 20 }}>{b.name}</h2><span className="small muted">Manager: {b.manager || '—'} · {hm(b.open_time)} – {hm(b.close_time)}</span></div>
            <span className="row gap8 small muted"><span className={'dot' + (b.devices_online ? '' : ' warn')} />{b.devices_online} device{b.devices_online === 1 ? '' : 's'} online</span></div>
          <div className="row" style={{ alignItems: 'flex-end' }}><span className="num lime" style={{ fontSize: 44, fontWeight: 700, lineHeight: 1 }}>{b.inside}</span><span className="small muted">inside now of {b.capacity}</span></div>
          <Progress pct={(b.inside / b.capacity) * 100} />
          <div className="grid3">
            <div className="inner"><span className="small muted">Check-ins</span><div className="num" style={{ fontSize: 18, fontWeight: 600 }}>{b.checkins_today}</div></div>
            <div className="inner"><span className="small muted">Collected</span><div className="num" style={{ fontSize: 18, fontWeight: 600 }}>{money(b.collected_today)}</div></div>
            <div className="inner"><span className="small warn">Dues</span><div className="num" style={{ fontSize: 18, fontWeight: 600 }}>{money(b.dues)}</div></div>
          </div>
        </div>
      ))}</section>
      {data.length > 1 && (
        <section className="card">
          <h2>Compare · this month</h2>
          <div className="tablebox"><table className="t"><thead><tr><th>Measure</th>{data.map((b) => <th key={b.id}>{b.name}</th>)}<th>All</th></tr></thead>
            <tbody>{rows.map(([label, key, fmt, lowerIsBetter]) => {
              const vals = data.map((b) => Number(b[key] || 0));
              const worst = lowerIsBetter ? Math.max(...vals) : Math.min(...vals);
              const total = key === 'avg_stay' ? Math.round(vals.reduce((a, v) => a + v, 0) / vals.length) : vals.reduce((a, v) => a + v, 0);
              const f = fmt || ((v) => v);
              return <tr key={key}><td className="t2">{label}</td>{vals.map((v, i) => <td key={i} className={'num ' + (vals.length > 1 && v === worst && new Set(vals).size > 1 ? 'warn' : '')} style={{ fontWeight: 600 }}>{f(v)}</td>)}<td className="num" style={{ fontWeight: 600 }}>{f(total)}</td></tr>;
            })}</tbody></table></div>
          <span className="small muted">Orange marks the weaker branch on that measure. Members with all-branch plans can punch in at either branch.</span>
        </section>
      )}
    </>
  );
}
