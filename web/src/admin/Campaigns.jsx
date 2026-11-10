import { useEffect, useState } from 'react';
import { post, put, del } from '../lib/api.js';
import { useStaff } from '../lib/session.jsx';
import { dateTime, titleCase, date, today } from '../lib/format.js';
import { Check, Confirm, ErrorBox, Input, Loading, Modal, Pill, Select, Stat, Tabs, useAction, useApi, Empty } from '../components/ui.jsx';

const AUTO = [['welcome', 'Welcome message', 'When a member joins'], ['payment_receipt', 'Payment receipts', 'After every payment'], ['renewal_reminder', 'Renewal reminders', '7, 3 and 1 days before the plan ends'],
  ['dues_reminder', 'Dues reminders with UPI link', 'Every few days while dues are pending'], ['expired_winback', 'Expired win-back', '14 days after a plan ends'], ['birthday', 'Birthday wishes', 'On the member\'s birthday'],
  ['class_reminder', 'Class reminders', 'A few hours before a booked class'], ['waitlist_promoted', 'Waitlist promotion', 'When a spot opens'], ['trial_booked', 'Trial pass', 'When a free trial is booked'],
  ['lead_followup', 'Lead follow-up (AI receptionist)', 'New leads not contacted in 24 hours'], ['whatsapp_bot', 'WhatsApp AI bot', 'Answers enquiries and books trials'],
  ['review_request', 'Google review request', 'After 30 days of membership'], ['pt_low', 'PT pack running low', 'When 2 sessions are left']];

export default function Campaigns() {
  const s = useStaff();
  const [tab, setTab] = useState('overview');
  const { data, error, loading, reload } = useApi('/campaigns');
  const [compose, setCompose] = useState(false);
  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox error={error} onRetry={reload} />;
  return (
    <>
      <header className="topbar">
        <div className="col gap4"><h1>WhatsApp &amp; campaigns</h1><span className="muted">Official WhatsApp Cloud API · automations, bulk campaigns, inbox</span></div>
        {s.can('campaigns.send') && <button className="btn primary" onClick={() => setCompose(true)}>+ New campaign</button>}
      </header>
      {!data.whatsapp_connected && <div className="banner">WhatsApp is not connected, so messages are {s.demo_mode ? 'simulated (demo mode)' : 'not delivered'}. Connect it in Settings › Integrations.</div>}
      <section className="grid4">
        <Stat label="Messages this month" value={data.stats.total || 0} />
        <Stat label="Sent / delivered" value={data.stats.sent || 0} sub={`${data.stats.read_count || 0} read`} />
        <Stat tone={Number(data.stats.failed) ? 'alert' : undefined} label="Failed" value={data.stats.failed || 0} />
        <Stat label="Renewed within 7 days of a reminder" value={data.stats.renewed_after_reminder} />
      </section>
      <Tabs items={[{ value: 'overview', label: 'Automations' }, { value: 'campaigns', label: 'Campaigns' }, { value: 'inbox', label: 'Inbox' }, { value: 'log', label: 'Message log' }, { value: 'templates', label: 'Templates' }, { value: 'community', label: 'Announcements & challenges' }]} value={tab} onChange={setTab} />
      {tab === 'overview' && <Automations auto={data.automations} onSaved={reload} />}
      {tab === 'campaigns' && (
        <section className="card">{data.campaigns.length === 0 ? <Empty>No campaigns yet.</Empty> : (
          <div className="tablebox"><table className="t"><thead><tr><th>Campaign</th><th>Channel</th><th>Audience</th><th>Status</th><th className="r">Recipients</th><th className="r">Delivered</th><th>When</th><th></th></tr></thead>
            <tbody>{data.campaigns.map((c) => <tr key={c.id}><td style={{ fontWeight: 500 }}>{c.name}<br /><span className="small muted">{c.body.slice(0, 60)}</span></td><td>{titleCase(c.channel)}</td>
              <td className="small">{titleCase(JSON.parse(c.segment || '{}').status || 'all')}</td><td><Pill tone={c.status === 'sent' ? undefined : 'grey'}>{titleCase(c.status)}</Pill></td>
              <td className="r">{c.recipients}</td><td className="r">{c.delivered}</td><td className="small">{dateTime(c.scheduled_at || c.created_at)}</td>
              <td>{c.status === 'scheduled' && <CancelCampaign id={c.id} onDone={reload} />}</td></tr>)}</tbody></table></div>
        )}</section>
      )}
      {tab === 'inbox' && <MessageLog inbox />}
      {tab === 'log' && <MessageLog />}
      {tab === 'templates' && <Templates items={data.templates} onSaved={reload} />}
      {tab === 'community' && <Community />}
      {compose && <Compose onClose={() => setCompose(false)} onDone={() => { setCompose(false); setTab('campaigns'); reload(); }} />}
    </>
  );
}

