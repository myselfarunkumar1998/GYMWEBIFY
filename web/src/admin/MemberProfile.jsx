import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { get, post, put, del, openHtml, resizeImage } from '../lib/api.js';
import { useStaff } from '../lib/session.jsx';
import { useI18n } from '../lib/i18n.jsx';
import { money, mins, date, dayDate, time, today, daysUntil, titleCase } from '../lib/format.js';
import { Avatar, Confirm, ErrorBox, Input, Loading, Modal, Pill, Select, SignaturePad, Stat, useAction, useApi, Empty } from '../components/ui.jsx';
import SellPlan from '../components/SellPlan.jsx';
import CollectPayment from '../components/CollectPayment.jsx';
import MemberSearch from '../components/MemberSearch.jsx';
import MemberForm from './MemberForm.jsx';

export default function MemberProfile() {
  const { id } = useParams();
  const s = useStaff();
  const { t } = useI18n();
  const nav = useNavigate();
  const { data: m, error, loading, reload } = useApi('/members/' + id);
  const [modal, setModal] = useState(null);
  const { run, busy } = useAction();
  if (loading && !m) return <Loading />;
  if (error) return <ErrorBox error={error} onRetry={reload} />;

  const cur = m.current;
  const daysLeft = cur ? daysUntil(cur.end_date) : null;
  const total = cur ? daysUntil(cur.end_date) - daysUntil(cur.start_date) + 1 : 1;
  const status = m.open_session ? <Pill>Inside now · {mins(m.open_session.elapsed_min)}</Pill>
    : m.is_blocked ? <Pill tone="warn">Blocked</Pill> : m.plan_status === 'active' ? <Pill>Active</Pill> : <Pill tone={m.plan_status === 'frozen' ? 'grey' : 'warn'}>{titleCase(m.plan_status)}</Pill>;
  const done = () => { setModal(null); reload(); };
  const risk = m.churn_score === null ? '—' : m.churn_score >= 75 ? 'High' : m.churn_score >= 50 ? 'Medium' : 'Low';
  const last = m.assessments?.[m.assessments.length - 1];
  const latest = m.assessments?.[0];

  return (
    <>
      <Link to="/admin/members" className="small" style={{ textDecoration: 'none' }}>← All members</Link>
      {!m.access.allowed && <div className="banner">Door access is blocked: {m.access.reason}</div>}
      <section className="card" aria-label="Member summary" style={{ flexDirection: 'row', flexWrap: 'wrap', alignItems: 'center', gap: 20, padding: 24 }}>
        <Avatar size="lg" name={m.name} src={m.has_photo ? `/api/members/${m.id}/file/photo` : null} />
        <div className="col gap8" style={{ flex: '1 1 260px' }}>
          <div className="row wrap"><h1 style={{ fontSize: 28 }}>{m.name}</h1>{status}</div>
          <span className="muted">{m.member_code} · +91 {m.phone} · Member since {date(m.created_at)}{m.trainer ? ` · Trainer: ${m.trainer.name}` : ''}</span>
          <span className="muted">{m.fingerprint_enrolled ? 'Fingerprint enrolled' : 'Fingerprint not enrolled'} · {m.face_enrolled ? 'Face enrolled' : 'Face not enrolled'} · Device ID {m.device_user_id || '—'} · {m.waiver_signed_at ? `Waiver signed ${date(m.waiver_signed_at)}` : 'Waiver not signed'}</span>
        </div>
        <div className="row wrap">
          {s.can('members.write') && <button className="btn" onClick={() => setModal('edit')}>{t('Edit')}</button>}
          {s.can('members.write') && cur && ['active', 'grace'].includes(m.plan_status) && <button className="btn" onClick={() => setModal('freeze')}>{t('Freeze plan')}</button>}
          {s.can('members.write') && <button className="btn ghost" onClick={() => setModal('message')}>WhatsApp</button>}
          {s.can('billing.collect') && <button className="btn primary" onClick={() => setModal('sell')}>{t('Renew plan')}</button>}
        </div>
      </section>

      <section className="grid4" aria-label="Visit stats">
        <Stat label="Visits this month" value={m.stats.visits} />
        <Stat label="Average time inside" value={mins(m.stats.avg_min)} />
        <Stat label="Total hours this month" value={mins(m.stats.total_min)} />
        <Stat tone={m.churn_score >= 60 ? 'alert' : undefined} label="Churn risk" value={<span className={m.churn_score >= 60 ? 'warn' : 'lime'}>{risk}</span>} sub={m.churn_reason || (m.churn_score !== null ? `Score ${m.churn_score}/100` : null)} />
      </section>

      <div className="split">
        <div className="main col gap16">
          <section className="card" aria-labelledby="visits-title">
            <div className="row between"><h2 id="visits-title">{t('Visit history')}</h2></div>
            {m.visits.length === 0 ? <Empty>No visits yet.</Empty> : (
              <div className="tablebox"><table className="t" style={{ minWidth: 480 }}>
                <thead><tr><th>{t('Date')}</th><th>IN</th><th>OUT</th><th>Duration</th><th>Method</th></tr></thead>
                <tbody>{m.visits.map((v) => (
                  <tr key={v.id}>
                    <td>{dayDate(v.in_at)}</td><td className="num">{time(v.in_at)}</td>
                    <td className={'num ' + (!v.out_at ? 'lime' : v.auto_closed ? 'warn' : '')}>{!v.out_at ? 'Inside' : time(v.out_at)}</td>
                    <td className="num" style={{ fontWeight: 600 }}>{v.auto_closed ? 'Auto-closed' : mins(v.duration_min)}</td>
                    <td className="muted">{v.auto_closed ? 'No OUT punch' : titleCase(v.method)}</td>
                  </tr>))}</tbody>
              </table></div>
            )}
            <span className="small muted">Missed OUT punches close automatically at closing time and are flagged.</span>
          </section>

          {s.can('billing.view') && (
            <section className="card" aria-labelledby="inv-title">
              <div className="row between"><h2 id="inv-title">Invoices & payments</h2>{m.dues > 0 && s.can('billing.collect') && <button className="btn primary sm" onClick={() => setModal('collect')}>Collect {money(m.dues)}</button>}</div>
              {m.invoices.length === 0 ? <Empty>No invoices.</Empty> : (
                <div className="tablebox"><table className="t"><thead><tr><th>Invoice</th><th>Date</th><th className="r">Total</th><th className="r">Balance</th><th>Status</th><th></th></tr></thead>
                  <tbody>{m.invoices.map((i) => (
                    <tr key={i.id}><td className="muted num">{i.invoice_no}</td><td>{date(i.invoice_date)}</td><td className="r num">{money(i.total, true)}</td>
                      <td className={'r num ' + (i.balance > 0 ? 'warn' : '')}>{money(i.balance, true)}</td>
                      <td><Pill tone={i.status === 'paid' ? undefined : i.status === 'cancelled' ? 'grey' : 'warn'}>{titleCase(i.status)}</Pill></td>
                      <td><button className="btn sm" onClick={() => openHtml(`/invoices/${i.id}/print`)}>View</button></td></tr>))}</tbody></table></div>
              )}
              {m.credit_balance > 0 && <span className="small lime">Referral credit available: {money(m.credit_balance)}</span>}
            </section>
          )}

          <section className="card">
            <h2>Plan history</h2>
            {m.memberships.map((ms) => (
              <div key={ms.id} className="listrow">
                <div className="grow col" style={{ gap: 2 }}>
                  <span style={{ fontWeight: 600 }}>{ms.plan_name} <span className="muted small">#{ms.id}</span></span>
                  <span className="small muted">{date(ms.start_date)} – {date(ms.end_date)}{ms.kind === 'pt' ? ` · ${ms.sessions_total - ms.sessions_used} of ${ms.sessions_total} sessions left` : ''}{ms.freeze_start ? ` · frozen ${dayDate(ms.freeze_start)}–${dayDate(ms.freeze_end)}` : ''}{ms.notes ? ` · ${ms.notes}` : ''}</span>
                </div>
                <Pill tone={['active', 'upcoming'].includes(ms.status) ? undefined : ms.status === 'frozen' ? 'grey' : 'warn'}>{titleCase(ms.status)}</Pill>
                {['active', 'frozen', 'upcoming'].includes(ms.status) && <PlanMenu ms={ms} member={m} s={s} onDone={done} />}
              </div>
            ))}
          </section>
        </div>

        <div className="side">
          <section className="card" aria-labelledby="plan-title">
            <h2 id="plan-title">{t('Current plan')}</h2>
            {!cur ? <span className="muted">No plan. Use Renew plan to add one.</span> : <>
              <Row k="Plan" v={cur.plan_name} />
              <Row k={t('Valid till')} v={date(cur.end_date)} />
              <Row k="Days left" v={Math.max(0, daysLeft)} />
              <div className="progress"><div style={{ width: `${Math.max(0, Math.min(100, (daysLeft / total) * 100))}%` }} /></div>
              {m.pt && <Row k="PT sessions" v={`${m.pt.sessions_total - m.pt.sessions_used} of ${m.pt.sessions_total} left`} />}
              <Row k={t('Dues')} v={<span className={m.dues > 0 ? 'warn' : ''}>{money(m.dues)}</span>} />
              <Row k="AutoPay" v={cur.autopay ? <span className="lime">UPI AutoPay on</span> : 'Off'} />
            </>}
          </section>

          <section className="card" aria-labelledby="body-title">
            <div className="row between"><h2 id="body-title">{t('Progress')}</h2><Link className="small" to={`/admin/members/${m.id}/plans`} style={{ fontWeight: 600 }}>Workout &amp; diet plan →</Link></div>
            {latest ? (
              <div className="grid3">
                <Metric k={t('Weight')} v={latest.weight_kg && `${+latest.weight_kg} kg`} d={last?.weight_kg && latest.weight_kg ? +(latest.weight_kg - last.weight_kg).toFixed(1) : null} unit="kg" />
                <Metric k={t('Body fat')} v={latest.body_fat_pct && `${+latest.body_fat_pct}%`} d={last?.body_fat_pct && latest.body_fat_pct ? +(latest.body_fat_pct - last.body_fat_pct).toFixed(1) : null} unit="%" />
                <Metric k={t('Waist')} v={latest.waist_in && `${+latest.waist_in} in`} d={last?.waist_in && latest.waist_in ? +(latest.waist_in - last.waist_in).toFixed(1) : null} unit="in" />
              </div>
            ) : <span className="muted small">No assessments yet.</span>}
          </section>

          <section className="card">
            <h2>Profile</h2>
            <Row k="Goal" v={m.goal || '—'} /><Row k="Date of birth" v={date(m.dob)} /><Row k="Gender" v={titleCase(m.gender || '—')} />
            <Row k="Emergency" v={m.emergency_contact || '—'} /><Row k="Language" v={m.language === 'ta' ? 'Tamil' : 'English'} /><Row k="Came from" v={m.source || '—'} />
            {m.referrer && <Row k="Referred by" v={<Link to={'/admin/members/' + m.referrer.id}>{m.referrer.name}</Link>} />}
            {m.health_notes && <div className="inner small"><b>Health notes:</b> {m.health_notes}</div>}
          </section>

          {s.can('members.write') && (
            <section className="card">
              <h2>Documents & access</h2>
              <div className="row wrap">
                <label className="btn sm">Photo<input type="file" accept="image/*" capture="user" hidden onChange={async (e) => { const f = e.target.files[0]; if (f) await run(async () => post(`/members/${m.id}/upload/photo`, { file: await resizeImage(f, 800) }), 'Photo saved'); reload(); }} /></label>
                <label className="btn sm">ID proof<input type="file" accept="image/*,application/pdf" hidden onChange={async (e) => { const f = e.target.files[0]; if (f) await run(async () => post(`/members/${m.id}/upload/id-proof`, { file: f.type === 'application/pdf' ? await (await import('../lib/api.js')).fileToDataUrl(f) : await resizeImage(f) }), 'ID proof saved'); reload(); }} /></label>
                {m.has_id_proof && <a className="btn sm" href={`/api/members/${m.id}/file/id-proof`} target="_blank" rel="noreferrer">View ID</a>}
                <button className="btn sm" onClick={() => setModal('waiver')}>{m.waiver_signed_at ? 'Re-sign waiver' : 'Sign waiver'}</button>
                <button className="btn sm" onClick={() => setModal('enroll')}>Enrol fingerprint/face</button>
                <button className="btn sm danger" disabled={busy} onClick={() => setModal('block')}>{m.is_blocked ? 'Unblock' : 'Block access'}</button>
                {s.can('members.delete') && <button className="btn sm danger" onClick={() => setModal('delete')}>Delete</button>}
              </div>
            </section>
          )}
        </div>
      </div>

      {modal === 'sell' && <SellPlan member={m} onClose={() => setModal(null)} onDone={done} />}
      {modal === 'collect' && <CollectPayment member={m} onClose={() => setModal(null)} onDone={done} />}
      {modal === 'edit' && <Modal title="Edit member" wide onClose={() => setModal(null)}><MemberForm member={m} onSaved={done} /></Modal>}
      {modal === 'freeze' && <FreezeModal ms={cur} onClose={() => setModal(null)} onDone={done} />}
      {modal === 'message' && <MessageModal member={m} onClose={() => setModal(null)} onDone={done} />}
      {modal === 'waiver' && <WaiverModal member={m} text={s.rules?.waiver_text} onClose={() => setModal(null)} onDone={done} />}
      {modal === 'enroll' && <EnrollModal member={m} onClose={() => setModal(null)} />}
      {modal === 'block' && <BlockModal member={m} onClose={() => setModal(null)} onDone={done} />}
      {modal === 'delete' && <Confirm title="Delete member?" danger confirmLabel="Delete" message="The member is removed from lists and door devices. Invoices stay for your accounts." onClose={() => setModal(null)}
        onConfirm={async () => { if (await run(() => del('/members/' + m.id), 'Member deleted')) nav('/admin/members'); }} />}
    </>
  );
}

