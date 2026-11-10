<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Auth, DB, HttpException, Request, Util, V};
use App\Services\Notify;

final class WorkoutsController extends Base
{
    public function exercises(Request $req): array
    {
        return DB::all('SELECT * FROM exercises WHERE gym_id IS NULL OR gym_id = ? ORDER BY muscle_group, name', [$this->gym()]);
    }

    public function addExercise(Request $req): array
    {
        $d = V::check($req->json(), ['name' => 'required|string|max:100', 'muscle_group' => 'required|string|max:40', 'equipment' => 'nullable|string|max:40', 'video_url' => 'nullable|url']);
        return ['id' => DB::insert('exercises', $d + ['gym_id' => $this->gym()])];
    }

    public static function planFor(int $gymId, ?int $memberId, ?int $planId = null): ?array
    {
        $p = $planId
            ? DB::one('SELECT * FROM workout_plans WHERE id = ? AND gym_id = ?', [$planId, $gymId])
            : DB::one('SELECT * FROM workout_plans WHERE gym_id = ? AND member_id = ? ORDER BY id DESC LIMIT 1', [$gymId, $memberId]);
        if (!$p) return null;
        $p['items'] = DB::all('SELECT wi.*, e.name AS exercise, e.muscle_group, e.video_url FROM workout_items wi JOIN exercises e ON e.id = wi.exercise_id WHERE wi.plan_id = ? ORDER BY wi.day_of_week, wi.sort, wi.id', [$p['id']]);
        return $p;
    }

    public static function dietFor(int $gymId, ?int $memberId, ?int $planId = null): ?array
    {
        $p = $planId
            ? DB::one('SELECT * FROM diet_plans WHERE id = ? AND gym_id = ?', [$planId, $gymId])
            : DB::one('SELECT * FROM diet_plans WHERE gym_id = ? AND member_id = ? ORDER BY id DESC LIMIT 1', [$gymId, $memberId]);
        if (!$p) return null;
        $p['meals'] = DB::all('SELECT * FROM diet_meals WHERE diet_plan_id = ? ORDER BY sort, meal_time', [$p['id']]);
        return $p;
    }

    public function memberPlans(Request $req): array
    {
        $m = $this->memberRow($req->id());
        $g = $this->gym();
        $last = [];
        foreach (DB::all('SELECT l.exercise_id, l.sets, l.reps, l.weight, l.log_date FROM workout_logs l WHERE l.member_id = ? AND l.id IN (SELECT MAX(id) FROM workout_logs WHERE member_id = ? GROUP BY exercise_id)', [$m['id'], $m['id']]) as $l) $last[$l['exercise_id']] = $l;
        return ['member' => ['id' => (int)$m['id'], 'name' => $m['name'], 'member_code' => $m['member_code'], 'goal' => $m['goal'], 'trainer_id' => $m['trainer_id']],
            'workout' => self::planFor($g, (int)$m['id']), 'diet' => self::dietFor($g, (int)$m['id']), 'last_logged' => $last,
            'assessment' => DB::one('SELECT * FROM assessments WHERE member_id = ? ORDER BY assessed_on DESC LIMIT 1', [$m['id']]),
            'templates' => ['workout' => DB::all('SELECT id, name FROM workout_plans WHERE gym_id = ? AND is_template = 1', [$g]), 'diet' => DB::all('SELECT id, name FROM diet_plans WHERE gym_id = ? AND is_template = 1', [$g])]];
    }

    /** Replace a member's workout plan (or save a template when member_id is null). */
    public function saveWorkout(Request $req): array
    {
        Auth::require('workouts.manage');
        $memberId = $req->id() ?: null;
        if ($memberId) $this->memberRow($memberId);
        $d = V::check($req->json(), ['name' => 'required|string|max:120', 'items' => 'required|array', 'as_template' => 'nullable|bool']);
        $g = $this->gym();
        return DB::tx(function () use ($g, $memberId, $d) {
            $isTpl = !empty($d['as_template']);
            $pid = DB::insert('workout_plans', ['gym_id' => $g, 'member_id' => $isTpl ? null : $memberId, 'name' => $d['name'], 'is_template' => $isTpl ? 1 : 0, 'created_by' => Auth::staffId()]);
            foreach ($d['items'] as $i => $it) {
                $x = V::check((array)$it, ['day_of_week' => 'required|int|min:0|max:6', 'day_title' => 'nullable|string|max:60', 'exercise_id' => 'required|int',
                    'sets' => 'required|string|max:10', 'reps' => 'required|string|max:20', 'weight' => 'nullable|string|max:20', 'rest_sec' => 'nullable|int|min:0|max:900']);
                if (!DB::val('SELECT id FROM exercises WHERE id = ? AND (gym_id IS NULL OR gym_id = ?)', [$x['exercise_id'], $g])) throw HttpException::bad('Unknown exercise');
                DB::insert('workout_items', $x + ['plan_id' => $pid, 'sort' => $i]);
            }
            if ($memberId && !$isTpl) {
                DB::run('DELETE FROM workout_plans WHERE gym_id = ? AND member_id = ? AND id <> ?', [$g, $memberId, $pid]);
                Notify::member($g, $memberId, 'New workout plan', $d['name'] . ' is ready in your app.', 'workout');
            }
            return ['id' => $pid];
        });
    }