function CancelCampaign({ id, onDone }) {
  const { run } = useAction();
  return <button className="btn sm danger" onClick={async () => { if (await run(() => post(`/campaigns/${id}/cancel`), 'Cancelled')) onDone(); }}>Cancel</button>;
}

function Automations({ auto, onSaved }) {
  const s = useStaff();
  const [f, setF] = useState(auto);
  const { run, busy } = useAction();
  useEffect(() => setF(auto), [auto]);
  return (
    <section className="card">
      <div className="grid2">{AUTO.map(([k, l, d]) => (
        <label key={k} className="inner row" style={{ cursor: s.can('campaigns.send') ? 'pointer' : 'default' }}>
          <input type="checkbox" style={{ width: 18, height: 18, accentColor: 'var(--lime)' }} disabled={!s.can('campaigns.send')} checked={f[k] !== false} onChange={(e) => setF({ ...f, [k]: e.target.checked })} />
          <div className="col" style={{ gap: 2 }}><span style={{ fontWeight: 600 }}>{l}</span><span className="small muted">{d}</span></div>
        </label>))}</div>
      {s.can('campaigns.send') && <button className="btn primary" style={{ alignSelf: 'flex-start' }} disabled={busy} onClick={async () => { if (await run(() => put('/automations', f), 'Automations saved')) onSaved(); }}>Save</button>}
      <span className="small muted">Messages go out in each member's language (Tamil or English). Business-initiated WhatsApp messages need approved templates: add the template name under Templates.</span>
    </section>
  );
}

