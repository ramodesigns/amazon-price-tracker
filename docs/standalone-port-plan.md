# Standalone Port - Plan of Action

Plan for porting this plugin's core functionality (fetch product details from
the Amazon Creators API, store/manage/refresh them in MariaDB, expose
password-protected REST endpoints) into a **new repository with zero WordPress
dependencies**. Written 2026-07-15 for a future session to execute cold.

The architecture makes this an easy-to-moderate lift: controllers are thin,
all real logic lives in the service layer, and the helpers are nearly pure
PHP. WordPress is mostly acting as hosting framework (HTTP client, router,
auth, cron) - each role has a small standalone replacement. Estimated 2-4
focused days for a working system, plus comparable time re-establishing test
confidence.

---

## Phase 0 - Decisions to confirm with Paul BEFORE writing code

Ask these up front; each one deletes or shapes a chunk of the port:

1. **Single-tenant?** (Assumed yes.) If yes: the whole per-user settings
   system collapses to env vars / a config file, `created_by` and the
   admin-vs-subscriber rate-limit split disappear, and `Encryption` becomes
   optional. This deletes ~30% of the current code rather than porting it.
2. **Which endpoints survive?** Recommended keep: health, regions, products
   (full CRUD + prices + refresh + reactivate + bulk), blacklist. Recommended
   drop or defer: settings CRUD (env config instead), stats (nice-to-have),
   everything wp-admin (dashboard widget, admin page - Paul drives this via
   Insomnia anyway).
