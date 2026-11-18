# MajiLink

**Pin it. Order it. Delivered.**

Neighborhood water delivery platform for 20-litre jerrycan refills. One Kotlin app with two modes
(blue customer, green vendor), a PHP/MySQL API, a server-rendered admin portal and a static landing page.

```
public_html/     API front controller, admin portal, landing page, assets
src/             Support, HTTP, Middleware, Providers, Services, Controllers
config/          app.php, database.php, routes.php
database/        migrations/, seeders/, migrate.php
storage/         logs, uploads (vendor documents, outside the web root), cache
android/         Kotlin + Jetpack Compose app (customer and vendor modes)
tests/           api_smoke.php — 76 end-to-end checks
cron.php         order expiry, recurring orders, heartbeat cleanup
```

## What is built and verified

| Component | State |
|---|---|
| MySQL schema (36 tables, no ENUMs, lookup tables + FKs) | Built and migrated into a real MariaDB instance |
| API v1 (auth, OTP, devices, pins, vendors, orders, payments, wallet, payouts, recurring, ratings, admin, config, M-Pesa callback) | Built; 76/76 smoke checks pass against the running server |
| Admin portal (14 pages + actions) | Built; every page returns 200 with a staff session, actions persist and are audited |
| Landing page (hero, steps, why, vendor CTA, FAQ, contact) | Built; served and contact form writes to `storage/logs/contact.log` |
| Android app (Compose, Retrofit, Room, MapLibre, biometrics, FCM service) | Source complete; see build notes below |

## Running the backend locally

The project ships with an isolated development database configuration (port **3307**) so it never
touches an existing MySQL/MariaDB service on 3306.

```bash
# 1. Start a dedicated MariaDB with its own data directory (XAMPP's binaries work)
"C:/xampp/mysql/bin/mysql_install_db.exe" --datadir="C:/projects/MajiSasa/.devdb" --password="majilink_dev" --port=3307
"C:/xampp/mysql/bin/mysqld.exe" --defaults-file="C:/projects/MajiSasa/.devdb/my.ini" --standalone

mysql -h127.0.0.1 -P3307 -uroot -pmajilink_dev -e "CREATE DATABASE IF NOT EXISTS majilink CHARACTER SET utf8mb4"

# 2. Configure and build the schema
cp .env.example .env          # then set APP_KEY, DB_* and SEED_DEMO_PASSWORD
php database/migrate.php
php database/seeders/seed.php   # defaults + demo unless APP_ENV=production

# 3. Serve
php -S 127.0.0.1:8099 -t public_html public_html/router.php
```

Then open:

* Landing page — <http://127.0.0.1:8099/site/>
* Admin portal — <http://127.0.0.1:8099/admin/index.php>
* API health — <http://127.0.0.1:8099/api/v1/health>

### Demo accounts

Demo data is seeded only when `SEED_DEMO_PASSWORD` is set (8+ characters) and `APP_ENV` is not
`production`. Every demo account uses that password; the seeder never prints it.

| Role | Email | Phone |
|---|---|---|
| Admin | admin@majilink.co.ke | 0700000001 |
| Support | support@majilink.co.ke | 0700000002 |
| Customer | albert@example.com | 0712000001 |
| Customer | grace@example.com | 0712000002 |
| Vendor (Kilimani Pure Water) | peter@kilimaniwater.co.ke | 0722000001 |
| Vendor (Kileleshwa Borehole) | mary@kileleshwa.co.ke | 0722000002 |
| Vendor pending | brian@example.com | 0722000005 |

Seeded demo shops are open 24 hours and heartbeat at seed time so they appear in nearby searches
regardless of the hour. Run the seeder again to revive heartbeat freshness.

## API

JSON, versioned under `/api/v1`, JWT access token (15 min) + per-device refresh token, PIN per device.
Permissions are checked by name (`orders.create`, `vendors.approve` is `vendors.review`, …), never by role.

