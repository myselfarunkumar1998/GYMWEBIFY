<?php
declare(strict_types=1);

use App\Controllers as C;
use App\Core\Router;

$r = new Router();
$none = ['auth' => 'none'];
$member = ['auth' => 'member'];
$hook = ['auth' => 'none', 'csrf' => false];
$p = fn(string $perm, array $extra = []) => ['perm' => $perm] + $extra;

// Public
$r->get('/health', [C\PublicController::class, 'health'], $none);
$r->get('/public/gym/{slug:s}', [C\PublicController::class, 'show'], $none);
$r->post('/public/gym/{slug:s}/trial', [C\PublicController::class, 'trial'], $none + ['rate' => ['trial', 5, 3600]]);
$r->get('/public/demo-pay/{id}', [C\PublicController::class, 'demoPay'], $none);
$r->post('/public/demo-pay/{id}', [C\PublicController::class, 'demoPay'], $none + ['rate' => ['demopay', 20, 3600]]);
$r->get('/public/logo/{id}', [C\SettingsController::class, 'logo'], $none);

// Staff auth
$r->post('/auth/login', [C\AuthController::class, 'login'], $none + ['rate' => ['login', 20, 900]]);
$r->post('/auth/logout', [C\AuthController::class, 'logout'], $none);
$r->get('/auth/me', [C\AuthController::class, 'me']);
$r->post('/auth/password', [C\AuthController::class, 'changePassword']);
$r->put('/auth/profile', [C\AuthController::class, 'updateProfile']);
$r->post('/auth/forgot', [C\AuthController::class, 'forgot'], $none + ['rate' => ['forgot', 5, 3600]]);
$r->post('/auth/reset', [C\AuthController::class, 'reset'], $none + ['rate' => ['reset', 10, 3600]]);

// Dashboard & insights
$r->get('/dashboard', [C\DashboardController::class, 'index'], $p('dashboard.view'));
$r->get('/insights', [C\InsightsController::class, 'index'], $p('insights.view'));
$r->post('/insights/winback', [C\InsightsController::class, 'winback'], $p('insights.view'));
$r->post('/insights/rescore', [C\InsightsController::class, 'rescore'], $p('insights.view'));

// Members
$r->get('/members', [C\MembersController::class, 'index'], $p('members.view', ['apikey' => true]));
$r->get('/members/export', [C\MembersController::class, 'export'], $p('members.view'));
$r->post('/members', [C\MembersController::class, 'store'], $p('members.write', ['apikey' => true]));
$r->post('/members/import', [C\MembersController::class, 'import'], $p('members.write'));
$r->get('/members/{id}', [C\MembersController::class, 'show'], $p('members.view', ['apikey' => true]));
$r->put('/members/{id}', [C\MembersController::class, 'update'], $p('members.write', ['apikey' => true]));
$r->delete('/members/{id}', [C\MembersController::class, 'destroy'], $p('members.delete'));
$r->post('/members/{id}/upload/{kind:s}', [C\MembersController::class, 'upload'], $p('members.write'));
$r->get('/members/{id}/file/{kind:s}', [C\MembersController::class, 'file'], $p('members.view'));
$r->post('/members/{id}/waiver', [C\MembersController::class, 'waiver'], $p('members.write'));
$r->post('/members/{id}/block', [C\MembersController::class, 'block'], $p('members.write'));
$r->post('/members/{id}/enroll', [C\MembersController::class, 'enroll'], $p('members.write'));
$r->post('/members/{id}/message', [C\MembersController::class, 'message'], $p('members.write'));
$r->get('/members/{id}/visits', [C\MembersController::class, 'visits'], $p('members.view'));
$r->post('/members/{id}/memberships', [C\MembersController::class, 'sell'], $p('billing.collect'));
$r->get('/members/{id}/plans', [C\WorkoutsController::class, 'memberPlans'], $p('members.view'));
$r->put('/members/{id}/workout', [C\WorkoutsController::class, 'saveWorkout'], $p('workouts.manage'));
$r->put('/members/{id}/diet', [C\WorkoutsController::class, 'saveDiet'], $p('workouts.manage'));
$r->get('/members/{id}/assessments', [C\WorkoutsController::class, 'assessments'], $p('members.view'));
$r->post('/members/{id}/assessments', [C\WorkoutsController::class, 'addAssessment'], $p('workouts.manage'));
$r->post('/memberships/{id}/freeze', [C\MembersController::class, 'freeze'], $p('members.write'));
$r->post('/memberships/{id}/unfreeze', [C\MembersController::class, 'unfreeze'], $p('members.write'));
$r->post('/memberships/{id}/extend', [C\MembersController::class, 'extend'], $p('billing.manage'));
$r->post('/memberships/{id}/upgrade', [C\MembersController::class, 'upgrade'], $p('billing.collect'));
$r->post('/memberships/{id}/transfer', [C\MembersController::class, 'transfer'], $p('billing.manage'));
$r->post('/memberships/{id}/autopay', [C\MembersController::class, 'autopay'], $p('billing.collect'));

