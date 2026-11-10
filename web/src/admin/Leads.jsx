import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { get, post, put, del } from '../lib/api.js';
import { useStaff } from '../lib/session.jsx';
import { useI18n } from '../lib/i18n.jsx';
import { dateTime, titleCase } from '../lib/format.js';
import { Confirm, ErrorBox, Input, Loading, Modal, Pill, Select, Stat, useAction, useApi, Empty } from '../components/ui.jsx';

const STAGES = [['new', 'New enquiry'], ['trial', 'Trial booked'], ['follow_up', 'Follow-up'], ['joined', 'Joined']];
const SOURCES = ['walk_in', 'instagram', 'facebook', 'google', 'website', 'referral', 'whatsapp_bot', 'phone', 'other'];

export default function Leads() {
  const s = useStaff();
  const { t } = useI18n();
  const [q, setQ] = useState('');
  const [mine, setMine] = useState(false);
  const { data, error, loading, reload } = useApi('/leads', { q, mine: mine ? 1 : '' });
  const [add, setAdd] = useState(false);
  const [open, setOpen] = useState(null);
  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox error={error} onRetry={reload} />;
  const st = data.stats;
  return (
    <>
      <header className="topbar">
        <div className="col gap4"><h1>Leads &amp; enquiries</h1><span className="muted">Instagram, Google, website, walk-ins and the WhatsApp bot, in one pipeline</span></div>
        <div className="row wrap">
          <input className="input" style={{ width: 220 }} type="search" aria-label="Search leads" placeholder="Name or phone" value={q} onChange={(e) => setQ(e.target.value)} />
          <button className={'chip' + (mine ? ' on' : '')} aria-pressed={mine} onClick={() => setMine(!mine)}>Mine</button>
          {s.can('leads.write') && <button className="btn primary" onClick={() => setAdd(true)}>+ Add enquiry</button>}
        </div>
      </header>
      <section className="kanban" aria-label="Pipeline">
        {STAGES.map(([k, label]) => (
          <div key={k} className="card" style={{ padding: 16 }}>
            <div className="row between"><h2 style={{ fontSize: 16 }}>{label}</h2><span className="pill grey">{data.columns[k].length}</span></div>
            {data.columns[k].length === 0 && <span className="small muted">Empty</span>}
            {data.columns[k].map((l) => (
              <button key={l.id} className="inner col gap8" style={{ textAlign: 'left', border: '1px solid var(--line)', cursor: 'pointer' }} onClick={() => setOpen(l.id)}>
                <div className="row between gap8"><span style={{ fontWeight: 600 }}>{l.name}</span><span className="small muted">{titleCase(l.source)}</span></div>
                {l.interest && <span className="small t2">{l.interest}</span>}
                <div className="row between gap8">
                  <span className={'small ' + (l.overdue ? 'warn' : 'lime')}>{k === 'joined' ? 'Member' : l.overdue ? 'Overdue · ' + dateTime(l.next_followup_at) : l.trial_at && k === 'trial' ? 'Trial ' + dateTime(l.trial_at) : l.next_followup_at ? 'Follow up ' + dateTime(l.next_followup_at) : 'No follow-up set'}</span>
                  <span className="small muted">{l.assigned_name || (l.source === 'whatsapp_bot' ? 'Bot' : '')}</span>
                </div>
              </button>
            ))}
          </div>
        ))}
      </section>
      <section className="grid4">
        <Stat label="Enquiry → member this month" value={`${st.conversion}%`} sub={`${st.month_total} enquiries`} />
        <Stat label="Best source (90 days)" value={st.best_source ? titleCase(st.best_source) : '—'} />
        <Stat label="WhatsApp bot chats this month" value={st.bot_chats} />
        <Stat label="Lost (last 30 days)" value={data.columns.lost.length} />
      </section>
      <section className="card">
        <h2>Conversion by source and by staff · 90 days</h2>
        <div className="grid2">
          <table className="t"><thead><tr><th>Source</th><th className="r">Leads</th><th className="r">Joined</th><th className="r">Rate</th></tr></thead>
            <tbody>{st.by_source.map((r) => <tr key={r.source}><td>{titleCase(r.source)}</td><td className="r">{r.total}</td><td className="r">{r.joined}</td><td className="r">{Math.round((r.joined / r.total) * 100)}%</td></tr>)}</tbody></table>
          <table className="t"><thead><tr><th>Staff</th><th className="r">Leads</th><th className="r">Joined</th></tr></thead>
            <tbody>{st.by_staff.map((r) => <tr key={r.name}><td>{r.name}</td><td className="r">{r.total}</td><td className="r">{r.joined}</td></tr>)}</tbody></table>
        </div>
      </section>
      {add && <LeadForm onClose={() => setAdd(false)} onDone={() => { setAdd(false); reload(); }} />}
      {open && <LeadDrawer id={open} onClose={() => setOpen(null)} onChanged={reload} />}
    </>
  );
}

