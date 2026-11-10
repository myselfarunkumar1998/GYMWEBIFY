import { useEffect, useState } from 'react';
import { post, put, del, download, fileToDataUrl } from '../lib/api.js';
import { useStaff, enablePush } from '../lib/session.jsx';
import { dateTime, hm, titleCase } from '../lib/format.js';
import { Check, Confirm, ErrorBox, Input, Loading, Modal, Pill, Select, Tabs, useAction, useApi, useToast, Empty } from '../components/ui.jsx';

export default function Settings() {
  const s = useStaff();
  const { data, error, loading, reload } = useApi('/settings');
  const [tab, setTab] = useState('gym');
  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox error={error} onRetry={reload} />;
  const tabs = [{ value: 'gym', label: 'Gym profile' }, { value: 'rules', label: 'Rules' }, { value: 'branches', label: 'Branches' }];
  if (s.can('settings.manage')) tabs.push({ value: 'integrations', label: 'Integrations' }, { value: 'developer', label: 'API & webhooks' }, { value: 'audit', label: 'Audit log' }, { value: 'data', label: 'Backup' });
  tabs.push({ value: 'account', label: 'My account' });
  return (
    <>
      <header className="topbar"><div className="col gap4"><h1>Settings</h1><span className="muted">{data.gym.name} · plan: {titleCase(data.gym.subscription_plan || '')}{data.gym.subscription_until ? ` till ${data.gym.subscription_until}` : ''}</span></div></header>
      <section className="card"><h2>Your links</h2>
        <div className="grid2">
          <div className="inner col gap4"><span className="small muted">Gym website & trial booking</span><a href={data.urls.website} target="_blank" rel="noreferrer">{data.urls.website}</a></div>
          <div className="inner col gap4"><span className="small muted">Member app (share on WhatsApp)</span><a href={data.urls.member_app} target="_blank" rel="noreferrer">{data.urls.member_app}</a></div>
        </div>
      </section>
      <Tabs items={tabs} value={tab} onChange={setTab} />
      {tab === 'gym' && <GymProfile d={data} onSaved={() => { reload(); s.reload(); }} />}
      {tab === 'rules' && <Rules d={data} onSaved={reload} />}
      {tab === 'branches' && <BranchesTab d={data} onSaved={() => { reload(); s.reload(); }} />}
      {tab === 'integrations' && <Integrations d={data} onSaved={reload} />}
      {tab === 'developer' && <Developer />}
      {tab === 'audit' && <Audit />}
      {tab === 'data' && <section className="card"><h2>Backup</h2><span className="muted">Download all your gym's data as JSON (members, plans, invoices, payments, attendance, leads and more). Keep it somewhere safe.</span><button className="btn primary" style={{ alignSelf: 'flex-start' }} onClick={() => download('/backup')}>Download backup</button></section>}
      {tab === 'account' && <Account />}
    </>
  );
}

function GymProfile({ d, onSaved }) {
  const s = useStaff();
  const [f, setF] = useState({ ...d.gym });
  const { run, busy, fields } = useAction();
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value });
  const ro = !s.can('settings.manage');
  return (
    <section className="card">
      <div className="row wrap">
        <div className="logo" style={{ width: 64, height: 64, borderRadius: 18 }}>{d.gym.logo_url ? <img src={d.gym.logo_url} alt="Gym logo" /> : d.gym.name[0]}</div>
        {!ro && <label className="btn sm">Upload logo<input type="file" accept="image/png,image/jpeg,image/webp" hidden onChange={async (e) => { const file = e.target.files[0]; if (file && await run(async () => post('/settings/logo', { file: await fileToDataUrl(file) }), 'Logo updated')) onSaved(); }} /></label>}
      </div>
      <div className="grid2">
        <Input label="Gym name" value={f.name} onChange={set('name')} disabled={ro} error={fields.name} />
        <Input label="Legal name (on invoices)" value={f.legal_name || ''} onChange={set('legal_name')} disabled={ro} />
        <Input label="GSTIN" value={f.gstin || ''} onChange={(e) => setF({ ...f, gstin: e.target.value.toUpperCase() })} disabled={ro} error={fields.gstin} hint="Leave blank if not GST registered (invoices then show no tax)" />
        <Input label="State code" value={f.state_code || ''} onChange={set('state_code')} disabled={ro} hint="33 = Tamil Nadu. Set automatically from the GSTIN." />
        <Input label="Phone" value={f.phone || ''} onChange={set('phone')} disabled={ro} error={fields.phone} />
        <Input label="Email" value={f.email || ''} onChange={set('email')} disabled={ro} error={fields.email} />
        <Select label="Default language" value={f.default_language} onChange={set('default_language')} disabled={ro} options={[{ value: 'en', label: 'English' }, { value: 'ta', label: 'Tamil' }]} />
      </div>
      <Input label="Address" value={f.address || ''} onChange={set('address')} disabled={ro} />
      {!ro && <button className="btn primary" style={{ alignSelf: 'flex-start' }} disabled={busy} onClick={async () => {
        const b = { name: f.name, legal_name: f.legal_name || null, gstin: f.gstin || null, state_code: f.state_code || null, address: f.address || null, phone: f.phone || null, email: f.email || null, default_language: f.default_language };
        if (await run(() => put('/settings/gym', b), 'Saved')) onSaved();
      }}>Save</button>}
    </section>
  );
}

