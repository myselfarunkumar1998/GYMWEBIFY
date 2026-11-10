<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\{Auth, DB, HttpException};

final class Provision
{
    public const DEFAULT_SETTINGS = [
        'invoice_prefix' => 'INV',
        'prices_include_gst' => false,
        'google_review_url' => null,
        'waiver_text' => "I confirm that I am physically fit to exercise and will follow the trainer's instructions. I understand that exercise carries a risk of injury and I take part at my own risk. I agree to the gym's rules and to the use of my fingerprint/face only for entry.",
        'rules' => [
            'grace_days' => 3, 'joining_fee' => 500, 'freeze_min_days' => 7, 'freeze_fee' => 200, 'transfer_fee' => 1000,
            'referral_credit' => 500, 'block_dues_after_days' => 15, 'trial_days' => 3, 'renewal_reminder_days' => [7, 3, 1],
            'dues_reminder_every_days' => 3, 'review_after_days' => 30, 'emi_parts_yearly' => 3, 'late_cancel_hours' => 2,
        ],
        'automations' => [
            'welcome' => true, 'payment_receipt' => true, 'renewal_reminder' => true, 'dues_reminder' => true, 'birthday' => true,
            'class_reminder' => true, 'whatsapp_bot' => true, 'lead_followup' => true, 'review_request' => true, 'pt_low' => true,
            'expired_winback' => true, 'waitlist_promoted' => true, 'trial_booked' => true, 'otp' => true, 'payment_link' => true, 'winback' => true,
        ],
        'devices' => ['allowed_group' => 1, 'blocked_group' => 2],
        'integrations' => [],
    ];

    public static function slugify(string $name): string
    {
        $s = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $name), '-'));
        return substr($s ?: 'gym', 0, 50);
    }

    /** @return array{gym_id:int, branch_id:int, owner_id:int} */
    public static function createGym(array $gym, array $owner, array $branch = []): array
    {
        return DB::tx(function () use ($gym, $owner, $branch) {
            $slug = self::slugify($gym['slug'] ?? $gym['name']);
            $base = $slug; $i = 2;
            while (DB::val('SELECT id FROM gyms WHERE slug = ?', [$slug])) $slug = $base . '-' . $i++;
            if (DB::val('SELECT id FROM users WHERE email = ?', [strtolower($owner['email'])])) throw HttpException::bad('That email is already in use', ['email' => 'Already in use']);
            $gymId = DB::insert('gyms', [
                'slug' => $slug, 'name' => $gym['name'], 'legal_name' => $gym['legal_name'] ?? null, 'gstin' => $gym['gstin'] ?? null,
                'state_code' => $gym['state_code'] ?? '33', 'address' => $gym['address'] ?? null, 'phone' => $gym['phone'] ?? null,
                'email' => $gym['email'] ?? $owner['email'], 'default_language' => $gym['default_language'] ?? 'en',
                'settings' => json_encode(self::DEFAULT_SETTINGS), 'status' => $gym['status'] ?? 'active',
                'subscription_plan' => $gym['subscription_plan'] ?? 'standard', 'subscription_until' => $gym['subscription_until'] ?? date('Y-m-d', strtotime('+30 days')),
            ]);
            $branchId = DB::insert('branches', [
                'gym_id' => $gymId, 'name' => $branch['name'] ?? 'Main branch', 'address' => $branch['address'] ?? ($gym['address'] ?? null),
                'capacity' => $branch['capacity'] ?? 100,
            ]);
            $ownerId = DB::insert('users', [
                'gym_id' => $gymId, 'name' => $owner['name'], 'email' => strtolower($owner['email']), 'phone' => $owner['phone'] ?? null,
                'password_hash' => Auth::hashPassword($owner['password']), 'role' => 'owner', 'title' => 'Owner',
            ]);
            Messenger::seedTemplates($gymId);
            return ['gym_id' => $gymId, 'branch_id' => $branchId, 'owner_id' => $ownerId, 'slug' => $slug];
        });
    }

    public const EXERCISES = [
        ['Bench press', 'Chest', 'Barbell'], ['Incline dumbbell press', 'Chest', 'Dumbbell'], ['Push-up', 'Chest', 'Bodyweight'], ['Cable fly', 'Chest', 'Cable'],
        ['Overhead press', 'Shoulders', 'Barbell'], ['Lateral raise', 'Shoulders', 'Dumbbell'], ['Rear delt fly', 'Shoulders', 'Dumbbell'],
        ['Triceps pushdown', 'Triceps', 'Cable'], ['Skull crusher', 'Triceps', 'Barbell'], ['Dips', 'Triceps', 'Bodyweight'],
        ['Deadlift', 'Back', 'Barbell'], ['Lat pulldown', 'Back', 'Cable'], ['Seated row', 'Back', 'Cable'], ['Pull-up', 'Back', 'Bodyweight'], ['Bent-over row', 'Back', 'Barbell'],
        ['Barbell curl', 'Biceps', 'Barbell'], ['Hammer curl', 'Biceps', 'Dumbbell'],
        ['Squat', 'Legs', 'Barbell'], ['Leg press', 'Legs', 'Machine'], ['Romanian deadlift', 'Legs', 'Barbell'], ['Walking lunge', 'Legs', 'Dumbbell'], ['Leg curl', 'Legs', 'Machine'], ['Calf raise', 'Legs', 'Machine'],
        ['Plank', 'Core', 'Bodyweight'], ['Hanging leg raise', 'Core', 'Bodyweight'], ['Cable crunch', 'Core', 'Cable'],
        ['Treadmill incline walk', 'Cardio', 'Treadmill'], ['Cycling', 'Cardio', 'Bike'], ['Rowing machine', 'Cardio', 'Rower'], ['Skipping', 'Cardio', 'Rope'],
    ];

    public static function seedExercises(): void
    {
        if (DB::val('SELECT COUNT(*) FROM exercises WHERE gym_id IS NULL') > 0) return;
        foreach (self::EXERCISES as [$n, $g, $e]) DB::insert('exercises', ['gym_id' => null, 'name' => $n, 'muscle_group' => $g, 'equipment' => $e]);
    }
}