function LeadForm({ lead, onClose, onDone }) {
  const s = useStaff();
  const staff = useApi('/staff');
  const [f, setF] = useState(lead ? { ...lead, trial_at: lead.trial_at?.slice(0, 16).replace(' ', 'T') || '', next_followup_at: lead.next_followup_at?.slice(0, 16).replace(' ', 'T') || '' } : { name: '', phone: '', email: '', source: 'walk_in', interest: '', trial_at: '', next_followup_at: '', assigned_to: '' });
  const { run, busy, fields } = useAction();
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value });
  const save = async () => {
    const body = { name: f.name, phone: f.phone, email: f.email || null, source: f.source, interest: f.interest || null, notes: f.notes || null,
      trial_at: f.trial_at ? f.trial_at.replace('T', ' ') + ':00' : null, next_followup_at: f.next_followup_at ? f.next_followup_at.replace('T', ' ') + ':00' : null,
      assigned_to: f.assigned_to ? Number(f.assigned_to) : null, branch_id: f.branch_id ? Number(f.branch_id) : null };
    if (lead) { body.stage = f.stage; body.lost_reason = f.lost_reason || null; }
    if (await run(() => (lead ? put('/leads/' + lead.id, body) : post('/leads', body)), lead ? 'Lead saved' : 'Lead added. Trial pass sent if a trial was booked.')) onDone();
  };
  return (
    <Modal title={lead ? 'Edit lead' : 'Add enquiry'} onClose={onClose} footer={<><button className="btn" onClick={onClose}>Cancel</button><button className="btn primary" disabled={busy} onClick={save}>Save</button></>}>
      <div className="grid2">
        <Input label="Name" value={f.name} onChange={set('name')} error={fields.name} />
        <Input label="Phone" type="tel" value={f.phone} onChange={set('phone')} error={fields.phone} />
        <Select label="Source" value={f.source} onChange={set('source')} options={SOURCES.map((x) => ({ value: x, label: titleCase(x) }))} />
        <Select label="Assigned to" value={f.assigned_to || ''} onChange={set('assigned_to')} placeholder="Auto-assign" options={(staff.data?.items || []).filter((u) => u.status === 'active').map((u) => ({ value: u.id, label: u.name }))} />
        <Input label="Free trial at" type="datetime-local" value={f.trial_at} onChange={set('trial_at')} />
        <Input label="Next follow-up" type="datetime-local" value={f.next_followup_at} onChange={set('next_followup_at')} />
        {lead && <Select label="Stage" value={f.stage} onChange={set('stage')} options={[...STAGES, ['lost', 'Lost']].map(([v, l]) => ({ value: v, label: l }))} />}
        {lead && f.stage === 'lost' && <Input label="Lost reason" value={f.lost_reason || ''} onChange={set('lost_reason')} error={fields.lost_reason} />}
        {s.branches.length > 1 && <Select label="Branch" value={f.branch_id || ''} placeholder="—" onChange={set('branch_id')} options={s.branches.map((b) => ({ value: b.id, label: b.name }))} />}
      </div>
      <Input label="Interested in" value={f.interest || ''} onChange={set('interest')} placeholder="e.g. weight loss, evening batch" />
      <Input as="textarea" label="Notes" value={f.notes || ''} onChange={set('notes')} />
    </Modal>
  );
}

