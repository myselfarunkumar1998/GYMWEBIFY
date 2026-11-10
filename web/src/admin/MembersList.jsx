import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { download, post } from '../lib/api.js';
import { useStaff } from '../lib/session.jsx';
import { useI18n } from '../lib/i18n.jsx';
import { money, mins, date, relDays, daysUntil } from '../lib/format.js';
import { Avatar, Chips, ErrorBox, Loading, Pill, useApi, Modal, useAction, Empty } from '../components/ui.jsx';
import ImportMembers from './ImportMembers.jsx';

export function statusPill(m) {
  if (m.is_blocked) return <Pill tone="warn">Blocked</Pill>;
  if (m.inside > 0) return <Pill>Inside now</Pill>;
  if (m.plan_status === 'frozen') return <Pill tone="grey">Frozen</Pill>;
  if (m.plan_status === 'expired' || m.plan_status === 'no_plan') return <Pill tone="grey">{m.plan_status === 'no_plan' ? 'No plan' : 'Expired'}</Pill>;
  if (m.dues > 0) return <Pill tone="warn">Dues</Pill>;
  if (m.churn_score >= 60) return <Pill tone="warn">Churn risk</Pill>;
  if (m.plan_status === 'grace') return <Pill tone="warn">Grace period</Pill>;
  if (m.end_date && daysUntil(m.end_date) <= 7) return <Pill tone="warn">Expires in {daysUntil(m.end_date)} days</Pill>;
  if (m.pt_left !== null && m.pt_left !== undefined) return <Pill>PT · {m.pt_left} left</Pill>;
  return <Pill>Active</Pill>;
}

