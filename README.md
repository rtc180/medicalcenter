# Good Samaritan Medical Center Management System

HTML, CSS, PHP 8.1+ and MySQL/MariaDB. Public SEO website plus a role-based staff portal that follows the clinic activity flow.

## Setup (XAMPP / WAMP / any LAMP host)

1. Copy this folder into your web root (for example `htdocs/balm-of-hope`), or point a virtual host at it so the site is served from `/`.
2. Create the database: `mysql -u root -p < database/schema.sql`
3. Edit `config/config.php` (or set the `BOH_*` environment variables): database user and password, `APP_URL`, `BOH_PUBLIC_URL`, verified clinic contact details, and `CONSULTATION_FEE`. The published centre information currently confirms phone/WhatsApp `+263 775 606 149`; address and email are left blank unless configured.
4. Create the first administrator from a terminal:
   `php database/install_admin.php "Your Name" you@example.com "A-Strong-Password-1"`
5. On an existing installation, run `database/migrations/20261104_admin_payment_catalogs.sql` and then `database/migrations/20261106_inventory_print_settings.sql` once each in phpMyAdmin or the MySQL client. Fresh installations get the same tables from `database/schema.sql`.
6. Open the site, click **Staff Login**, sign in, then use **Users** to add and manage accounts, and **Settings** to manage payment methods, medical-aid types, and the details printed on clinic documents. System backup and data transfer are available from **Settings**. New accounts can be created as administrator, doctor, or nurse. Existing receptionist and pharmacist accounts remain available for compatibility.

Quick local run without Apache: set `BOH_URL=http://localhost:8000` and run `php -S localhost:8000` from this folder.

The public website content, service descriptions, contact phone and WhatsApp link are based on [Good Samaritan Medical Center's published website](https://rtc180.github.io/goodsamaritan/). Contact the centre to confirm service availability; unlisted address, email and opening hours are not assumed.

## How the flowchart maps to the system

| Flowchart step | Where it happens |
|---|---|
| Patient arrives / registration check | Patients: search by name, phone, ID or number; filter by illness, payment method and medical aid |
| Register new patient | Patients > Register (demographics and medical history, duplicate guard) |
| Wait for nurse / triage and vitals | Live Queue > Start triage > Triage form |
| Wait for doctor / consultation and exam | Live Queue > Start consultation > Consultation form |
| Treatment decision | Medication (prescriptions), Refer (lab, diagnostics, specialist), Follow-up appointment |
| Pharmacy dispensing | Pharmacy page (pharmacist) |
| Billing and checkout | Reception Billing page: admin-configured payment methods and medical aid copay; printable receipt |
| Patient departs | Visit closes automatically after payment |

Stages are enforced in order: a doctor cannot see a patient who has not been triaged, and a patient cannot leave without payment being recorded.

## Printing and patient filters

Staff pages include **Print / Save as PDF**. Use the browser print dialog to print or choose **Save as PDF**; the sidebar, controls and navigation are omitted from printed copies. Clinic name, address, phone, email, registration number, and footer note can be changed under **Settings** and are used on patient records, prescriptions, receipts, and reports. Patient records have a patient-card-only print action. Patient search supports diagnosis/complaint, payment-method, and medical-aid provider/member-number filters.

Administrators can use **Reports** to select Patients, Appointments, Prescriptions, Billing, and Audit Log records for a preset or custom date range. Each selected report lists matching records (up to 500 per type) with matching totals and visual activity analytics limited to the selected record types. **Print / Save as PDF** produces a clinic-branded document containing only the selected reports and their date range.

Administrators can export a complete JSON backup or individual CSV files that open in Excel. CSV import supports clinic records one table at a time; JSON/CSV imports insert records only, do not overwrite existing records, and roll back on conflicts. During JSON restore, matching staff accounts are linked by email, existing clinic print settings are preserved, and matching medical-aid/payment catalogs are reused. Oversized text fields are rejected instead of being silently truncated. Full JSON backups include patient health information and password hashes: keep them encrypted/offline and share only with trusted administrators. Inventory tracks medicine and supply stock, reorders, expiry dates, and movement history; dispensing a prescription linked to a medicine item deducts stock automatically.

The public site adds mobile call, WhatsApp, and enquiry shortcuts; staff pages include role-appropriate operational notifications. Canonical/social URLs and the branded social-preview image, `robots.txt` sitemap discovery, and the XML sitemap use `BOH_PUBLIC_URL`; configure it to the deployed public base URL before launch. The sitemap contains only the public homepage because services are currently sections on that single page.

## Security built in

- PDO prepared statements everywhere (SQL injection); all output escaped (XSS)
- CSRF token on every form, POST-only logout
- `password_hash` (bcrypt/argon), login lockout after 5 failures, session ID regeneration, 20-minute idle timeout, HttpOnly + SameSite=Strict cookies, session bound to browser
- Role-based access control on every page and every action
- Content-Security-Policy, X-Frame-Options, nosniff, Referrer-Policy, HSTS on HTTPS
- Audit log of logins, record views, clinical actions and payments
- Honeypot and rate limit on the public contact form
- `.htaccess` blocks `config/`, `includes/`, `database/` and `.sql`/`.env` files

## Before going live

- Set `BOH_ENV=production` (hides errors) and enable HTTPS, then uncomment the redirect in `.htaccess`
- Set `BOH_PUBLIC_URL` to the system's public base URL before going live. Canonical and social-preview metadata, `robots.txt` sitemap discovery, and the XML sitemap are enabled from that value; without it, the site omits public indexing URLs.
- Use a dedicated MySQL user with only the privileges this app needs, not root
- Delete or block `database/install_admin.php` after creating the admin
- Back up the database regularly; patient data is sensitive and may fall under local health-privacy law
