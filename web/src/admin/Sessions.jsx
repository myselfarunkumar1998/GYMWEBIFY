import { useState } from 'react';
import { Link } from 'react-router-dom';
import { post, put } from '../lib/api.js';
import { useStaff } from '../lib/session.jsx';
import { useI18n } from '../lib/i18n.jsx';
import { mins, time, today, dayDate, titleCase, hm } from '../lib/format.js';
import { Chips, ErrorBox, Input, Loading, Modal, Stat, useAction, useApi, usePoll, Empty } from '../components/ui.jsx';
import MemberSearch from '../components/MemberSearch.jsx';

export default function Sessions() {
  const s = useStaff();
  const { t } = useI18n();
  const [d, setD] = useState(today());
  const [filter, setFilter] = useState('');
  const [who, setWho] = useState('members');
  const { data, error, loading, reload } = useApi('/sessions', { date: d, filter, who });
  const denied = useApi('/punches', { date: d, result: 'denied' });
  usePoll(() => { reload(true); denied.reload(true); }, 15000);
  const [manual, setManual] = useState(false);
  const [fix, setFix] = useState(null);
  const { run } = useAction();
  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox error={error} onRetry={reload} />;
  const st = data.stats;
  return (
    <>
      <header className="topbar">
        <div className="col gap4"><h1>{t('Check-ins & sessions')}</h1><span className="muted">Every IN punch starts a timer. The OUT punch stops it.</span></div>
        <div className="row wrap">
          <label className="sr" htmlFor="sd">Date</label>
          <input id="sd" type="date" className="input" style={{ width: 160 }} value={d} max={today()} onChange={(e) => setD(e.target.value)} />
          {s.can('sessions.manage') && <button className="btn" onClick={() => setManual(true)}>Manual check-in</button>}
          {s.can('sessions.manage') && <Link className="btn primary" to="/admin/punch">Open front-desk screen</Link>}
        </div>
      </header>
      <section className="grid4">
        <Stat tone="hero" label={t('Inside right now')} value={d === today() ? (st.inside ?? 0) : '—'} sub={`of ${s.branches.reduce((a, b) => a + Number(b.capacity), 0)} capacity`} />
        <Stat label="Average stay" value={mins(st.avg_min)} sub={`${st.checkins} check-ins`} />
        <Stat tone={st.missed_out_yesterday ? 'alert' : undefined} label="Missed OUT punches" value={st.missed_out_yesterday} sub="Auto-closed the day before" />
        <Stat tone={st.blocked ? 'alert' : undefined} label="Blocked at the door" value={st.blocked} sub={denied.data?.slice(0, 3).map((x) => x.reason).filter(Boolean).join(' · ')} />
      </section>
      <div className="split">
        <section className="card main">
          <div className="row between wrap">
            <h2>Sessions · {dayDate(d)}</h2>
            <div className="row wrap">
              <Chips label="Who" items={[{ value: 'members', label: 'Members' }, { value: 'staff', label: 'Staff' }]} value={who} onChange={setWho} />
              <Chips label="Show" items={[{ value: '', label: 'All' }, { value: 'inside', label: 'Inside' }, { value: 'flagged', label: 'Flagged' }]} value={filter} onChange={setFilter} />
            </div>
          </div>
          {data.items.length === 0 ? <Empty>No sessions.</Empty> : (
            <div className="tablebox"><table className="t" style={{ minWidth: 640 }}>
              <thead><tr><th>{who === 'staff' ? 'Staff' : 'Member'}</th><th>IN</th><th>OUT</th><th>Time inside</th><th>Method · door</th><th>Note</th><th></th></tr></thead>
              <tbody>{data.items.map((x) => (
                <tr key={x.id}>
                  <td>{x.member_id ? <Link to={'/admin/members/' + x.member_id} style={{ color: 'inherit', textDecoration: 'none' }}><div className="col" style={{ gap: 0 }}><span style={{ fontWeight: 500 }}>{x.name}</span><span className="small muted">{x.member_code}</span></div></Link> : <span style={{ fontWeight: 500 }}>{x.staff_name}</span>}</td>
                  <td className="num">{time(x.in_at)}</td>
                  <td className={'num ' + (!x.out_at ? 'lime' : x.auto_closed ? 'warn' : '')}>{!x.out_at ? 'Inside' : x.auto_closed ? 'Auto-closed' : time(x.out_at)}</td>
                  <td className="num" style={{ fontWeight: 600 }}>{!x.out_at ? mins(x.elapsed_min) : mins(x.duration_min)}</td>
                  <td className="t2">{titleCase(x.method)}{x.device_name ? ` · ${x.device_name}` : ''}</td>
                  <td className={'small ' + (x.auto_closed || x.method === 'manual' ? 'warn' : 'muted')}>{x.note || (x.method === 'manual' && x.created_by_name ? `Added by ${x.created_by_name}` : '')}</td>
                  <td className="r">{s.can('sessions.manage') && (!x.out_at
                    ? <button className="btn sm" onClick={() => run(() => post(`/sessions/${x.id}/checkout`), 'Checked out').then(reload)}>Check out</button>
                    : x.auto_closed ? <button className="btn sm ghost" onClick={() => setFix(x)}>Fix</button> : null)}</td>
                </tr>))}</tbody>
            </table></div>
          )}
        </section>
        <div className="side">
          <section className="card">
            <h2>Blocked {d === today() ? 'today' : 'that day'}</h2>
            {denied.data?.length === 0 && <span className="small muted">No one was turned away.</span>}
            {denied.data?.map((b) => (
              <div key={b.id} className="listrow">
                <div className="grow col" style={{ gap: 2 }}><span style={{ fontWeight: 500 }}>{b.member_name || b.staff_name || `Unknown ID ${b.device_user_id}`} · {time(b.punched_at)}</span><span className="small warn">{b.reason}</span></div>
                {b.member_id && <Link className="btn sm ghost" to={'/admin/members/' + b.member_id}>{/expired|plan/i.test(b.reason || '') ? 'Renew' : /dues/i.test(b.reason || '') ? 'Collect' : 'Open'}</Link>}
              </div>))}
          </section>
          <section className="card">
            <h2>Session rules</h2>
            {s.branches.map((b) => <div key={b.id} className="row between"><span className="muted">{b.name}</span><span className="small">Auto-close at closing time</span></div>)}
            <div className="row between"><span className="muted">Grace after expiry</span><span style={{ fontWeight: 600 }}>{s.rules?.grace_days} days</span></div>
            <div className="row between"><span className="muted">Duplicate punches</span><span style={{ fontWeight: 600 }}>Ignored within 90 s</span></div>
            {s.can('devices.manage') && <Link to="/admin/devices" className="small" style={{ fontWeight: 600 }}>Devices &amp; access settings →</Link>}
          </section>
        </div>
      </div>
      {manual && <ManualCheckin onClose={() => setManual(false)} onDone={() => { setManual(false); reload(); }} />}
      {fix && <FixSession s={fix} onClose={() => setFix(null)} onDone={() => { setFix(null); reload(); }} />}
    </>
  );
}

