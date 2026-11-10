import { put } from '../lib/api.js';
import { useI18n } from '../lib/i18n.jsx';
import { dateTime } from '../lib/format.js';
import { Check, Loading, Progress, useApi, Empty, useAction } from '../components/ui.jsx';
import { BackHeader, useMember } from './MemberApp.jsx';

export default function Challenges() {
  const m = useMember();
  const { t } = useI18n();
  const { data } = useApi('/m/challenges');
  const { run } = useAction();
  if (!data) return <div className="phone-body"><Loading /></div>;
  return (
    <div className="phone-body">
      <BackHeader title={t('Challenges')} />
      {data.challenges.length === 0 && <Empty>No challenges running right now.</Empty>}
      {data.challenges.map((c, idx) => (
        <div key={c.id} className="col gap12" style={{ gap: 12 }}>
          {idx === 0 ? (
            <section className="card hero" style={{ borderRadius: 22 }} aria-label="Your challenge">
              <span className="small" style={{ fontWeight: 600 }}>{c.days_left} days left · {c.prize ? `Prize: ${c.prize}` : ''}</span>
              <span className="num" style={{ fontSize: 24, fontWeight: 700, lineHeight: 1.1 }}>{c.name}</span>
              <div className="progress" style={{ background: 'var(--lime-dim)', height: 10 }}><div style={{ width: `${Math.min(100, (c.my_score / c.target) * 100)}%`, background: 'var(--bg)' }} /></div>
              <div className="row between small" style={{ fontWeight: 600 }}><span>{c.my_score} of {c.target} {c.metric}</span>{c.my_rank && <span>Rank #{c.my_rank}</span>}</div>
            </section>
          ) : (
            <section className="card" style={{ borderRadius: 18 }}><b>{c.name}</b><Progress pct={(c.my_score / c.target) * 100} /><span className="small muted">{c.my_score} of {c.target} {c.metric}{c.my_rank ? ` · rank #${c.my_rank}` : ''}</span></section>
          )}
          <section className="card" style={{ borderRadius: 20, padding: '14px 16px 4px', gap: 0 }}>
            <div className="row between" style={{ paddingBottom: 8 }}><h2 style={{ fontSize: 16 }}>{t('Leaderboard')}</h2><span className="small muted">{c.metric === 'hours' ? 'Hours in gym' : 'Visits'}</span></div>
            {c.leaderboard.map((r) => (
              <div key={r.rank} className="listrow" style={{ minHeight: 50 }}>
                <span className="num" style={{ width: 28, fontWeight: 700, color: r.me ? 'var(--lime)' : 'var(--muted)' }}>{r.rank}</span>
                <span className="grow" style={{ fontWeight: r.me ? 600 : 500, color: r.me ? 'var(--lime)' : undefined }}>{r.me ? 'You' : r.name}</span>
                <span className="num" style={{ fontWeight: 600, color: r.me ? 'var(--lime)' : undefined }}>{r.score}{c.metric === 'hours' ? 'h' : ''}</span>
              </div>
            ))}
          </section>
        </div>
      ))}
      {data.announcements.map((a, i) => <section key={i} className="card" style={{ borderRadius: 20, gap: 6 }}><span className="small muted">{t('From the gym')} · {dateTime(a.created_at)}</span><b>{a.title}</b><span className="small t2">{a.body}</span></section>)}
      <Check label={t('Show my name on the leaderboard')} checked={m.member.leaderboard_opt_in} onChange={async (v) => { if (await run(() => put('/m/me', { leaderboard_opt_in: v }))) m.reload(); }} />
    </div>
  );
}