const Row = ({ k, v }) => <div className="row between gap8"><span className="muted">{k}</span><span style={{ fontWeight: 600, textAlign: 'right' }}>{v}</span></div>;
const Metric = ({ k, v, d, unit }) => (
  <div className="inner col gap4"><span className="small muted">{k}</span><span className="num" style={{ fontSize: 18, fontWeight: 600 }}>{v || '—'}</span>
    {d !== null && d !== 0 && <span className={'small ' + (d < 0 ? 'lime' : 'warn')}>{d > 0 ? '+' : '−'}{Math.abs(d)} {unit}</span>}</div>
);

function PlanMenu({ ms, member, s, onDone }) {
  const [m, setM] = useState(null);
  const { run, busy } = useAction();
  return (
    <>
      <select className="input" style={{ width: 130, height: 36 }} aria-label={`Actions for ${ms.plan_name}`} value="" onChange={(e) => setM(e.target.value)}>
        <option value="">Actions…</option>
        {ms.status === 'frozen' ? <option value="unfreeze">Unfreeze</option> : ms.kind === 'membership' && <option value="freeze">Freeze</option>}
        {s.can('billing.collect') && <option value="upgrade">Upgrade</option>}
        {s.can('billing.manage') && <option value="transfer">Transfer to member</option>}
        {s.can('billing.manage') && <option value="extend">Extend days</option>}
        {s.can('billing.collect') && <option value="autopay">{ms.autopay ? 'Turn off AutoPay' : 'Set up AutoPay'}</option>}
      </select>
      {m === 'freeze' && <FreezeModal ms={ms} onClose={() => setM(null)} onDone={onDone} />}
      {m === 'unfreeze' && <Confirm title="Unfreeze plan?" message="Unused freeze days are given back and the end date moves earlier." onClose={() => setM(null)} onConfirm={async () => { if (await run(() => post(`/memberships/${ms.id}/unfreeze`), 'Plan unfrozen')) onDone(); }} />}
      {m === 'upgrade' && <UpgradeModal ms={ms} onClose={() => setM(null)} onDone={onDone} />}
      {m === 'transfer' && <TransferModal ms={ms} member={member} onClose={() => setM(null)} onDone={onDone} />}
      {m === 'extend' && <ExtendModal ms={ms} onClose={() => setM(null)} onDone={onDone} />}
      {m === 'autopay' && <Confirm title={ms.autopay ? 'Turn off AutoPay?' : 'Set up UPI AutoPay?'} confirmLabel={ms.autopay ? 'Turn off' : 'Create AutoPay link'}
        message={ms.autopay ? 'Renewals will need a manual payment.' : 'A Razorpay AutoPay link is created. Send it to the member to approve the UPI mandate.'}
        onClose={() => setM(null)} onConfirm={async () => {
          const r = await run(() => post(`/memberships/${ms.id}/autopay`, { enabled: !ms.autopay }));
          if (r?.short_url) { navigator.clipboard?.writeText(r.short_url).catch(() => {}); alert('AutoPay link copied: ' + r.short_url); }
          if (r) onDone();
        }} />}
      {busy && null}
    </>
  );
}