```
POST /auth/otp/request|verify          POST /auth/register|login|login/pin|refresh
POST /auth/pin|password/forgot|reset   GET  /auth/channels|me|devices
GET/POST/PUT/DELETE /locations         GET  /vendors/nearby?location_id=|{id}
POST /orders/quote                     POST /orders
GET  /orders?filter=active             GET  /orders/{id}
POST /orders/{id}/cancel|rate|pay/mpesa
GET/POST/PUT/DELETE /recurring-orders  GET  /notifications
POST /vendor/apply|documents|online|heartbeat
GET  /vendor/application|profile|orders|wallet|transactions|payouts
POST /vendor/orders/{id}/accept|decline|on-the-way|delivered|cancel
GET  /admin/vendors/applications       POST /admin/vendors/{id}/review
GET  /admin/orders                     POST /admin/orders/{id}/status
GET  /admin/payments|payouts|reports   POST /admin/payouts/{id}/process
POST /admin/broadcast                  POST /callbacks/mpesa
```

Responses are `{"data": …}` or `{"error":{"code","message","fields"}}`.

### Runtime config and the app gate

`GET /api/v1/config` is public and needs no token. It is what lets one APK serve every
environment: the version floor, maintenance switch, feature flags and public limits all come
from `app_settings` rather than from a compile-time constant. Rows land in Admin → Settings
automatically (that page renders every `app_settings` key) and every edit is audited.

| Key | Effect |
|---|---|
| `min_supported_version_code` | Android `versionCode` below this is refused by the API with **426 `app_update_required`** |
| `min_recommended_version_code` | Below this the app shows the update prompt; `force_update` decides whether it can be dismissed |
| `latest_app_version_name`, `update_message`, `update_url` | Copy and destination for the update screen |
| `maintenance_mode`, `maintenance_message` | All `/api/v1` traffic except `config` and `health` returns **503 `maintenance_mode`** with this message |
| `api_base_url` | Advertised base URL. The app adopts it only when it stays on the same host it already talked to |
| `allow_endpoint_override` | Opens the in-app Server screen on release builds (debug builds always have it) |
| `feature_flags` | Comma-separated enabled names, served as a `{"name":true}` map |
| `currency`, `min_quantity`, `max_quantity`, `default_radius_km`, `max_radius_km`, `order_expiry_minutes` | Values the app prints or enforces before it has a quote |

`RuntimeGate` runs before authentication on every `/api/v1` route, so an unusable build can be
locked out without shipping an APK. A request with **no** `X-App-Version-Code` header is treated
as a legacy client and never gated — that keeps old APKs, the admin portal and the landing page
working. New clients send `X-App-Version-Code`, `X-App-Version-Name` and `X-Device-Uid`, which is
also how you tell broken builds apart in `storage/logs/api-errors.log`.

`database/migrations/002_app_config.sql` inserts those rows. It is additive: no renamed or dropped
columns, so existing databases upgrade in place with `php database/migrate.php`.

### Business rules implemented in code

