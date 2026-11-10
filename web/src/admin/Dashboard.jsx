import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { post } from '../lib/api.js';
import { useStaff } from '../lib/session.jsx';
import { useI18n } from '../lib/i18n.jsx';
import { money, mins, time, dayDate, today, daysUntil, titleCase } from '../lib/format.js';
import { Bars, ErrorBox, Loading, Pill, Stat, useAction, useApi, usePoll, Empty } from '../components/ui.jsx';
import MemberSearch from '../components/MemberSearch.jsx';
import CollectPayment from '../components/CollectPayment.jsx';

export function greet(t) {
  const h = new Date().getHours();
  return t(h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening');
}

export default function Dashboard({ notes }) {
  const s = useStaff();
  const { t } = useI18n();
  const nav = useNavigate();
  const [branch, setBranch] = useState('');
  const { data, error, loading, reload } = useApi('/dashboard', { branch_id: branch });
  usePoll(() => reload(true), 20000);
  const [collect, setCollect] = useState(false);
  const { run } = useAction();

  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox error={error} onRetry={reload} />;
  const d = data;
  const pct = d.capacity ? Math.round((d.inside / d.capacity) * 100) : 0;
  const modeLabel = (m) => ({ fingerprint: 'Fingerprint', face: 'Face', qr: 'QR', manual: 'Manual', rfid: 'Card', app: 'App' }[m] || m);
  const collectedTotal = d.collected?.total || 0;
  const modeShare = d.collected?.by_mode?.map((x) => `${titleCase(x.mode)} ${collectedTotal ? Math.round((x.amt / collectedTotal) * 100) : 0}%`).join(' · ');
  const peak = Math.max(...d.occupancy.map((o) => o.count));

  return (
    <>
      <header className="topbar">
        <div className="col gap4">
          <h1>{greet(t)}, {s.user.name.split(' ')[0]}</h1>
          <span className="muted">{dayDate(today())} · {s.branches.length > 1 ? `${s.branches.length} branches` : s.branches[0]?.name}</span>
        </div>
        <div className="row wrap">
          <div style={{ width: 260 }}><MemberSearch placeholder="Search member, phone or ID" onPick={(m) => nav('/admin/members/' + m.id)} /></div>
          {s.branches.length > 1 && !s.user.branch_id && (
            <select className="input" style={{ width: 160 }} aria-label="Branch" value={branch} onChange={(e) => setBranch(e.target.value)}>
              <option value="">All branches</option>
              {s.branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
            </select>
          )}
          {s.can('billing.collect') && <button className="btn" onClick={() => setCollect(true)}>{t('Record payment')}</button>}
          {s.can('members.write') && <Link className="btn primary" to="/admin/members/new">{t('+ Add member')}</Link>}
        </div>
      </header>

      <section className="grid4" aria-label="Today at a glance">
        <Stat tone="hero" label={t('Inside right now')} value={d.inside} sub={`of ${d.capacity} capacity (${pct}%) · avg stay ${mins(d.avg_stay_min)}`} />
        <Stat label={t('Check-ins today')} value={d.checkins} sub={d.checkins_by_method.map((m) => `${modeLabel(m.method)} ${m.n}`).join(' · ') || 'No check-ins yet'} />
        {d.collected ? <Stat label={t('Collected today')} value={money(collectedTotal)} sub={modeShare || 'No payments yet'} />
          : <Stat label="New members today" value={d.new_members_today} />}
        {d.dues ? <Stat tone={d.dues.amount > 0 ? 'alert' : undefined} label={t('Dues pending')} value={money(d.dues.amount)} sub={`${d.dues.members} members`} />
          : <Stat label="Churn risk" value={d.churn_risk} sub="members" />}
      </section>

      <div className="split">
        <section className="card main" aria-labelledby="punch-title">
          <div className="row between">
            <h2 id="punch-title">{t('Live punch log')}</h2>
            <Link to="/admin/sessions" className="small">All sessions →</Link>
          </div>
          {d.punches.length === 0 ? <Empty>No punches yet today.</Empty> : (
            <div className="tablebox">
              <table className="t" style={{ minWidth: 560 }}>
                <thead><tr><th>Member</th><th>Punch IN</th><th>Punch OUT</th><th>Time inside</th><th>Plan</th></tr></thead>
                <tbody>
                  {d.punches.map((p) => {
                    const inside = !p.out_at;
                    const left = p.end_date ? daysUntil(p.end_date) : null;
                    const warn = p.dues > 0 ? `Dues ${money(p.dues)}` : left !== null && left <= 5 ? `Expires in ${left} days` : null;
                    return (
                      <tr key={p.id} className="click" onClick={() => nav('/admin/members/' + p.member_id)}>
                        <td><div className="col" style={{ gap: 0 }}><span style={{ fontWeight: 500 }}>{p.name}</span><span className="small muted">{p.member_code}</span></div></td>
                        <td className="num">{time(p.in_at)}</td>
                        <td className={'num' + (inside ? ' lime' : p.auto_closed ? ' warn' : '')}>{inside ? t('Inside') : p.auto_closed ? 'Auto-closed' : time(p.out_at)}</td>
                        <td className="num" style={{ fontWeight: 600 }}>{mins(inside ? p.elapsed_min : p.duration_min)}</td>
                        <td>{warn ? <Pill tone="warn">{warn}</Pill> : <Pill>{p.plan_name || 'No plan'}</Pill>}</td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
        </section>

        <div className="side">
          {s.can('insights.view') && (
            <section className="card" aria-labelledby="ai-title">
              <h2 id="ai-title">{t('AI insights')}</h2>
              {d.insights.length === 0 && <span className="muted">Nothing needs attention right now.</span>}
              {d.insights.map((i) => (
                <div key={i.kind} className="inner col gap4">
                  <span style={{ fontWeight: 600 }}>{i.title}</span>
                  <span className="small muted">{i.detail}</span>
                  <Link to={'/admin/' + i.link} className="small" style={{ fontWeight: 600 }}>{i.action} →</Link>
                </div>
              ))}
            </section>
          )}
          <section className="card" aria-labelledby="renew-title">
            <div className="row between"><h2 id="renew-title">{t('Renewals this week')}</h2><span className="small muted">{d.renewals.length} members</span></div>
            {d.renewals.length === 0 && <span className="muted small">No plans end this week.</span>}
            {d.renewals.slice(0, 6).map((r) => (
              <div key={r.id} className="listrow">
                <Link to={'/admin/members/' + r.id} className="grow col" style={{ gap: 0, textDecoration: 'none', color: 'inherit' }}>
                  <span style={{ fontWeight: 500 }}>{r.name}</span>
                  <span className="small muted">{r.plan_name} {money(r.price)} · {dayDate(r.end_date)}{r.autopay ? ' · AutoPay' : ''}</span>
                </Link>
                {!r.autopay && s.can('members.write') && (
                  <button className="btn ghost sm" aria-label={`Send WhatsApp reminder to ${r.name}`}
                    onClick={() => run(() => post(`/members/${r.id}/message`, { body: `Hi ${r.name.split(' ')[0]}, your ${r.plan_name} plan ends on ${dayDate(r.end_date)}. Renew in the app or at the desk to keep your access.` }), 'Reminder sent')}>
                    {t('Remind')}
                  </button>
                )}
              </div>
            ))}
          </section>
        </div>
      </div>

      <section className="card" aria-labelledby="peak-title">
        <div className="row between wrap"><h2 id="peak-title">{t('Members inside by hour · today')}</h2><span className="small muted">From punch IN/OUT pairs</span></div>
        <Bars label="Members inside by hour" hiAt={Math.max(1, peak * 0.75)} data={d.occupancy.map((o) => ({ label: o.hour > 12 ? `${o.hour - 12}p` : o.hour === 12 ? '12p' : `${o.hour}a`, value: o.count }))} />
      </section>

      {notes?.data?.items?.length > 0 && (
        <section className="card">
          <div className="row between"><h2>Alerts</h2><button className="btn sm" onClick={async () => { await post('/notifications/read'); notes.reload(); }}>Mark all read</button></div>
          {notes.data.items.slice(0, 6).map((n) => (
            <div key={n.id} className="listrow">
              <span className={'dot' + (n.severity === 'warning' ? ' warn' : '')} />
              <div className="grow col" style={{ gap: 2 }}><span style={{ fontWeight: n.read_at ? 400 : 600 }}>{n.title}</span>{n.body && <span className="small muted">{n.body}</span>}</div>
              <span className="small muted">{time(n.created_at)}</span>
            </div>
          ))}
        </section>
      )}
      {collect && <CollectPayment onClose={() => setCollect(false)} onDone={() => { setCollect(false); reload(); }} />}
    </>
  );
}
