import { useState } from 'react';
import { post } from '../lib/api.js';
import { useI18n } from '../lib/i18n.jsx';
import { money, date, titleCase } from '../lib/format.js';
import { Check, Input, Loading, Pill, useAction, useApi, Empty } from '../components/ui.jsx';
import { BackHeader, useMember, payOrder } from './MemberApp.jsx';

export default function Renew() {
  const m = useMember();
  const { t } = useI18n();
  const { data, reload } = useApi('/m/plans');
  const [planId, setPlanId] = useState(null);
  const [coupon, setCoupon] = useState('');
  const [credit, setCredit] = useState(true);
  const [autopay, setAutopay] = useState(false);
  const [done, setDone] = useState(null);
  const { run, busy, fields } = useAction();
  if (!data) return <div className="phone-body"><Loading /></div>;
  const plans = data.plans;
  const sel = plans.find((p) => p.id === (planId ?? data.current?.plan_id)) || null;
  const gst = (p) => (data.prices_include_gst ? 0 : Number(p.price) * Number(p.gst_rate) / 100);
  const unpaid = data.invoices.filter((i) => ['unpaid', 'partial'].includes(i.status));

  const pay = async () => {
    const r = await run(() => post('/m/renew', { plan_id: sel.id, coupon: coupon || null, use_credit: credit && data.credit_balance > 0, autopay }));
    if (!r) return;
    await run(() => payOrder(r, () => setDone(r.invoice)));
    reload(); m.reload();
  };
  const payDue = async (inv) => {
    const r = await run(() => post(`/m/invoices/${inv.id}/pay`));
    if (r) await run(() => payOrder(r, () => setDone(r.invoice)));
    reload();
  };

  if (done) return (
    <div className="phone-body">
      <BackHeader title={t('Payment successful')} />
      <section className="card hero" style={{ borderRadius: 22 }}><span className="stat-label">{done.invoice_no}</span><span className="stat-value">{t('Paid')}</span><span className="small">GST invoice sent on WhatsApp.</span></section>
      <a className="btn block" href={`/api/m/invoices/${done.id}/print`} target="_blank" rel="noreferrer">View invoice</a>
      <button className="btn primary block" onClick={() => setDone(null)}>Done</button>
    </div>
  );

  return (
    <div className="phone-body">
      <BackHeader title={t('Renew & pay')} sub={data.current ? `${data.current.name} · ${t('Valid till')} ${date(data.current.end_date)}` : null} />
      {unpaid.length > 0 && <section className="card alert" style={{ borderRadius: 18 }}><b>{t('Dues to pay')}</b>
        {unpaid.map((i) => <div key={i.id} className="row between"><span className="small">{i.invoice_no} · {money(i.balance)}</span><button className="btn sm primary" disabled={busy} onClick={() => payDue(i)}>{t('Pay now')}</button></div>)}</section>}
      <h2 style={{ fontSize: 17 }}>{t('Choose a plan')}</h2>
      {plans.length === 0 && <Empty>No plans available online. Please visit the desk.</Empty>}
      <div className="col gap8" role="radiogroup" aria-label="Plans">
        {plans.map((p) => (
          <button key={p.id} role="radio" aria-checked={sel?.id === p.id} onClick={() => setPlanId(p.id)} className="card tight" style={{ flexDirection: 'row', alignItems: 'center', textAlign: 'left', cursor: 'pointer', borderRadius: 16, borderColor: sel?.id === p.id ? 'var(--lime)' : undefined, background: sel?.id === p.id ? 'var(--lime-bg)' : undefined }}>
            <div className="grow col" style={{ gap: 2 }}><b>{p.name} {p.kind === 'pt' && <Pill tone="grey">PT</Pill>}</b><span className="small muted">{p.description || `${p.duration_days} days`}{p.sessions_count ? ` · ${p.sessions_count} sessions` : ''}</span></div>
            <div className="col" style={{ alignItems: 'flex-end', gap: 0 }}><span className="num" style={{ fontWeight: 700, fontSize: 17 }}>{money(Number(p.price) + gst(p))}</span><span className="small muted">{data.prices_include_gst ? 'incl. GST' : `${money(p.price)} + GST`}</span></div>
          </button>
        ))}
      </div>
      {sel && <>
        <Input label="Coupon code" value={coupon} onChange={(e) => setCoupon(e.target.value.toUpperCase())} error={fields.coupon} />
        {data.credit_balance > 0 && <Check label={`${t('Use referral credit')} (${money(data.credit_balance)})`} checked={credit} onChange={setCredit} />}
        {sel.kind === 'membership' && <Check label={`${t('Turn on UPI AutoPay')} (renews automatically)`} checked={autopay} onChange={setAutopay} />}
        <button className="btn primary block" style={{ height: 52, fontSize: 16 }} disabled={busy} onClick={pay}>{t('Pay now')} · {money(Number(sel.price) + gst(sel))}</button>
        <span className="small muted">UPI, cards and net banking via Razorpay. The new plan starts the day after your current plan ends. Joining fee does not apply to renewals.</span>
      </>}
      {data.invoices.length > 0 && <section className="card" style={{ borderRadius: 18 }}><h2 style={{ fontSize: 16 }}>{t('Invoices')}</h2>
        {data.invoices.map((i) => <a key={i.id} href={`/api/m/invoices/${i.id}/print`} target="_blank" rel="noreferrer" className="row between small" style={{ color: 'inherit', textDecoration: 'none', padding: '6px 0' }}><span>{i.invoice_no}<br /><span className="muted">{date(i.invoice_date)}</span></span><span>{money(i.total)} <Pill tone={i.status === 'paid' ? undefined : 'warn'}>{titleCase(i.status)}</Pill></span></a>)}</section>}
    </div>
  );
}
