import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { post } from '../lib/api.js';
import { useStaff } from '../lib/session.jsx';
import { mins, time, date } from '../lib/format.js';
import { Avatar, useApi, usePoll, Icon } from '../components/ui.jsx';
import MemberSearch from '../components/MemberSearch.jsx';

/** Front-desk kiosk: scan the member app QR with the camera, or search by name. Results show big and clear. */
export default function PunchScreen() {
  const s = useStaff();
  const [branch, setBranch] = useState(s.user.branch_id || s.branches[0]?.id);
  const [result, setResult] = useState(null);
  const [camOn, setCamOn] = useState(false);
  const [camErr, setCamErr] = useState(null);
  const videoRef = useRef(null);
  const busy = useRef(false);
  const lastToken = useRef('');
  const dash = useApi('/dashboard', { branch_id: branch });
  usePoll(() => dash.reload(true), 10000);

  const submit = async (body) => {
    if (busy.current) return;
    busy.current = true;
    try {
      const r = await post('/sessions/checkin', { ...body, branch_id: Number(branch) });
      setResult(r);
      dash.reload(true);
      try { new Audio(r.result === 'denied' ? 'data:audio/wav;base64,UklGRiQAAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQAAAAA=' : 'data:audio/wav;base64,UklGRiQAAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQAAAAA=').play().catch(() => {}); } catch { /* no audio */ }
    } catch (e) {
      setResult({ result: 'denied', name: 'Not recognised', reason: e.message });
    } finally {
      setTimeout(() => { busy.current = false; }, 1500);
    }
  };

  useEffect(() => {
    if (!camOn) return undefined;
    let stream; let raf; let stop = false;
    const canvas = document.createElement('canvas');
    (async () => {
      try {
        const jsQR = (await import('jsqr')).default;
        stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: 640, height: 480 } });
        const v = videoRef.current;
        v.srcObject = stream;
        await v.play();
        const tick = () => {
          if (stop) return;
          if (v.readyState === v.HAVE_ENOUGH_DATA) {
            canvas.width = v.videoWidth; canvas.height = v.videoHeight;
            const ctx = canvas.getContext('2d', { willReadFrequently: true });
            ctx.drawImage(v, 0, 0);
            const code = jsQR(ctx.getImageData(0, 0, canvas.width, canvas.height).data, canvas.width, canvas.height, { inversionAttempts: 'dontInvert' });
            if (code?.data?.startsWith('GM1.') && code.data !== lastToken.current) { lastToken.current = code.data; submit({ qr: code.data }); }
          }
          raf = requestAnimationFrame(tick);
        };
        tick();
      } catch (e) { setCamErr(e.message || 'Camera not available'); setCamOn(false); }
    })();
    return () => { stop = true; cancelAnimationFrame(raf); stream?.getTracks().forEach((t) => t.stop()); };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [camOn, branch]);

  useEffect(() => { if (!result) return undefined; const id = setTimeout(() => setResult(null), 8000); return () => clearTimeout(id); }, [result]);
  const d = dash.data;

  return (
    <div className="kiosk">
      <div className="col gap16">
        <div className="row between wrap">
          <div className="row gap8"><div className="logo">{s.gym.name[0]}</div><h1 style={{ fontSize: 24 }}>{s.gym.name} · Front desk</h1></div>
          <div className="row">
            {s.branches.length > 1 && <select className="input" style={{ width: 160 }} aria-label="Branch" value={branch} onChange={(e) => setBranch(e.target.value)}>{s.branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}</select>}
            <Link className="btn" to="/admin">Exit</Link>
          </div>
        </div>
        {camOn ? <video ref={videoRef} muted playsInline aria-label="Camera for scanning member QR codes" /> : (
          <div className="card" style={{ alignItems: 'center', padding: 40, textAlign: 'center' }}>
            <Icon name="qr" size={56} />
            <h2>Scan member QR</h2>
            <span className="muted">Members open My QR in their app and hold it to this camera.</span>
            <button className="btn primary" onClick={() => { setCamErr(null); setCamOn(true); }}>Turn on camera</button>
            {camErr && <span className="warn small">{camErr}</span>}
          </div>
        )}
        <div className="card">
          <h2>Or find the member</h2>
          <MemberSearch onPick={(m) => submit({ member_id: m.id })} placeholder="Type name, phone or member ID, then Enter" />
          <TrialBox onSubmit={(code) => submit({ trial_code: code })} />
        </div>
      </div>
      <div className="col gap16">
        {result ? (
          <div className={'result ' + result.result} role="status" aria-live="assertive">
            <div className="row gap16">
              {result.member && <Avatar size="lg" name={result.name} src={result.member.has_photo ? `/api/members/${result.member.id}/file/photo` : null} />}
              <div className="col gap4">
                <span style={{ fontSize: 15, fontWeight: 700 }}>{result.result === 'in' ? 'WELCOME · CHECKED IN' : result.result === 'out' ? 'GOODBYE · CHECKED OUT' : result.result === 'denied' ? 'ENTRY NOT ALLOWED' : 'ALREADY RECORDED'}</span>
                <span className="num" style={{ fontSize: 34, fontWeight: 700 }}>{result.name}</span>
              </div>
            </div>
            {result.reason && <span style={{ fontSize: 18 }}>{result.reason}</span>}
            {result.duration_min ? <span style={{ fontSize: 18 }}>Time inside: {mins(result.duration_min)}</span> : null}
            {result.plan && <span>{result.plan.name} · valid till {date(result.plan.end_date)}</span>}
            {result.dues > 0 && <span style={{ fontWeight: 700 }}>Dues pending: ₹{result.dues}. Please collect.</span>}
          </div>
        ) : (
          <div className="card hero" style={{ minHeight: 180, justifyContent: 'center' }}>
            <span className="stat-label">Inside right now</span>
            <span className="stat-value" style={{ fontSize: 64 }}>{d?.inside ?? '…'}</span>
            <span className="stat-sub">{d ? `${d.checkins} check-ins today · avg stay ${mins(d.avg_stay_min)}` : ''}</span>
          </div>
        )}
        <div className="card">
          <h2>Recent punches</h2>
          {d?.punches.slice(0, 8).map((p) => (
            <div key={p.id} className="listrow"><span className={'dot' + (p.out_at ? ' grey' : '')} /><span className="grow">{p.name}</span>
              <span className="small muted">{p.auto_closed ? 'No OUT punch' : p.out_at ? `OUT ${time(p.out_at)} · ${mins(p.duration_min)}` : `IN ${time(p.in_at)}`}</span></div>
          ))}
        </div>
      </div>
    </div>
  );
}

function TrialBox({ onSubmit }) {
  const [c, setC] = useState('');
  return (
    <form className="row" onSubmit={(e) => { e.preventDefault(); if (c) onSubmit(c); setC(''); }}>
      <label className="sr" htmlFor="trial">Trial pass code</label>
      <input id="trial" className="input" placeholder="Trial pass code" value={c} onChange={(e) => setC(e.target.value.toUpperCase())} />
      <button className="btn">Check in trial</button>
    </form>
  );
}
