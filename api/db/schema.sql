-- Gym Management Platform schema (MySQL 5.7+/8 or MariaDB 10.3+)
-- Every tenant-owned table carries gym_id. All queries are scoped by gym_id in the API.
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS gyms (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(60) NOT NULL UNIQUE,
  name VARCHAR(150) NOT NULL,
  legal_name VARCHAR(200) NULL,
  gstin VARCHAR(20) NULL,
  state_code CHAR(2) NOT NULL DEFAULT '33',
  address VARCHAR(400) NULL,
  phone VARCHAR(20) NULL,
  email VARCHAR(150) NULL,
  logo_url VARCHAR(300) NULL,
  timezone VARCHAR(50) NOT NULL DEFAULT 'Asia/Kolkata',
  currency CHAR(3) NOT NULL DEFAULT 'INR',
  default_language ENUM('en','ta') NOT NULL DEFAULT 'en',
  settings JSON NULL,
  status ENUM('active','suspended','trial') NOT NULL DEFAULT 'active',
  subscription_plan VARCHAR(40) NULL,
  subscription_until DATE NULL,
  invoice_seq INT UNSIGNED NOT NULL DEFAULT 0,
  member_seq INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS branches (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gym_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  address VARCHAR(400) NULL,
  phone VARCHAR(20) NULL,
  open_time TIME NOT NULL DEFAULT '05:00:00',
  close_time TIME NOT NULL DEFAULT '22:00:00',
  staffed_from TIME NOT NULL DEFAULT '05:00:00',
  staffed_to TIME NOT NULL DEFAULT '22:00:00',
  capacity INT UNSIGNED NOT NULL DEFAULT 100,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (gym_id),
  CONSTRAINT fk_branch_gym FOREIGN KEY (gym_id) REFERENCES gyms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gym_id INT UNSIGNED NULL,
  branch_id INT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(150) NOT NULL,
  phone VARCHAR(20) NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('platform_admin','owner','manager','front_desk','trainer','staff') NOT NULL,
  title VARCHAR(80) NULL,
  salary DECIMAL(12,2) NOT NULL DEFAULT 0,
  pt_commission_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
  sales_commission_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
  device_user_id VARCHAR(20) NULL,
  shift_start TIME NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  failed_logins INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  last_login_at DATETIME NULL,
  language ENUM('en','ta') NOT NULL DEFAULT 'en',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_email (email),
  KEY (gym_id),
  CONSTRAINT fk_user_gym FOREIGN KEY (gym_id) REFERENCES gyms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_sessions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  token_hash CHAR(64) NOT NULL UNIQUE,
  subject_type ENUM('staff','member') NOT NULL,
  subject_id INT UNSIGNED NOT NULL,
  gym_id INT UNSIGNED NULL,
  ip VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  expires_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (subject_type, subject_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS otp_codes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gym_id INT UNSIGNED NOT NULL,
  phone VARCHAR(20) NOT NULL,
  code_hash CHAR(64) NOT NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (gym_id, phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
  rl_key VARCHAR(190) PRIMARY KEY,
  hits INT UNSIGNED NOT NULL,
  window_start INT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS plans (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gym_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  kind ENUM('membership','pt','addon') NOT NULL DEFAULT 'membership',
  duration_days INT UNSIGNED NOT NULL,
  price DECIMAL(12,2) NOT NULL,
  sessions_count INT UNSIGNED NOT NULL DEFAULT 0,
  max_members TINYINT UNSIGNED NOT NULL DEFAULT 1,
  freeze_days_allowed INT UNSIGNED NOT NULL DEFAULT 0,
  access_start TIME NULL,
  access_end TIME NULL,
  all_branches TINYINT(1) NOT NULL DEFAULT 0,
  gst_rate DECIMAL(5,2) NOT NULL DEFAULT 18.00,
  sac_code VARCHAR(10) NOT NULL DEFAULT '999723',
  description VARCHAR(300) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (gym_id),
  CONSTRAINT fk_plan_gym FOREIGN KEY (gym_id) REFERENCES gyms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS members (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gym_id INT UNSIGNED NOT NULL,
  branch_id INT UNSIGNED NULL,
  member_code VARCHAR(20) NOT NULL,
  name VARCHAR(120) NOT NULL,
  phone VARCHAR(20) NOT NULL,
  email VARCHAR(150) NULL,
  dob DATE NULL,
  gender ENUM('male','female','other') NULL,
  address VARCHAR(400) NULL,
  state_code CHAR(2) NULL,
  emergency_contact VARCHAR(150) NULL,
  health_notes TEXT NULL,
  goal VARCHAR(80) NULL,
  source VARCHAR(60) NULL,
  photo_path VARCHAR(255) NULL,
  id_proof_path VARCHAR(255) NULL,
  language ENUM('en','ta') NOT NULL DEFAULT 'en',
  trainer_id INT UNSIGNED NULL,
  referred_by INT UNSIGNED NULL,
  device_user_id VARCHAR(20) NULL,
  fingerprint_enrolled TINYINT(1) NOT NULL DEFAULT 0,
  face_enrolled TINYINT(1) NOT NULL DEFAULT 0,
  waiver_signed_at DATETIME NULL,
  waiver_signature_path VARCHAR(255) NULL,
  qr_secret CHAR(32) NOT NULL,
  churn_score TINYINT UNSIGNED NULL,
  churn_reason VARCHAR(255) NULL,
  leaderboard_opt_in TINYINT(1) NOT NULL DEFAULT 1,
  credit_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
  is_blocked TINYINT(1) NOT NULL DEFAULT 0,
  blocked_reason VARCHAR(120) NULL,
  push_subscription TEXT NULL,
  strava_tokens TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  UNIQUE KEY uq_member_code (gym_id, member_code),
  KEY (gym_id, phone),
  KEY (gym_id, device_user_id),
  CONSTRAINT fk_member_gym FOREIGN KEY (gym_id) REFERENCES gyms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS memberships (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gym_id INT UNSIGNED NOT NULL,
  member_id INT UNSIGNED NOT NULL,
  plan_id INT UNSIGNED NOT NULL,
  kind ENUM('membership','pt','addon') NOT NULL DEFAULT 'membership',
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  price DECIMAL(12,2) NOT NULL,
  status ENUM('upcoming','active','frozen','expired','cancelled','transferred') NOT NULL DEFAULT 'active',
  freeze_start DATE NULL,
  freeze_end DATE NULL,
  frozen_days_used INT UNSIGNED NOT NULL DEFAULT 0,
  sessions_total INT UNSIGNED NOT NULL DEFAULT 0,
  sessions_used INT UNSIGNED NOT NULL DEFAULT 0,
  autopay TINYINT(1) NOT NULL DEFAULT 0,
  razorpay_subscription_id VARCHAR(60) NULL,
  invoice_id INT UNSIGNED NULL,
  notes VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (gym_id, member_id),
  KEY (gym_id, end_date),
  CONSTRAINT fk_ms_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coupons (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gym_id INT UNSIGNED NOT NULL,
  code VARCHAR(30) NOT NULL,
  kind ENUM('percent','flat') NOT NULL,
  value DECIMAL(12,2) NOT NULL,
  valid_from DATE NULL,
  valid_to DATE NULL,
  max_uses INT UNSIGNED NULL,
  used_count INT UNSIGNED NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY (gym_id, code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gift_cards (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gym_id INT UNSIGNED NOT NULL,
  code VARCHAR(30) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  balance DECIMAL(12,2) NOT NULL,
  buyer_name VARCHAR(120) NULL,
  valid_to DATE NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY (gym_id, code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS member_credits (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gym_id INT UNSIGNED NOT NULL,
  member_id INT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  reason VARCHAR(150) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (gym_id, member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoices (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gym_id INT UNSIGNED NOT NULL,
  branch_id INT UNSIGNED NULL,
  invoice_no VARCHAR(30) NOT NULL,
  member_id INT UNSIGNED NULL,
  customer_name VARCHAR(120) NULL,
  customer_gstin VARCHAR(20) NULL,
  place_of_supply CHAR(2) NULL,
  invoice_date DATE NOT NULL,
  due_date DATE NULL,
  kind ENUM('membership','pt','store','other') NOT NULL DEFAULT 'membership',
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
  discount DECIMAL(12,2) NOT NULL DEFAULT 0,
  taxable DECIMAL(12,2) NOT NULL DEFAULT 0,
  cgst DECIMAL(12,2) NOT NULL DEFAULT 0,
  sgst DECIMAL(12,2) NOT NULL DEFAULT 0,
  igst DECIMAL(12,2) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  paid DECIMAL(12,2) NOT NULL DEFAULT 0,
  balance DECIMAL(12,2) NOT NULL DEFAULT 0,
  status ENUM('unpaid','partial','paid','cancelled') NOT NULL DEFAULT 'unpaid',
  coupon_code VARCHAR(30) NULL,
  notes VARCHAR(300) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY (gym_id, invoice_no),
  KEY (gym_id, invoice_date),
  KEY (gym_id, member_id),
  KEY (gym_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoice_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT UNSIGNED NOT NULL,
  description VARCHAR(200) NOT NULL,
  hsn_sac VARCHAR(10) NULL,
  qty DECIMAL(10,2) NOT NULL DEFAULT 1,
  rate DECIMAL(12,2) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  gst_rate DECIMAL(5,2) NOT NULL DEFAULT 18,
  ref_type VARCHAR(20) NULL,
  ref_id INT UNSIGNED NULL,
  KEY (invoice_id),
  CONSTRAINT fk_item_inv FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gym_id INT UNSIGNED NOT NULL,
  invoice_id INT UNSIGNED NOT NULL,
  member_id INT UNSIGNED NULL,
  amount DECIMAL(12,2) NOT NULL,
  mode ENUM('cash','upi','card','netbanking','online','autopay','credit','giftcard') NOT NULL,
  reference VARCHAR(100) NULL,
  gateway_payment_id VARCHAR(60) NULL,
  received_by INT UNSIGNED NULL,
  paid_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (gym_id, paid_at),
  UNIQUE KEY uq_gateway_payment (gateway_payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS credit_notes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gym_id INT UNSIGNED NOT NULL,
  note_no VARCHAR(30) NOT NULL,
  invoice_id INT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  reason VARCHAR(200) NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (gym_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_links (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gym_id INT UNSIGNED NOT NULL,
  invoice_id INT UNSIGNED NOT NULL,
  gateway_link_id VARCHAR(60) NULL,
  short_url VARCHAR(255) NULL,
  amount DECIMAL(12,2) NOT NULL,
  status ENUM('created','paid','expired','cancelled') NOT NULL DEFAULT 'created',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (gym_id),
  KEY (gateway_link_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expenses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gym_id INT UNSIGNED NOT NULL,
  branch_id INT UNSIGNED NULL,
  category VARCHAR(60) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  expense_date DATE NOT NULL,
  note VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (gym_id, expense_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS devices (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gym_id INT UNSIGNED NOT NULL,
  branch_id INT UNSIGNED NOT NULL,
  serial_no VARCHAR(60) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  model VARCHAR(60) NULL,
  kind ENUM('fingerprint','face','rfid','turnstile','qr_kiosk') NOT NULL DEFAULT 'fingerprint',
  direction ENUM('both','in','out') NOT NULL DEFAULT 'both',
  relay_controls_door TINYINT(1) NOT NULL DEFAULT 1,
  last_seen_at DATETIME NULL,
  last_stamp VARCHAR(30) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (gym_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS device_commands (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id INT UNSIGNED NOT NULL,
  command TEXT NOT NULL,
  status ENUM('pending','sent','done','failed') NOT NULL DEFAULT 'pending',
  result VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME NULL,
  KEY (device_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance_sessions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gym_id INT UNSIGNED NOT NULL,
  branch_id INT UNSIGNED NOT NULL,
  member_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,
  in_at DATETIME NOT NULL,
  out_at DATETIME NULL,
  duration_min INT UNSIGNED NULL,
  method ENUM('fingerprint','face','qr','rfid','manual','app') NOT NULL,
  device_id INT UNSIGNED NULL,
  auto_closed TINYINT(1) NOT NULL DEFAULT 0,
  note VARCHAR(150) NULL,
  created_by INT UNSIGNED NULL,
  KEY (gym_id, in_at),
  KEY (gym_id, member_id, in_at),
  KEY (gym_id, user_id, in_at),
  KEY (gym_id, out_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS punch_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gym_id INT UNSIGNED NOT NULL,
  branch_id INT UNSIGNED NULL,
  device_id INT UNSIGNED NULL,
  device_user_id VARCHAR(20) NULL,
  member_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,
  punched_at DATETIME NOT NULL,
  method VARCHAR(20) NOT NULL,
  result ENUM('in','out','denied','ignored') NOT NULL,
  reason VARCHAR(150) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (gym_id, punched_at),
  UNIQUE KEY uq_punch (device_id, device_user_id, punched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS classes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gym_id INT UNSIGNED NOT NULL,
  branch_id INT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  coach_id INT UNSIGNED NULL,
  room VARCHAR(60) NULL,
  day_of_week TINYINT UNSIGNED NOT NULL,
  start_time TIME NOT NULL,
  duration_min INT UNSIGNED NOT NULL DEFAULT 60,
  capacity INT UNSIGNED NOT NULL DEFAULT 20,
  late_cancel_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
  no_show_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  KEY (gym_id, day_of_week)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS class_bookings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gym_id INT UNSIGNED NOT NULL,
  class_id INT UNSIGNED NOT NULL,
  class_date DATE NOT NULL,
  member_id INT UNSIGNED NOT NULL,
  status ENUM('booked','waitlist','attended','no_show','cancelled','late_cancel') NOT NULL DEFAULT 'booked',
  waitlist_pos INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_booking (class_id, class_date, member_id),
  KEY (gym_id, class_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pt_appointments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gym_id INT UNSIGNED NOT NULL,
  member_id INT UNSIGNED NOT NULL,
  trainer_id INT UNSIGNED NOT NULL,
  membership_id INT UNSIGNED NULL,
  starts_at DATETIME NOT NULL,
  duration_min INT UNSIGNED NOT NULL DEFAULT 60,
  status ENUM('booked','done','no_show','cancelled') NOT NULL DEFAULT 'booked',
  notes VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (gym_id, starts_at),
  KEY (trainer_id, starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS resources (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gym_id INT UNSIGNED NOT NULL,
  branch_id INT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  slot_minutes INT UNSIGNED NOT NULL DEFAULT 60,
  price DECIMAL(10,2) NOT NULL DEFAULT 0,
  open_time TIME NOT NULL DEFAULT '06:00:00',
  close_time TIME NOT NULL DEFAULT '21:00:00',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  KEY (gym_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS resource_bookings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gym_id INT UNSIGNED NOT NULL,
  resource_id INT UNSIGNED NOT NULL,
  member_id INT UNSIGNED NOT NULL,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  status ENUM('booked','cancelled','done') NOT NULL DEFAULT 'booked',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (resource_id, starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS leads (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gym_id INT UNSIGNED NOT NULL,
  branch_id INT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  phone VARCHAR(20) NOT NULL,
  email VARCHAR(150) NULL,
  source VARCHAR(40) NOT NULL DEFAULT 'walk_in',
  interest VARCHAR(200) NULL,
  stage ENUM('new','trial','follow_up','joined','lost') NOT NULL DEFAULT 'new',
  assigned_to INT UNSIGNED NULL,
  next_followup_at DATETIME NULL,
  trial_at DATETIME NULL,
  trial_pass_code VARCHAR(12) NULL,
  lost_reason VARCHAR(150) NULL,
  member_id INT UNSIGNED NULL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY (gym_id, stage),
  KEY (gym_id, phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lead_activities (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lead_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  kind VARCHAR(30) NOT NULL,
  note VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (lead_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gym_id INT UNSIGNED NOT NULL,
  branch_id INT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  category ENUM('supplement','merchandise','locker','service','other') NOT NULL DEFAULT 'other',
  sku VARCHAR(40) NULL,
  hsn VARCHAR(10) NULL,
  price DECIMAL(12,2) NOT NULL,
  cost DECIMAL(12,2) NOT NULL DEFAULT 0,
  gst_rate DECIMAL(5,2) NOT NULL DEFAULT 18,
  stock INT NOT NULL DEFAULT 0,
  reorder_level INT NOT NULL DEFAULT 0,
  track_stock TINYINT(1) NOT NULL DEFAULT 1,
  sell_online TINYINT(1) NOT NULL DEFAULT 1,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  KEY (gym_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_moves (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gym_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  qty INT NOT NULL,
  kind ENUM('purchase','sale','adjust','return') NOT NULL,
  ref VARCHAR(60) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_runs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gym_id INT UNSIGNED NOT NULL,
  period CHAR(7) NOT NULL,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  status ENUM('draft','paid') NOT NULL DEFAULT 'draft',
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY (gym_id, period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  run_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  days_present INT UNSIGNED NOT NULL,
  working_days INT UNSIGNED NOT NULL,
  base_salary DECIMAL(12,2) NOT NULL,
  pt_sessions INT UNSIGNED NOT NULL DEFAULT 0,
  commission DECIMAL(12,2) NOT NULL DEFAULT 0,
  deductions DECIMAL(12,2) NOT NULL DEFAULT 0,
  net DECIMAL(12,2) NOT NULL,
  KEY (run_id),
  CONSTRAINT fk_pi_run FOREIGN KEY (run_id) REFERENCES payroll_runs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS message_templates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gym_id INT UNSIGNED NOT NULL,
  tkey VARCHAR(40) NOT NULL,
  language ENUM('en','ta') NOT NULL,
  body TEXT NOT NULL,
  wa_template_name VARCHAR(80) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY (gym_id, tkey, language)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS campaigns (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gym_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  channel ENUM('whatsapp','sms','email','push') NOT NULL DEFAULT 'whatsapp',
  segment JSON NOT NULL,
  body TEXT NOT NULL,
  status ENUM('draft','scheduled','sending','sent','cancelled') NOT NULL DEFAULT 'draft',
  scheduled_at DATETIME NULL,
  recipients INT UNSIGNED NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (gym_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS messages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gym_id INT UNSIGNED NOT NULL,
  member_id INT UNSIGNED NULL,
  lead_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,
  campaign_id INT UNSIGNED NULL,
  direction ENUM('out','in') NOT NULL DEFAULT 'out',
  channel ENUM('whatsapp','sms','email','push') NOT NULL DEFAULT 'whatsapp',
  to_addr VARCHAR(150) NOT NULL,
  tkey VARCHAR(40) NULL,
  body TEXT NOT NULL,
  payload JSON NULL,
  status ENUM('queued','sent','delivered','read','failed','received') NOT NULL DEFAULT 'queued',
  error VARCHAR(255) NULL,
  provider_id VARCHAR(100) NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  send_after DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (status, send_after),
  KEY (gym_id, created_at),
  KEY (provider_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gym_id INT UNSIGNED NOT NULL,
  audience ENUM('staff','member') NOT NULL,
  member_id INT UNSIGNED NULL,
  title VARCHAR(150) NOT NULL,
  body VARCHAR(500) NULL,
  link VARCHAR(150) NULL,
  severity ENUM('info','warning') NOT NULL DEFAULT 'info',
  read_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (gym_id, audience, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staff_push_subscriptions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  endpoint TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS exercises (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gym_id INT UNSIGNED NULL,
  name VARCHAR(100) NOT NULL,
  muscle_group VARCHAR(40) NOT NULL,
  equipment VARCHAR(40) NULL,
  video_url VARCHAR(255) NULL,
  KEY (gym_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workout_plans (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gym_id INT UNSIGNED NOT NULL,
  member_id INT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  is_template TINYINT(1) NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (gym_id, member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workout_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  plan_id INT UNSIGNED NOT NULL,
  day_of_week TINYINT UNSIGNED NOT NULL,
  day_title VARCHAR(60) NULL,
  exercise_id INT UNSIGNED NOT NULL,
  sets VARCHAR(10) NOT NULL DEFAULT '3',
  reps VARCHAR(20) NOT NULL DEFAULT '10',
  weight VARCHAR(20) NULL,
  rest_sec INT UNSIGNED NULL,
  sort INT UNSIGNED NOT NULL DEFAULT 0,
  KEY (plan_id),
  CONSTRAINT fk_wi_plan FOREIGN KEY (plan_id) REFERENCES workout_plans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workout_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gym_id INT UNSIGNED NOT NULL,
  member_id INT UNSIGNED NOT NULL,
  exercise_id INT UNSIGNED NOT NULL,
  log_date DATE NOT NULL,
  sets INT UNSIGNED NOT NULL,
  reps INT UNSIGNED NOT NULL,
  weight DECIMAL(6,2) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (member_id, exercise_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS diet_plans (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gym_id INT UNSIGNED NOT NULL,
  member_id INT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  kcal_target INT UNSIGNED NULL,
  protein_target INT UNSIGNED NULL,
  is_template TINYINT(1) NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (gym_id, member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS diet_meals (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  diet_plan_id INT UNSIGNED NOT NULL,
  meal_time VARCHAR(10) NOT NULL,
  food VARCHAR(255) NOT NULL,
  kcal INT UNSIGNED NOT NULL DEFAULT 0,
  protein INT UNSIGNED NOT NULL DEFAULT 0,
  sort INT UNSIGNED NOT NULL DEFAULT 0,
  KEY (diet_plan_id),
  CONSTRAINT fk_dm_plan FOREIGN KEY (diet_plan_id) REFERENCES diet_plans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS diet_logs (
  member_id INT UNSIGNED NOT NULL,
  meal_id INT UNSIGNED NOT NULL,
  log_date DATE NOT NULL,
  PRIMARY KEY (member_id, meal_id, log_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assessments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gym_id INT UNSIGNED NOT NULL,
  member_id INT UNSIGNED NOT NULL,
  assessed_on DATE NOT NULL,
  weight_kg DECIMAL(5,1) NULL,
  height_cm DECIMAL(5,1) NULL,
  bmi DECIMAL(4,1) NULL,
  body_fat_pct DECIMAL(4,1) NULL,
  waist_in DECIMAL(4,1) NULL,
  chest_in DECIMAL(4,1) NULL,
  arm_in DECIMAL(4,1) NULL,
  photo_path VARCHAR(255) NULL,
  notes VARCHAR(300) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (gym_id, member_id, assessed_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS personal_records (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gym_id INT UNSIGNED NOT NULL,
  member_id INT UNSIGNED NOT NULL,
  exercise_id INT UNSIGNED NOT NULL,
  value VARCHAR(40) NOT NULL,
  achieved_on DATE NOT NULL,
  KEY (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS challenges (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gym_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  metric ENUM('visits','hours') NOT NULL DEFAULT 'visits',
  target INT UNSIGNED NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  prize VARCHAR(150) NULL,
  KEY (gym_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS announcements (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gym_id INT UNSIGNED NOT NULL,
  title VARCHAR(150) NOT NULL,
  body VARCHAR(1000) NOT NULL,
  visible_until DATE NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (gym_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webhooks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gym_id INT UNSIGNED NOT NULL,
  url VARCHAR(300) NOT NULL,
  events VARCHAR(300) NOT NULL,
  secret CHAR(64) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (gym_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_keys (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gym_id INT UNSIGNED NOT NULL,
  name VARCHAR(80) NOT NULL,
  key_prefix CHAR(8) NOT NULL,
  key_hash CHAR(64) NOT NULL UNIQUE,
  last_used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (gym_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gym_id INT UNSIGNED NULL,
  actor_type ENUM('staff','member','system','device') NOT NULL,
  actor_id INT UNSIGNED NULL,
  action VARCHAR(60) NOT NULL,
  entity VARCHAR(40) NULL,
  entity_id INT UNSIGNED NULL,
  details VARCHAR(1000) NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (gym_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cron_runs (
  job VARCHAR(40) PRIMARY KEY,
  last_run_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webhook_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  webhook_id INT UNSIGNED NOT NULL,
  event VARCHAR(60) NOT NULL,
  payload JSON NOT NULL,
  status ENUM('pending','delivered','failed') NOT NULL DEFAULT 'pending',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error VARCHAR(255) NULL,
  next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (status, next_attempt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schema_version (
  version INT UNSIGNED NOT NULL PRIMARY KEY,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO schema_version (version) VALUES (1);

SET FOREIGN_KEY_CHECKS = 1;