function MessageLog({ inbox }) {
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const { data, loading } = useApi('/messages', { status, inbox: inbox ? 1 : '', page, limit: 50 });
  return (
    <section className="card">
      {!inbox && <select className="input" style={{ width: 160 }} aria-label="Status" value={status} onChange={(e) => setStatus(e.target.value)}><option value="">All</option><option value="queued">Queued</option><option value="sent">Sent</option><option value="failed">Failed</option></select>}
      {loading && !data ? <Loading /> : data.items.length === 0 ? <Empty>{inbox ? 'No incoming WhatsApp messages yet.' : 'No messages.'}</Empty> : (
        <div className="tablebox"><table className="t" style={{ minWidth: 700 }}><thead><tr><th>When</th><th>{inbox ? 'From' : 'To'}</th><th>Type</th><th>Message</th><th>Status</th></tr></thead>
          <tbody>{data.items.map((m) => <tr key={m.id}><td className="small" style={{ whiteSpace: 'nowrap' }}>{dateTime(m.created_at)}</td><td>{m.member_name || m.lead_name || m.to_addr}<br /><span className="small muted">{titleCase(m.channel)}</span></td>
            <td className="small">{titleCase(m.tkey || (m.direction === 'in' ? 'incoming' : ''))}</td><td className="small t2" style={{ maxWidth: 380 }}>{m.body}</td>
            <td><Pill tone={m.status === 'failed' ? 'warn' : m.status === 'queued' ? 'grey' : undefined}>{m.provider_id === 'simulated' ? 'Simulated' : titleCase(m.status)}</Pill>{m.error && <div className="small warn">{m.error}</div>}</td></tr>)}</tbody></table></div>
      )}
      {data && <div className="row" style={{ justifyContent: 'flex-end' }}><button className="btn sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>Previous</button><button className="btn sm" disabled={page * 50 >= data.total} onClick={() => setPage(page + 1)}>Next</button></div>}
    </section>
  );
}

function Templates({ items, onSaved }) {
  const s = useStaff();
  const [edit, setEdit] = useState(null);
  const { run, busy } = useAction();
  return (
    <section className="card">
      <span className="small muted">Placeholders: {'{name} {gym} {plan} {date} {amount} {invoice} {balance} {link} {code} {class} {time} {count} {offer}'}. For WhatsApp, create a matching template in Meta Business Manager and enter its name here.</span>
      <div className="tablebox"><table className="t"><thead><tr><th>Message</th><th>Lang</th><th>Text</th><th>WhatsApp template</th><th></th></tr></thead>
        <tbody>{items.map((t) => <tr key={t.id}><td style={{ fontWeight: 500 }}>{titleCase(t.tkey)}</td><td>{t.language.toUpperCase()}</td><td className="small t2" style={{ maxWidth: 420 }}>{t.body}</td><td className="small">{t.wa_template_name || '—'}</td>
          <td>{s.can('campaigns.send') && <button className="btn sm" onClick={() => setEdit({ ...t })}>Edit</button>}</td></tr>)}</tbody></table></div>
      {edit && <Modal title={`${titleCase(edit.tkey)} · ${edit.language.toUpperCase()}`} onClose={() => setEdit(null)} footer={<><button className="btn" onClick={() => setEdit(null)}>Cancel</button>
        <button className="btn primary" disabled={busy} onClick={async () => { if (await run(() => put('/templates/' + edit.id, { body: edit.body, wa_template_name: edit.wa_template_name || null, is_active: !!Number(edit.is_active) }), 'Template saved')) { setEdit(null); onSaved(); } }}>Save</button></>}>
        <textarea className="input" rows={5} aria-label="Text" value={edit.body} onChange={(e) => setEdit({ ...edit, body: e.target.value })} />
        <Input label="Approved WhatsApp template name (optional)" value={edit.wa_template_name || ''} onChange={(e) => setEdit({ ...edit, wa_template_name: e.target.value })} hint="e.g. renewal_reminder_ta" />
        <Check label="Active" checked={!!Number(edit.is_active)} onChange={(v) => setEdit({ ...edit, is_active: v ? 1 : 0 })} />
      </Modal>}
    </section>
  );
}

function Compose({ onClose, onDone }) {
  const s = useStaff();
  const plans = useApi('/plans');
  const [f, setF] = useState({ name: '', channel: 'whatsapp', body: 'Hi {name}, ', when: '', segment: { status: 'active' } });
  const [preview, setPreview] = useState(null);
  const { run, busy, fields } = useAction();
  const seg = (k, v) => setF({ ...f, segment: { ...f.segment, [k]: v || undefined } });
  useEffect(() => { const id = setTimeout(() => post('/campaigns/preview', { segment: f.segment }).then(setPreview).catch(() => setPreview(null)), 300); return () => clearTimeout(id); }, [f.segment]);
  return (
    <Modal title="New campaign" wide onClose={onClose} footer={<><button className="btn" onClick={onClose}>Cancel</button>
      <button className="btn primary" disabled={busy || !preview?.count} onClick={async () => { const r = await run(() => post('/campaigns', { name: f.name, channel: f.channel, body: f.body, segment: f.segment, scheduled_at: f.when ? f.when.replace('T', ' ') + ':00' : null })); if (r) { alert(f.when ? 'Campaign scheduled.' : `Queued to ${r.recipients} members.`); onDone(); } }}>{f.when ? 'Schedule' : `Send to ${preview?.count ?? 0}`}</button></>}>
      <div className="grid2">
        <Input label="Campaign name" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} error={fields.name} />
        <Select label="Channel" value={f.channel} onChange={(e) => setF({ ...f, channel: e.target.value })} options={[{ value: 'whatsapp', label: 'WhatsApp' }, { value: 'sms', label: 'SMS' }, { value: 'email', label: 'Email' }, { value: 'push', label: 'App notification' }]} />
        <Select label="Audience" value={f.segment.status} onChange={(e) => seg('status', e.target.value)} options={[['all', 'All members'], ['active', 'Active members'], ['expiring', 'Plan ending soon'], ['expired', 'Expired'], ['inactive', 'Active but not visiting'], ['dues', 'With dues'], ['churn', 'At churn risk'], ['birthday_month', 'Birthday this month']].map(([v, l]) => ({ value: v, label: l }))} />
        {['expiring', 'inactive'].includes(f.segment.status) && <Input label={f.segment.status === 'inactive' ? 'No visit in (days)' : 'Ending within (days)'} type="number" value={f.segment.days || ''} onChange={(e) => seg('days', Number(e.target.value))} />}
        {f.segment.status === 'expired' && <Input label="Expired within (days)" type="number" value={f.segment.expired_within_days || ''} onChange={(e) => seg('expired_within_days', Number(e.target.value))} />}
        <Select label="Plan" value={f.segment.plan_id || ''} placeholder="Any plan" onChange={(e) => seg('plan_id', Number(e.target.value))} options={(plans.data || []).map((p) => ({ value: p.id, label: p.name }))} />
        {s.branches.length > 1 && <Select label="Branch" value={f.segment.branch_id || ''} placeholder="All branches" onChange={(e) => seg('branch_id', Number(e.target.value))} options={s.branches.map((b) => ({ value: b.id, label: b.name }))} />}
        <Select label="Gender" value={f.segment.gender || ''} placeholder="Any" onChange={(e) => seg('gender', e.target.value)} options={['male', 'female', 'other']} />
        <Select label="Language" value={f.segment.language || ''} placeholder="Any" onChange={(e) => seg('language', e.target.value)} options={[{ value: 'ta', label: 'Tamil' }, { value: 'en', label: 'English' }]} />
        <Input label="Schedule (optional)" type="datetime-local" value={f.when} onChange={(e) => setF({ ...f, when: e.target.value })} />
      </div>
      <Input as="textarea" rows={4} label="Message ({name} = first name)" value={f.body} onChange={(e) => setF({ ...f, body: e.target.value })} error={fields.body} />
      {preview && <div className="inner small">{preview.count} recipients{preview.sample.length ? ': ' + preview.sample.map((x) => x.name).join(', ') + (preview.count > 5 ? '…' : '') : ''}</div>}
      {f.channel === 'whatsapp' && <span className="small muted">WhatsApp only delivers free-text to people who messaged you in the last 24 hours. For bulk sends, use an approved template (edit the template under Templates).</span>}
    </Modal>
  );
}

