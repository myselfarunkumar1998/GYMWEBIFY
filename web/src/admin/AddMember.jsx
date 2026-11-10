import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { get, post, resizeImage } from '../lib/api.js';
import { useStaff } from '../lib/session.jsx';
import { useI18n } from '../lib/i18n.jsx';
import { money, today } from '../lib/format.js';
import { Check, Input, Select, SignaturePad, useAction, useApi } from '../components/ui.jsx';
import { billLines } from '../components/SellPlan.jsx';
import MemberSearch from '../components/MemberSearch.jsx';
import { MemberFields } from './MemberForm.jsx';

export default function AddMember() {
  const s = useStaff();
  const { t } = useI18n();
  const nav = useNavigate();
  const [sp] = useSearchParams();
  const plans = useApi('/plans', { active: 1 });
  const staff = useApi('/staff');
  const [f, setF] = useState({ name: '', phone: '', language: 'en', branch_id: s.user.branch_id || s.branches[0]?.id || '', plan_id: '', start_date: today(), discount: '', coupon: '', pay_mode: 'upi', pay_amount: '', autopay: true, part: false });
  const [referrer, setReferrer] = useState(null);
  const [photo, setPhoto] = useState(null);
  const [sig, setSig] = useState(null);
  const { run, busy, fields } = useAction();
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value });

  useEffect(() => {
    const lead = sp.get('lead');
    if (lead) get(`/leads/${lead}/convert`).then((d) => setF((x) => ({ ...x, name: d.name, phone: d.phone, email: d.email || '', source: d.source, lead_id: d.lead_id, branch_id: d.branch_id || x.branch_id }))).catch(() => {});
  }, [sp]);

  const plan = plans.data?.find((p) => String(p.id) === String(f.plan_id));
  const bill = useMemo(() => billLines(plan, { joiningFee: plan?.kind === 'membership' ? Number(s.rules?.joining_fee || 0) : 0, discount: f.discount, inclusive: s.rules?.prices_include_gst }), [plan, f.discount, s.rules]);

  const submit = async (e) => {
    e.preventDefault();
    const body = { ...f, referred_by: referrer?.id || null, photo, trainer_id: f.trainer_id ? Number(f.trainer_id) : null, branch_id: f.branch_id ? Number(f.branch_id) : null };
    ['plan_id'].forEach((k) => { body[k] = body[k] ? Number(body[k]) : null; });
    body.discount = f.discount ? Number(f.discount) : 0;
    body.pay_amount = !plan ? 0 : f.part ? Number(f.pay_amount || 0) : bill.total;
    Object.keys(body).forEach((k) => body[k] === '' && delete body[k]);
    const r = await run(() => post('/members', body));
    if (!r) return;
    if (sig) await post(`/members/${r.id}/waiver`, { signature: sig }).catch(() => {});
    nav('/admin/members/' + r.id);
  };

  return (
    <form onSubmit={submit} className="col gap20">
      <Link to="/admin/members" className="small" style={{ textDecoration: 'none' }}>← All members</Link>
      <header className="topbar"><h1>Add member</h1>{f.lead_id && <span className="pill">From lead</span>}</header>
      <div className="split">
        <section className="card main" aria-labelledby="det-title">
          <h2 id="det-title">Personal details</h2>
          <div className="row wrap">
            <div className="avatar lg" style={{ background: 'transparent', border: '1px dashed var(--line-2)' }}>{photo ? <img src={photo} alt="Member photo" /> : <span className="small muted">Photo</span>}</div>
            <label className="btn sm">Take or upload photo<input type="file" accept="image/*" capture="user" hidden onChange={async (e) => e.target.files[0] && setPhoto(await resizeImage(e.target.files[0], 800))} /></label>
          </div>
          <MemberFields f={f} set={set} fields={fields} staff={staff.data?.items || []} branches={s.branches} />
          <div className="field"><span className="label">Referred by (member gets referral credit)</span>
            {referrer ? <div className="row"><span className="inner grow">{referrer.name} · {referrer.member_code}</span><button type="button" className="btn sm" onClick={() => setReferrer(null)}>Clear</button></div> : <MemberSearch onPick={setReferrer} placeholder="Search referring member" />}
          </div>
          <h2 style={{ marginTop: 8 }}>Waiver</h2>
          <div className="inner small" style={{ whiteSpace: 'pre-line' }}>{s.rules?.waiver_text}</div>
          <SignaturePad onChange={setSig} />
          <span className="small muted">After saving, enrol fingerprint or face from the member's profile. The member is added to every door device automatically.</span>
        </section>

        <section className="card side" aria-labelledby="pay-title" style={{ gap: 14 }}>
          <h2 id="pay-title">Plan and payment</h2>
          <Select label="Plan" value={f.plan_id} onChange={set('plan_id')} placeholder="No plan now" options={(plans.data || []).map((p) => ({ value: p.id, label: `${p.name} · ${money(p.price)}` }))} />
          {plan && <>
            <Input label="Start date" type="date" value={f.start_date} onChange={set('start_date')} />
            <div className="grid2">
              <Input label="Discount (₹)" type="number" min="0" value={f.discount} onChange={set('discount')} />
              <Input label="Coupon" value={f.coupon} onChange={(e) => setF({ ...f, coupon: e.target.value.toUpperCase() })} error={fields.coupon} />
            </div>
            <div className="inner col gap8">
              <div className="row between"><span className="muted">{plan.name}</span><span>{money(bill.base, true)}</span></div>
              {bill.jf > 0 && <div className="row between"><span className="muted">Joining fee</span><span>{money(bill.jf, true)}</span></div>}
              {bill.disc > 0 && <div className="row between"><span className="muted">Discount</span><span className="lime">− {money(bill.disc, true)}</span></div>}
              <div className="row between"><span className="muted">CGST 9%</span><span>{money(bill.cgst, true)}</span></div>
              <div className="row between"><span className="muted">SGST 9%</span><span>{money(bill.sgst, true)}</span></div>
              <div className="row between num" style={{ fontSize: 20, fontWeight: 700, borderTop: '1px solid var(--line)', paddingTop: 8 }}><span>Total</span><span>{money(bill.total, true)}</span></div>
            </div>
            {referrer && <span className="small lime">Referral credit is added to both members after saving.</span>}
            <fieldset style={{ border: 0, margin: 0, padding: 0 }} className="col gap8">
              <legend className="label" style={{ paddingBottom: 6 }}>Payment</legend>
              <div className="seg">{[['upi', 'UPI'], ['cash', 'Cash'], ['card', 'Card']].map(([v, l]) => <button key={v} type="button" aria-pressed={f.pay_mode === v} className={f.pay_mode === v ? 'on' : ''} onClick={() => setF({ ...f, pay_mode: v })}>{l}</button>)}</div>
              <Check label="Set up UPI AutoPay for renewals" checked={f.autopay} onChange={(v) => setF({ ...f, autopay: v })} />
              <Check label="Part payment now, balance as dues" checked={f.part} onChange={(v) => setF({ ...f, part: v })} />
              {f.part && <Input label="Amount now (₹)" type="number" min="0" value={f.pay_amount} onChange={set('pay_amount')} />}
            </fieldset>
          </>}
          <button className="btn primary" style={{ height: 48 }} disabled={busy}>{plan ? `Collect ${money(f.part ? f.pay_amount || 0 : bill.total, true)} and create member` : 'Create member'}</button>
          <span className="small muted">GST invoice and welcome message go to WhatsApp in the member's language.</span>
        </section>
      </div>
    </form>
  );
}
