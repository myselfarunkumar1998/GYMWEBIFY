import { useState } from 'react';
import { Link } from 'react-router-dom';
import { post } from '../lib/api.js';
import { useI18n } from '../lib/i18n.jsx';
import { DOW, date } from '../lib/format.js';
import { Loading, Modal, Input, useAction, useApi, Empty } from '../components/ui.jsx';
import { BackHeader, useMember } from './MemberApp.jsx';

export default function Workout() {
  const m = useMember();
  const { t } = useI18n();
  const { data, reload } = useApi('/m/workout');
  const [day, setDay] = useState(new Date().getDay());
  const [log, setLog] = useState(null);
  if (!data) return <div className="phone-body"><Loading /></div>;
  const items = (data.plan?.items || []).filter((i) => i.day_of_week === day);
  const doneToday = new Set(data.today.map((x) => x.exercise_id));
  const titleFor = (d) => data.plan?.items.find((i) => i.day_of_week === d)?.day_title;
  return (
    <div className="phone-body">
      <BackHeader title={t('Workouts')} sub={data.plan ? data.plan.name : null} right={<Link to={m.base + '/diet'} className="btn sm">{t('Diet plan')}</Link>} />
      {!data.plan ? <Empty>Your trainer has not added a plan yet.</Empty> : <>
        <div className="row" style={{ overflowX: 'auto', gap: 6 }}>
          {[1, 2, 3, 4, 5, 6, 0].map((d) => <button key={d} className={'chip' + (day === d ? ' on' : '')} aria-pressed={day === d} onClick={() => setDay(d)}>{DOW[d]}{titleFor(d) ? ` · ${titleFor(d)}` : ''}</button>)}
        </div>
        {items.length === 0 ? <Empty>{t('Rest day')}</Empty> : items.map((it) => {
          const last = data.last[it.exercise_id];
          const done = day === new Date().getDay() && doneToday.has(it.exercise_id);
          return (
            <section key={it.id} className="card" style={{ borderRadius: 18, padding: 16, borderColor: done ? '#3F4A1C' : undefined }}>
              <div className="row between"><div className="col" style={{ gap: 2 }}><b>{it.exercise}</b><span className="small muted">{it.muscle_group}</span></div>
                {day === new Date().getDay() && <button className={'btn sm ' + (done ? 'ghost' : 'primary')} onClick={() => setLog(it)}>{done ? '✓ Logged' : t('Log set')}</button>}</div>
              <div className="row wrap small t2"><span><b className="num">{it.sets}</b> sets</span><span>× <b className="num">{it.reps}</b></span>{it.weight && <span>@ <b className="num">{it.weight}</b></span>}{it.rest_sec ? <span>rest {it.rest_sec}s</span> : null}</div>
              {last && <span className="small muted">{t('Last time')}: {last.weight ? `${+last.weight} kg × ` : ''}{last.reps} · {date(last.log_date)}</span>}
              {it.video_url && <a href={it.video_url} target="_blank" rel="noreferrer" className="small">How to do it →</a>}
            </section>
          );
        })}
      </>}
      {log && <LogModal it={log} last={data.last[log.exercise_id]} onClose={() => setLog(null)} onDone={(pr) => { setLog(null); if (pr) alert('New personal record! 🎉'); reload(); }} />}
    </div>
  );
}

function LogModal({ it, last, onClose, onDone }) {
  const [f, setF] = useState({ sets: parseInt(it.sets, 10) || 3, reps: parseInt(it.reps, 10) || 10, weight: last?.weight ? +last.weight : parseFloat(it.weight) || '' });
  const { run, busy } = useAction();
  return (
    <Modal title={it.exercise} onClose={onClose} footer={<button className="btn primary block" disabled={busy} onClick={async () => { const r = await run(() => post('/m/workout/log', { exercise_id: it.exercise_id, sets: Number(f.sets), reps: Number(f.reps), weight: f.weight === '' ? null : Number(f.weight) })); if (r) onDone(r.personal_record); }}>Save</button>}>
      <div className="grid3">
        <Input label="Sets" type="number" inputMode="numeric" value={f.sets} onChange={(e) => setF({ ...f, sets: e.target.value })} />
        <Input label="Reps" type="number" inputMode="numeric" value={f.reps} onChange={(e) => setF({ ...f, reps: e.target.value })} />
        <Input label="Weight (kg)" type="number" inputMode="decimal" value={f.weight} onChange={(e) => setF({ ...f, weight: e.target.value })} />
      </div>
    </Modal>
  );
}
