import { useState } from 'react';
import { Link } from 'react-router-dom';
import { post, put, del } from '../lib/api.js';
import { money, date, hm } from '../lib/format.js';
import { Check, ErrorBox, Input, Loading, Modal, Pill, Select, useAction, useApi } from '../components/ui.jsx';

const BLANK = { name: '', kind: 'membership', duration_days: 30, price: '', sessions_count: 0, freeze_days_allowed: 0, access_start: '', access_end: '', all_branches: false, gst_rate: 18, description: '', is_active: true };

export default function Plans() {
  const plans = useApi('/plans');
  const coupons = useApi('/coupons');
  const gifts = useApi('/gift-cards');
  const settings = useApi('/settings');
  const [edit, setEdit] = useState(null);
  const [coupon, setCoupon] = useState(null);
  const [gift, setGift] = useState(false);
  const r = settings.data?.settings?.rules || {};
  if (plans.loading && !plans.data) return <Loading />;
  if (plans.error) return <ErrorBox error={plans.error} onRetry={plans.reload} />;
  return (
    <>
      <Link to="/admin/members" className="small" style={{ textDecoration: 'none' }}>← All members</Link>
      <header className="topbar">
        <div className="col gap4"><h1>Plans &amp; packages</h1><span className="muted">{plans.data.filter((p) => p.is_active).length} plans on sale · prices {settings.data?.settings?.prices_include_gst ? 'include' : 'exclude'} GST</span></div>
        <button className="btn primary" onClick={() => setEdit(BLANK)}>+ New plan</button>
      </header>
      <div className="split">
        <section className="card main" aria-label="Plans" style={{ paddingTop: 8 }}>
          <div className="tablebox"><table className="t" style={{ minWidth: 640 }}>
            <thead><tr><th>Plan</th><th>Price</th><th>Access</th><th>Freeze</th><th>Members</th><th><span className="sr">Edit</span></th></tr></thead>
            <tbody>{plans.data.map((p) => (
              <tr key={p.id} style={{ opacity: p.is_active ? 1 : 0.5 }}>
                <td><div className="col" style={{ gap: 2 }}><span style={{ fontWeight: 600 }}>{p.name} {p.kind === 'pt' && <Pill tone="grey">PT</Pill>} {!p.is_active && <Pill tone="grey">Archived</Pill>}</span><span className="small muted">{p.duration_days} days{p.sessions_count ? ` · ${p.sessions_count} sessions` : ''}{p.max_members > 1 ? ` · ${p.max_members} people` : ''}</span></div></td>
                <td className="num" style={{ fontSize: 16, fontWeight: 600 }}>{money(p.price)}</td>
                <td className="t2">{p.access_start ? `${hm(p.access_start)} – ${hm(p.access_end)} only` : 'All hours'}{p.all_branches ? ', all branches' : ''}</td>
                <td className="t2">{p.freeze_days_allowed ? `${p.freeze_days_allowed} days` : 'None'}</td>
                <td className="num">{p.active_members}</td>
                <td className="r"><button className="btn sm" aria-label={`Edit ${p.name}`} onClick={() => setEdit({ ...p, access_start: p.access_start?.slice(0, 5) || '', access_end: p.access_end?.slice(0, 5) || '' })}>Edit</button></td>
              </tr>))}</tbody>
          </table></div>
        </section>
        <div className="side">
          <section className="card">
            <h2>Membership rules</h2>
            {[['Joining fee', money(r.joining_fee) + ', once'], ['Grace after expiry', `${r.grace_days} days, then door blocks`], ['Freeze', `Min ${r.freeze_min_days} days · ${money(r.freeze_fee)} fee`],
              ['Transfer to another person', money(r.transfer_fee)], ['Upgrade', 'Pay the difference'], ['Block for unpaid dues', r.block_dues_after_days ? `After ${r.block_dues_after_days} days` : 'Off'],
              ['Referral credit', money(r.referral_credit) + ' each']].map(([k, v]) => <div key={k} className="row between"><span className="muted">{k}</span><span style={{ fontWeight: 600 }}>{v}</span></div>)}
            <Link className="btn" to="/admin/settings">Edit rules</Link>
          </section>
          <section className="card">
            <div className="row between"><h2>Coupons</h2><button className="btn sm ghost" onClick={() => setCoupon({ code: '', kind: 'percent', value: 10, valid_from: '', valid_to: '', max_uses: '', is_active: true })}>+ New</button></div>
            {coupons.data?.length === 0 && <span className="small muted">No coupons.</span>}
            {coupons.data?.map((c) => (
              <button key={c.id} className="inner col gap4" style={{ textAlign: 'left', border: 0, cursor: 'pointer', opacity: c.is_active ? 1 : 0.5 }} onClick={() => setCoupon(c)}>
                <span style={{ fontWeight: 600 }}>{c.code} · {c.kind === 'percent' ? `${+c.value}% off` : `${money(c.value)} off`}</span>
                <span className="small muted">Used {c.used_count}{c.max_uses ? ` of ${c.max_uses}` : ''}{c.valid_to ? ` · valid till ${date(c.valid_to)}` : ''}</span>
              </button>))}
          </section>
          <section className="card">
            <div className="row between"><h2>Gift cards</h2><button className="btn sm ghost" onClick={() => setGift(true)}>+ Sell</button></div>
            {gifts.data?.slice(0, 5).map((g) => <div key={g.id} className="row between small"><span>{g.code} · {g.buyer_name || '—'}</span><span className="num">{money(g.balance)} / {money(g.amount)}</span></div>)}
            {gifts.data?.length === 0 && <span className="small muted">None sold yet. Members redeem them at billing.</span>}
          </section>
        </div>
      </div>
      {edit && <PlanModal plan={edit} onClose={() => setEdit(null)} onDone={() => { setEdit(null); plans.reload(); }} />}
      {coupon && <CouponModal c={coupon} onClose={() => setCoupon(null)} onDone={() => { setCoupon(null); coupons.reload(); }} />}
      {gift && <GiftModal onClose={() => setGift(false)} onDone={() => { setGift(false); gifts.reload(); }} />}
    </>
  );
}

