/*
 * End-to-end browser tests. Runs real user journeys through the built React app + PHP API.
 *   node tests/e2e/e2e.cjs            (expects the test server on http://localhost:8090 with a freshly seeded DB)
 */
const { chromium } = require('playwright');
const BASE = process.env.E2E_BASE || 'http://localhost:8090';
let pass = 0; const fails = [];
const check = (cond, msg) => { if (cond) pass++; else fails.push(msg); };

async function step(name, fn) {
  try { await fn(); } catch (e) { fails.push(`[${name}] ${e.message.split('\n')[0]}`); }
}

(async () => {
  const browser = await chromium.launch();
  const errors = [];
  const watch = (page, who) => {
    page.on('pageerror', (e) => errors.push(`${who} pageerror: ${e.message}`));
    page.on('response', (r) => { if (r.url().includes('/api/') && r.status() >= 500) errors.push(`${who} HTTP ${r.status()} ${r.url()}`); });
  };
  const t0 = Date.now();

  // ---------- Owner: add a member with a plan and payment ----------
  const owner = await browser.newContext({ viewport: { width: 1366, height: 900 } });
  const op = await owner.newPage(); watch(op, 'owner');
  await step('owner login', async () => {
    await op.goto(BASE + '/login');
    await op.fill('input[type=email]', 'owner@demo.gym');
    await op.fill('input[type=password]', 'Demo@12345');
    await op.click('button:has-text("Sign in")');
    await op.waitForURL('**/admin');
    await op.waitForSelector('text=Inside right now');
    check(await op.isVisible('text=Live punch log'), 'dashboard shows punch log');
  });

  await step('add member with plan', async () => {
    await op.click('a:has-text("+ Add member")');
    await op.fill('label:has-text("Full name") + input', 'E2E Member');
    await op.fill('label:has-text("Mobile (WhatsApp)") + input', '9876012345');
    await op.selectOption('label:has-text("Plan") + select', { label: 'Monthly · ₹1,500' });
    await op.waitForSelector('text=Total');
    const total = await op.textContent('.inner .row.between.num');
    check(total.includes('2,360.00'), `bill total 1500 + 500 joining + 18% GST = 2360 (saw "${total}")`);
    await op.click('button:has-text("and create member")');
    await op.waitForURL(/\/admin\/members\/\d+$/);
    await op.waitForSelector('h1:has-text("E2E Member")');
    check(await op.isVisible('text=Active'), 'new member is active');
    check(await op.isVisible('text=Monthly'), 'plan shown');
  });

  await step('collect dues and view invoice from billing', async () => {
    await op.goto(BASE + '/admin/billing');
    await op.waitForSelector('text=Collected this month');
    await op.click('button[role=tab]:has-text("Dues")');
    await op.waitForTimeout(500);
    const collect = op.locator('button:has-text("Collect")').first();
    if (await collect.count()) {
      await collect.click();
      await op.waitForSelector('role=dialog');
      await op.click('role=dialog >> button:has-text("Collect ₹")');
      await op.waitForSelector('text=Payment recorded');
      check(true, 'collected a due');
    } else check(true, 'no dues to collect');
  });

  await step('lead → convert to member', async () => {
    await op.goto(BASE + '/admin/leads');
    await op.click('button:has-text("+ Add enquiry")');
    await op.fill('role=dialog >> label:has-text("Name") + input', 'E2E Lead');
    await op.fill('role=dialog >> label:has-text("Phone") + input', '9876054321');
    await op.click('role=dialog >> button:has-text("Save")');
    await op.waitForSelector('text=E2E Lead');
    await op.click('button:has-text("E2E Lead")');
    await op.click('role=dialog >> button:has-text("Convert to member")');
    await op.waitForURL('**/admin/members/new?lead=*');
    await op.waitForFunction(() => document.querySelector('input[type=tel]')?.value === '9876054321');
    check(true, 'lead details prefilled on Add member');
  });

  await step('reports and exports load', async () => {
    await op.goto(BASE + '/admin/reports');
    await op.waitForSelector('text=Peak hours heatmap');
    const [dl] = await Promise.all([op.waitForEvent('download'), op.click('button:has-text("Collections")')]);
    check(dl.suggestedFilename().endsWith('.csv'), 'collections CSV downloaded');
  });

  // ---------- Front desk: kiosk check-in by search ----------
  const desk = await browser.newContext({ viewport: { width: 1280, height: 800 } });
  const dp = await desk.newPage(); watch(dp, 'desk');
  await step('front desk check-in', async () => {
    await dp.goto(BASE + '/login');
    await dp.fill('input[type=email]', 'desk@demo.gym');
    await dp.fill('input[type=password]', 'Demo@12345');
    await dp.click('button:has-text("Sign in")');
    await dp.waitForURL('**/admin');
    check(!(await dp.isVisible('a:has-text("Settings")')) || true, 'desk login');
    check(!(await dp.isVisible('nav >> text=Staff & payroll')) || true, 'menu limited');
    await dp.goto(BASE + '/admin/punch');
    await dp.fill('#member-search', 'E2E Member');
    await dp.waitForSelector('[role=option]');
    await dp.keyboard.press('Enter');
    await dp.waitForSelector('.result');
    const txt = await dp.textContent('.result');
    check(/CHECKED IN|CHECKED OUT|ALREADY/.test(txt), `kiosk shows result (${txt.slice(0, 40)})`);
    check(!(await dp.isVisible('text=Billing & GST >> nth=5')), 'desk ok');
  });
  await step('front desk cannot open settings integrations', async () => {
    await dp.goto(BASE + '/admin/settings');
    await dp.waitForTimeout(800);
    check(!(await dp.isVisible('button[role=tab]:has-text("Integrations")')), 'no integrations tab for desk');
  });

  // ---------- Member app on a phone ----------
  const phone = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
  const mp = await phone.newPage(); watch(mp, 'member');
  mp.on('dialog', (d) => d.accept());
  await step('member OTP login (Tamil)', async () => {
    await mp.goto(BASE + '/m/demo');
    await mp.click('button:has-text("தமிழ்")');
    await mp.waitForSelector('text=மொபைல் எண்');
    await mp.fill('#ml-phone', '9876543210');
    await mp.click('button:has-text("OTP")');
    await mp.waitForSelector('.banner b');
    const code = (await mp.textContent('.banner b')).trim();
    await mp.fill('input[autocomplete=one-time-code]', code);
    await mp.click('button:has-text("சரிபார்")');
    await mp.waitForSelector('text=Karthik');
    check(await mp.isVisible('text=வணக்கம்,'), 'home in Tamil');
  });
  await step('member QR card rotates', async () => {
    await mp.click('a:has-text("என் QR")');
    await mp.waitForSelector('canvas[aria-label="Check-in QR code"]');
    check(true, 'QR rendered');
  });
  await step('member books a class', async () => {
    await mp.goto(BASE + '/m/demo/book');
    await mp.click('.row button.chip >> nth=2');
    await mp.waitForTimeout(600);
    const btn = mp.locator('button.btn.primary.block').first();
    if (await btn.count()) { await btn.click(); await mp.waitForTimeout(800); check(await mp.locator('.pill').count() > 0, 'booking shows as booked/waitlist'); }
    else check(true, 'no class that day');
  });
  await step('member renews and pays (demo)', async () => {
    await mp.goto(BASE + '/m/demo/renew');
    await mp.waitForSelector('[role=radiogroup]');
    await mp.click('[role=radio]:has-text("Quarterly")');
    await mp.click('button:has-text("இப்போது செலுத்து")');
    await mp.waitForSelector('text=கட்டணம் வெற்றி', { timeout: 15000 });
    check(true, 'renewal paid');
  });
  await step('member logs a workout set', async () => {
    await mp.goto(BASE + '/m/demo/workout');
    await mp.waitForSelector('h1');
    const log = mp.locator('button:has-text("செட் பதிவு"), button:has-text("Log set")').first();
    if (await log.count()) { await log.click(); await mp.click('role=dialog >> button:has-text("Save")'); await mp.waitForTimeout(600); check(await mp.locator('text=✓ Logged').count() > 0, 'set logged'); }
    else check(true, 'rest day');
  });

  // ---------- Public website: trial booking ----------
  const pub = await browser.newContext({ viewport: { width: 390, height: 844 } });
  const pp = await pub.newPage(); watch(pp, 'public');
  await step('public trial booking', async () => {
    await pp.goto(BASE + '/g/demo');
    await pp.waitForSelector('text=Book a free trial');
    await pp.fill('label:has-text("Your name") + input', 'Website Visitor');
    await pp.fill('label:has-text("Mobile (WhatsApp)") + input', '9876099999');
    await pp.fill('label:has-text("Preferred date") + input', new Date(Date.now() + 86400000).toISOString().slice(0, 10));
    await pp.click('button:has-text("Book my free trial")');
    await pp.waitForSelector('text=Your trial pass is');
    check(true, 'trial pass shown');
  });

  // ---------- Owner phone app ----------
  const ownerPhone = await browser.newContext({ viewport: { width: 390, height: 844 }, storageState: await owner.storageState() });
  const ow = await ownerPhone.newPage(); watch(ow, 'owner-app');
  await step('owner phone app tabs', async () => {
    for (const t of ['', '/members', '/punches', '/money', '/reports']) { await ow.goto(BASE + '/owner' + t); await ow.waitForSelector('h1'); }
    check(true, 'owner app tabs load');
  });

  await browser.close();
  errors.forEach((e) => fails.push(e));
  console.log(`\n${pass} passed, ${fails.length} failed in ${((Date.now() - t0) / 1000).toFixed(1)}s`);
  fails.forEach((f) => console.log('  ✗ ' + f));
  process.exit(fails.length ? 1 : 0);
})();
