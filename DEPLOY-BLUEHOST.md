# Deploying to Bluehost (cPanel shared hosting)

Total time: about 20 minutes. You need cPanel access and your domain pointed at Bluehost.

## 1. Prepare cPanel

1. **PHP version:** cPanel › MultiPHP Manager › select the domain › PHP 8.2 or 8.3 › Apply.
2. **SSL:** cPanel › SSL/TLS Status › run AutoSSL for the domain. The app forces HTTPS.
3. **Database:** cPanel › MySQL Database Wizard.
   - Create a database, for example `cpuser_gym`.
   - Create a user with a strong password.
   - Tick **ALL PRIVILEGES**.
   - Note the three values.

## 2. Upload

1. cPanel › File Manager › `public_html` (or the add-on domain's folder). Make sure it is empty, or move old files away.
2. Upload `gymapp-bluehost.zip` and **Extract** it there. `index.html`, `.htaccess` and the `api` folder should sit directly inside `public_html`.
3. Turn on **Show Hidden Files** (Settings, top right) and confirm `.htaccess` and `.user.ini` are present.
4. Open `api/INSTALL_KEY.txt` and copy the key.

## 3. Run the installer

1. Visit `https://YOUR-DOMAIN/api/install.php`.
2. Every requirement must show ✓.
3. Fill in:
   - Install key
   - Database name, user and password (host is `localhost`)
   - Site address
   - **Platform admin** email and password. This is you, the software owner, and it opens the console where you create gym accounts.
   - Optional: your first gym, with its owner login.
   - Keep **Create the demo gym** ticked to get the sample gym "FitZone Chennai" (`owner@demo.gym` / `Demo@12345`).
4. Click **Install**.

The installer:
- creates the tables
- writes the config to `/home/CPANELUSER/gymapp-config-<site-folder>.php`, which is outside `public_html`
- generates encryption, cron and push keys
- deletes the install key and locks itself

## 4. Add the cron job (required)

cPanel › Cron Jobs › Common settings **Once Per Five Minutes** › command:

```bash
/usr/local/bin/php /home/CPANELUSER/public_html/api/cron.php > /dev/null 2>&1
```

The exact path is printed on the installer's success page. Cron does all of the following:
- sends queued WhatsApp, SMS and email
- closes open sessions at closing time
- activates, freezes and expires plans
- blocks expired members on door devices
- sends renewal and dues reminders, birthday wishes, class reminders and review requests
- runs lead follow-ups
- scores churn nightly

## 5. Connect services, per gym (Settings › Integrations)

| Service | What you need | Where |
|---|---|---|
| WhatsApp Cloud API | Phone number ID, permanent token, app secret, a verify token you choose | developers.facebook.com › your app › WhatsApp. Webhook URL is shown in Settings. Create approved templates (Tamil and English) and enter their names under Campaigns › Templates. |
| Razorpay | Key ID, key secret, webhook secret | dashboard.razorpay.com › Settings › API keys and Webhooks (events: `payment_link.paid`, `subscription.charged`, `subscription.cancelled`, `subscription.halted`). Turn on UPI AutoPay (Subscriptions). |
| SMS (optional) | Gateway URL with `{to}` `{msg}` `{key}` | Any HTTP SMS gateway (MSG91, Fast2SMS…) |
| AI assistant (optional) | Anthropic API key | console.anthropic.com. Without it the WhatsApp bot uses built-in keyword replies. |
| Lead ads (optional) | Meta page token + verify token; Google Ads webhook key | Webhook URLs are shown in Settings |
| Strava (optional) | Client ID and secret | strava.com/settings/api. Callback URL is shown in Settings. |

Gyms without WhatsApp still work: members get login codes by SMS (if set) or email.

## 6. Biometric devices (eSSL / ZKTeco with ADMS / "Cloud Server")

1. Admin › Devices & access › **Add device** with the serial number.
2. On the device, open Comm › Cloud Server Setting:
   - Server address: your domain
   - Port: 443 with HTTPS, or 80 if the model has no HTTPS
   - Proxy: off
3. The device shows Online within a minute and receives every member.
4. On the device, configure **access group 2** with a time zone that has no allowed hours. Expired, frozen and blocked members are moved to group 2, so the door refuses them.

Test with one device before rolling out: check a punch IN and OUT appears in Check-ins and that an expired member is refused.

## 7. Go live checklist

- [ ] Sign in at `/platform` as platform admin, create each gym (or use the one from the installer).
- [ ] Gym owner: Settings › Gym profile (GSTIN, address, logo), Rules (joining fee, grace days, freeze fee), Branches (hours, capacity).
- [ ] Plans & packages, classes, staff logins, store products.
- [ ] Members › Import from Excel to bring in existing members.
- [ ] Integrations: WhatsApp and Razorpay (then use **Test**).
- [ ] Share the member app link `https://YOUR-DOMAIN/m/GYM-SLUG` and the website `https://YOUR-DOMAIN/g/GYM-SLUG`.
- [ ] Settings › Backup: download a backup weekly (cPanel › Backup also backs up the database).

## Updating to a new version

1. Upload the new zip.
2. Extract it over `public_html`. Keep `api/uploads`. The config file is outside `public_html` and is not touched.
3. Delete `api/INSTALL_KEY.txt` from the new files; the installer stays locked.

## Bluehost bot filter (ModSecurity)

Bluehost rejects POST requests from headless browsers with `406 Not Acceptable`. Real browsers, phones, eSSL devices and the Razorpay/Meta webhooks are not affected. To run the browser tests against a live site, pass a normal user agent:

```bash
E2E_BASE=https://YOUR-DOMAIN E2E_UA="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36" node tests/e2e/e2e.cjs
```