function Rules({ d, onSaved }) {
  const s = useStaff();
  const r = d.settings.rules || {};
  const [f, setF] = useState({ ...r, renewal_reminder_days: (r.renewal_reminder_days || []).join(', '), invoice_prefix: d.settings.invoice_prefix, prices_include_gst: !!d.settings.prices_include_gst,
    google_review_url: d.settings.google_review_url || '', waiver_text: d.settings.waiver_text || '', website_about: d.settings.website?.about || '', allowed_group: d.settings.devices?.allowed_group ?? 1, blocked_group: d.settings.devices?.blocked_group ?? 2 });
  const { run, busy, fields } = useAction();
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value });
  const ro = !s.can('settings.manage');
  const num = ['grace_days', 'joining_fee', 'freeze_min_days', 'freeze_fee', 'transfer_fee', 'referral_credit', 'block_dues_after_days', 'trial_days', 'dues_reminder_every_days', 'review_after_days', 'late_cancel_hours'];
  const save = async () => {
    const rules = Object.fromEntries(num.map((k) => [k, Number(f[k] || 0)]));
    rules.renewal_reminder_days = String(f.renewal_reminder_days).split(',').map((x) => Number(x.trim())).filter(Boolean);
    rules.winback_offer = f.winback_offer || null;
    if (await run(() => put('/settings/rules', { rules, invoice_prefix: f.invoice_prefix, prices_include_gst: f.prices_include_gst, google_review_url: f.google_review_url || null, waiver_text: f.waiver_text, website_about: f.website_about,
      devices: { allowed_group: Number(f.allowed_group), blocked_group: Number(f.blocked_group) } }), 'Rules saved')) onSaved();
  };
  return (
    <section className="card">
      <div className="grid3">
        <Input label="Joining fee (₹)" type="number" value={f.joining_fee} onChange={set('joining_fee')} disabled={ro} />
        <Input label="Grace days after expiry" type="number" value={f.grace_days} onChange={set('grace_days')} disabled={ro} />
        <Input label="Block door when dues older than (days, 0 = never)" type="number" value={f.block_dues_after_days} onChange={set('block_dues_after_days')} disabled={ro} />
        <Input label="Minimum freeze (days)" type="number" value={f.freeze_min_days} onChange={set('freeze_min_days')} disabled={ro} />
        <Input label="Freeze fee (₹)" type="number" value={f.freeze_fee} onChange={set('freeze_fee')} disabled={ro} />
        <Input label="Transfer fee (₹)" type="number" value={f.transfer_fee} onChange={set('transfer_fee')} disabled={ro} />
        <Input label="Referral credit each (₹)" type="number" value={f.referral_credit} onChange={set('referral_credit')} disabled={ro} />
        <Input label="Free trial days" type="number" value={f.trial_days} onChange={set('trial_days')} disabled={ro} />
        <Input label="Renewal reminders (days before)" value={f.renewal_reminder_days} onChange={set('renewal_reminder_days')} disabled={ro} hint="e.g. 7, 3, 1" />
        <Input label="Dues reminder every (days)" type="number" value={f.dues_reminder_every_days} onChange={set('dues_reminder_every_days')} disabled={ro} />
        <Input label="Ask for Google review after (days)" type="number" value={f.review_after_days} onChange={set('review_after_days')} disabled={ro} />
        <Input label="Late cancel window (hours)" type="number" value={f.late_cancel_hours} onChange={set('late_cancel_hours')} disabled={ro} />
        <Input label="Invoice prefix" value={f.invoice_prefix} onChange={set('invoice_prefix')} disabled={ro} hint="Numbers look like INV/2026-27/0001" />
        <Input label="Win-back offer text" value={f.winback_offer || ''} onChange={set('winback_offer')} disabled={ro} placeholder="no joining fee" />
        <Input label="Google review link" value={f.google_review_url} onChange={set('google_review_url')} disabled={ro} error={fields.google_review_url} />
        <Input label="Device group: allowed" type="number" value={f.allowed_group} onChange={set('allowed_group')} disabled={ro} />
        <Input label="Device group: blocked" type="number" value={f.blocked_group} onChange={set('blocked_group')} disabled={ro} />
      </div>
      <Check label="Plan and product prices include GST" checked={f.prices_include_gst} onChange={(v) => !ro && setF({ ...f, prices_include_gst: v })} />
      <Input as="textarea" rows={4} label="Waiver / consent text" value={f.waiver_text} onChange={set('waiver_text')} disabled={ro} />
      <Input as="textarea" rows={3} label="About the gym (website)" value={f.website_about} onChange={set('website_about')} disabled={ro} />
      {!ro && <button className="btn primary" style={{ alignSelf: 'flex-start' }} disabled={busy} onClick={save}>Save rules</button>}
    </section>
  );
}

