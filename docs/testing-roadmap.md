# Testing Roadmap

Working backlog for the plugin's testing effort. Ordered roughly by what comes next; performance testing is explicitly lowest priority.

## Done

- [x] Unit tests for `Validation`, `Response`, `Regions`, `Encryption` helpers (`tests/unit/`) - 100% method/line coverage on all four except `Encryption::get_key()`'s no-`AUTH_KEY` fallback branch, which isn't reachable from the WP test environment.
- [x] PCOV installed locally for coverage reporting (`pecl install pcov`, needed `CFLAGS="-I/opt/homebrew/include"` on this machine for the `pcre2.h` build dependency).
- [x] Integration test harness stood up: `tests/bootstrap.php` now runs `Installer::install()` so plugin DB tables exist (they otherwise only get created via `register_activation_hook()`, which never fires when the bootstrap just `require`s the plugin directly); `phpunit.xml.dist` has an `integration` testsuite pointing at `tests/integration/`.
- [x] One real example: `tests/integration/test-products-controller.php` - full REST dispatch through `POST /products`, real DB writes, real network call to Amazon PA-API. Credentials come from env vars (`APT_TEST_PA_API_*`), never hardcoded.
- [x] Found and fixed a real bug this surfaced: `Amazon_API.php` was building request paths in PascalCase (`/paapi5/GetItems`) instead of the lowercase paths Amazon's routes require (`/paapi5/getitems`) - every live API call was silently broken.
- [x] Found and fixed a related bug: `Product_Service::create_product()`'s internal transaction used `catch (\Exception $e)`, which misses `\Error`/`\TypeError` and could leave a transaction open on a crash. Now `catch (\Throwable $e)`.
- [x] Diagnosed (via manual local WP env, not a bug): real PA-API responses were coming back with no `Offers` resource at all despite a successful 200, so `current_price`/`availability` always saved as null/unknown. Root cause is an Amazon account-level restriction - PA-API omits `Offers` for Associates accounts without 3+ qualifying sales in the trailing 180 days. Added a `WP_DEBUG` log line in `Amazon_API::get_items()` for this, plus a real-network canary test, `tests/integration/test-amazon-api-pricing.php`, that asserts pricing is currently null - it's meant to start *failing* once the account clears that threshold and Amazon starts returning real offers, as the signal to update expectations elsewhere.

## Testing tiers: unit vs. component vs. integration

Three tiers now, split by what's real vs. faked and where they live:

| Tier | What's real | What's faked | Directory | CI |
|---|---|---|---|---|
| Unit | The class under test | Everything else - no REST dispatch, no DB writes | `tests/unit/` | Always |
| Component | REST dispatch, DB, WP core | Amazon PA-API (via `pre_http_request`) | `tests/component/` | Always |
| Integration | REST dispatch, DB, WP core, Amazon PA-API | Nothing | `tests/integration/` | Opt-in only (env-var gated) |

**Determination - which tier a given test belongs in:**

- Testing *this plugin's own logic* (routing, permission checks, validation, error mapping, DB row shape, response shape) → **component**. Amazon's actual response content is irrelevant to what's being verified, and a canned fixture is arguably better than the real thing here: it lets you deterministically hit every branch (success, ASIN not found, throttled, malformed response, partial data) on demand, which live Amazon won't reliably reproduce.
- Testing that the plugin's real request/response code actually *interoperates* with Amazon's real API (correct signing, correct per-region hosts/paths, real payload shape, credential handling) → **integration**. This is exactly what caught the `/paapi5/GetItems` path-casing bug tonight - no amount of mocking would have found that, since a hand-written mock would just have encoded the same wrong assumption the production code had.
- Rule of thumb: component tests should heavily outnumber integration tests. Integration tests answer "does the wire format actually work," not "does every branch of business logic work" - exercising every branch against a live, rate-limited, credentialed external service is slow and expensive for no extra signal.

**Mechanics for component tests:** intercept at the `pre_http_request` filter, which `wp_remote_post()` already checks before making a real socket call. A canned Amazon-shaped JSON response comes back through the *same* code path that runs in production - `Amazon_API::request()` still builds the real signed request, still parses whatever comes back - so component tests still catch bugs in URL building, header construction, and response parsing (they would have caught the casing bug too, once tests existed at all), just not "does Amazon's server accept our signature."

