import { useEffect, useState } from 'react';
import { get, post, openHtml } from '../lib/api.js';
import { money, date } from '../lib/format.js';
import { Input, Modal, Select, useAction, Empty } from './ui.jsx';
import MemberSearch from './MemberSearch.jsx';

/** Collect against a member's unpaid invoices (cash/UPI/card/credit/gift card), or send a UPI link. */
export default function CollectPayment({ member: initial, invoiceId, onClose, onDone }) {
  const [member, setMember] = useState(initial || null);
  const [invoices, setInvoices] = useState(null);
  const [inv, setInv] = useState(invoiceId || '');
  const [f, setF] = useState({ amount: '', mode: 'upi', reference: '' });
  const { run, busy, fields } = useAction();

  useEffect(() => {
    if (!member) return;
    get('/invoices', { member_id: member.id, status: 'due', limit: 50 }).then((r) => {
      setInvoices(r.items);
      const first = r.items.find((x) => String(x.id) === String(invoiceId)) || r.items[0];
      if (first) { setInv(first.id); setF((x) => ({ ...x, amount: first.balance })); }
    });
  }, [member, invoiceId]);
  const current = invoices?.find((x) => String(x.id) === String(inv));

  const pay = async () => {
    const r = await run(() => post(`/invoices/${inv}/payments`, { amount: Number(f.amount), mode: f.mode, reference: f.reference || null }), 'Payment recorded. Receipt sent on WhatsApp.');
    if (r) onDone?.(r);
  };
  const link = async () => {
    const r = await run(() => post(`/invoices/${inv}/payment-link`, { send: true }));
    if (r) { navigator.clipboard?.writeText(r.url).catch(() => {}); onDone?.(r); }
  };

  return (
    <Modal title="Record payment" onClose={onClose}>
      {!initial && <MemberSearch autoFocus onPick={setMember} />}
      {member && <div className="inner"><b>{member.name}</b> <span className="muted">· {member.member_code}</span></div>}
      {member && invoices === null && <span className="muted">Loading dues…</span>}
      {member && invoices?.length === 0 && <Empty>No dues for this member. To sell a plan, open the member and use Renew plan.</Empty>}
      {invoices?.length > 0 && <>
        <Select label="Invoice" value={inv} onChange={(e) => { setInv(e.target.value); const x = invoices.find((i) => String(i.id) === e.target.value); setF({ ...f, amount: x?.balance || '' }); }}
          options={invoices.map((i) => ({ value: i.id, label: `${i.invoice_no} · ${date(i.invoice_date)} · due ${money(i.balance, true)}` }))} />
        {current && <span className="small muted">{current.items}</span>}
        <div className="grid2">
          <Input label="Amount (₹)" type="number" min="1" step="0.01" value={f.amount} onChange={(e) => setF({ ...f, amount: e.target.value })} error={fields.amount} />
          <Select label="Mode" value={f.mode} onChange={(e) => setF({ ...f, mode: e.target.value })}
            options={[{ value: 'upi', label: 'UPI' }, { value: 'cash', label: 'Cash' }, { value: 'card', label: 'Card' }, { value: 'netbanking', label: 'Net banking' }, { value: 'credit', label: 'Referral credit' }, { value: 'giftcard', label: 'Gift card' }]} />
        </div>
        <Input label={f.mode === 'giftcard' ? 'Gift card code' : 'Reference (UPI ref / card last 4)'} value={f.reference} onChange={(e) => setF({ ...f, reference: e.target.value })} />
        <div className="row wrap" style={{ justifyContent: 'flex-end' }}>
          <button className="btn" onClick={() => openHtml(`/invoices/${inv}/print`)}>View invoice</button>
          <button className="btn ghost" disabled={busy} onClick={link}>Send UPI link on WhatsApp</button>
          <button className="btn primary" disabled={busy || !f.amount} onClick={pay}>Collect {f.amount ? money(f.amount, true) : ''}</button>
        </div>
      </>}
    </Modal>
  );
}