* Vendor visibility: approved + online + heartbeat within timeout + inside the customer radius
  (capped by `max_radius_km` and the vendor's own radius) + inside operating hours.
* Matching: bounding-box index pass, Haversine distance, then road distance/ETA for the top 10
  cached for 10 minutes (OSRM, falls back to a straight-line estimate if unreachable).
* Order lifecycle: a single transition map; anything else returns 409. Pending orders expire
  after `order_expiry_minutes` (cron).
* Money: integer-cent arithmetic, `DECIMAL(10,2)` storage, commission stored on the order.
  Cash delivery credits earnings and debits commission; M-Pesa callback credits net earnings once
  (idempotent by `CheckoutRequestID`).
* Privacy before acceptance: the vendor sees only first name, quantity, total and an approximate
  distance. The exact pin, alias and phone unlock on acceptance.
* Payouts: requested against the available balance (balance minus open payouts), approved, then
  marked paid with the M-Pesa receipt, which debits the wallet.
* Verification channels: a channel cannot be enabled until a test send succeeds, and the last
  enabled channel cannot be disabled. Provider config is encrypted with libsodium.

## Tests

Three layers, each cheap enough to run on every change.

| Layer | Command | What it proves |
|---|---|---|
| PHPUnit (unit + services) | `composer test` | Pricing maths, order state machine, vendor matching, wallet/payout ledger, validation, tokens |
| Kotlin JVM unit tests | `cd android && ./gradlew testDebugUnitTest` | The JSON contract between the app and the API, and error/envelope mapping |
| End-to-end API smoke | `composer test:api` (server must be running) | The real HTTP surface against a real database: auth, orders, money, admin |

Narrower runs: `composer test:unit`, `composer test:services`,
`./vendor/bin/phpunit --filter PricingTest`.

### The test database is separate and disposable

`tests/php/bootstrap.php` repoints `DB_NAME` at **`majilink_test`** before anything else runs, and
refuses to start if `TEST_DB_NAME` resolves to the database in `.env` — so a test run can never
migrate or wipe your dev data. It creates the database, applies `database/migrations` with the
same `Migrator` `database/migrate.php` uses, seeds the lookup tables, then clears the transactional
tables before each test. Point it elsewhere with `TEST_DB_NAME=majilink_ci composer test`.

Reads and writes are the only side effects: no outbound HTTP (`ROUTING_URL` is blanked, so ETAs
use the straight-line estimate) and no push (FCM is unconfigured).

### What is covered

* **Pricing** — subtotal, base + per-km delivery fee, commission on the subtotal only, cent
  rounding, zero-fee configuration, quantity bounds read from settings.
* **Order lifecycle** — price snapshot at order time, the legal transition map, double-accept and
  post-delivery rejection, automatic expiry, customer/vendor/admin cancellation rules, the
  pre-acceptance privacy boundary, and cross-customer / cross-vendor access.
* **Vendor matching** — approved + online + fresh heartbeat, customer radius clamped to the
  maximum, the vendor's own delivery radius, opening hours including windows that cross midnight,
  distance ordering and ETA.
* **Wallet and payouts** — cash earnings less commission, running ledger balance, duplicate-credit
  guard, payouts capped at the available balance, and per-vendor isolation.
* **App contract** — snake_case JSON parsed with the production Gson, unknown fields ignored
  (additive backend columns), error envelopes mapped to field errors, network failures reported as
  such, and the `app_update_required` code surviving the mapping.

The end-to-end smoke script additionally covers registration with email OTP, password/PIN login,
refresh rotation and reuse rejection, saved pins, M-Pesa STK + idempotent callback, vendor
application + admin approval + mode unlock, admin reports, broadcast and 401/403 boundaries.

Not automated yet: Compose UI tests (login → order → track) and the admin portal's HTML. Those are
still manual, as is anything that needs a map, GPS or a real phone.

PHP lint: `find src public_html config database tests -name "*.php" -exec php -l {} \;`

## Deploying behind Apache (shared hosting)

`public_html/` is the only directory that should be web-exposed; `src`, `config`, `database`, `storage`,
`bootstrap.php` and `cron.php` sit beside it and travel with it (`BASE_PATH` is anchored to
`bootstrap.php`). cPanel serves the domain from `~/public_html`, so upload the application to a
folder such as `~/majilink` and copy the contents of `public_html/` into `~/public_html` — keep
`bootstrap.php` and the other app folders one level above the web root. If you upload the entire
project under `~/public_html` instead, point the domain/subdomain document root at
`public_html/public_html`.

`public_html/.htaccess` rewrites everything that is not a real file to `index.php`,
which is what `public_html/router.php` does for `php -S` — without it the `/api/v1/...` URLs 404 in
production, and the admin portal, landing page and assets keep being served directly because the
rule skips existing files. Set `APP_URL` to `https://majilink.co.ke`, `api_base_url` in Admin →
Settings to `https://majilink.co.ke/api/v1/`, `OTP_DEV_EXPOSE=0`, `MPESA_SIMULATE=0`, point
`MPESA_CALLBACK_URL` at `https://majilink.co.ke/api/v1/callbacks/mpesa`, and give `storage/logs`,
`storage/cache` and `storage/uploads` write permission. With `APP_ENV=production`, loopback
`APP_URL`/`api_base_url` values are ignored and the live origin is used instead.

### First deploy: schema and reference data

Run both commands once from the application root (the folder containing `bootstrap.php`):

```bash
php database/migrate.php
php database/seeders/seed.php
```

With `APP_ENV=production` the seeder is defaults-only: it creates roles, permissions, lookup
tables, OTP channels and `app_settings`, and skips every demo customer, vendor, pin and order.
To create the first admin, set these in `.env` before running it:

```dotenv
SEED_ADMIN_FIRST_NAME=
SEED_ADMIN_LAST_NAME=
SEED_ADMIN_PHONE=
SEED_ADMIN_EMAIL=
SEED_ADMIN_PASSWORD=
```

The seeder is idempotent. Re-running it does not reset an existing admin password; it only
ensures the `admin` role is attached. Leave the values blank and no admin is created.
`--defaults` forces the same mode outside production.

## Scheduled jobs

```cron
* * * * * php /path/to/majilink/cron.php >> /path/to/majilink/storage/logs/cron.log 2>&1
```

Expires stale pending orders (and notifies the customer), creates due recurring orders, and marks
silent vendors offline.

## Android app

```bash
cd android
./gradlew assembleDebug        # or: gradle assembleDebug with Gradle 8.9
```

* Kotlin 2.0.21, Compose BOM 2024.10.01, AGP 8.7.3, minSdk 26, target/compileSdk 35.
* Retrofit + OkHttp with a device-bound refresh flow, Room cache for vendors/orders/pins,
  MapLibre + OpenFreeMap, FCM messaging service, BiometricPrompt + EncryptedSharedPreferences.
* The API base is resolved at **runtime**, not baked into every call. Order is: an address saved on
  the in-app **Server** screen, then a base URL adopted from `/api/v1/config` (same host only),
  then the build default. Release builds default to `https://majilink.co.ke/api/v1/`; debug builds
  default to `http://10.0.2.2:8099/api/v1/` (emulator to host). A release build only accepts an
  HTTPS override via `-Pmajilink.apiBase=https://api.example.com/api/v1/`; loopback and cleartext
  overrides are discarded.
* The Server screen is reachable on any debug build, and on a release build only when the server
  sets `allow_endpoint_override`. Saving drops the session, because those tokens belong to the
  server that issued them. A failed config fetch never blocks the app; a cached config does.
* `AppGate` renders the server's decision above the whole nav graph: maintenance screen, or the
  update screen (dismissible unless the server forces it). `MainActivity` hosts it.
* Maps use **MapLibre + OpenFreeMap** (`ui/components/MajiMap.kt`): no Google Maps key, no
  billing account. Tiles come from `https://tiles.openfreemap.org/styles/liberty`; the style is a
  single constant there if you ever move to a paid or self-hosted tile server.
  Keep the `© OpenFreeMap · © OpenMapTiles · © OpenStreetMap` attribution visible — it is a
  licence condition.
* GPS still uses Google Play Services' fused location provider (free, unrelated to Maps billing),
  and the Navigate button hands off to the phone's map app.
* Push needs a Firebase project: drop `google-services.json` into `android/app/` and apply the
  Google services plugin. Without it the app runs; notifications are still stored server-side and
  readable in-app.

### Point the app at your laptop (LAN, no emulator)

The debug build target is `http://10.0.2.2:8099/api/v1/`, which only works inside the emulator.
To use a real phone on the same Wi-Fi, three things have to line up: the server must listen on the
LAN, Windows must allow the port, and the APK must point at your laptop's IP.

**1. Find your laptop's IP** (use the `192.168.x.x` / `10.x.x.x` one, not `127.0.0.1` or the
virtual adapter addresses):