function FreezeModal({ ms, onClose, onDone }) {
  const [f, setF] = useState({ from: today(), days: 7 });
  const { run, busy, fields } = useAction();
  const left = (ms.freeze_days_allowed ?? 0) - (ms.frozen_days_used ?? 0);
  return (
    <Modal title="Freeze plan" onClose={onClose} footer={<><button className="btn" onClick={onClose}>Cancel</button>
      <button className="btn primary" disabled={busy} onClick={async () => { if (await run(() => post(`/memberships/${ms.id}/freeze`, { from: f.from, days: Number(f.days) }), 'Plan frozen. End date extended.')) onDone(); }}>Freeze</button></>}>
      <span className="muted">{ms.plan_name} allows {left} more freeze days. The end date moves forward by the same number of days. Door access is off while frozen.</span>
      <div className="grid2">
        <Input label="From" type="date" min={today()} value={f.from} onChange={(e) => setF({ ...f, from: e.target.value })} error={fields.from} />
        <Input label="Days" type="number" min="1" max={left} value={f.days} onChange={(e) => setF({ ...f, days: e.target.value })} error={fields.days} />
      </div>
    </Modal>
  );
}

function UpgradeModal({ ms, onClose, onDone }) {
  const plans = useApi('/plans', { active: 1 });
  const [f, setF] = useState({ plan_id: '', pay_amount: '', pay_mode: 'upi' });
  const { run, busy } = useAction();
  return (
    <Modal title="Upgrade plan" onClose={onClose} footer={<><button className="btn" onClick={onClose}>Cancel</button>
      <button className="btn primary" disabled={busy || !f.plan_id} onClick={async () => { const r = await run(() => post(`/memberships/${ms.id}/upgrade`, { plan_id: Number(f.plan_id), pay_amount: f.pay_amount ? Number(f.pay_amount) : 0, pay_mode: f.pay_mode })); if (r) { alert(`Upgraded. Credit ${money(r.credit)} from the old plan; ${money(r.due)} + GST to pay.`); onDone(); } }}>Upgrade</button></>}>
      <span className="muted">The unused value of {ms.plan_name} is credited against the new plan, which starts today.</span>
      <Select label="New plan" value={f.plan_id} placeholder="Choose" onChange={(e) => setF({ ...f, plan_id: e.target.value })} options={(plans.data || []).filter((p) => p.kind === ms.kind && p.id !== ms.plan_id).map((p) => ({ value: p.id, label: `${p.name} · ${money(p.price)}` }))} />
      <div className="grid2">
        <Input label="Collect now (₹)" type="number" value={f.pay_amount} onChange={(e) => setF({ ...f, pay_amount: e.target.value })} />
        <Select label="Mode" value={f.pay_mode} onChange={(e) => setF({ ...f, pay_mode: e.target.value })} options={['upi', 'cash', 'card']} />
      </div>
    </Modal>
  );
}

