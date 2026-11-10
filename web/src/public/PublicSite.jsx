import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { get, post } from '../lib/api.js';
import { money, hm, DOW, today } from '../lib/format.js';
import { Input, Loading, Select, useAction } from '../components/ui.jsx';
import { LangToggle } from '../lib/i18n.jsx';

/** Free gym website with plans, timetable and a trial booking form (creates a lead). */
export default function PublicSite() {
  const { slug } = useParams();
  const [g, setG] = useState(null);
  const [f, setF] = useState({ name: '', phone: '', interest: '', trial_date: '', branch_id: '', website: '' });
  const [done, setDone] = useState(null);
  const { run, busy, fields } = useAction();
  useEffect(() => { get('/public/gym/' + slug).then((d) => { setG(d); document.title = d.name; }).catch(() => setG(false)); }, [slug]);
  if (g === null) return <Loading />;
  if (g === false) return <div className="content"><h1>Gym not found</h1></div>;
  const memberships = g.plans.filter((p) => p.kind === 'membership');
  const pt = g.plans.filter((p) => p.kind === 'pt');
  const submit = async (e) => {
    e.preventDefault();
    const r = await run(() => post(`/public/gym/${slug}/trial`, { ...f, branch_id: f.branch_id ? Number(f.branch_id) : null, trial_date: f.trial_date || null }));
    if (r) setDone(r);
  };
  return (
    <div style={{ maxWidth: 1100, margin: '0 auto', padding: '24px 16px 64px' }} className="col gap24">
      <header className="row between wrap">
        <div className="row gap8"><div className="logo">{g.logo_url ? <img src={g.logo_url} alt="" /> : g.name[0]}</div><span style={{ fontFamily: 'var(--display)', fontWeight: 700, fontSize: 20 }}>{g.name}</span></div>
        <div className="row wrap"><LangToggle compact /><Link className="btn" to={`/m/${slug}`}>Member login</Link><a className="btn primary" href="#trial">Book a free trial</a></div>
      </header>
      <section className="card hero" style={{ padding: 32, borderRadius: 28 }}>
        <h1 style={{ fontSize: 'clamp(30px, 5vw, 52px)', lineHeight: 1.05, color: 'var(--bg)' }}>Train with us. First {g.trial_days} days free.</h1>
        {g.about && <p style={{ margin: 0, fontSize: 16, maxWidth: 680 }}>{g.about}</p>}
        <div className="row wrap"><a className="btn" style={{ background: 'var(--bg)', color: 'var(--lime)', borderColor: 'var(--bg)' }} href="#trial">Book your trial</a>{g.phone && <a className="btn" style={{ background: 'transparent', color: 'var(--bg)', borderColor: 'var(--bg)' }} href={`https://wa.me/91${g.phone}`} target="_blank" rel="noreferrer">WhatsApp us</a>}</div>
      </section>
      <section className="col gap16"><h2 style={{ fontSize: 24 }}>Plans</h2>
        <div className="grid3">{memberships.map((p) => <div key={p.id} className="card"><b style={{ fontSize: 17 }}>{p.name}</b><span className="num" style={{ fontSize: 28, fontWeight: 700 }}>{money(p.price)}<span className="small muted" style={{ fontFamily: 'var(--body)' }}> + GST</span></span><span className="small muted">{p.description || `${p.duration_days} days`}{p.access_start ? ` · ${hm(p.access_start)}–${hm(p.access_end)}` : ''}</span></div>)}</div>
        {pt.length > 0 && <div className="row wrap">{pt.map((p) => <span key={p.id} className="pill grey">{p.name} · {money(p.price)}</span>)}</div>}
      </section>
      {g.classes.length > 0 && <section className="col gap16"><h2 style={{ fontSize: 24 }}>Class timetable</h2>
        <div className="card"><div className="tablebox"><table className="t"><thead><tr><th>Day</th><th>Time</th><th>Class</th><th>Coach</th></tr></thead>
          <tbody>{g.classes.map((c, i) => <tr key={i}><td>{DOW[c.day_of_week]}</td><td>{hm(c.start_time)}</td><td style={{ fontWeight: 600 }}>{c.name}</td><td>{c.coach || '—'}</td></tr>)}</tbody></table></div></div></section>}
      <div className="split">
        <section className="card main" id="trial">
          <h2 style={{ fontSize: 22 }}>Book a free trial</h2>
          {done ? <div className="banner info">Thanks! {done.trial_pass ? <>Your trial pass is <b>{done.trial_pass}</b>. We have sent it on WhatsApp too. Show it at the front desk.</> : 'Our team will call you shortly.'}</div> : (
            <form onSubmit={submit} className="col gap16">
              <div className="grid2">
                <Input label="Your name" required value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} error={fields.name} />
                <Input label="Mobile (WhatsApp)" type="tel" required value={f.phone} onChange={(e) => setF({ ...f, phone: e.target.value })} error={fields.phone} />
                <Input label="Preferred date" type="date" min={today()} value={f.trial_date} onChange={(e) => setF({ ...f, trial_date: e.target.value })} />
                {g.branches.length > 1 && <Select label="Branch" value={f.branch_id} onChange={(e) => setF({ ...f, branch_id: e.target.value })} placeholder="Any" options={g.branches.map((b) => ({ value: b.id, label: b.name }))} />}
              </div>
              <Input label="Your goal" value={f.interest} onChange={(e) => setF({ ...f, interest: e.target.value })} placeholder="e.g. weight loss, morning batch" />
              <input type="text" name="website" tabIndex={-1} autoComplete="off" aria-hidden="true" value={f.website} onChange={(e) => setF({ ...f, website: e.target.value })} style={{ position: 'absolute', left: -9999, width: 1, height: 1 }} />
              <button className="btn primary" style={{ alignSelf: 'flex-start' }} disabled={busy}>Book my free trial</button>
            </form>
          )}
        </section>
        <section className="card side">
          <h2>Visit us</h2>
          {g.branches.map((b) => <div key={b.id} className="inner col gap4"><b>{b.name}</b><span className="small t2">{b.address}</span><span className="small muted">{hm(b.open_time)} – {hm(b.close_time)}{b.phone ? ` · ${b.phone}` : ''}</span>
            <a className="small" href={`https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(b.address || g.name)}`} target="_blank" rel="noreferrer">Directions →</a></div>)}
          {g.google_review_url && <a href={g.google_review_url} target="_blank" rel="noreferrer" className="btn">Read our Google reviews</a>}
        </section>
      </div>
      <footer className="small muted">© {new Date().getFullYear()} {g.name}</footer>
    </div>
  );
}