function BranchesTab({ d, onSaved }) {
  const s = useStaff();
  const [edit, setEdit] = useState(null);
  const { run, busy } = useAction();
  return (
    <section className="card">
      {s.can('branches.manage') && <button className="btn ghost" style={{ alignSelf: 'flex-start' }} onClick={() => setEdit({ name: '', address: '', phone: '', open_time: '05:00', close_time: '22:00', staffed_from: '05:00', staffed_to: '22:00', capacity: 80, is_active: true })}>+ Add branch</button>}
      <table className="t"><thead><tr><th>Branch</th><th>Hours</th><th>Staffed</th><th>Capacity</th><th></th></tr></thead>
        <tbody>{d.branches.map((b) => <tr key={b.id} style={{ opacity: b.is_active ? 1 : 0.5 }}><td style={{ fontWeight: 500 }}>{b.name}<br /><span className="small muted">{b.address}</span></td><td>{hm(b.open_time)} – {hm(b.close_time)}</td><td>{hm(b.staffed_from)} – {hm(b.staffed_to)}</td><td>{b.capacity}</td>
          <td>{s.can('branches.manage') && <button className="btn sm" onClick={() => setEdit({ ...b, open_time: b.open_time.slice(0, 5), close_time: b.close_time.slice(0, 5), staffed_from: b.staffed_from.slice(0, 5), staffed_to: b.staffed_to.slice(0, 5), is_active: !!b.is_active })}>Edit</button>}</td></tr>)}</tbody></table>
      <span className="small muted">Open sessions close automatically at closing time. Entries outside staffed hours alert the owner (24/7 unstaffed access).</span>
      {edit && <Modal title={edit.id ? 'Edit branch' : 'Add branch'} onClose={() => setEdit(null)} footer={<><button className="btn" onClick={() => setEdit(null)}>Cancel</button>
        <button className="btn primary" disabled={busy} onClick={async () => { const b = { name: edit.name, address: edit.address || null, phone: edit.phone || null, open_time: edit.open_time, close_time: edit.close_time, staffed_from: edit.staffed_from, staffed_to: edit.staffed_to, capacity: Number(edit.capacity), is_active: !!edit.is_active }; if (await run(() => (edit.id ? put('/branches/' + edit.id, b) : post('/branches', b)), 'Saved')) { setEdit(null); onSaved(); } }}>Save</button></>}>
        <div className="grid2">
          {[['name', 'Name'], ['phone', 'Phone'], ['capacity', 'Capacity', 'number'], ['open_time', 'Opens', 'time'], ['close_time', 'Closes (auto-close)', 'time'], ['staffed_from', 'Staffed from', 'time'], ['staffed_to', 'Staffed until', 'time']].map(([k, l, t]) => <Input key={k} label={l} type={t || 'text'} value={edit[k] || ''} onChange={(e) => setEdit({ ...edit, [k]: e.target.value })} />)}
        </div>
        <Input label="Address" value={edit.address || ''} onChange={(e) => setEdit({ ...edit, address: e.target.value })} />
        <Check label="Active" checked={edit.is_active} onChange={(v) => setEdit({ ...edit, is_active: v })} />
      </Modal>}
    </section>
  );
}