function TransferModal({ ms, member, onClose, onDone }) {
  const [to, setTo] = useState(null);
  const { run, busy } = useAction();
  return (
    <Modal title="Transfer membership" onClose={onClose} footer={<><button className="btn" onClick={onClose}>Cancel</button>
      <button className="btn primary" disabled={busy || !to} onClick={async () => { if (await run(() => post(`/memberships/${ms.id}/transfer`, { to_member_id: to.id }), 'Transferred. Fee invoice created if a fee is set.')) onDone(); }}>Transfer</button></>}>
      <span className="muted">The remaining days of {ms.plan_name} move from {member.name} to the chosen member. Add the person as a member first.</span>
      <MemberSearch onPick={setTo} />
      {to && <div className="inner">To: <b>{to.name}</b> ({to.member_code})</div>}
    </Modal>
  );
}

function ExtendModal({ ms, onClose, onDone }) {
  const [f, setF] = useState({ days: 7, reason: '' });
  const { run, busy, fields } = useAction();
  return (
    <Modal title="Extend plan" onClose={onClose} footer={<><button className="btn" onClick={onClose}>Cancel</button>
      <button className="btn primary" disabled={busy} onClick={async () => { if (await run(() => post(`/memberships/${ms.id}/extend`, { days: Number(f.days), reason: f.reason }), 'Plan extended')) onDone(); }}>Extend</button></>}>
      <div className="grid2">
        <Input label="Days to add" type="number" min="1" value={f.days} onChange={(e) => setF({ ...f, days: e.target.value })} error={fields.days} />
        <Input label="Reason" value={f.reason} onChange={(e) => setF({ ...f, reason: e.target.value })} error={fields.reason} placeholder="e.g. gym closed for repairs" />
      </div>
    </Modal>
  );
}