```bash
ipconfig | grep -i "IPv4"
```

**2. Serve on all interfaces** (`127.0.0.1` is unreachable from the phone):

```bash
php -S 0.0.0.0:8099 -t public_html public_html/router.php
```

Confirm from the laptop first — if this fails, nothing else will work:

```bash
curl http://192.168.100.11:8099/api/v1/health
```

**3. Open the port once** (Command Prompt as Administrator; the rule is for the private/home
network, so it does not expose the laptop on public Wi-Fi):

```bat
netsh advfirewall firewall add rule name="MajiLink API 8099" dir=in action=allow protocol=TCP localport=8099 profile=private
```

Then load `http://192.168.100.11:8099/api/v1/health` in the phone's browser. A timeout there is
almost always the firewall or the phone being on a different network/SSID (guest networks and
"client isolation" block device-to-device traffic).

**4. Build the APK against that IP:**

```bash
cd android
./gradlew installDebug -Pmajilink.apiBase=http://192.168.100.11:8099/api/v1/
```

In **PowerShell** the whole flag has to be quoted, or the parser splits it at the dot inside the
property name and Gradle reports `project '.apiBase=http' not found`:

```powershell
./gradlew installDebug "-Pmajilink.apiBase=http://192.168.100.11:8099/api/v1/"
```