// Plans, coupons, gift cards
$r->get('/plans', [C\PlansController::class, 'index'], $p('members.view'));
$r->post('/plans', [C\PlansController::class, 'store'], $p('plans.manage'));
$r->put('/plans/{id}', [C\PlansController::class, 'update'], $p('plans.manage'));
$r->delete('/plans/{id}', [C\PlansController::class, 'destroy'], $p('plans.manage'));
$r->get('/coupons', [C\PlansController::class, 'coupons'], $p('billing.view'));
$r->post('/coupons', [C\PlansController::class, 'saveCoupon'], $p('plans.manage'));
$r->put('/coupons/{id}', [C\PlansController::class, 'saveCoupon'], $p('plans.manage'));
$r->get('/gift-cards', [C\PlansController::class, 'giftCards'], $p('billing.view'));
$r->post('/gift-cards', [C\PlansController::class, 'createGiftCard'], $p('billing.collect'));

// Billing
$r->get('/billing/summary', [C\BillingController::class, 'summary'], $p('billing.view'));
$r->get('/invoices', [C\BillingController::class, 'invoices'], $p('billing.view', ['apikey' => true]));
$r->post('/invoices', [C\BillingController::class, 'store'], $p('billing.collect'));
$r->get('/invoices/{id}', [C\BillingController::class, 'show'], $p('billing.view'));
$r->get('/invoices/{id}/print', [C\BillingController::class, 'print'], $p('billing.view'));
$r->post('/invoices/{id}/payments', [C\BillingController::class, 'pay'], $p('billing.collect'));
$r->post('/invoices/{id}/cancel', [C\BillingController::class, 'cancel'], $p('billing.manage'));
$r->post('/invoices/{id}/payment-link', [C\BillingController::class, 'paymentLink'], $p('billing.collect'));
$r->get('/dues', [C\BillingController::class, 'dues'], $p('billing.view'));
$r->post('/dues/remind', [C\BillingController::class, 'remindDues'], $p('billing.collect'));
$r->get('/payments', [C\BillingController::class, 'payments'], $p('billing.view'));
$r->get('/payment-links', [C\BillingController::class, 'paymentLinks'], $p('billing.view'));
$r->get('/credit-notes', [C\BillingController::class, 'creditNotes'], $p('billing.view'));
$r->get('/expenses', [C\BillingController::class, 'expenses'], $p('billing.manage'));
$r->post('/expenses', [C\BillingController::class, 'saveExpense'], $p('billing.manage'));
$r->put('/expenses/{id}', [C\BillingController::class, 'saveExpense'], $p('billing.manage'));
$r->delete('/expenses/{id}', [C\BillingController::class, 'deleteExpense'], $p('billing.manage'));
$r->get('/billing/gst', [C\BillingController::class, 'gstReport'], $p('billing.view'));
$r->get('/billing/export/tally', [C\BillingController::class, 'tally'], $p('billing.view'));
$r->get('/billing/export/zoho', [C\BillingController::class, 'zoho'], $p('billing.view'));

// Check-ins & sessions
$r->get('/sessions', [C\SessionsController::class, 'index'], $p('sessions.view'));
$r->get('/punches', [C\SessionsController::class, 'punches'], $p('sessions.view'));
$r->post('/sessions/checkin', [C\SessionsController::class, 'checkin'], $p('sessions.manage', ['apikey' => true]));
$r->post('/sessions/{id}/checkout', [C\SessionsController::class, 'checkout'], $p('sessions.manage'));
$r->put('/sessions/{id}', [C\SessionsController::class, 'update'], $p('sessions.manage'));
$r->post('/sessions/{id}/allow', [C\SessionsController::class, 'allow'], $p('sessions.manage'));