function MessageModal({ member, onClose, onDone }) {
  const [f, setF] = useState({ body: `Hi ${member.name.split(' ')[0]}, `, channel: 'whatsapp' });
  const { run, busy } = useAction();
  return (
    <Modal title={`Message ${member.name}`} onClose={onClose} footer={<><button className="btn" onClick={onClose}>Cancel</button>
      <button className="btn primary" disabled={busy || f.body.length < 3} onClick={async () => { const r = await run(() => post(`/members/${member.id}/message`, f)); if (r) { if (r.status === 'failed') alert('Not sent: ' + r.error); onDone(); } }}>Send</button></>}>
      <div className="seg">{['whatsapp', 'sms', 'email'].map((c) => <button key={c} type="button" className={f.channel === c ? 'on' : ''} onClick={() => setF({ ...f, channel: c })}>{titleCase(c)}</button>)}</div>
      <textarea className="input" rows={4} value={f.body} onChange={(e) => setF({ ...f, body: e.target.value })} aria-label="Message" maxLength={1000} />
      {member.messages?.length > 0 && <div className="col gap4"><span className="small muted">Recent messages</span>
        {member.messages.slice(0, 4).map((x) => <div key={x.id} className="inner small"><span className="muted">{x.direction === 'in' ? 'Received' : titleCase(x.status)} · {dayDate(x.created_at)}</span><br />{x.body}</div>)}</div>}
    </Modal>
  );
}