function PlanModal({ plan, onClose, onDone }) {
  const [f, setF] = useState(plan);
  const { run, busy, fields } = useAction();
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value });
  const save = async () => {
    const body = { ...f, price: Number(f.price), duration_days: Number(f.duration_days), sessions_count: Number(f.sessions_count || 0), freeze_days_allowed: Number(f.freeze_days_allowed || 0),
      gst_rate: Number(f.gst_rate), access_start: f.access_start || null, access_end: f.access_end || null, all_branches: !!Number(f.all_branches) || f.all_branches === true, is_active: !!f.is_active };
    ['id', 'gym_id', 'created_at', 'active_members', 'max_members', 'sac_code'].forEach((k) => delete body[k]);
    if (await run(() => (plan.id ? put('/plans/' + plan.id, body) : post('/plans', body)), 'Plan saved')) onDone();
  };
  return (
    <Modal title={plan.id ? 'Edit plan' : 'New plan'} onClose={onClose} footer={<>
      {plan.id && <button className="btn danger" onClick={async () => { const r = await run(() => del('/plans/' + plan.id)); if (r) onDone(); }}>{plan.active_members > 0 ? 'Archive' : 'Delete'}</button>}
      <button className="btn" onClick={onClose}>Cancel</button><button className="btn primary" disabled={busy} onClick={save}>Save</button></>}>
      <div className="grid2">
        <Input label="Name" value={f.name} onChange={set('name')} error={fields.name} />
        <Select label="Type" value={f.kind} onChange={set('kind')} options={[{ value: 'membership', label: 'Membership' }, { value: 'pt', label: 'PT pack' }, { value: 'addon', label: 'Add-on' }]} />
        <Input label="Price (₹)" type="number" min="0" value={f.price} onChange={set('price')} error={fields.price} />
        <Input label="Duration (days)" type="number" min="1" value={f.duration_days} onChange={set('duration_days')} error={fields.duration_days} />
        {f.kind === 'pt' && <Input label="Sessions" type="number" min="0" value={f.sessions_count} onChange={set('sessions_count')} />}
        <Input label="Freeze days allowed" type="number" min="0" value={f.freeze_days_allowed} onChange={set('freeze_days_allowed')} />
        <Select label="GST rate" value={f.gst_rate} onChange={set('gst_rate')} options={[{ value: 18, label: '18%' }, { value: 12, label: '12%' }, { value: 5, label: '5%' }, { value: 0, label: '0%' }]} />
        <Input label="Access from (off-peak)" type="time" value={f.access_start} onChange={set('access_start')} hint="Blank = all hours" />
        <Input label="Access until" type="time" value={f.access_end} onChange={set('access_end')} />
      </div>
      <Input label="Description" value={f.description || ''} onChange={set('description')} />
      <Check label="Valid at all branches" checked={!!Number(f.all_branches) || f.all_branches === true} onChange={(v) => setF({ ...f, all_branches: v })} />
      <Check label="On sale" checked={!!Number(f.is_active) || f.is_active === true} onChange={(v) => setF({ ...f, is_active: v })} />
    </Modal>
  );
}