function Community() {
  const ann = useApi('/announcements');
  const ch = useApi('/challenges');
  const [a, setA] = useState({ title: '', body: '', visible_until: '', notify: true });
  const [c, setC] = useState(null);
  const [delA, setDelA] = useState(null);
  const { run, busy } = useAction();
  return (
    <div className="split">
      <section className="card main">
        <h2>Challenges &amp; leaderboards</h2>
        <button className="btn ghost" style={{ alignSelf: 'flex-start' }} onClick={() => setC({ name: '', metric: 'visits', target: 20, start_date: today().slice(0, 8) + '01', end_date: '', prize: '' })}>+ New challenge</button>
        {ch.data?.map((x) => (
          <div key={x.id} className="inner col gap8">
            <div className="row between"><b>{x.name}</b><button className="btn sm" onClick={() => setC(x)}>Edit</button></div>
            <span className="small muted">{date(x.start_date)} – {date(x.end_date)} · target {x.target} {x.metric} · prize: {x.prize || '—'}</span>
            {x.leaderboard.slice(0, 5).map((l, i) => <div key={l.id} className="row between small"><span>{i + 1}. {l.name}</span><span className="num">{l.score}</span></div>)}
          </div>
        ))}
      </section>
      <section className="card side">
        <h2>Announcements</h2>
        <Input label="Title" value={a.title} onChange={(e) => setA({ ...a, title: e.target.value })} />
        <Input as="textarea" label="Message" value={a.body} onChange={(e) => setA({ ...a, body: e.target.value })} />
        <Input label="Show until" type="date" value={a.visible_until} onChange={(e) => setA({ ...a, visible_until: e.target.value })} />
        <Check label="Notify active members in the app" checked={a.notify} onChange={(v) => setA({ ...a, notify: v })} />
        <button className="btn primary" disabled={busy || !a.title || !a.body} onClick={async () => { if (await run(() => post('/announcements', { ...a, visible_until: a.visible_until || null }), 'Posted')) { setA({ title: '', body: '', visible_until: '', notify: true }); ann.reload(); } }}>Post</button>
        {ann.data?.map((x) => <div key={x.id} className="inner col gap4"><div className="row between"><b>{x.title}</b><button className="btn sm danger" onClick={() => setDelA(x.id)}>Delete</button></div><span className="small">{x.body}</span><span className="small muted">{dateTime(x.created_at)}</span></div>)}
      </section>
      {c && <Modal title={c.id ? 'Edit challenge' : 'New challenge'} onClose={() => setC(null)} footer={<><button className="btn" onClick={() => setC(null)}>Cancel</button>
        <button className="btn primary" disabled={busy} onClick={async () => { const b = { name: c.name, metric: c.metric, target: Number(c.target), start_date: c.start_date, end_date: c.end_date, prize: c.prize || null }; if (await run(() => (c.id ? put('/challenges/' + c.id, b) : post('/challenges', b)), 'Saved')) { setC(null); ch.reload(); } }}>Save</button></>}>
        <div className="grid2">
          <Input label="Name" value={c.name} onChange={(e) => setC({ ...c, name: e.target.value })} />
          <Select label="Measure" value={c.metric} onChange={(e) => setC({ ...c, metric: e.target.value })} options={[{ value: 'visits', label: 'Visits (days)' }, { value: 'hours', label: 'Hours in gym' }]} />
          <Input label="Target" type="number" value={c.target} onChange={(e) => setC({ ...c, target: e.target.value })} />
          <Input label="Prize" value={c.prize || ''} onChange={(e) => setC({ ...c, prize: e.target.value })} />
          <Input label="Starts" type="date" value={c.start_date} onChange={(e) => setC({ ...c, start_date: e.target.value })} />
          <Input label="Ends" type="date" value={c.end_date} onChange={(e) => setC({ ...c, end_date: e.target.value })} />
        </div>
      </Modal>}
      {delA && <Confirm title="Delete announcement?" danger confirmLabel="Delete" onClose={() => setDelA(null)} onConfirm={async () => { await run(() => del('/announcements/' + delA)); setDelA(null); ann.reload(); }} />}
    </div>
  );
}