function WaiverModal({ member, text, onClose, onDone }) {
  const [sig, setSig] = useState(null);
  const { run, busy } = useAction();
  return (
    <Modal title="Consent & waiver" onClose={onClose} footer={<><button className="btn" onClick={onClose}>Cancel</button>
      <button className="btn primary" disabled={busy || !sig} onClick={async () => { if (await run(() => post(`/members/${member.id}/waiver`, { signature: sig }), 'Waiver signed')) onDone(); }}>Save signature</button></>}>
      <div className="inner small" style={{ whiteSpace: 'pre-line', maxHeight: 200, overflowY: 'auto' }}>{text}</div>
      <span className="small muted">Hand the screen to {member.name.split(' ')[0]} to sign below.</span>
      <SignaturePad onChange={setSig} />
    </Modal>
  );
}

function EnrollModal({ member, onClose }) {
  const devices = useApi('/devices');
  const [f, setF] = useState({ device_id: '', kind: 'fingerprint' });
  const { run, busy } = useAction();
  const [msg, setMsg] = useState(null);
  return (
    <Modal title="Enrol on a door device" onClose={onClose}>
      {devices.error ? <span className="warn">Only managers can start enrolment.</span> : <>
        <Select label="Device" value={f.device_id} placeholder="Choose device" onChange={(e) => setF({ ...f, device_id: e.target.value })} options={(devices.data?.devices || []).map((d) => ({ value: d.id, label: `${d.name} · ${d.branch_name}${d.online ? '' : ' (offline)'}` }))} />
        <div className="seg">{['fingerprint', 'face'].map((k) => <button key={k} type="button" className={f.kind === k ? 'on' : ''} onClick={() => setF({ ...f, kind: k })}>{titleCase(k)}</button>)}</div>
        <span className="small muted">Device ID for {member.name}: <b>{member.device_user_id}</b>. You can also enrol directly on the device with this ID.</span>
        {msg && <div className="banner info">{msg}</div>}
        <div className="row" style={{ justifyContent: 'flex-end' }}><button className="btn primary" disabled={busy || !f.device_id} onClick={async () => { const r = await run(() => post(`/members/${member.id}/enroll`, { device_id: Number(f.device_id), kind: f.kind })); if (r) setMsg(r.message); }}>Start enrolment</button></div>
      </>}
    </Modal>
  );
}

function BlockModal({ member, onClose, onDone }) {
  const [reason, setReason] = useState('');
  const { run, busy } = useAction();
  const blocking = !member.is_blocked;
  return (
    <Modal title={blocking ? 'Block door access' : 'Unblock door access'} onClose={onClose} footer={<><button className="btn" onClick={onClose}>Cancel</button>
      <button className="btn primary" disabled={busy} onClick={async () => { if (await run(() => post(`/members/${member.id}/block`, { blocked: blocking, reason }), blocking ? 'Access blocked on all devices' : 'Access restored')) onDone(); }}>{blocking ? 'Block' : 'Unblock'}</button></>}>
      {blocking && <Input label="Reason" value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Shown at the front desk" />}
      <span className="small muted">The change is sent to every door device the next time it connects.</span>
    </Modal>
  );
}
