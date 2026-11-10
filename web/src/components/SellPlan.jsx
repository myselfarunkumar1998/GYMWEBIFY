import { useMemo, useState } from 'react';
import { post } from '../lib/api.js';
import { money, today } from '../lib/format.js';
import { Check, Input, Modal, Select, useAction, useApi } from './ui.jsx';
import { useStaff } from '../lib/session.jsx';

/** Bill summary with GST, same maths as the server (prices exclude GST unless the gym says otherwise). */
export function billLines(plan, { joiningFee = 0, discount = 0, inclusive = false }) {
  if (!plan) return null;
  const gst = Number(plan.gst_rate ?? 18) / 100;
  const base = inclusive ? Number(plan.price) / (1 + gst) : Number(plan.price);
  const jf = inclusive ? joiningFee / 1.18 : joiningFee;
  const sub = base + jf;
  const disc = Math.min(sub, Number(discount) || 0);
  const taxable = sub - disc;
  const tax = (base - disc * (base / sub)) * gst + (jf - disc * (jf / sub)) * 0.18;
  return { base, jf, sub, disc, taxable, cgst: tax / 2, sgst: tax / 2, total: Math.round((taxable + tax) * 100) / 100 };
}

export default function SellPlan({ member, onClose, onDone, title = 'Renew plan' }) {
  const plans = useApi('/plans', { active: 1 });
  const rules = useStaff()?.rules || {};
  const [f, setF] = useState({ plan_id: '', start_date: '', discount: '', coupon: '', pay_amount: '', pay_mode: 'upi', pay_reference: '', autopay: false });
  const { run, busy, fields } = useAction();
  const plan = plans.data?.find((p) => String(p.id) === String(f.plan_id));
  const first = !member.memberships?.some((m) => m.kind === 'membership' && m.status !== 'cancelled');
  const joiningFee = plan?.kind === 'membership' && first ? Number(rules.joining_fee || 0) : 0;
  const bill = useMemo(() => billLines(plan, { joiningFee, discount: f.discount, inclusive: rules.prices_include_gst }), [plan, joiningFee, f.discount, rules.prices_include_gst]);

  const submit = async () => {
    const body = { plan_id: Number(f.plan_id), start_date: f.start_date || null, discount: f.discount ? Number(f.discount) : 0, coupon: f.coupon || null, autopay: f.autopay,
      pay_amount: f.pay_amount === '' ? (bill?.total ?? 0) : Number(f.pay_amount), pay_mode: f.pay_mode, pay_reference: f.pay_reference || null };
    const r = await run(() => post(`/members/${member.id}/memberships`, body), 'Plan added. Invoice sent on WhatsApp.');
    if (r) onDone(r);
  };

  return (
    <Modal title={title} onClose={onClose}>
      <Select label="Plan" value={f.plan_id} onChange={(e) => setF({ ...f, plan_id: e.target.value })} placeholder="Choose a plan" error={fields.plan_id}
        options={(plans.data || []).map((p) => ({ value: p.id, label: `${p.name} · ${money(p.price)}${p.kind === 'pt' ? ' (PT)' : ''}` }))} />
      <div className="grid2">
        <Input label="Start date" type="date" min={today()} value={f.start_date} onChange={(e) => setF({ ...f, start_date: e.target.value })} hint="Blank = day after current plan ends" />
        <Input label="Discount (₹)" type="number" min="0" value={f.discount} onChange={(e) => setF({ ...f, discount: e.target.value })} />
      </div>
      <Input label="Coupon" value={f.coupon} onChange={(e) => setF({ ...f, coupon: e.target.value.toUpperCase() })} error={fields.coupon} />
      {bill && (
        <div className="inner col gap8">
          <div className="row between"><span className="muted">{plan.name}</span><span>{money(bill.base, true)}</span></div>
          {bill.jf > 0 && <div className="row between"><span className="muted">Joining fee</span><span>{money(bill.jf, true)}</span></div>}
          {bill.disc > 0 && <div className="row between"><span className="muted">Discount</span><span className="lime">− {money(bill.disc, true)}</span></div>}
          <div className="row between"><span className="muted">CGST + SGST</span><span>{money(bill.cgst + bill.sgst, true)}</span></div>
          {f.coupon && <span className="small muted">Coupon discount is applied when saved.</span>}
          <div className="row between num" style={{ fontSize: 20, fontWeight: 700, borderTop: '1px solid var(--line)', paddingTop: 8 }}><span>Total</span><span>{money(bill.total, true)}</span></div>
        </div>
      )}
      <div className="seg" role="group" aria-label="Payment mode">
        {['upi', 'cash', 'card'].map((m) => <button key={m} type="button" className={f.pay_mode === m ? 'on' : ''} aria-pressed={f.pay_mode === m} onClick={() => setF({ ...f, pay_mode: m })}>{m.toUpperCase()}</button>)}
      </div>
      <div className="grid2">
        <Input label="Amount collected now (₹)" type="number" min="0" placeholder={bill ? String(bill.total) : ''} value={f.pay_amount} onChange={(e) => setF({ ...f, pay_amount: e.target.value })} hint="Less than total = rest stays as dues" />
        <Input label="Reference" value={f.pay_reference} onChange={(e) => setF({ ...f, pay_reference: e.target.value })} />
      </div>
      <Check label="Set up UPI AutoPay for renewals" checked={f.autopay} onChange={(v) => setF({ ...f, autopay: v })} />
      <div className="row" style={{ justifyContent: 'flex-end' }}>
        <button className="btn" onClick={onClose}>Cancel</button>
        <button className="btn primary" disabled={busy || !f.plan_id} onClick={submit}>{bill ? `Save · ${money(f.pay_amount === '' ? bill.total : f.pay_amount, true)} collected` : 'Save'}</button>
      </div>
    </Modal>
  );
}