No need to rebuild when the IP changes: on a debug build open **Server** (Account → Server) and
save the new address; it accepts a bare `192.168.100.11:8099` and adds `http://` and `/api/v1/`.
The session is dropped on save because the tokens belong to the old server.

**5. Tell the backend its own public address.** Admin → Settings → `api_base_url`, or:

```bash
mysql majilink_dev -e "UPDATE app_settings SET setting_value='http://192.168.100.11:8099/api/v1/' WHERE setting_key='api_base_url'"
```

`/api/v1/config` then advertises that URL, and the app adopts it after a fetch (same host only) so
one install can follow the laptop around the network. For production use the HTTPS origin here.

**Over USB instead of Wi-Fi** — no firewall rule, no IP juggling:

```bash
adb reverse tcp:8099 tcp:8099
./gradlew installDebug -Pmajilink.apiBase=http://127.0.0.1:8099/api/v1/
```

Two cautions: debug builds allow cleartext HTTP (the manifest sets
`usesCleartextTraffic="true"`); a release build must talk HTTPS, so do not ship the LAN setup.
And if the phone shows "No connection", check `storage/logs/server-lan.log` — a request that never
arrives is the firewall, a request that arrives with a 500 is the backend.

### Mode theming

`MajiTheme(role, dark)` swaps the whole colour scheme: customer `#0077B6`, vendor `#15803D`, action
teal `#2EC4B6` always with navy text, danger `#B91C1C`, warning `#F77F00`. Status chips always show
their text label as well as colour.

## Security notes

* Argon2id for passwords and PINs (bcrypt only if the PHP build lacks libargon2), trivial PINs rejected.
* Access tokens 15 minutes; refresh tokens stored hashed per device and revocable.
* Rate limits on OTP requests, logins and PIN attempts per identity and per IP.
* Vendor documents live in `storage/uploads` (outside the web root) and are streamed only through
  an authenticated admin endpoint.
* Admin portal: session auth, CSRF on every POST (419 on mismatch), permission check per page,
  audit log on every change.
* M-Pesa provider keys come from the environment; `MPESA_SIMULATE=1` is local-only.

## Deferred / not configured here

* Real SMS and WhatsApp credentials (drivers exist; enable them from Admin → Verification channels
  after a successful test).
* Firebase project config and Maps API key (see above).
* Automated B2C payouts — v1 pays manually and records the receipt.
* iOS, in-app chat, live rider tracking, coupons, multi-language beyond English/Swahili.