3. **Auth**: recommend a single static bearer token from env, checked with
   `hash_equals()`, HTTPS required. (Paul asked for "basic password
   restricted auth header" - Basic with one credential is equally fine;
   either way it's ~10 lines of middleware.)
4. **Rate limiting**: keep as a global daily creation cap (SQL count, ports
   directly) or drop entirely for a single-operator tool?
5. **Framework**: recommend none or Slim 4/FastRoute at most. The surface is
   ~15 routes; a plain front controller with a route table is enough and
   keeps the dependency tree tiny.
6. **PHP/MariaDB floors**: recommend PHP 8.2+, MariaDB 10.6+. Note the
   products-list SQL uses `JSON_EXTRACT`/`JSON_UNQUOTE` - fine on MariaDB
   10.2+, verify on the actual target server.

## Phase 1 - Repo skeleton (half a day)

```
amazon-price-tracker-api/
├── bin/                      # CLI entry points for real crontab
│   ├── refresh.php           #   (replaces WP-Cron + Scheduled_Refresh)
│   └── prune-history.php     #   (replaces Price_History_Maintenance cron)
├── config/
├── db/migrations/            # numbered .sql files (see Phase 3)
├── docs/openapi.yaml         # ported contract - see "OAS as the contract"
├── public/index.php          # front controller: auth -> route -> handler
├── src/
│   ├── Amazon/               # CreatorsApiClient, TokenProvider, ItemParser, Regions
│   ├── Api/                  # Router, handlers (one per resource), Response, AuthMiddleware
│   ├── Domain/               # ProductService, PriceHistoryMaintenance
│   ├── Infra/                # PdoFactory, HttpClientInterface + CurlHttpClient, Clock
│   └── Support/              # Validation (+ Encryption only if kept)
├── tests/
│   ├── unit/                 # pure logic, no I/O
│   ├── service/              # real MariaDB test DB + fake HttpClient (the old "component" tier)
│   └── integration/          # env-gated, real Amazon (same APT_TEST_CREATORS_API_* vars)
├── .env.example
├── composer.json             # proper PSR-4 autoload (replaces the plugin's spl_autoload hack)
└── phpunit.xml.dist
```

Bootstrap: `composer init`, PSR-4 autoload, phpunit + a dotenv loader (or
port the plugin's own `Env_File`). No other runtime dependencies needed
unless Slim is chosen.

**Build the seams first** - they are what make everything else testable:
- `HttpClientInterface` (request(method, url, headers, body) → status/body) +
  a cURL implementation. The Amazon client depends on the interface, tests
  inject a fake. This replaces both `wp_remote_post` AND the
  `pre_http_request` mock in one move - a far simpler harness than the WP
  one (no bootstrap, no table reinstall, no nested-transaction COMMIT hell).
- `PdoFactory` returning a configured PDO (ERRMODE_EXCEPTION - note the old
  code's silent-`$wpdb`-failure bug class, e.g. the 11-char ASIN incident,
  disappears with this).

## Phase 2 - Port the pure layer (half a day)

Port order matters: each step is testable before the next starts.

1. `Regions` - copy nearly verbatim (already pure after the MIGR-12 cleanup).
2. `Validation` - replace `sanitize_text_field` with `trim` +
   `filter_var`/`htmlspecialchars` where output-bound; the rest is pure.
3. Response shape helper - keep the exact JSON contract from the OAS
   (`{code, message, data:{status,...}}` errors, pagination meta) so
   existing clients/Insomnia collections keep working.
4. Port `tests/unit/` - these translate almost mechanically (drop
   `WP_UnitTestCase` for plain `TestCase`).

## Phase 3 - Schema + migrations (half a day)

- Port `Installer.php`'s CREATE TABLEs into `db/migrations/001_init.sql` -
  they run on MariaDB unchanged. Apply the Phase-0 decisions: likely drop
  `apt_user_settings` entirely (env config), drop `created_by` or keep as a
  simple audit column.
- Write a 20-line `bin/migrate.php` (track applied files in a `migrations`
  table). The plugin never had an upgrade path (noted in MIGR-02) - don't
  repeat that; migrations from day one.

## Phase 4 - Port the Amazon client (1 day)

- `Amazon_Creators_API` → `Amazon/CreatorsApiClient` + `TokenProvider` +
  `ItemParser` (split the 800-line class along its natural seams). Only WP
  calls to replace: `wp_remote_post` → `HttpClientInterface`,
  `wp_json_encode` → `json_encode`, `error_log` gating → PSR-3-ish logger or
  plain `error_log`.
- Port `tests/component/test-creators-api-parsing.php` and the canned
  fixtures from `creators-api-mock.php` as unit tests against the fake HTTP
  client - these carry the hard-won parsing knowledge (see "Institutional
  knowledge" below) and translate almost directly.

## Phase 5 - Port ProductService (1 day)

- `$wpdb->prepare/get_row/insert/update` → PDO prepared statements
  (mechanical); `current_time('mysql', true)` → `gmdate('Y-m-d H:i:s')` via
  the `Clock` seam; `delete_transient` cache-busting → drop or a trivial
  cache interface.
- **Do FEAT-07 as part of the port, not after**: design `bulkCreate()`
  batched from day one (pre-checks first, then getItems in chunks of 10 per
  region, absence-from-response = per-item not-found) instead of porting the
  call-per-item loop and refactoring later. `bulk_refresh()` is the
  reference implementation.
- Port the service-tier tests (`test-product-service.php`,
  `test-scheduled-refresh.php` logic) against a real MariaDB test database +
  fake HTTP client. Use a dedicated test DB with per-test TRUNCATE - simpler
  and more honest than the WP transaction-wrapping that fought us all
  session.

## Phase 6 - HTTP layer (1 day)

- Front controller: parse path/method → route table → handler. Auth
  middleware first (constant-time token compare; return the same
  401 JSON shape as the OAS documents).
- Port handlers thin, in this order (each verifiable in Insomnia as it
  lands): health → regions → products list/get/prices → create →
  refresh/reactivate/delete → bulk → blacklist.
- Rate limiting (if kept): the SQL count ports directly, minus the
  role-based bypass.

## Phase 7 - Cron + deploy (half a day)

- `bin/refresh.php`: the `Scheduled_Refresh::run_scheduled_refresh()` logic
  minus all the WP-Cron scheduling bookkeeping - real crontab handles
  scheduling (an upgrade: WP-Cron only fires on traffic). Same for history
  pruning.
- Deploy notes: vhost with `public/` as docroot, HTTPS, env file outside
  docroot, crontab entries.

## Phase 8 - Acceptance (half a day)

- Port `tests/integration/` (the 6-file live suite) - same
  `APT_TEST_CREATORS_API_*` env gating, same journeys; these become the
  port's acceptance suite. The product-lifecycle chain passing live against
  the new stack is the "done" signal.
- Import the ported `openapi.yaml` into Insomnia and walk
  `docs/api-test-checklist.md` (trimmed to surviving endpoints).

---

## The OAS as the contract

`docs/openapi.yaml` in THIS repo is the porting contract: copy it into the
new repo, amend auth (bearer/basic instead of Application Passwords), delete
dropped endpoints (settings CRUD, stats if dropped), and keep
request/response schemas byte-compatible otherwise. Regenerate
`api-reference.html` via `npx @redocly/cli build-docs`. Any behavior
question during the port is answered by the spec + this repo's tests, in
that order.

## Institutional knowledge that MUST carry over (learned the hard way here)

- **Account-level no-`Offers` restriction**: Associates accounts without 3+
  qualifying sales/180 days get items back with pricing silently omitted -
  200 OK, no error. Port the WP_DEBUG-style log line AND the live pricing
  canary test that fails-on-purpose when pricing appears.
- **Absence IS the not-found signal**: the Creators API has no per-item
  not-found error; an unknown ASIN is just missing from a 200 response.
  Distinguish that from `itemsResult.items` missing entirely (malformed →
  error) - see `Amazon_Creators_API::get_items()`.
- **Availability parsing order**: out-of-stock/unavailable → only-N-left →
  in-stock, most-specific first ("Only 3 left in stock" contains "in
  stock"; getting this backwards was a real shipped bug found 2026-07-14).
- **Batch cap**: 10 itemIds per getItems; group by region (per-region
  marketplace/token); 100ms inter-batch courtesy delay.
- **Cognito (v2.x) credential flow has never executed anywhere** - only the
  LWA/v3.x path is proven. Carry the gap forward explicitly, don't
  silently assume it works.
- **Region data**: `Regions` is the single source of truth for
  marketplace/currency per region code; UK partner tag lives per region.

## What is deliberately NOT ported

Dashboard widget and all wp-admin UI, Application Passwords / WP user model,
WP-Cron bookkeeping (`is_scheduled`/`get_next_run` etc.), transient caching,
i18n (`__()` - strip to plain strings), and (pending Phase 0) per-user
settings CRUD + at-rest credential encryption.

## Task tracking

Tracked as **PORT-01** in `docs/pendingTasks.txt`. Suggested first prompt
for the future session: *"Read docs/standalone-port-plan.md, confirm the
Phase 0 decisions with me, then execute Phases 1-2."*
