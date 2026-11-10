import { useState } from 'react';
import { Link } from 'react-router-dom';
import { post, del, download, openHtml } from '../lib/api.js';
import { useStaff } from '../lib/session.jsx';
import { useI18n } from '../lib/i18n.jsx';
import { money, date, dateTime, month as thisMonth, titleCase, today, relDays } from '../lib/format.js';
import { Confirm, Empty, ErrorBox, Input, Loading, Modal, Pill, Select, Stat, Tabs, useAction, useApi } from '../components/ui.jsx';
import MemberSearch from '../components/MemberSearch.jsx';
import CollectPayment from '../components/CollectPayment.jsx';

export default function Billing() {
  const s = useStaff();
  const { t } = useI18n();
  const [m, setM] = useState(thisMonth());
  const [tab, setTab] = useState('invoices');
  const sum = useApi('/billing/summary', { month: m });
  const [newInv, setNewInv] = useState(false);
  const [collect, setCollect] = useState(null);
  const [y, mo] = m.split('-').map(Number);
  const monthName = new Date(y, mo - 1, 1).toLocaleDateString('en-IN', { month: 'long', year: 'numeric' });
  const tabs = [{ value: 'invoices', label: 'Invoices' }, { value: 'dues', label: `Dues${sum.data ? ` (${sum.data.overdue.members})` : ''}` }, { value: 'payments', label: 'Payments' },
    { value: 'links', label: 'Payment links' }, { value: 'store', label: 'Store sales' }];
  if (s.can('billing.manage')) tabs.push({ value: 'expenses', label: 'Expenses' }, { value: 'credit', label: 'Credit notes' });
  if (s.can('reports.finance')) tabs.push({ value: 'gst', label: 'GST report' });

  return (
    <>
      <header className="topbar">
        <div className="col gap4"><h1>{t('Billing & GST')}</h1><span className="muted">{monthName}{sum.data?.gstin ? ` · GSTIN ${sum.data.gstin}` : ' · GSTIN not set (Settings)'}</span></div>
        <div className="row wrap">
          <label className="sr" htmlFor="bm">Month</label>
          <input id="bm" type="month" className="input" style={{ width: 160 }} value={m} max={thisMonth()} onChange={(e) => e.target.value && setM(e.target.value)} />
          {s.can('reports.finance') && <button className="btn" onClick={() => download('/billing/export/tally', { month: m })}>Export to Tally</button>}
          {s.can('reports.finance') && <button className="btn" onClick={() => download('/billing/export/zoho', { month: m })}>Zoho CSV</button>}
          {s.can('billing.collect') && <button className="btn primary" onClick={() => setNewInv(true)}>+ New invoice</button>}
        </div>
      </header>
      {sum.data && (
        <section className="grid4">
          <Stat label="Collected this month" value={money(sum.data.collected)} sub={sum.data.by_mode.map((x) => `${titleCase(x.mode)} ${money(x.amount)}`).join(' · ')} />
          <Stat label="GST on invoices" value={money(sum.data.gst_total)} sub={Number(sum.data.gst.igst) > 0 ? `IGST ${money(sum.data.gst.igst)}` : `CGST ${money(sum.data.gst.cgst)} · SGST ${money(sum.data.gst.sgst)}`} />
          <Stat label="AutoPay renewals" value={sum.data.autopay_count} sub="collected without follow-up" />
          <Stat tone={sum.data.overdue.amount > 0 ? 'alert' : undefined} label="Overdue" value={money(sum.data.overdue.amount)} sub={`${sum.data.overdue.members} members · ${sum.data.overdue.blocked} blocked at door`} />
        </section>
      )}
      <Tabs items={tabs} value={tab} onChange={setTab} />
      {tab === 'invoices' && <Invoices month={m} onCollect={setCollect} />}
      {tab === 'store' && <Invoices month={m} kind="store" onCollect={setCollect} />}
      {tab === 'dues' && <Dues onCollect={setCollect} />}
      {tab === 'payments' && <Payments />}
      {tab === 'links' && <Links />}
      {tab === 'expenses' && <Expenses month={m} />}
      {tab === 'credit' && <CreditNotes />}
      {tab === 'gst' && <Gst month={m} />}
      {newInv && <NewInvoice onClose={() => setNewInv(false)} onDone={() => { setNewInv(false); sum.reload(); }} />}
      {collect && <CollectPayment member={collect.member} invoiceId={collect.invoiceId} onClose={() => setCollect(null)} onDone={() => { setCollect(null); sum.reload(); }} />}
    </>
  );
}

