import { useState } from 'react';
import { post } from '../lib/api.js';
import { useI18n } from '../lib/i18n.jsx';
import { today } from '../lib/format.js';
import { Icon, Loading, Progress, useApi, Empty, useToast } from '../components/ui.jsx';
import { BackHeader, useMember } from './MemberApp.jsx';

export default function Diet() {
  const m = useMember();
  const { t } = useI18n();
  const toast = useToast();
  const [d] = useState(today());
  const { data, reload } = useApi('/m/diet', { date: d });
  const [pending, setPending] = useState({});
  if (!data) return <div className="phone-body"><Loading /></div>;
  const p = data.plan;
  const done = new Set(data.done);
  Object.entries(pending).forEach(([k, v]) => (v ? done.add(Number(k)) : done.delete(Number(k))));
  const meals = p?.meals || [];
  const kcal = meals.filter((x) => done.has(x.id)).reduce((a, x) => a + x.kcal, 0);
  const prot = meals.filter((x) => done.has(x.id)).reduce((a, x) => a + x.protein, 0);
  const toggle = async (meal) => {
    const v = !done.has(meal.id);
    setPending({ ...pending, [meal.id]: v });
    try { await post('/m/diet/toggle', { meal_id: meal.id, date: d, done: v }); reload(true); } catch (e) { toast(e.message, 'err'); setPending((x) => { const y = { ...x }; delete y[meal.id]; return y; }); }
  };
  return (
    <div className="phone-body">
      <BackHeader title={t('Diet plan')} sub={p ? p.name : null} to={m.base + '/workout'} />
      {!p ? <Empty>Your trainer has not added a diet plan yet.</Empty> : <>
        <section className="card" style={{ borderRadius: 20, padding: 16 }} aria-label="Today's target">
          <div className="row between" style={{ alignItems: 'baseline' }}><span className="num" style={{ fontSize: 22, fontWeight: 700 }}>{kcal.toLocaleString('en-IN')} <span className="small muted" style={{ fontFamily: 'var(--body)', fontWeight: 400 }}>of {(p.kcal_target || meals.reduce((a, x) => a + x.kcal, 0)).toLocaleString('en-IN')} kcal</span></span>
            <span className="small lime" style={{ fontWeight: 600 }}>{prot} g of {p.protein_target || meals.reduce((a, x) => a + x.protein, 0)} g protein</span></div>
          <Progress pct={(kcal / (p.kcal_target || 1900)) * 100} />
        </section>
        {meals.map((ml) => {
          const on = done.has(ml.id);
          return (
            <div key={ml.id} className="card tight" style={{ flexDirection: 'row', alignItems: 'center', borderRadius: 18, borderColor: on ? '#3F4A1C' : undefined }}>
              <div className="grow col" style={{ gap: 2 }}><span className="small muted">{ml.meal_time} · {ml.kcal} kcal · {ml.protein} g protein</span><b>{ml.food}</b></div>
              <button onClick={() => toggle(ml)} aria-pressed={on} aria-label={`Mark ${ml.meal_time} meal as eaten`} className="iconbtn" style={{ background: on ? 'var(--lime)' : 'var(--panel-2)', color: on ? 'var(--bg)' : '#6B717C', borderColor: on ? 'var(--lime)' : 'var(--line)' }}><Icon name="check" /></button>
            </div>
          );
        })}
        <span className="small muted">{t('Tap the tick when you finish a meal.')}</span>
      </>}
    </div>
  );
}