function LeadDrawer({ id, onClose, onChanged }) {
  const s = useStaff();
  const nav = useNavigate();
  const { data: l, loading, reload } = useApi('/leads/' + id);
  const [note, setNote] = useState({ kind: 'call', note: '', next: '' });
  const [edit, setEdit] = useState(false);
  const [delOpen, setDelOpen] = useState(false);
  const { run, busy } = useAction();
  if (loading && !l) return <Modal title="Lead" onClose={onClose}><Loading /></Modal>;
  const log = async () => {
    if (await run(() => post(`/leads/${id}/activity`, { kind: note.kind, note: note.note, next_followup_at: note.next ? note.next.replace('T', ' ') + ':00' : null }), note.kind === 'whatsapp' ? 'WhatsApp sent' : 'Logged')) { setNote({ kind: 'call', note: '', next: '' }); reload(); onChanged(); }
  };
  return (
    <Modal title={l.name} wide onClose={onClose}>
      <div className="row wrap">
        <Pill tone="grey">{titleCase(l.stage)}</Pill><span className="muted">+91 {l.phone} · {titleCase(l.source)}{l.trial_pass_code ? ` · Trial pass ${l.trial_pass_code}` : ''}</span>
        <a className="btn sm" href={`tel:+91${l.phone}`}>Call</a>
        <a className="btn sm" href={`https://wa.me/91${l.phone}`} target="_blank" rel="noreferrer">Open WhatsApp</a>
        {s.can('leads.write') && <button className="btn sm" onClick={() => setEdit(true)}>Edit</button>}
        {s.can('members.write') && l.stage !== 'joined' && <button className="btn sm primary" onClick={() => nav('/admin/members/new?lead=' + l.id)}>Convert to member</button>}
        {l.member_id && <Link className="btn sm" to={'/admin/members/' + l.member_id}>Open member</Link>}
        {s.can('leads.write') && <button className="btn sm danger" onClick={() => setDelOpen(true)}>Delete</button>}
      </div>
      {l.interest && <div className="inner">{l.interest}</div>}
      {s.can('leads.write') && (
        <div className="card tight" style={{ background: 'var(--panel-2)' }}>
          <div className="seg">{[['call', 'Call'], ['note', 'Note'], ['whatsapp', 'WhatsApp'], ['visit', 'Visit']].map(([k, lbl]) => <button key={k} type="button" className={note.kind === k ? 'on' : ''} onClick={() => setNote({ ...note, kind: k })}>{lbl}</button>)}</div>
          <textarea className="input" rows={2} aria-label="Note" placeholder={note.kind === 'whatsapp' ? 'Message to send' : 'What happened?'} value={note.note} onChange={(e) => setNote({ ...note, note: e.target.value })} />
          <div className="row wrap"><Input label="Next follow-up" type="datetime-local" value={note.next} onChange={(e) => setNote({ ...note, next: e.target.value })} /><button className="btn primary" style={{ alignSelf: 'flex-end' }} disabled={busy || note.note.length < 2} onClick={log}>{note.kind === 'whatsapp' ? 'Send' : 'Log'}</button></div>
        </div>
      )}
      <h3>Timeline</h3>
      {l.activities.length === 0 ? <Empty>No activity.</Empty> : l.activities.map((a) => (
        <div key={a.id} className="listrow"><span className="dot grey" /><div className="grow col" style={{ gap: 2 }}><span className="small muted">{titleCase(a.kind)} · {a.user_name || 'System'} · {dateTime(a.created_at)}</span><span>{a.note}</span></div></div>
      ))}
      {l.messages.length > 0 && <><h3>WhatsApp conversation</h3>{l.messages.slice().reverse().map((m, i) => (
        <div key={i} className="inner small" style={{ alignSelf: m.direction === 'in' ? 'flex-start' : 'flex-end', maxWidth: '80%', background: m.direction === 'in' ? 'var(--panel-2)' : 'var(--lime-bg)' }}>{m.body}<div className="muted" style={{ fontSize: 11 }}>{dateTime(m.created_at)}</div></div>
      ))}</>}
      {edit && <LeadForm lead={l} onClose={() => setEdit(false)} onDone={() => { setEdit(false); reload(); onChanged(); }} />}
      {delOpen && <Confirm title="Delete lead?" danger confirmLabel="Delete" onClose={() => setDelOpen(false)} onConfirm={async () => { await run(() => del('/leads/' + id)); onChanged(); onClose(); }} />}
    </Modal>
  );
}
