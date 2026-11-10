import { useEffect, useState } from 'react';
import { post, put } from '../lib/api.js';
import { useI18n, LangToggle } from '../lib/i18n.jsx';
import { money, dateTime, date } from '../lib/format.js';
import { enablePush } from '../lib/session.jsx';
import { Avatar, Input, SignaturePad, useAction, useApi, useToast, Empty } from '../components/ui.jsx';
import { useMember } from './MemberApp.jsx';

export default function Profile() {
  const m = useMember();
  const { t, lang } = useI18n();
  const toast = useToast();
  const ref = useApi('/m/referral');
  const notes = useApi('/m/notifications');
  const [sig, setSig] = useState(null);
  const [friend, setFriend] = useState({ name: '', phone: '' });
  const { run, busy, fields } = useAction();
  const me = m.member;
  useEffect(() => { if (lang !== me.language) put('/m/me', { language: lang }).catch(() => {}); }, [lang, me.language]);
  useEffect(() => { const id = window.location.hash.slice(1); if (id) setTimeout(() => document.getElementById(id)?.scrollIntoView({ behavior: 'smooth' }), 300); }, []);
  const share = () => {
    const text = `Join me at ${m.gym.name}! Use my member code ${ref.data?.code} when you sign up. Book a free trial: ${window.location.origin}/g/${m.gym.slug}`;
    if (navigator.share) navigator.share({ text }).catch(() => {}); else window.open('https://wa.me/?text=' + encodeURIComponent(text), '_blank');
  };
  return (
    <div className="phone-body">
      <header className="row gap16">
        <Avatar size="lg" name={me.name} src={me.has_photo ? '/api/m/photo' : null} />
        <div className="col" style={{ gap: 2 }}><h1 style={{ fontSize: 22 }}>{me.name}</h1><span className="small muted">{me.member_code} · +91 {me.phone}</span><span className="small muted">{me.fingerprint_enrolled ? 'Fingerprint ✓' : 'Fingerprint not enrolled'}{me.face_enrolled ? ' · Face ✓' : ''}</span></div>
      </header>
      <section className="card" style={{ borderRadius: 18 }}><b>{t('Language')}</b><LangToggle /></section>
      <section className="card" id="notifications" style={{ borderRadius: 18 }}>
        <div className="row between"><b>{t('Notifications')}</b><button className="btn sm" onClick={() => enablePush(m.vapid_public, '/m/push/subscribe').then(() => toast('Notifications on')).catch((e) => toast(e.message, 'err'))}>Turn on</button></div>
        {notes.data?.length === 0 && <span className="small muted">No notifications yet.</span>}
        {notes.data?.slice(0, 8).map((n) => <div key={n.id} className="listrow"><div className="grow col" style={{ gap: 2 }}><span style={{ fontWeight: n.read_at ? 400 : 600 }}>{n.title}</span>{n.body && <span className="small muted">{n.body}</span>}</div><span className="small muted">{dateTime(n.created_at)}</span></div>)}
      </section>
      <section className="card" style={{ borderRadius: 18 }}>
        <b>{t('Refer a friend')}</b>
        <span className="small muted">You and your friend each get {money(ref.data?.credit_per_referral)} credit when they join. Your code: <b className="lime">{ref.data?.code}</b> · credit {money(ref.data?.credit_balance)}</span>
        <div className="grid2"><Input label="Friend's name" value={friend.name} onChange={(e) => setFriend({ ...friend, name: e.target.value })} error={fields.name} /><Input label="Friend's phone" type="tel" value={friend.phone} onChange={(e) => setFriend({ ...friend, phone: e.target.value })} error={fields.phone} /></div>
        <div className="row"><button className="btn primary" disabled={busy || !friend.name || !friend.phone} onClick={async () => { if (await run(() => post('/m/referral', friend), 'Thanks! The gym will contact your friend.')) setFriend({ name: '', phone: '' }); }}>Send to gym</button><button className="btn" onClick={share}>Share on WhatsApp</button></div>
        {ref.data?.referred?.length > 0 && <span className="small muted">Joined from your referrals: {ref.data.referred.map((x) => x.name).join(', ')}</span>}
      </section>
      <section className="card" id="waiver" style={{ borderRadius: 18 }}>
        <b>Consent &amp; waiver</b>
        {me.waiver_signed ? <span className="small lime">Signed ✓</span> : <>
          <div className="inner small" style={{ whiteSpace: 'pre-line' }}>{m.gym.waiver_text}</div>
          <SignaturePad onChange={setSig} />
          <button className="btn primary" disabled={busy || !sig} onClick={async () => { if (await run(() => post('/m/waiver', { signature: sig }), 'Signed. Thank you!')) m.reload(); }}>{t('Sign the waiver')}</button>
        </>}
      </section>
      <section className="card" style={{ borderRadius: 18 }}>
        <b>Gym</b><span className="small muted">{m.gym.name}{m.gym.phone ? ` · ${m.gym.phone}` : ''}</span>
        {m.gym.phone && <div className="row"><a className="btn sm" href={`tel:${m.gym.phone}`}>Call</a><a className="btn sm" href={`https://wa.me/91${m.gym.phone}`} target="_blank" rel="noreferrer">WhatsApp</a></div>}
        <span className="small muted">Install this app: in your browser menu choose "Add to Home screen".</span>
      </section>
      <button className="btn block danger" onClick={m.logout}>{t('Sign out')}</button>
    </div>
  );
}