function CouponModal({ c, onClose, onDone }) {
  const [f, setF] = useState({ ...c, valid_from: c.valid_from || '', valid_to: c.valid_to || '', max_uses: c.max_uses || '' });
  const { run, busy, fields } = useAction();
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value });
  const save = async () => {
    const body = { code: f.code, kind: f.kind, value: Number(f.value), valid_from: f.valid_from || null, valid_to: f.valid_to || null, max_uses: f.max_uses ? Number(f.max_uses) : null, is_active: !!Number(f.is_active) || f.is_active === true };
    if (await run(() => (c.id ? put('/coupons/' + c.id, body) : post('/coupons', body)), 'Coupon saved')) onDone();
  };
  return (
    <Modal title={c.id ? 'Edit coupon' : 'New coupon'} onClose={onClose} footer={<><button className="btn" onClick={onClose}>Cancel</button><button className="btn primary" disabled={busy} onClick={save}>Save</button></>}>
      <div className="grid2">
        <Input label="Code" value={f.code} onChange={(e) => setF({ ...f, code: e.target.value.toUpperCase() })} error={fields.code} />
        <Select label="Type" value={f.kind} onChange={set('kind')} options={[{ value: 'percent', label: 'Percent off' }, { value: 'flat', label: '₹ off' }]} />
        <Input label="Value" type="number" min="0" value={f.value} onChange={set('value')} error={fields.value} />
        <Input label="Max uses" type="number" min="1" value={f.max_uses} onChange={set('max_uses')} hint="Blank = unlimited" />
        <Input label="Valid from" type="date" value={f.valid_from} onChange={set('valid_from')} />
        <Input label="Valid to" type="date" value={f.valid_to} onChange={set('valid_to')} />
      </div>
      <Check label="Active" checked={!!Number(f.is_active) || f.is_active === true} onChange={(v) => setF({ ...f, is_active: v })} />
    </Modal>
  );
}

function GiftModal({ onClose, onDone }) {
  const [f, setF] = useState({ amount: 2000, buyer_name: '', pay_mode: 'upi' });
  const { run, busy } = useAction();
  return (
    <Modal title="Sell a gift card" onClose={onClose} footer={<><button className="btn" onClick={onClose}>Cancel</button>
      <button className="btn primary" disabled={busy} onClick={async () => { const r = await run(() => post('/gift-cards', { ...f, amount: Number(f.amount) })); if (r) { alert('Gift card code: ' + r.code); onDone(); } }}>Sell</button></>}>
      <div className="grid2">
        <Input label="Amount (₹)" type="number" min="1" value={f.amount} onChange={(e) => setF({ ...f, amount: e.target.value })} />
        <Input label="Buyer name" value={f.buyer_name} onChange={(e) => setF({ ...f, buyer_name: e.target.value })} />
      </div>
      <div className="seg">{['upi', 'cash', 'card'].map((m) => <button type="button" key={m} className={f.pay_mode === m ? 'on' : ''} onClick={() => setF({ ...f, pay_mode: m })}>{m.toUpperCase()}</button>)}</div>
    </Modal>
  );
}