// Devices
$r->get('/devices', [C\DevicesController::class, 'index'], $p('devices.manage'));
$r->post('/devices', [C\DevicesController::class, 'save'], $p('devices.manage'));
$r->put('/devices/{id}', [C\DevicesController::class, 'save'], $p('devices.manage'));
$r->delete('/devices/{id}', [C\DevicesController::class, 'destroy'], $p('devices.manage'));
$r->post('/devices/{id}/sync', [C\DevicesController::class, 'sync'], $p('devices.manage'));
$r->get('/devices/{id}/commands', [C\DevicesController::class, 'commands'], $p('devices.manage'));
$r->post('/devices/{id}/command', [C\DevicesController::class, 'command'], $p('settings.manage'));

// Classes, PT, resources
$r->get('/classes', [C\ClassesController::class, 'index'], $p('classes.view'));
$r->post('/classes', [C\ClassesController::class, 'save'], $p('classes.manage'));
$r->put('/classes/{id}', [C\ClassesController::class, 'save'], $p('classes.manage'));
$r->delete('/classes/{id}', [C\ClassesController::class, 'destroy'], $p('classes.manage'));
$r->get('/classes/{id}/bookings', [C\ClassesController::class, 'bookings'], $p('classes.view'));
$r->post('/classes/{id}/bookings', [C\ClassesController::class, 'staffBook'], $p('pt.manage'));
$r->put('/bookings/{id}', [C\ClassesController::class, 'updateBooking'], $p('pt.manage'));
$r->get('/pt', [C\ClassesController::class, 'pt'], $p('classes.view'));
$r->post('/pt', [C\ClassesController::class, 'createPt'], $p('pt.manage'));
$r->put('/pt/{id}', [C\ClassesController::class, 'updatePt'], $p('pt.manage'));
$r->get('/resources', [C\ClassesController::class, 'resources'], $p('classes.view'));
$r->post('/resources', [C\ClassesController::class, 'saveResource'], $p('classes.manage'));
$r->put('/resources/{id}', [C\ClassesController::class, 'saveResource'], $p('classes.manage'));
$r->post('/resources/{id}/book', [C\ClassesController::class, 'staffBookResource'], $p('pt.manage'));

// Workouts
$r->get('/exercises', [C\WorkoutsController::class, 'exercises'], $p('members.view'));
$r->post('/exercises', [C\WorkoutsController::class, 'addExercise'], $p('workouts.manage'));
$r->post('/workout-templates', [C\WorkoutsController::class, 'saveWorkout'], $p('workouts.manage'));
$r->post('/diet-templates', [C\WorkoutsController::class, 'saveDiet'], $p('workouts.manage'));
$r->get('/templates/{kind:s}/{id}', [C\WorkoutsController::class, 'template'], $p('workouts.manage'));

// Leads
$r->get('/leads', [C\LeadsController::class, 'index'], $p('leads.view'));
$r->post('/leads', [C\LeadsController::class, 'store'], $p('leads.write', ['apikey' => true]));
$r->get('/leads/{id}', [C\LeadsController::class, 'show'], $p('leads.view'));
$r->put('/leads/{id}', [C\LeadsController::class, 'update'], $p('leads.write'));
$r->delete('/leads/{id}', [C\LeadsController::class, 'destroy'], $p('leads.write'));
$r->post('/leads/{id}/activity', [C\LeadsController::class, 'activity'], $p('leads.write'));
$r->get('/leads/{id}/convert', [C\LeadsController::class, 'convert'], $p('members.write'));

// Staff & payroll
$r->get('/staff', [C\StaffController::class, 'index'], $p('staff.view'));
$r->post('/staff', [C\StaffController::class, 'store'], $p('staff.manage'));
$r->put('/staff/{id}', [C\StaffController::class, 'update'], $p('staff.manage'));
$r->get('/staff/{id}/attendance', [C\StaffController::class, 'attendance'], $p('staff.view'));
$r->post('/payroll/run', [C\StaffController::class, 'runPayroll'], $p('payroll.manage'));
$r->get('/payroll/{period:s}', [C\StaffController::class, 'payroll'], $p('payroll.manage'));
$r->post('/payroll/{period:s}/paid', [C\StaffController::class, 'markPaid'], $p('payroll.manage'));