function ManualCheckin({ onClose, onDone }) {
  const s = useStaff();
  const [res, setRes] = useState(null);
  const [branch, setBranch] = useState(s.user.branch_id || s.branches[0]?.id);
  const [code, setCode] = useState('');
  const { run } = useAction();
  const go = async (body) => { const r = await run(() => post('/sessions/checkin', { ...body, branch_id: Number(branch) })); if (r) setRes(r); };
  return (
    <Modal title="Manual check-in / out" onClose={onClose} footer={<button className="btn primary" onClick={onDone}>Done</button>}>
      {s.branches.length > 1 && <select className="input" aria-label="Branch" value={branch} onChange={(e) => setBranch(e.target.value)}>{s.branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}</select>}
      <MemberSearch autoFocus onPick={(m) => go({ member_id: m.id })} />
      <div className="row"><Input label="Or trial pass code" value={code} onChange={(e) => setCode(e.target.value.toUpperCase())} className="grow" /><button className="btn" style={{ alignSelf: 'flex-end' }} onClick={() => go({ trial_code: code })}>Check in trial</button></div>
      {res && <div className={'result ' + res.result}><span className="num" style={{ fontSize: 22, fontWeight: 700 }}>{res.result === 'in' ? 'Checked IN' : res.result === 'out' ? 'Checked OUT' : res.result === 'denied' ? 'Not allowed' : 'Ignored'}: {res.name}</span>
        {res.reason && <span>{res.reason}</span>}{res.duration_min ? <span>Stayed {mins(res.duration_min)}</span> : null}</div>}
    </Modal>
  );
}

function FixSession({ s, onClose, onDone }) {
  const [out, setOut] = useState(s.in_at.slice(0, 10) + 'T' + new Date(new Date(s.in_at.replace(' ', 'T')).getTime() + 75 * 60000).toTimeString().slice(0, 5));
  const { run, busy } = useAction();
  return (
    <Modal title={`Fix session · ${s.name}`} onClose={onClose} footer={<><button className="btn" onClick={onClose}>Cancel</button>
      <button className="btn primary" disabled={busy} onClick={async () => { if (await run(() => put('/sessions/' + s.id, { out_at: out.replace('T', ' ') + ':00', note: 'OUT time corrected by staff' }), 'Session fixed')) onDone(); }}>Save</button></>}>
      <span className="muted">IN at {time(s.in_at)}. Set the real OUT time so the stay counts.</span>
      <Input label="OUT time" type="datetime-local" value={out} onChange={(e) => setOut(e.target.value)} />
    </Modal>
  );
}