    public function saveDiet(Request $req): array
    {
        Auth::require('workouts.manage');
        $memberId = $req->id() ?: null;
        if ($memberId) $this->memberRow($memberId);
        $d = V::check($req->json(), ['name' => 'required|string|max:120', 'kcal_target' => 'nullable|int|min:500|max:6000', 'protein_target' => 'nullable|int|min:0|max:400', 'meals' => 'required|array', 'as_template' => 'nullable|bool']);
        $g = $this->gym();
        return DB::tx(function () use ($g, $memberId, $d) {
            $isTpl = !empty($d['as_template']);
            $pid = DB::insert('diet_plans', ['gym_id' => $g, 'member_id' => $isTpl ? null : $memberId, 'name' => $d['name'], 'kcal_target' => $d['kcal_target'] ?? null,
                'protein_target' => $d['protein_target'] ?? null, 'is_template' => $isTpl ? 1 : 0, 'created_by' => Auth::staffId()]);
            foreach ($d['meals'] as $i => $m) {
                $x = V::check((array)$m, ['meal_time' => 'required|string|max:10', 'food' => 'required|string|max:255', 'kcal' => 'nullable|int|min:0|max:3000', 'protein' => 'nullable|int|min:0|max:300']);
                DB::insert('diet_meals', $x + ['diet_plan_id' => $pid, 'sort' => $i]);
            }
            if ($memberId && !$isTpl) {
                DB::run('DELETE FROM diet_plans WHERE gym_id = ? AND member_id = ? AND id <> ?', [$g, $memberId, $pid]);
                Notify::member($g, $memberId, 'New diet plan', $d['name'] . ' is ready in your app.', 'diet');
            }
            return ['id' => $pid];
        });
    }

    public function template(Request $req): array
    {
        $kind = $req->params['kind'] ?? '';
        return match ($kind) {
            'workout' => self::planFor($this->gym(), null, $req->id()) ?? throw HttpException::notFound(),
            'diet' => self::dietFor($this->gym(), null, $req->id()) ?? throw HttpException::notFound(),
            default => throw HttpException::notFound(),
        };
    }

    public function assessments(Request $req): array
    {
        $m = $this->memberRow($req->id());
        return DB::all('SELECT * FROM assessments WHERE member_id = ? AND gym_id = ? ORDER BY assessed_on', [$m['id'], $this->gym()]);
    }

    public function addAssessment(Request $req): array
    {
        Auth::require('workouts.manage');
        $m = $this->memberRow($req->id());
        $d = V::check($req->json(), ['assessed_on' => 'required|date', 'weight_kg' => 'nullable|num|min:20|max:300', 'height_cm' => 'nullable|num|min:90|max:250',
            'body_fat_pct' => 'nullable|num|min:2|max:70', 'waist_in' => 'nullable|num|min:15|max:80', 'chest_in' => 'nullable|num|min:15|max:80', 'arm_in' => 'nullable|num|min:5|max:40', 'notes' => 'nullable|string|max:300']);
        $h = $d['height_cm'] ?? DB::val('SELECT height_cm FROM assessments WHERE member_id = ? AND height_cm IS NOT NULL ORDER BY assessed_on DESC LIMIT 1', [$m['id']]);
        if (!empty($d['weight_kg']) && $h) $d['bmi'] = round($d['weight_kg'] / (($h / 100) ** 2), 1);
        if ($photo = $req->input('photo')) $d['photo_path'] = \App\Core\Upload::store($this->gym(), $photo);
        return ['id' => DB::insert('assessments', $d + ['gym_id' => $this->gym(), 'member_id' => $m['id'], 'created_by' => Auth::staffId()])];
    }
}