// Store
$r->get('/products', [C\StoreController::class, 'index'], $p('store.view'));
$r->post('/products', [C\StoreController::class, 'save'], $p('store.manage'));
$r->put('/products/{id}', [C\StoreController::class, 'save'], $p('store.manage'));
$r->post('/products/{id}/stock', [C\StoreController::class, 'stock'], $p('store.manage'));
$r->get('/products/{id}/moves', [C\StoreController::class, 'moves'], $p('store.view'));
$r->post('/store/sale', [C\StoreController::class, 'sale'], $p('store.sell'));

// WhatsApp & campaigns
$r->get('/campaigns', [C\CampaignsController::class, 'overview'], $p('campaigns.view'));
$r->post('/campaigns', [C\CampaignsController::class, 'store'], $p('campaigns.send'));
$r->post('/campaigns/preview', [C\CampaignsController::class, 'preview'], $p('campaigns.view'));
$r->post('/campaigns/{id}/cancel', [C\CampaignsController::class, 'cancel'], $p('campaigns.send'));
$r->get('/messages', [C\CampaignsController::class, 'messages'], $p('campaigns.view'));
$r->put('/templates/{id}', [C\CampaignsController::class, 'saveTemplate'], $p('campaigns.send'));
$r->put('/automations', [C\CampaignsController::class, 'automations'], $p('campaigns.send'));
$r->get('/announcements', [C\CampaignsController::class, 'announcements'], $p('campaigns.view'));
$r->post('/announcements', [C\CampaignsController::class, 'saveAnnouncement'], $p('campaigns.send'));
$r->delete('/announcements/{id}', [C\CampaignsController::class, 'deleteAnnouncement'], $p('campaigns.send'));
$r->get('/challenges', [C\CampaignsController::class, 'challenges'], $p('campaigns.view'));
$r->post('/challenges', [C\CampaignsController::class, 'saveChallenge'], $p('campaigns.send'));
$r->put('/challenges/{id}', [C\CampaignsController::class, 'saveChallenge'], $p('campaigns.send'));

// Reports
$r->get('/reports', [C\ReportsController::class, 'summary'], $p('reports.view'));
$r->get('/reports/branches', [C\ReportsController::class, 'branches'], $p('reports.view'));
$r->get('/reports/export/{kind:s}', [C\ReportsController::class, 'export'], $p('reports.view'));

// Settings
$r->get('/settings', [C\SettingsController::class, 'index'], $p('settings.view'));
$r->put('/settings/gym', [C\SettingsController::class, 'updateGym'], $p('settings.view'));
$r->put('/settings/rules', [C\SettingsController::class, 'updateRules'], $p('settings.view'));
$r->put('/settings/integrations', [C\SettingsController::class, 'updateIntegrations'], $p('settings.view'));
$r->post('/settings/integrations/test/{kind:s}', [C\SettingsController::class, 'testIntegration'], $p('settings.view'));
$r->post('/settings/logo', [C\SettingsController::class, 'uploadLogo'], $p('settings.view'));
$r->post('/branches', [C\SettingsController::class, 'saveBranch'], $p('settings.view'));
$r->put('/branches/{id}', [C\SettingsController::class, 'saveBranch'], $p('settings.view'));
$r->get('/settings/developer', [C\SettingsController::class, 'apiKeys'], $p('settings.view'));
$r->post('/api-keys', [C\SettingsController::class, 'createApiKey'], $p('settings.view'));
$r->delete('/api-keys/{id}', [C\SettingsController::class, 'deleteApiKey'], $p('settings.view'));
$r->post('/webhooks', [C\SettingsController::class, 'saveWebhook'], $p('settings.view'));
$r->put('/webhooks/{id}', [C\SettingsController::class, 'saveWebhook'], $p('settings.view'));
$r->delete('/webhooks/{id}', [C\SettingsController::class, 'deleteWebhook'], $p('settings.view'));
$r->get('/audit', [C\SettingsController::class, 'audit'], $p('settings.view'));
$r->get('/backup', [C\SettingsController::class, 'backup'], $p('settings.view'));
$r->get('/notifications', [C\SettingsController::class, 'notifications']);
$r->post('/notifications/read', [C\SettingsController::class, 'readNotifications']);
$r->post('/push/subscribe', [C\SettingsController::class, 'pushSubscribe']);