export default function MembersList() {
  const s = useStaff();
  const { t } = useI18n();
  const nav = useNavigate();
  const [q, setQ] = useState('');
  const [status, setStatus] = useState('');
  const [plan, setPlan] = useState('');
  const [page, setPage] = useState(1);
  const [importing, setImporting] = useState(false);
  const [bulk, setBulk] = useState(false);
  const plans = useApi('/plans');
  const { data, error, loading, reload } = useApi('/members', { q, status, plan_id: plan, page, limit: 50 });
  const c = data?.counts || {};
  const chips = [
    { value: '', label: t('All'), count: c.all_count }, { value: 'active', label: t('Active'), count: c.active }, { value: 'expiring', label: 'Expiring in 7 days', count: c.expiring },
    { value: 'dues', label: t('Dues'), count: c.dues }, { value: 'frozen', label: t('Frozen'), count: c.frozen }, { value: 'expired', label: t('Expired'), count: c.expired },
    { value: 'inside', label: t('Inside'), count: c.inside }, { value: 'churn', label: 'Churn risk', count: c.churn },
  ];
  return (
    <>
      <header className="topbar">
        <div className="col gap4"><h1>{t('Members')}</h1><span className="muted">{c.all_count ?? '…'} members · {c.active ?? '…'} active · {c.inside ?? '…'} inside right now</span></div>
        <div className="row wrap">
          {s.can('plans.manage') && <Link className="btn" to="/admin/plans">{t('Plans & packages')}</Link>}
          {s.can('members.write') && <button className="btn" onClick={() => setImporting(true)}>Import from Excel</button>}
          {s.can('members.write') && <Link className="btn primary" to="/admin/members/new">{t('+ Add member')}</Link>}
        </div>
      </header>
      <div className="row wrap between">
        <Chips label="Filter by status" items={chips} value={status} onChange={(v) => { setStatus(v); setPage(1); }} />
        <div className="row wrap">
          <label className="sr" htmlFor="mq">Search</label>
          <input id="mq" className="input" style={{ width: 240 }} type="search" placeholder="Name, phone or member ID" value={q} onChange={(e) => { setQ(e.target.value); setPage(1); }} />
          <label className="sr" htmlFor="mp">Plan</label>
          <select id="mp" className="input" style={{ width: 170 }} value={plan} onChange={(e) => { setPlan(e.target.value); setPage(1); }}>
            <option value="">All plans</option>
            {plans.data?.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
          </select>
        </div>
      </div>
      <section className="card" aria-label="Member list" style={{ paddingTop: 8 }}>
        {loading && !data ? <Loading /> : error ? <ErrorBox error={error} onRetry={reload} /> : data.items.length === 0 ? <Empty>No members match.</Empty> : (
          <div className="tablebox">
            <table className="t" style={{ minWidth: 880 }}>
              <thead><tr><th>Member</th><th>Phone</th><th>{t('Plan')}</th><th>{t('Valid till')}</th><th>Last visit</th><th>{t('Avg stay')}</th><th>{t('Dues')}</th><th>{t('Status')}</th></tr></thead>
              <tbody>
                {data.items.map((m) => (
                  <tr key={m.id} className="click" onClick={() => nav('/admin/members/' + m.id)}>
                    <td><Link to={'/admin/members/' + m.id} className="row" style={{ textDecoration: 'none', color: 'inherit' }} onClick={(e) => e.stopPropagation()}>
                      <Avatar name={m.name} src={m.photo_path ? `/api/members/${m.id}/file/photo` : null} />
                      <span className="col" style={{ gap: 0 }}><span style={{ fontWeight: 500 }}>{m.name}</span><span className="small muted">{m.member_code}</span></span>
                    </Link></td>
                    <td className="t2 num">{m.phone}</td>
                    <td>{m.plan_name || '—'}</td>
                    <td style={{ whiteSpace: 'nowrap' }}>{m.plan_status === 'frozen' ? 'Frozen' : date(m.end_date)}</td>
                    <td className={m.inside > 0 ? 'lime' : (m.last_visit && -daysUntil(m.last_visit.slice(0, 10)) > 10 ? 'warn' : '')} style={{ whiteSpace: 'nowrap' }}>{m.inside > 0 ? 'Inside now' : relDays(m.last_visit)}</td>
                    <td className="num" style={{ fontWeight: 600 }}>{mins(m.avg_stay)}</td>
                    <td className={m.dues > 0 ? 'warn num' : 'muted num'}>{money(m.dues)}</td>
                    <td>{statusPill(m)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        {data && (
          <div className="row wrap between" style={{ borderTop: '1px solid var(--line)', paddingTop: 10 }}>
            <span className="small muted">Showing {data.items.length} of {data.total}</span>
            <div className="row wrap">
              <button className="btn sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>Previous</button>
              <button className="btn sm" disabled={page * data.limit >= data.total} onClick={() => setPage(page + 1)}>Next</button>
              {s.can('reports.view') && <button className="btn sm" onClick={() => download('/members/export', { q, status, plan_id: plan })}>{t('Export')}</button>}
              {s.can('campaigns.send') && <button className="btn sm ghost" onClick={() => setBulk(true)}>WhatsApp this list</button>}
            </div>
          </div>
        )}
      </section>
      {importing && <ImportMembers onClose={() => setImporting(false)} onDone={() => { setImporting(false); reload(); }} />}
      {bulk && <BulkMessage filter={{ q, status, plan_id: plan }} total={data?.total} onClose={() => setBulk(false)} />}
    </>
  );
}

function BulkMessage({ filter, total, onClose }) {
  const [body, setBody] = useState('Hi {name}, ');
  const { run, busy } = useAction();
  const segStatus = { active: 'active', expiring: 'expiring', dues: 'dues', expired: 'expired', churn: 'churn' }[filter.status] || 'all';
  const send = async () => {
    const r = await run(() => post('/campaigns', { name: 'Members list message', channel: 'whatsapp', body, segment: { status: segStatus, plan_id: filter.plan_id || undefined } }));
    if (r) { onClose(); }
  };
  return (
    <Modal title="WhatsApp this list" onClose={onClose} footer={<><button className="btn" onClick={onClose}>Cancel</button><button className="btn primary" disabled={busy || body.length < 5} onClick={send}>Send</button></>}>
      <span className="muted">Sends to members in the "{segStatus}" segment{filter.plan_id ? ' on the selected plan' : ''} (about {total} people). Use {'{name}'} for the first name.</span>
      {filter.q && <div className="banner">The search text is not applied to bulk messages. Use filters instead.</div>}
      <textarea className="input" rows={4} value={body} onChange={(e) => setBody(e.target.value)} aria-label="Message" maxLength={1000} />
    </Modal>
  );
}
