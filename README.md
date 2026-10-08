# Observers

Scheduling system for Pima County election **Observers and volunteers**: Democratic Party volunteers (partisan) and Indivisible non-partisan observers. Plain PHP 8 + MariaDB (PDO prepared statements), vanilla JS, Leaflet + OpenStreetMap (vendored, no API key). No framework.

## Pages

| URL | Purpose |
|---|---|
| `/Observers/` | Landing page |
| `/Observers/nonpartisan/` | Indivisible Non-Partisan Observers: outside observers, drop boxes, roaming |
| `/Observers/partisan/` | Democratic Party volunteers: electioneering, roaming, inside-observer interest |
| `/Observers/verify.php`, `cancel.php` | Email verification (token link) and shift cancel link |
| `/Observers/coordinator/?t=TOKEN` | Private page for external coordinators (no login) |
| `/Observers/admin/` | Admin portal (both groups, with filter) |

Each public entry point shows only its own role's shifts: browse by date, browse by map, coverage by site (date/time grid) and coverage by time (which sites). Colors: red = none, yellow = low (below the shift's target), green = covered. Coverage = confirmed volunteers + coverage reported by external coordinators.

## Roles and site types

| Site type | Non-Partisan Observer | Partisan Electioneering | Partisan Observer |
|---|---|---|---|
| Election Day polling place / Early vote center (75 ft away) | this app | this app | external coordinator (inside) |
| Ballot drop box (75 ft away) | this app | - | - |
| Recorder's Office, Elections Office | - | - | external coordinator |
| Roaming (two virtual sites) | this app | this app | - |

For *external* role/site combinations (Partisan Observer, or any site/role you assign a coordinator to under Admin > Coordinators) the app does not book seats. After the volunteer verifies their email it emails the coordinator (site, shifts, volunteer details, logged in `email_log`) and shows the volunteer as "interest". Coordinators use their private link to upload a CSV (`site_id` or `site`, `date`, `start`, `end`, `volunteers`) that appears in the coverage views.

## Install on shared hosting (PHP 8, MariaDB)

1. Upload the repository to the `Observers` folder of your web root (so it is served at `/Observers`).
2. Create a MariaDB database and user in your hosting control panel.
3. `cp config.sample.php config.php` and edit: database, `site_url` (absolute URL used in emails), `base_url`, and your SMTP relay under `mail`. `config.php` is gitignored.
4. Run the installer (SSH): `php tools/install.php --admin=yourname --import`. It creates the tables from `schema.sql`, seeds roles, the fixed sites ("Pima County Recorder's Office", "Pima County Elections Office") and two virtual "Roaming" sites, creates the admin (password is prompted, or set `OBS_ADMIN_PASSWORD`), and imports the three CSVs in the repo root. Without SSH, import the CSVs from Admin > Import sites. The installer is safe to re-run. More admins: `php tools/create_admin.php USERNAME`.
5. Make sure the `.htaccess` files are honored (Apache). They deny web access to `lib/`, `data/`, `tools/`, `tests/`, `config.php`, `schema.sql` and `*.log`, and disable directory listing. On other servers add equivalent rules. `data/` must be writable if you use the `log` mail transport (`data/mail.log`) or the null SMS provider (`data/sms.log`).
6. Log in at `/Observers/admin/`, then: *Sites* (check sites flagged "needs geocoding" and enter lat/lng), *Shifts > Generate* (pick site types and a date range; default 7am-7pm in 2-hour shifts, configurable per site default or per site/date override, optional `max_volunteers` and target coverage), *Coordinators*.

The fixed Recorder's/Elections Office addresses are seeded without coordinates; set them in Admin > Sites.

### Site CSV import

`php tools/import_sites.php [--type=drop_box|early_vote|election_day] [file.csv ...]` (no arguments: the three CSVs in the repo root), or Admin > Import sites. Headers are matched case-insensitively with aliases (name, address, city, zip, lat/lng, precinct, hours, date / start date / end date, start / end hour). Rows are upserted by type + name + address, so re-importing is safe. Missing lat/lng is stored as NULL and flagged in the admin. Hours in the CSV are stored per day and used by the shift generator when "use imported hours" is ticked.

## Verification, SMS and email

* Email: tokenised link (24 h, configurable); signups are held (and count against `max_volunteers`) until then. The link opens a confirm page and only a POST consumes the token, so mail scanners cannot burn it. A confirmation email contains a cancel link per shift.
* Phone: normalised to US E.164. SMS goes through the `SmsProvider` interface; only `NullSmsProvider` exists (logs to `data/sms.log`, sends nothing), so phones are stored as `unverified_pending_provider`. Add a provider by implementing `SmsProvider` and selecting it in `NullSmsProvider::make()`. Set `verification.require_phone` to `true` to require a verified phone before confirming (with the null provider this means signups stay pending, so leave it `false` for now).
* Use Admin > Blast for the **text-blast export** (one row per phone number) and the email list; it can also send an email blast through the SMTP relay in batches (`mail.blast_batch_size`).
* Mail is sent by a small built-in SMTP client (STARTTLS / SSL / none, AUTH LOGIN). Set `mail.transport` to `log` to write mails to `data/mail.log` instead.

## Security notes

* All SQL uses PDO prepared statements; all output is HTML-escaped; CSV exports neutralise spreadsheet formulas.
* CSRF tokens on every form; sessions are HttpOnly, SameSite=Lax, strict mode, regenerated on login, with an idle timeout; admin passwords use `password_hash`; login and verification attempts are rate-limited (config `rate_limits`).
* Capacity and overlap checks run inside a transaction with row locks (`SELECT ... FOR UPDATE`), so concurrent signups cannot overbook a shift.
* **PII is never shown publicly.** Public pages show only counts and coverage. Volunteer details are visible only to logged-in admins and to a coordinator (via their private token link) for their own sites. Coordinator tokens are stored hashed; rotate them under Admin > Coordinators. Already-verified volunteers keep their stored contact details when someone signs up again with the same email.
* Protect `/Observers/admin/` with HTTPS. Keep `config.php` out of version control.

## Tests

```
phpunit        # uses in-memory SQLite and the real schema.sql
```

Covers capacity enforcement, overlap prevention, verification and phone flows, rate limiting, shift generation, coverage, coordinator reports, exports and CSV import (including the three repo CSVs).