function Invoices({ month, kind, onCollect }) {
  const s = useStaff();
  const [q, setQ] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const from = month + '-01';
  const to = month + '-' + String(new Date(+month.slice(0, 4), +month.slice(5), 0).getDate()).padStart(2, '0');
  const { data, error, loading, reload } = useApi('/invoices', { from, to, q, status, kind, page, limit: 50 });
  const [cancel, setCancel] = useState(null);
  return (
    <section className="card" style={{ paddingTop: 12 }}>
      <div className="row wrap">
        <input className="input" style={{ width: 240 }} type="search" aria-label="Search invoices" placeholder="Invoice no. or customer" value={q} onChange={(e) => { setQ(e.target.value); setPage(1); }} />
        <select className="input" style={{ width: 150 }} aria-label="Status" value={status} onChange={(e) => setStatus(e.target.value)}>
          <option value="">All statuses</option><option value="paid">Paid</option><option value="partial">Partial</option><option value="unpaid">Unpaid</option><option value="cancelled">Cancelled</option>
        </select>
      </div>
      {loading && !data ? <Loading /> : error ? <ErrorBox error={error} /> : data.items.length === 0 ? <Empty>No invoices.</Empty> : (
        <div className="tablebox"><table className="t" style={{ minWidth: 820 }}>
          <thead><tr><th>Invoice</th><th>Customer</th><th>Item</th><th className="r">Amount</th><th className="r">GST</th><th className="r">Total</th><th>Mode</th><th>Status</th><th></th></tr></thead>
          <tbody>{data.items.map((i) => (
            <tr key={i.id}>
              <td className="muted num" style={{ whiteSpace: 'nowrap' }}>{i.invoice_no}<br /><span className="small">{date(i.invoice_date)}</span></td>
              <td style={{ fontWeight: 500 }}>{i.member_id ? <Link to={'/admin/members/' + i.member_id} style={{ color: 'inherit' }}>{i.customer_name}</Link> : i.customer_name}</td>
              <td className="small t2" style={{ maxWidth: 220 }}>{i.items}</td>
              <td className="r num">{money(i.taxable, true)}</td><td className="r num muted">{money(i.gst, true)}</td><td className="r num" style={{ fontWeight: 600 }}>{money(i.total, true)}</td>
              <td className="small">{i.modes ? i.modes.split(',').map(titleCase).join(', ') : '—'}</td>
              <td><Pill tone={i.status === 'paid' ? undefined : i.status === 'cancelled' ? 'grey' : 'warn'}>{i.status === 'partial' ? `Due ${money(i.balance)}` : titleCase(i.status)}</Pill></td>
              <td className="r" style={{ whiteSpace: 'nowrap' }}>
                <button className="btn sm" onClick={() => openHtml(`/invoices/${i.id}/print`)}>View</button>{' '}
                {i.balance > 0 && i.status !== 'cancelled' && s.can('billing.collect') && <button className="btn sm ghost" onClick={() => onCollect({ member: i.member_id ? { id: i.member_id, name: i.customer_name } : null, invoiceId: i.id })}>Collect</button>}{' '}
                {i.status !== 'cancelled' && s.can('billing.manage') && <button className="btn sm danger" onClick={() => setCancel(i)}>Cancel</button>}
              </td>
            </tr>))}</tbody>
        </table></div>
      )}
      {data && <div className="row between"><span className="small muted">{data.total} invoices</span><div className="row"><button className="btn sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>Previous</button><button className="btn sm" disabled={page * 50 >= data.total} onClick={() => setPage(page + 1)}>Next</button></div></div>}
      {cancel && <CancelInvoice inv={cancel} onClose={() => setCancel(null)} onDone={() => { setCancel(null); reload(); }} />}
    </section>
  );
}

function CancelInvoice({ inv, onClose, onDone }) {
  const [reason, setReason] = useState('');
  const { run, busy, fields } = useAction();
  return (
    <Modal title={`Cancel ${inv.invoice_no}?`} onClose={onClose} footer={<><button className="btn" onClick={onClose}>Keep</button>
      <button className="btn danger" disabled={busy} onClick={async () => { const r = await run(() => post(`/invoices/${inv.id}/cancel`, { reason })); if (r) { alert(`Credit note ${r.credit_note} created.${r.refund_due > 0 ? ` Refund ${money(r.refund_due, true)} to the member.` : ''}`); onDone(); } }}>Cancel invoice</button></>}>
      <span className="muted">A GST credit note is issued, linked plans are cancelled and store items go back to stock.</span>
      <Input label="Reason" value={reason} onChange={(e) => setReason(e.target.value)} error={fields.reason} />
    </Modal>
  );
}

function Dues({ onCollect }) {
  const s = useStaff();
  const { data, loading, reload } = useApi('/dues');
  const [sel, setSel] = useState([]);
  const { run, busy } = useAction();
  if (loading && !data) return <Loading />;
  return (
    <section className="card">
      <div className="row between wrap">
        <span className="muted">{data.length} members owe {money(data.reduce((a, x) => a + Number(x.balance), 0))}</span>
        {s.can('billing.collect') && <button className="btn ghost" disabled={busy} onClick={async () => { const r = await run(() => post('/dues/remind', { member_ids: sel })); if (r) { alert(`${r.queued} WhatsApp reminders with UPI links queued.`); reload(); } }}>Send UPI pay links on WhatsApp {sel.length ? `(${sel.length})` : '(all)'}</button>}
      </div>
      {data.length === 0 ? <Empty>No dues. Everyone has paid.</Empty> : (
        <div className="tablebox"><table className="t">
          <thead><tr><th><span className="sr">Select</span></th><th>Member</th><th className="r">Balance</th><th>Oldest due</th><th>Overdue</th><th>Last reminded</th><th></th></tr></thead>
          <tbody>{data.map((d) => (
            <tr key={d.member_id}>
              <td><input type="checkbox" aria-label={`Select ${d.name}`} checked={sel.includes(d.member_id)} onChange={(e) => setSel(e.target.checked ? [...sel, d.member_id] : sel.filter((x) => x !== d.member_id))} /></td>
              <td><Link to={'/admin/members/' + d.member_id} style={{ color: 'inherit' }}>{d.name}</Link> <span className="small muted">{d.member_code} · {d.phone}</span></td>
              <td className="r num warn" style={{ fontWeight: 600 }}>{money(d.balance, true)}</td>
              <td>{date(d.oldest_due)}</td><td className={d.days_overdue > 7 ? 'warn' : ''}>{d.days_overdue} days</td><td className="small muted">{d.last_reminded ? relDays(d.last_reminded) : 'Never'}</td>
              <td className="r">{s.can('billing.collect') && <button className="btn sm ghost" onClick={() => onCollect({ member: { id: d.member_id, name: d.name, member_code: d.member_code } })}>Collect</button>}</td>
            </tr>))}</tbody>
        </table></div>
      )}
    </section>
  );
}

function Payments() {
  const [d, setD] = useState('');
  const { data, loading } = useApi('/payments', { date: d, limit: 100 });
  return (
    <section className="card">
      <input type="date" className="input" style={{ width: 170 }} aria-label="Date" value={d} max={today()} onChange={(e) => setD(e.target.value)} />
      {loading && !data ? <Loading /> : data.items.length === 0 ? <Empty>No payments.</Empty> : (
        <div className="tablebox"><table className="t"><thead><tr><th>When</th><th>Invoice</th><th>Customer</th><th>Mode</th><th>Reference</th><th>By</th><th className="r">Amount</th></tr></thead>
          <tbody>{data.items.map((p) => <tr key={p.id}><td>{dateTime(p.paid_at)}</td><td className="muted">{p.invoice_no}</td><td>{p.customer_name}</td><td>{titleCase(p.mode)}</td><td className="small muted">{p.reference || p.gateway_payment_id || '—'}</td><td className="small">{p.received_by_name || 'Online'}</td><td className="r num" style={{ fontWeight: 600 }}>{money(p.amount, true)}</td></tr>)}</tbody></table></div>
      )}
    </section>
  );
}

function Links() {
  const { data, loading } = useApi('/payment-links');
  if (loading && !data) return <Loading />;
  return (
    <section className="card">
      <span className="small muted">UPI/card links sent from invoices and dues reminders. Paid links update the invoice automatically.</span>
      {data.length === 0 ? <Empty>No payment links yet.</Empty> : (
        <div className="tablebox"><table className="t"><thead><tr><th>Created</th><th>Invoice</th><th>Customer</th><th className="r">Amount</th><th>Status</th><th>Link</th></tr></thead>
          <tbody>{data.map((l) => <tr key={l.id}><td>{dateTime(l.created_at)}</td><td className="muted">{l.invoice_no}</td><td>{l.customer_name}</td><td className="r num">{money(l.amount, true)}</td><td><Pill tone={l.status === 'paid' ? undefined : 'grey'}>{titleCase(l.status)}</Pill></td><td><a href={l.short_url} target="_blank" rel="noreferrer" className="small">{l.short_url}</a></td></tr>)}</tbody></table></div>
      )}
    </section>
  );
}

function CreditNotes() {
  const { data, loading } = useApi('/credit-notes');
  if (loading && !data) return <Loading />;
  return (
    <section className="card">{data.length === 0 ? <Empty>No credit notes.</Empty> : (
      <div className="tablebox"><table className="t"><thead><tr><th>Note</th><th>Date</th><th>Against</th><th>Customer</th><th>Reason</th><th className="r">Amount</th></tr></thead>
        <tbody>{data.map((c) => <tr key={c.id}><td className="muted">{c.note_no}</td><td>{date(c.created_at)}</td><td>{c.invoice_no}</td><td>{c.customer_name}</td><td className="small">{c.reason}</td><td className="r num">{money(c.amount, true)}</td></tr>)}</tbody></table></div>
    )}</section>
  );
}

const EXP_CATS = ['Rent', 'Electricity', 'Salaries', 'Equipment service', 'Equipment purchase', 'Housekeeping', 'Marketing', 'Store purchase', 'Water & supplies', 'Internet & software', 'Other'];
function Expenses({ month }) {
  const s = useStaff();
  const { data, loading, reload } = useApi('/expenses', { month });
  const [f, setF] = useState({ category: 'Rent', amount: '', expense_date: today(), note: '', branch_id: '' });
  const { run, busy, fields } = useAction();
  const [delId, setDelId] = useState(null);
  const total = (data || []).reduce((a, e) => a + Number(e.amount), 0);
  return (
    <div className="split">
      <section className="card main">
        <div className="row between"><h2>Expenses</h2><span className="num" style={{ fontWeight: 700 }}>{money(total)}</span></div>
        {loading && !data ? <Loading /> : data.length === 0 ? <Empty>No expenses this month.</Empty> : (
          <div className="tablebox"><table className="t"><thead><tr><th>Date</th><th>Category</th><th>Note</th><th>Branch</th><th className="r">Amount</th><th></th></tr></thead>
            <tbody>{data.map((e) => <tr key={e.id}><td>{date(e.expense_date)}</td><td>{e.category}</td><td className="small t2">{e.note}</td><td className="small">{e.branch_name || '—'}</td><td className="r num">{money(e.amount, true)}</td><td className="r"><button className="btn sm danger" aria-label="Delete expense" onClick={() => setDelId(e.id)}>Delete</button></td></tr>)}</tbody></table></div>
        )}
      </section>
      <section className="card side">
        <h2>Add expense</h2>
        <Select label="Category" value={f.category} onChange={(e) => setF({ ...f, category: e.target.value })} options={EXP_CATS} />
        <Input label="Amount (₹)" type="number" min="1" value={f.amount} onChange={(e) => setF({ ...f, amount: e.target.value })} error={fields.amount} />
        <Input label="Date" type="date" value={f.expense_date} onChange={(e) => setF({ ...f, expense_date: e.target.value })} />
        {s.branches.length > 1 && <Select label="Branch" value={f.branch_id} placeholder="All / head office" onChange={(e) => setF({ ...f, branch_id: e.target.value })} options={s.branches.map((b) => ({ value: b.id, label: b.name }))} />}
        <Input label="Note" value={f.note} onChange={(e) => setF({ ...f, note: e.target.value })} />
        <button className="btn primary" disabled={busy} onClick={async () => { if (await run(() => post('/expenses', { ...f, amount: Number(f.amount), branch_id: f.branch_id ? Number(f.branch_id) : null }), 'Expense added')) { setF({ ...f, amount: '', note: '' }); reload(); } }}>Add</button>
      </section>
      {delId && <Confirm title="Delete expense?" danger confirmLabel="Delete" onClose={() => setDelId(null)} onConfirm={async () => { await run(() => del('/expenses/' + delId)); setDelId(null); reload(); }} />}
    </div>
  );
}

function Gst({ month }) {
  const { data, loading, error } = useApi('/billing/gst', { month });
  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox error={error} />;
  const t = data.totals;
  return (
    <section className="card">
      <div className="row between wrap"><h2>GST summary · {month}</h2><button className="btn" onClick={() => download('/billing/gst', { month, format: 'csv' })}>Download CSV for accountant</button></div>
      <div className="grid4">
        <div className="inner"><span className="small muted">Taxable value</span><div className="num" style={{ fontSize: 22, fontWeight: 700 }}>{money(t.taxable, true)}</div></div>
        <div className="inner"><span className="small muted">CGST</span><div className="num" style={{ fontSize: 22, fontWeight: 700 }}>{money(t.cgst, true)}</div></div>
        <div className="inner"><span className="small muted">SGST</span><div className="num" style={{ fontSize: 22, fontWeight: 700 }}>{money(t.sgst, true)}</div></div>
        <div className="inner"><span className="small muted">IGST</span><div className="num" style={{ fontSize: 22, fontWeight: 700 }}>{money(t.igst, true)}</div></div>
      </div>
      <span className="muted small">B2C invoices: {data.b2c_count} · B2B invoices (customer GSTIN): {data.b2b.length} · Credit notes: {data.credit_notes.length}</span>
      <h3>By rate and SAC/HSN (for GSTR-1 table 12)</h3>
      <div className="tablebox"><table className="t"><thead><tr><th>SAC/HSN</th><th>GST rate</th><th>Lines</th><th className="r">Gross value</th></tr></thead>
        <tbody>{data.by_rate.map((r, i) => <tr key={i}><td>{r.hsn_sac}</td><td>{+r.gst_rate}%</td><td>{r.line_count}</td><td className="r num">{money(r.gross, true)}</td></tr>)}</tbody></table></div>
      {data.b2b.length > 0 && <><h3>B2B invoices</h3><div className="tablebox"><table className="t"><thead><tr><th>Invoice</th><th>Customer</th><th>GSTIN</th><th className="r">Taxable</th><th className="r">Tax</th></tr></thead>
        <tbody>{data.b2b.map((r) => <tr key={r.invoice_no}><td>{r.invoice_no}</td><td>{r.customer_name}</td><td>{r.customer_gstin}</td><td className="r num">{money(r.taxable, true)}</td><td className="r num">{money(Number(r.cgst) + Number(r.sgst) + Number(r.igst), true)}</td></tr>)}</tbody></table></div></>}
    </section>
  );
}

function NewInvoice({ onClose, onDone }) {
  const [member, setMember] = useState(null);
  const [f, setF] = useState({ customer_name: '', customer_gstin: '', discount: '', pay_amount: '', pay_mode: 'upi' });
  const [items, setItems] = useState([{ description: '', rate: '', qty: 1, gst_rate: 18 }]);
  const { run, busy, fields } = useAction();
  const sub = items.reduce((a, i) => a + Number(i.rate || 0) * Number(i.qty || 1), 0);
  const tax = items.reduce((a, i) => a + Number(i.rate || 0) * Number(i.qty || 1) * Number(i.gst_rate) / 100, 0) * (sub ? (sub - Number(f.discount || 0)) / sub : 1);
  const save = async () => {
    const body = { member_id: member?.id, customer_name: member ? null : f.customer_name, customer_gstin: f.customer_gstin || null, discount: Number(f.discount || 0),
      items: items.filter((i) => i.description && i.rate !== '').map((i) => ({ ...i, rate: Number(i.rate), qty: Number(i.qty), gst_rate: String(i.gst_rate) })),
      pay_amount: f.pay_amount ? Number(f.pay_amount) : 0, pay_mode: f.pay_mode };
    const r = await run(() => post('/invoices', body), 'Invoice created');
    if (r) { openHtml(`/invoices/${r.id}/print`); onDone(); }
  };
  return (
    <Modal title="New invoice" wide onClose={onClose} footer={<><button className="btn" onClick={onClose}>Cancel</button><button className="btn primary" disabled={busy} onClick={save}>Create invoice</button></>}>
      <span className="small muted">For plans use the member profile (Renew plan). For store items use Store › New sale.</span>
      {member ? <div className="row"><span className="inner grow">{member.name} · {member.member_code}</span><button className="btn sm" onClick={() => setMember(null)}>Change</button></div>
        : <div className="grid2"><MemberSearch onPick={setMember} /><Input label="…or customer name" value={f.customer_name} onChange={(e) => setF({ ...f, customer_name: e.target.value })} error={fields.customer_name} /></div>}
      <Input label="Customer GSTIN (B2B, optional)" value={f.customer_gstin} onChange={(e) => setF({ ...f, customer_gstin: e.target.value.toUpperCase() })} error={fields.customer_gstin} />
      {items.map((it, i) => (
        <div key={i} className="row wrap" style={{ alignItems: 'flex-end' }}>
          <Input label="Item" className="grow" value={it.description} onChange={(e) => setItems(items.map((x, j) => (j === i ? { ...x, description: e.target.value } : x)))} />
          <Input label="Rate (₹)" type="number" style={{ width: 110 }} value={it.rate} onChange={(e) => setItems(items.map((x, j) => (j === i ? { ...x, rate: e.target.value } : x)))} />
          <Input label="Qty" type="number" style={{ width: 70 }} value={it.qty} onChange={(e) => setItems(items.map((x, j) => (j === i ? { ...x, qty: e.target.value } : x)))} />
          <Select label="GST" value={it.gst_rate} onChange={(e) => setItems(items.map((x, j) => (j === i ? { ...x, gst_rate: e.target.value } : x)))} options={['0', '5', '12', '18', '28']} />
        </div>
      ))}
      <button className="btn sm" style={{ alignSelf: 'flex-start' }} onClick={() => setItems([...items, { description: '', rate: '', qty: 1, gst_rate: 18 }])}>+ Add line</button>
      <div className="grid3">
        <Input label="Discount (₹)" type="number" value={f.discount} onChange={(e) => setF({ ...f, discount: e.target.value })} />
        <Input label="Collect now (₹)" type="number" value={f.pay_amount} onChange={(e) => setF({ ...f, pay_amount: e.target.value })} />
        <Select label="Mode" value={f.pay_mode} onChange={(e) => setF({ ...f, pay_mode: e.target.value })} options={['upi', 'cash', 'card', 'netbanking']} />
      </div>
      <div className="row between num" style={{ fontSize: 18, fontWeight: 700 }}><span>Estimated total</span><span>{money(sub - Number(f.discount || 0) + tax, true)}</span></div>
    </Modal>
  );
}