// Platform console
$r->get('/platform/gyms', [C\PlatformController::class, 'gyms'], ['auth' => 'platform']);
$r->post('/platform/gyms', [C\PlatformController::class, 'create'], ['auth' => 'platform']);
$r->put('/platform/gyms/{id}', [C\PlatformController::class, 'update'], ['auth' => 'platform']);

// Incoming webhooks
$r->post('/hooks/razorpay/{id}', [C\HooksController::class, 'razorpay'], $hook);
$r->get('/hooks/whatsapp/{id}', [C\HooksController::class, 'whatsapp'], $hook);
$r->post('/hooks/whatsapp/{id}', [C\HooksController::class, 'whatsapp'], $hook);
$r->get('/hooks/meta-leads/{id}', [C\HooksController::class, 'metaLeads'], $hook);
$r->post('/hooks/meta-leads/{id}', [C\HooksController::class, 'metaLeads'], $hook);
$r->post('/hooks/google-leads/{id}', [C\HooksController::class, 'googleLeads'], $hook);

// Member app
$M = C\MemberAppController::class;
$r->post('/m/auth/otp', [$M, 'requestOtp'], $none + ['rate' => ['otp', 20, 3600]]);
$r->post('/m/auth/verify', [$M, 'verifyOtp'], $none + ['rate' => ['otpv', 30, 3600]]);
$r->post('/m/auth/logout', [$M, 'logout'], $none);
$r->get('/m/me', [$M, 'profile'], $member);
$r->put('/m/me', [$M, 'updatePrefs'], $member);
$r->get('/m/photo', [$M, 'photo'], $member);
$r->get('/m/home', [$M, 'home'], $member);
$r->get('/m/card', [$M, 'card'], $member);
$r->get('/m/visits', [$M, 'visits'], $member);
$r->get('/m/classes', [$M, 'classes'], $member);
$r->post('/m/classes/{id}/book', [$M, 'bookClass'], $member);
$r->post('/m/bookings/{id}/cancel', [$M, 'cancelClass'], $member);
$r->get('/m/pt/slots', [$M, 'ptSlots'], $member);
$r->post('/m/pt', [$M, 'bookPt'], $member);
$r->post('/m/pt/{id}/cancel', [$M, 'cancelPt'], $member);
$r->get('/m/resources', [$M, 'resources'], $member);
$r->post('/m/resources/{id}/book', [$M, 'bookResource'], $member);
$r->get('/m/workout', [$M, 'workout'], $member);
$r->post('/m/workout/log', [$M, 'logWorkout'], $member);
$r->get('/m/diet', [$M, 'diet'], $member);
$r->post('/m/diet/toggle', [$M, 'toggleMeal'], $member);
$r->get('/m/progress', [$M, 'progress'], $member);
$r->post('/m/progress/weight', [$M, 'logWeight'], $member);
$r->get('/m/progress/photo/{id}', [$M, 'progressPhoto'], $member);
$r->get('/m/challenges', [$M, 'challenges'], $member);
$r->get('/m/plans', [$M, 'plans'], $member);
$r->post('/m/renew', [$M, 'renew'], $member);
$r->post('/m/invoices/{id}/pay', [$M, 'payInvoice'], $member);
$r->post('/m/pay/verify', [$M, 'verifyPayment'], $member);
$r->get('/m/invoices/{id}/print', [$M, 'invoicePrint'], $member);
$r->get('/m/store', [$M, 'store'], $member);
$r->post('/m/store/order', [$M, 'order'], $member);
$r->post('/m/waiver', [$M, 'waiver'], $member);
$r->get('/m/referral', [$M, 'referral'], $member);
$r->post('/m/referral', [$M, 'refer'], $member);
$r->get('/m/notifications', [$M, 'notifications'], $member);
$r->post('/m/push/subscribe', [$M, 'pushSubscribe'], $member);
$r->get('/m/strava/connect', [$M, 'stravaConnect'], $member);
$r->get('/m/strava/callback', [$M, 'stravaCallback'], $member);
$r->get('/m/strava/activities', [$M, 'stravaActivities'], $member);
$r->post('/m/strava/disconnect', [$M, 'stravaDisconnect'], $member);

return $r;
