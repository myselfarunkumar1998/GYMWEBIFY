import { useEffect, useMemo, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { get, post, put } from '../lib/api.js';
import { useStaff } from '../lib/session.jsx';
import { DOW, date, today } from '../lib/format.js';
import { ErrorBox, Input, Loading, Modal, Select, useAction, useApi, Empty } from '../components/ui.jsx';

export default function WorkoutBuilder() {
  const { id } = useParams();
  const s = useStaff();
  const { data, error, loading, reload } = useApi(`/members/${id}/plans`);
  const ex = useApi('/exercises');
  const [day, setDay] = useState(1);
  const [plan, setPlan] = useState(null);
  const [diet, setDiet] = useState(null);
  const [assess, setAssess] = useState(false);
  const { run, busy } = useAction();

  useEffect(() => {
    if (!data) return;
    setPlan({ name: data.workout?.name || 'Training plan', items: (data.workout?.items || []).map((i) => ({ day_of_week: i.day_of_week, day_title: i.day_title || '', exercise_id: i.exercise_id, sets: i.sets, reps: i.reps, weight: i.weight || '', rest_sec: i.rest_sec || '' })) });
    setDiet({ name: data.diet?.name || 'Diet chart', kcal_target: data.diet?.kcal_target || '', protein_target: data.diet?.protein_target || '', meals: (data.diet?.meals || []).map((m) => ({ meal_time: m.meal_time, food: m.food, kcal: m.kcal, protein: m.protein })) });
    const days = [...new Set((data.workout?.items || []).map((i) => i.day_of_week))];
    if (days.length) setDay(days.includes(new Date().getDay()) ? new Date().getDay() : days[0]);
  }, [data]);

  const exName = useMemo(() => Object.fromEntries((ex.data || []).map((e) => [e.id, e])), [ex.data]);
  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox error={error} onRetry={reload} />;
  if (!plan || !diet) return <Loading />;
  const m = data.member;
  const dayItems = plan.items.map((it, idx) => ({ ...it, idx })).filter((it) => it.day_of_week === day);
  const dayTitle = (d) => plan.items.find((i) => i.day_of_week === d)?.day_title || '';
  const upd = (idx, k, v) => setPlan({ ...plan, items: plan.items.map((x, i) => (i === idx ? { ...x, [k]: v } : x)) });
  const kcal = diet.meals.reduce((a, x) => a + Number(x.kcal || 0), 0);
  const prot = diet.meals.reduce((a, x) => a + Number(x.protein || 0), 0);
  const canEdit = s.can('workouts.manage');

  const saveWorkout = (asTemplate) => run(() => (asTemplate
    ? post('/workout-templates', { name: plan.name + ' (template)', items: plan.items, as_template: true })
    : put(`/members/${id}/workout`, { name: plan.name, items: plan.items.map((i) => ({ ...i, rest_sec: i.rest_sec ? Number(i.rest_sec) : null })) })), asTemplate ? 'Saved as template' : 'Workout sent to member app');
  const saveDiet = () => run(() => put(`/members/${id}/diet`, { ...diet, kcal_target: diet.kcal_target ? Number(diet.kcal_target) : null, protein_target: diet.protein_target ? Number(diet.protein_target) : null, meals: diet.meals.map((x) => ({ ...x, kcal: Number(x.kcal || 0), protein: Number(x.protein || 0) })) }), 'Diet sent to member app');
  const applyTemplate = async (tid) => {
    const t = await get(`/templates/workout/${tid}`);
    setPlan({ name: t.name.replace(' (template)', ''), items: t.items.map((i) => ({ day_of_week: i.day_of_week, day_title: i.day_title || '', exercise_id: i.exercise_id, sets: i.sets, reps: i.reps, weight: i.weight || '', rest_sec: i.rest_sec || '' })) });
  };

  return (
    <>
      <Link to={'/admin/members/' + id} className="small" style={{ textDecoration: 'none' }}>← {m.name}</Link>
      <header className="topbar">
        <div className="col gap4"><h1>Workout &amp; diet builder</h1><span className="muted">{m.name} · {m.member_code} · Goal: {m.goal || '—'}</span></div>
        {canEdit && <div className="row wrap">
          {data.templates.workout.length > 0 && <select className="input" style={{ width: 200 }} aria-label="Apply a template" value="" onChange={(e) => e.target.value && applyTemplate(e.target.value)}><option value="">Apply template…</option>{data.templates.workout.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}</select>}
          <button className="btn" disabled={busy} onClick={() => saveWorkout(true)}>Save as template</button>
          <button className="btn primary" disabled={busy} onClick={() => saveWorkout(false)}>Send to member app</button>
        </div>}
      </header>
      <div className="chips" role="group" aria-label="Day">
        {[1, 2, 3, 4, 5, 6, 0].map((d) => <button key={d} className={'chip' + (day === d ? ' on' : '')} aria-pressed={day === d} onClick={() => setDay(d)}>{DOW[d]}{dayTitle(d) ? ` · ${dayTitle(d)}` : ' · Rest'}</button>)}
      </div>
      <div className="split">
        <section className="card main">
          <div className="row between wrap">
            <Input label="Plan name" value={plan.name} onChange={(e) => setPlan({ ...plan, name: e.target.value })} className="grow" />
            <Input label={`${DOW[day]} title`} value={dayTitle(day)} placeholder="e.g. Push" onChange={(e) => setPlan({ ...plan, items: plan.items.map((x) => (x.day_of_week === day ? { ...x, day_title: e.target.value } : x)) })} />
          </div>
          {dayItems.length === 0 ? <Empty>Rest day. Add exercises below to make it a training day.</Empty> : (
            <div className="tablebox"><table className="t" style={{ minWidth: 640 }}>
              <thead><tr><th>Exercise</th><th>Sets</th><th>Reps</th><th>Weight</th><th>Rest (s)</th><th>Last logged</th><th></th></tr></thead>
              <tbody>{dayItems.map((it) => {
                const last = data.last_logged[it.exercise_id];
                return (
                  <tr key={it.idx}>
                    <td><div className="col" style={{ gap: 0 }}><span style={{ fontWeight: 500 }}>{exName[it.exercise_id]?.name}</span><span className="small muted">{exName[it.exercise_id]?.muscle_group}</span></div></td>
                    {['sets', 'reps', 'weight', 'rest_sec'].map((k) => <td key={k}><input className="input" style={{ width: k === 'weight' ? 90 : 70, height: 36 }} aria-label={k} value={it[k]} disabled={!canEdit} onChange={(e) => upd(it.idx, k, e.target.value)} /></td>)}
                    <td className="small muted">{last ? `${last.weight ? +last.weight + ' kg × ' : ''}${last.reps} · ${date(last.log_date)}` : '—'}</td>
                    <td>{canEdit && <button className="btn sm danger" aria-label="Remove exercise" onClick={() => setPlan({ ...plan, items: plan.items.filter((_, i) => i !== it.idx) })}>✕</button>}</td>
                  </tr>
                );
              })}</tbody>
            </table></div>
          )}
          {canEdit && (
            <select className="input" aria-label="Add exercise from library" value="" onChange={(e) => e.target.value && setPlan({ ...plan, items: [...plan.items, { day_of_week: day, day_title: dayTitle(day), exercise_id: Number(e.target.value), sets: '3', reps: '10', weight: '', rest_sec: '60' }] })}>
              <option value="">+ Add exercise from library</option>
              {Object.entries((ex.data || []).reduce((g, e) => { (g[e.muscle_group] ||= []).push(e); return g; }, {})).map(([grp, list]) => (
                <optgroup key={grp} label={grp}>{list.map((e) => <option key={e.id} value={e.id}>{e.name}{e.equipment ? ` (${e.equipment})` : ''}</option>)}</optgroup>
              ))}
            </select>
          )}
        </section>
        <div className="side">
          <section className="card">
            <div className="row between"><h2>Diet chart</h2><span className="small muted">{kcal} kcal · {prot} g protein</span></div>
            <div className="grid2">
              <Input label="kcal target" type="number" value={diet.kcal_target} onChange={(e) => setDiet({ ...diet, kcal_target: e.target.value })} />
              <Input label="Protein target (g)" type="number" value={diet.protein_target} onChange={(e) => setDiet({ ...diet, protein_target: e.target.value })} />
            </div>
            {diet.meals.map((ml, i) => (
              <div key={i} className="inner col gap8">
                <div className="row"><input className="input" style={{ width: 96, height: 36 }} aria-label="Meal time" value={ml.meal_time} onChange={(e) => setDiet({ ...diet, meals: diet.meals.map((x, j) => (j === i ? { ...x, meal_time: e.target.value } : x)) })} />
                  <button className="btn sm danger" style={{ marginLeft: 'auto' }} aria-label="Remove meal" onClick={() => setDiet({ ...diet, meals: diet.meals.filter((_, j) => j !== i) })}>✕</button></div>
                <input className="input" style={{ height: 36 }} aria-label="Food" value={ml.food} onChange={(e) => setDiet({ ...diet, meals: diet.meals.map((x, j) => (j === i ? { ...x, food: e.target.value } : x)) })} />
                <div className="row"><input className="input" style={{ height: 36 }} type="number" aria-label="kcal" placeholder="kcal" value={ml.kcal} onChange={(e) => setDiet({ ...diet, meals: diet.meals.map((x, j) => (j === i ? { ...x, kcal: e.target.value } : x)) })} />
                  <input className="input" style={{ height: 36 }} type="number" aria-label="Protein" placeholder="protein g" value={ml.protein} onChange={(e) => setDiet({ ...diet, meals: diet.meals.map((x, j) => (j === i ? { ...x, protein: e.target.value } : x)) })} /></div>
              </div>
            ))}
            {canEdit && <div className="row wrap"><button className="btn sm" onClick={() => setDiet({ ...diet, meals: [...diet.meals, { meal_time: '8:00 AM', food: '', kcal: '', protein: '' }] })}>+ Meal</button><button className="btn sm primary" disabled={busy} onClick={saveDiet}>Save diet chart</button></div>}
          </section>
          <section className="card">
            <h2>Assessment{data.assessment ? ` · ${date(data.assessment.assessed_on)}` : ''}</h2>
            {data.assessment ? (
              <div className="grid3">
                <div className="inner"><span className="small muted">Weight</span><div className="num" style={{ fontWeight: 600, fontSize: 18 }}>{data.assessment.weight_kg ? `${+data.assessment.weight_kg} kg` : '—'}</div></div>
                <div className="inner"><span className="small muted">BMI</span><div className="num" style={{ fontWeight: 600, fontSize: 18 }}>{data.assessment.bmi ?? '—'}</div></div>
                <div className="inner"><span className="small muted">Body fat</span><div className="num" style={{ fontWeight: 600, fontSize: 18 }}>{data.assessment.body_fat_pct ? `${+data.assessment.body_fat_pct}%` : '—'}</div></div>
              </div>
            ) : <span className="small muted">No assessment yet.</span>}
            {canEdit && <button className="btn ghost" onClick={() => setAssess(true)}>+ New assessment</button>}
          </section>
        </div>
      </div>
      {assess && <AssessModal id={id} onClose={() => setAssess(false)} onDone={() => { setAssess(false); reload(); }} />}
    </>
  );
}

function AssessModal({ id, onClose, onDone }) {
  const [f, setF] = useState({ assessed_on: today(), weight_kg: '', height_cm: '', body_fat_pct: '', waist_in: '', chest_in: '', arm_in: '', notes: '' });
  const { run, busy, fields } = useAction();
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value });
  const save = async () => {
    const body = Object.fromEntries(Object.entries(f).filter(([, v]) => v !== '').map(([k, v]) => [k, ['assessed_on', 'notes'].includes(k) ? v : Number(v)]));
    if (await run(() => post(`/members/${id}/assessments`, body), 'Assessment saved')) onDone();
  };
  return (
    <Modal title="New assessment" onClose={onClose} footer={<><button className="btn" onClick={onClose}>Cancel</button><button className="btn primary" disabled={busy} onClick={save}>Save</button></>}>
      <div className="grid3">
        <Input label="Date" type="date" value={f.assessed_on} onChange={set('assessed_on')} />
        <Input label="Weight (kg)" type="number" step="0.1" value={f.weight_kg} onChange={set('weight_kg')} error={fields.weight_kg} />
        <Input label="Height (cm)" type="number" value={f.height_cm} onChange={set('height_cm')} error={fields.height_cm} />
        <Input label="Body fat %" type="number" step="0.1" value={f.body_fat_pct} onChange={set('body_fat_pct')} />
        <Input label="Waist (in)" type="number" step="0.1" value={f.waist_in} onChange={set('waist_in')} />
        <Input label="Chest (in)" type="number" step="0.1" value={f.chest_in} onChange={set('chest_in')} />
        <Input label="Arm (in)" type="number" step="0.1" value={f.arm_in} onChange={set('arm_in')} />
      </div>
      <Input label="Notes" value={f.notes} onChange={set('notes')} />
    </Modal>
  );
}