const INTEG = [
  ['WhatsApp Cloud API (Meta)', 'whatsapp', [['whatsapp_phone_id', 'Phone number ID'], ['whatsapp_token', 'Permanent access token', true], ['whatsapp_app_secret', 'App secret (webhook signature)', true], ['whatsapp_verify_token', 'Webhook verify token']], 'whatsapp_webhook'],
  ['Razorpay (UPI, cards, AutoPay)', 'razorpay', [['razorpay_key_id', 'Key ID'], ['razorpay_key_secret', 'Key secret', true], ['razorpay_webhook_secret', 'Webhook secret', true]], 'razorpay_webhook'],
  ['SMS gateway', null, [['sms_url', 'Send URL with {to} {msg} {key}'], ['sms_key', 'API key', true]]],
  ['AI assistant (Claude)', 'ai', [['anthropic_key', 'Anthropic API key', true]]],
  ['Meta lead ads (Facebook / Instagram)', null, [['meta_verify_token', 'Webhook verify token'], ['meta_page_token', 'Page access token', true]], 'meta_leads_webhook'],
  ['Google Ads lead forms', null, [['google_lead_key', 'Webhook key', true]], 'google_leads_webhook'],
  ['Strava (member wearables)', null, [['strava_client_id', 'Client ID'], ['strava_client_secret', 'Client secret', true]], 'strava_callback'],
];

function Integrations({ d, onSaved }) {
  const [f, setF] = useState({ ...d.integrations });
  const { run, busy } = useAction();
  const [test, setTest] = useState({});
  useEffect(() => setF({ ...d.integrations }), [d]);
  return (
    <div className="col gap16">
      <span className="muted">Secret keys are encrypted before they are stored and are never shown again in full.</span>
      {INTEG.map(([title, kind, keys, url]) => (
        <section key={title} className="card">
          <div className="row between wrap"><h2>{title}</h2>
            {kind && <span className="row gap8">{d.status[kind] ? <Pill>Connected</Pill> : <Pill tone="grey">Not connected</Pill>}<button className="btn sm" onClick={async () => { const r = await run(() => post('/settings/integrations/test/' + kind)); if (r) setTest({ ...test, [kind]: r }); }}>Test</button></span>}</div>
          <div className="grid2">{keys.map(([k, l, secret]) => <Input key={k} label={l} type={secret ? 'password' : 'text'} autoComplete="off" value={f[k] || ''} onChange={(e) => setF({ ...f, [k]: e.target.value })} />)}</div>
          {url && <span className="small muted">{url.includes('callback') ? 'Callback URL' : 'Webhook URL'}: <span className="lime">{d.urls[url]}</span></span>}
          {test[kind] && <div className={'banner' + (test[kind].ok ? ' info' : '')}>{test[kind].message}</div>}
        </section>
      ))}
      <button className="btn primary" style={{ alignSelf: 'flex-start' }} disabled={busy} onClick={async () => {
        const body = {};
        INTEG.forEach(([, , keys]) => keys.forEach(([k]) => { if ((f[k] || '') !== (d.integrations[k] || '')) body[k] = f[k] || null; }));
        if (await run(() => put('/settings/integrations', body), 'Integrations saved')) onSaved();
      }}>Save integrations</button>
    </div>
  );
}

