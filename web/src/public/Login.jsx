import { useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { post } from '../lib/api.js';
import { Input, useAction } from '../components/ui.jsx';
import { LangToggle } from '../lib/i18n.jsx';

export default function Login({ mode }) {
  const [sp] = useSearchParams();
  const nav = useNavigate();
  const { run, busy, fields } = useAction();
  const [f, setF] = useState({ email: '', password: '', password2: '' });
  const [done, setDone] = useState(false);
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value });

  const submit = async (e) => {
    e.preventDefault();
    if (mode === 'login') {
      const me = await run(() => post('/auth/login', { email: f.email, password: f.password }));
      if (me) {
        const next = sp.get('next');
        const safe = next && next.startsWith('/') && !next.startsWith('//') ? next : null;
        nav(me.user.role === 'platform_admin' ? '/platform' : safe || (window.innerWidth < 700 && me.user.role === 'owner' ? '/owner' : '/admin'), { replace: true });
      }
    } else if (mode === 'forgot') {
      if (await run(() => post('/auth/forgot', { email: f.email }))) setDone(true);
    } else {
      if (f.password !== f.password2) return run(() => Promise.reject({ message: 'Passwords do not match', fields: { password2: 'Does not match' } }));
      if (await run(() => post('/auth/reset', { token: sp.get('token'), password: f.password }), 'Password updated. Please sign in.')) nav('/login');
    }
  };

  return (
    <div style={{ minHeight: '100vh', display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16 }}>
      <form onSubmit={submit} className="card" style={{ width: '100%', maxWidth: 420, padding: 28, gap: 16 }}>
        <div className="row gap8"><div className="logo">G</div><h1 style={{ fontSize: 24 }}>GymOS</h1></div>
        {mode === 'login' && <>
          <p className="muted" style={{ margin: 0 }}>Sign in to manage your gym.</p>
          <Input label="Email" type="email" autoComplete="username" required value={f.email} onChange={set('email')} error={fields.email} />
          <Input label="Password" type="password" autoComplete="current-password" required value={f.password} onChange={set('password')} error={fields.password} />
          <button className="btn primary block" disabled={busy}>{busy ? 'Signing in…' : 'Sign in'}</button>
          <div className="row between small"><Link to="/forgot">Forgot password?</Link><span className="muted">Members: use the member app link from your gym</span></div>
        </>}
        {mode === 'forgot' && (done
          ? <p>If that email has an account, a reset link is on its way. It works for 1 hour.</p>
          : <>
            <p className="muted" style={{ margin: 0 }}>We will email you a link to set a new password.</p>
            <Input label="Email" type="email" required value={f.email} onChange={set('email')} error={fields.email} />
            <button className="btn primary block" disabled={busy}>Send reset link</button>
          </>)}
        {mode === 'reset' && <>
          <Input label="New password" type="password" autoComplete="new-password" required value={f.password} onChange={set('password')} error={fields.password} hint="At least 10 characters with letters and numbers" />
          <Input label="Repeat password" type="password" autoComplete="new-password" required value={f.password2} onChange={set('password2')} error={fields.password2} />
          <button className="btn primary block" disabled={busy}>Set password</button>
        </>}
        {mode !== 'login' && <Link to="/login" className="small">Back to sign in</Link>}
        <LangToggle compact />
      </form>
    </div>
  );
}
