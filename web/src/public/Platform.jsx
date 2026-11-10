import { useState } from 'react';
import { post, put } from '../lib/api.js';
import { useStaff } from '../lib/session.jsx';
import { date, dateTime, titleCase } from '../lib/format.js';
import { Input, Loading, Modal, Pill, Select, useAction, useApi } from '../components/ui.jsx';

/** Software-owner console: create gym accounts, set subscription, suspend. */
export default function Platform() {
  const s = useStaff();
  const { data, reload } = useApi('/platform/gyms');
  const [add, setAdd] = useState(false);
  const { run } = useAction();
  return (
    <div className="content" style={{ margin: '0 auto' }}>
      <header className="topbar"><div className="col gap4"><h1>Platform console</h1><span className="muted">{data?.length ?? '…'} gyms · signed in as {s.user.email}</span></div>
        <div className="row"><button className="btn primary" onClick={() => setAdd(true)}>+ New gym</button><button className="btn" onClick={s.logout}>Sign out</button></div></header>
      {!data ? <Loading /> : (
        <section className="card"><div className="tablebox"><table className="t" style={{ minWidth: 900 }}>
          <thead><tr><th>Gym</th><th>Owner</th><th>Members</th><th>Branches</th><th>Last check-in</th><th>Plan</th><th>Paid till</th><th>Status</th><th></th></tr></thead>
          <tbody>{data.map((g) => (
            <tr key={g.id}><td style={{ fontWeight: 600 }}>{g.name}<br /><a className="small" href={`/g/${g.slug}`} target="_blank" rel="noreferrer">/g/{g.slug}</a></td><td className="small">{g.owner_email}</td><td>{g.members}</td><td>{g.branches}</td>
              <td className="small">{g.last_checkin ? dateTime(g.last_checkin) : '—'}</td><td>{titleCase(g.subscription_plan || '')}</td>
              <td className={g.subscription_until && g.subscription_until < new Date().toISOString().slice(0, 10) ? 'warn' : ''}>{date(g.subscription_until)}</td>
              <td><Pill tone={g.status === 'active' ? undefined : 'warn'}>{titleCase(g.status)}</Pill></td>
              <td className="r" style={{ whiteSpace: 'nowrap' }}>
                <button className="btn sm" onClick={async () => { const until = window.prompt('Paid till (YYYY-MM-DD)', g.subscription_until || ''); if (until) { await run(() => put('/platform/gyms/' + g.id, { subscription_until: until }), 'Updated'); reload(); } }}>Extend</button>{' '}
                <button className={'btn sm ' + (g.status === 'suspended' ? 'ghost' : 'danger')} onClick={async () => { await run(() => put('/platform/gyms/' + g.id, { status: g.status === 'suspended' ? 'active' : 'suspended' }), 'Updated'); reload(); }}>{g.status === 'suspended' ? 'Reactivate' : 'Suspend'}</button>
              </td></tr>))}</tbody>
        </table></div></section>
      )}
      {add && <NewGym onClose={() => setAdd(false)} onDone={() => { setAdd(false); reload(); }} />}
    </div>
  );
}

function NewGym({ onClose, onDone }) {
  const [f, setF] = useState({ name: '', phone: '', address: '', gstin: '', owner_name: '', owner_email: '', owner_password: '', subscription_plan: 'standard', subscription_until: '', default_language: 'en' });
  const { run, busy, fields } = useAction();
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value });
  return (
    <Modal title="New gym account" onClose={onClose} footer={<><button className="btn" onClick={onClose}>Cancel</button>
      <button className="btn primary" disabled={busy} onClick={async () => { const b = Object.fromEntries(Object.entries(f).map(([k, v]) => [k, v === '' ? null : v])); const r = await run(() => post('/platform/gyms', b)); if (r) { alert(`Created. Owner signs in at /login with ${f.owner_email}. Website: /g/${r.slug}`); onDone(); } }}>Create</button></>}>
      <div className="grid2">
        <Input label="Gym name" value={f.name} onChange={set('name')} error={fields.name} />
        <Input label="Phone" value={f.phone} onChange={set('phone')} error={fields.phone} />
        <Input label="GSTIN (optional)" value={f.gstin} onChange={(e) => setF({ ...f, gstin: e.target.value.toUpperCase() })} error={fields.gstin} />
        <Select label="Language" value={f.default_language} onChange={set('default_language')} options={[{ value: 'en', label: 'English' }, { value: 'ta', label: 'Tamil' }]} />
        <Input label="Owner name" value={f.owner_name} onChange={set('owner_name')} error={fields.owner_name} />
        <Input label="Owner email" type="email" value={f.owner_email} onChange={set('owner_email')} error={fields.owner_email} />
        <Input label="Owner password" type="password" value={f.owner_password} onChange={set('owner_password')} error={fields.owner_password} />
        <Select label="Subscription" value={f.subscription_plan} onChange={set('subscription_plan')} options={[{ value: 'starter', label: 'Starter' }, { value: 'standard', label: 'Standard' }, { value: 'multi_branch', label: 'Multi-branch' }]} />
        <Input label="Paid till" type="date" value={f.subscription_until} onChange={set('subscription_until')} />
      </div>
      <Input label="Address" value={f.address} onChange={set('address')} />
    </Modal>
  );
}
