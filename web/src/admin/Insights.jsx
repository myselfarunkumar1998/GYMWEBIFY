import { useState } from 'react';
import { Link } from 'react-router-dom';
import { post } from '../lib/api.js';
import { useStaff } from '../lib/session.jsx';
import { money, date, dateTime } from '../lib/format.js';
import { ErrorBox, Loading, Stat, useAction, useApi, Empty } from '../components/ui.jsx';

export default function Insights() {
  const s = useStaff();
  const { data, error, loading, reload } = useApi('/insights');
  const [sel, setSel] = useState([]);
  const { run, busy } = useAction();
  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox error={error} onRetry={reload} />;
  const winback = async (ids) => { const r = await run(() => post('/insights/winback', { member_ids: ids })); if (r) alert(`${r.queued} win-back messages queued on WhatsApp.`); };
  const action = (m) => {
    if (m.action === 'pay_link') return <Link className="btn sm ghost" to={'/admin/members/' + m.id}>Send pay link</Link>;
    if (m.action === 'trainer_call') return <a className="btn sm ghost" href={`tel:+91${m.phone}`}>Trainer call</a>;
    return <button className="btn sm ghost" onClick={() => winback([m.id])}>WhatsApp</button>;
  };
  return (
    <>
      <Link to="/admin" className="small" style={{ textDecoration: 'none' }}>← Dashboard</Link>
      <header className="topbar">
        <div className="col gap4"><h1>AI insights</h1><span className="muted">Risk scored every night from punches, payments and app use{data.last_scored ? ` · last run ${dateTime(data.last_scored)}` : ''}</span></div>
        <div className="row wrap">
          <button className="btn" disabled={busy} onClick={async () => { if (await run(() => post('/insights/rescore'), 'Scores updated')) reload(); }}>Re-score now</button>
          {s.can('campaigns.send') && <button className="btn primary" disabled={busy || !data.at_risk} onClick={() => winback(sel)}>{sel.length ? `Send win-back to ${sel.length}` : `Send win-back to all ${data.at_risk}`}</button>}
        </div>
      </header>
      <section className="grid4">
        <Stat tone="alert" label="At churn risk" value={data.at_risk} sub={`High ${data.high} · Medium ${data.medium}`} />
        <Stat label="Renewals at risk" value={money(data.renewals_at_risk)} sub={`If none of the ${data.at_risk} renew`} />
        <Stat label="Won back (60 days)" value={`${data.won_back.returned || 0} of ${data.won_back.sent || 0}`} sub="Visited within 14 days of a win-back" />
        <Stat label="WhatsApp bot today" value={`${data.bot_today.chats} chats`} sub={`${data.bot_today.trials} trials booked`} />
      </section>
      <div className="split">
        <section className="card main">
          <h2>Members likely to leave</h2>
          {data.members.length === 0 ? <Empty>No members at risk right now.</Empty> : (
            <div className="tablebox"><table className="t" style={{ minWidth: 680 }}>
              <thead><tr><th><span className="sr">Select</span></th><th>Member</th><th>Risk</th><th>Why</th><th>Plan ends</th><th><span className="sr">Action</span></th></tr></thead>
              <tbody>{data.members.map((m) => (
                <tr key={m.id}>
                  <td><input type="checkbox" aria-label={`Select ${m.name}`} checked={sel.includes(m.id)} onChange={(e) => setSel(e.target.checked ? [...sel, m.id] : sel.filter((x) => x !== m.id))} /></td>
                  <td><Link to={'/admin/members/' + m.id} style={{ color: 'inherit', textDecoration: 'none' }}><div className="col" style={{ gap: 0 }}><span style={{ fontWeight: 500 }}>{m.name}</span><span className="small muted">{m.member_code} · {m.plan_name || 'No plan'}</span></div></Link></td>
                  <td><div className="row gap8"><span className="num" style={{ fontWeight: 700, width: 28 }}>{m.churn_score}</span><div aria-hidden="true" style={{ width: 64 }} className="progress"><div style={{ width: `${m.churn_score}%`, background: m.churn_score >= 75 ? 'var(--warn)' : 'var(--warn-fg)' }} /></div></div></td>
                  <td className="small t2" style={{ maxWidth: 300 }}>{m.churn_reason}</td>
                  <td style={{ whiteSpace: 'nowrap' }}>{m.end_date ? (m.end_date < new Date().toISOString().slice(0, 10) ? 'Expired' : date(m.end_date)) : '—'}</td>
                  <td className="r">{action(m)}</td>
                </tr>))}</tbody>
            </table></div>
          )}
          <span className="small muted">Score 0–100. It weighs days since the last visit, change in weekly visits, shorter stays, overdue dues, a plan ending without AutoPay, skipped PT and app use.</span>
        </section>
        <section className="card side">
          <h2>This week</h2>
          {data.feed.length === 0 && <span className="muted">Nothing unusual this week.</span>}
          {data.feed.map((f) => (
            <div key={f.kind} className="inner col gap4">
              <span style={{ fontWeight: 600 }}>{f.title}</span><span className="small muted">{f.detail}</span>
              <Link to={'/admin/' + f.link} className="small" style={{ fontWeight: 600 }}>{f.action} →</Link>
            </div>
          ))}
        </section>
      </div>
    </>
  );
}