**Next actions:**

- [x] Create `tests/component/` and add a `component` testsuite entry to `phpunit.xml.dist`.
- [x] Build the `pre_http_request` mocking helper (parallel to `tests/unit/encryption-function-overrides.php`'s pattern: a small fixture file, not a framework) - `tests/component/pa-api-mock.php`.
- [x] Port the `Products_Controller` create-path test to a component version with canned success/failure fixtures (ASIN not found, malformed response, timeout) - `tests/component/test-products-controller.php` (class `Test_Products_Controller_Component`, renamed to avoid a class-name collision with the integration test when the full suite runs in one process) - keep the one real-network integration test as the sole "does the wire format work" check for that endpoint.
- [ ] Add component coverage for the rest, in rough order of cheapness: `Health_Controller` (no Amazon dependency), `Regions_Controller` + `Categories_Controller` (read-only, no Amazon dependency), `Settings_Controller` (credential storage/encryption round-trip), `Blacklist_Controller` (admin-only permission checks), then the remaining `Products_Controller` endpoints (list/filter, get by id, get by ASIN/region, price history + aggregations, refresh, bulk create, bulk refresh, delete).
- [ ] Integration tier stays deliberately thin: one or two real-network smoke tests per Amazon-touching operation (create, refresh), not a full endpoint sweep.
- [ ] Consider factoring the repeated `WP_REST_Server` boot + route registration out of `setUp()` into a shared base test case once a few more controller test files exist across both tiers (not yet - only one file today, premature to abstract).

## Mutation testing

Verifies the tests actually catch bugs, not just that they pass. Standard tool for PHP is [Infection](https://infection.github.io/).

- [ ] `composer require --dev infection/infection`
- [ ] Add `infection.json5` config, scoped to `includes/Helpers/` first (highest existing coverage, cheapest to get a clean baseline) before widening to `includes/Services/` and `includes/API/Controllers/`.
- [ ] Needs a coverage driver (already have PCOV installed) and a passing baseline test run to seed mutation targets.
- [ ] Expect the first run to surface weak assertions (e.g. `assertNotEmpty` where a mutant could still slip through) - budget time to strengthen tests, not just chase the score.

## Real WordPress environment for manual testing

Needed before E2E automation can exist. Standard options:

- [ ] `@wordpress/env` (`wp-env`) - official WordPress tooling, Docker-based, simplest to wire up for a single plugin, config lives in `.wp-env.json`.
- [ ] Alternative: plain `docker-compose.yml` with `wordpress` + `mysql` images if you want more control than `wp-env` gives you.
- [ ] Either way: mount this repo into `wp-content/plugins/`, activate it, then `docs/api-test-checklist.md` already exists as your manual QA script - Postman collection referenced there is `docs/api-collection.json`, worth confirming that's still current.
- [ ] Real credentials for manual PA-API testing should go through the plugin's own encrypted settings storage (as designed) rather than env vars - that's the real user flow, unlike the test shortcut we used tonight.

## E2E browser automation (after the container environment exists)

- [ ] Playwright is the more natural fit over Cypress here given WordPress's traditional (non-SPA) admin UI and the REST API surface - better multi-tab/multi-context support if testing Application Password creation flows.
- [ ] Start with the golden path: create Application Password → configure PA-API credentials → create a product via REST → see it reflected wherever the plugin surfaces it in wp-admin.
- [ ] This tier only makes sense once the `wp-env`/Docker environment above is reliable and scriptable (spin up, seed, tear down) - don't start this before that's solid.

## Performance testing (lower priority)

- [ ] Once the container environment exists: `k6` or `wrk` against the REST endpoints, particularly `GET /products` (the hand-rolled SQL with `JSON_EXTRACT` filtering/sorting in `Products_Controller::get_items()` is the most likely bottleneck at scale) and `bulk_refresh` (sequential Amazon API calls with a 100ms inter-batch delay - throughput-bound by design).
- [ ] Worth a realistic data volume seed (thousands of products/price-history rows) rather than testing against an empty dev DB - `tests/seed-test-data.php` may need extending for this.
