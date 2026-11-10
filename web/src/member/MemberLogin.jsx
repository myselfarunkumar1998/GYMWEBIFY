import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { get, post } from '../lib/api.js';
import { useI18n, LangToggle } from '../lib/i18n.jsx';
import { Input, useAction } from '../components/ui.jsx';

export default function MemberLogin({ slug, onLogin }) {
  const { t } = useI18n();
  const [gym, setGym] = useState(null);
  const [phone, setPhone] = useState('');
  const [code, setCode] = useState('');
  const [step, setStep] = useState('phone');
  const [demo, setDemo] = useState(null);
  const { run, busy, fields } = useAction();
  useEffect(() => { get('/public/gym/' + slug).then(setGym).catch(() => setGym(false)); }, [slug]);
  if (gym === false) return <div className="phone-body"><h1>Gym not found</h1><span className="muted">Check the link your gym shared.</span></div>;

  const send = async (e) => {
    e.preventDefault();
    const r = await run(() => post('/m/auth/otp', { gym: slug, phone }));
    if (r) { setStep('code'); setDemo(r.demo_code || null); }
  };
  const verify = async (e) => {
    e.preventDefault();
    if (await run(() => post('/m/auth/verify', { gym: slug, phone, code }))) onLogin();
  };

  return (
    <div className="phone">
      <div className="phone-body" style={{ paddingTop: 56, gap: 28 }}>
        <div className="col gap16">
          <div className="logo" style={{ width: 64, height: 64, borderRadius: 20, fontSize: 30 }}>{gym?.logo_url ? <img src={gym.logo_url} alt="" /> : (gym?.name || 'G')[0]}</div>
          <div className="col gap8"><h1 style={{ fontSize: 32, lineHeight: 1.15 }}>{t('Welcome to')} {gym?.name || '…'}</h1><span className="muted" style={{ fontSize: 15 }}>{t('Your card, visits, plan and workouts in one place.')}</span></div>
        </div>
        <fieldset style={{ border: 0, margin: 0, padding: 0 }} className="col gap8"><legend className="label" style={{ paddingBottom: 8 }}>Language · மொழி</legend><LangToggle /></fieldset>
        {step === 'phone' ? (
          <form onSubmit={send} className="col gap12">
            <label htmlFor="ml-phone" className="label">{t('Mobile number')}</label>
            <div className="row gap8">
              <span className="input" style={{ width: 64, display: 'flex', alignItems: 'center', height: 52 }}>+91</span>
              <input id="ml-phone" className="input" style={{ height: 52, fontSize: 16 }} type="tel" inputMode="numeric" autoComplete="tel-national" placeholder="98765 43210" value={phone} onChange={(e) => setPhone(e.target.value)} required />
            </div>
            {fields.phone && <span className="small warn">{fields.phone}</span>}
            <button className="btn primary block" style={{ height: 52, fontSize: 16 }} disabled={busy}>{t('Get OTP on WhatsApp')}</button>
            <span className="small muted">{t('Use the number you gave at the gym front desk.')}</span>
          </form>
        ) : (
          <form onSubmit={verify} className="col gap12">
            <Input label={t('Enter the 6-digit code')} inputMode="numeric" autoComplete="one-time-code" maxLength={6} value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))} error={fields.code} autoFocus style={{ height: 52, fontSize: 22, letterSpacing: 6 }} />
            {demo && <div className="banner info">Demo mode: your code is <b>{demo}</b></div>}
            <button className="btn primary block" style={{ height: 52, fontSize: 16 }} disabled={busy || code.length !== 6}>{t('Verify')}</button>
            <button type="button" className="btn block" onClick={() => { setStep('phone'); setCode(''); }}>Change number</button>
          </form>
        )}
        <div className="card" style={{ marginTop: 'auto' }}>
          <b>{t('Not a member yet?')}</b>
          <span className="small muted">Free {gym?.trial_days ?? 3}-day trial pass.</span>
          <Link to={'/g/' + slug} style={{ fontWeight: 600 }}>{t('Book a free trial')} →</Link>
        </div>
      </div>
    </div>
  );
}