function Developer() {
  const { data, reload } = useApi('/settings/developer');
  const [name, setName] = useState('');
  const [hook, setHook] = useState({ url: '', events: ['payment.received'] });
  const [shown, setShown] = useState(null);
  const [delKey, setDelKey] = useState(null);
  const { run, busy, fields } = useAction();
  if (!data) return <Loading />;
  return (
    <div className="grid2">
      <section className="card">
        <h2>API keys</h2>
        <span className="small muted">Use as "Authorization: Bearer KEY" on /api/members, /api/invoices, /api/leads and /api/sessions/checkin.</span>
        <div className="row"><Input label="Key name" value={name} onChange={(e) => setName(e.target.value)} className="grow" /><button className="btn" style={{ alignSelf: 'flex-end' }} disabled={busy || !name} onClick={async () => { const r = await run(() => post('/api-keys', { name })); if (r) { setShown(r.key); setName(''); reload(); } }}>Create</button></div>
        {shown && <div className="banner info" style={{ wordBreak: 'break-all' }}>Copy now, it will not be shown again: <b>{shown}</b></div>}
        {data.keys.map((k) => <div key={k.id} className="listrow"><span className="grow">{k.name} <span className="small muted">{k.key_prefix}… · last used {k.last_used_at ? dateTime(k.last_used_at) : 'never'}</span></span><button className="btn sm danger" onClick={() => setDelKey(k.id)}>Revoke</button></div>)}
      </section>
      <section className="card">
        <h2>Webhooks</h2>
        <Input label="HTTPS URL" value={hook.url} onChange={(e) => setHook({ ...hook, url: e.target.value })} error={fields.url} />
        <div className="chips">{data.events.map((ev) => <button key={ev} type="button" className={'chip' + (hook.events.includes(ev) ? ' on' : '')} onClick={() => setHook({ ...hook, events: hook.events.includes(ev) ? hook.events.filter((x) => x !== ev) : [...hook.events, ev] })}>{ev}</button>)}</div>
        <button className="btn" style={{ alignSelf: 'flex-start' }} disabled={busy} onClick={async () => { const r = await run(() => post('/webhooks', hook)); if (r) { setShown('Signing secret: ' + r.secret); setHook({ url: '', events: ['payment.received'] }); reload(); } }}>Add webhook</button>
        {data.webhooks.length === 0 && <Empty>No webhooks.</Empty>}
        {data.webhooks.map((w) => <div key={w.id} className="listrow"><span className="grow small">{w.url}<br /><span className="muted">{w.events}{Number(w.failed) ? ` · ${w.failed} failed` : ''}</span></span><button className="btn sm danger" onClick={async () => { await run(() => del('/webhooks/' + w.id)); reload(); }}>Delete</button></div>)}
      </section>
      {delKey && <Confirm title="Revoke API key?" danger confirmLabel="Revoke" onClose={() => setDelKey(null)} onConfirm={async () => { await run(() => del('/api-keys/' + delKey)); setDelKey(null); reload(); }} />}
    </div>
  );
}

function Audit() {
  const [page, setPage] = useState(1);
  const { data } = useApi('/audit', { page, limit: 50 });
  if (!data) return <Loading />;
  return (
    <section className="card">
      <div className="tablebox"><table className="t"><thead><tr><th>When</th><th>Who</th><th>Action</th><th>Record</th><th>Details</th><th>IP</th></tr></thead>
        <tbody>{data.items.map((a) => <tr key={a.id}><td className="small" style={{ whiteSpace: 'nowrap' }}>{dateTime(a.created_at)}</td><td>{a.actor_name || titleCase(a.actor_type)}</td><td>{a.action}</td><td className="small">{a.entity ? `${a.entity} #${a.entity_id}` : '—'}</td><td className="small muted" style={{ maxWidth: 280, wordBreak: 'break-word' }}>{a.details}</td><td className="small muted">{a.ip}</td></tr>)}</tbody></table></div>
      <div className="row" style={{ justifyContent: 'flex-end' }}><button className="btn sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>Previous</button><button className="btn sm" disabled={page * 50 >= data.total} onClick={() => setPage(page + 1)}>Next</button></div>
    </section>
  );
}

function Account() {
  const s = useStaff();
  const toast = useToast();
  const [f, setF] = useState({ current: '', password: '', password2: '' });
  const { run, busy, fields } = useAction();
  return (
    <div className="grid2">
      <section className="card">
        <h2>Change password</h2>
        <Input label="Current password" type="password" autoComplete="current-password" value={f.current} onChange={(e) => setF({ ...f, current: e.target.value })} error={fields.current} />
        <Input label="New password" type="password" autoComplete="new-password" value={f.password} onChange={(e) => setF({ ...f, password: e.target.value })} error={fields.password} hint="At least 10 characters with letters and numbers" />
        <Input label="Repeat" type="password" autoComplete="new-password" value={f.password2} onChange={(e) => setF({ ...f, password2: e.target.value })} />
        <button className="btn primary" style={{ alignSelf: 'flex-start' }} disabled={busy || f.password !== f.password2 || !f.password} onClick={async () => { if (await run(() => post('/auth/password', { current: f.current, password: f.password }), 'Password changed. Other devices were signed out.')) setF({ current: '', password: '', password2: '' }); }}>Change password</button>
      </section>
      <section className="card">
        <h2>Alerts on this device</h2>
        <span className="muted">Get push alerts for unstaffed entries, new ad leads and churn risk, even when the app is closed.</span>
        <button className="btn" style={{ alignSelf: 'flex-start' }} onClick={() => enablePush(s.vapid_public).then(() => toast('Push alerts turned on')).catch((e) => toast(e.message, 'err'))}>Turn on push alerts</button>
        {!s.vapid_public && <span className="small warn">Push keys are not configured on the server.</span>}
      </section>
    </div>
  );
}
