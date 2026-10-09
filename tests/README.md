# Testing

Install [DDEV](https://docs.ddev.com/en/stable/users/install/ddev-installation/) and a supported Docker provider (OrbStack works on macOS). From this plugin's checkout, run:

```sh
ddev test
ddev test --filter='a test name'
ddev test --suite=all
ddev test --suite=performance
ddev test --suite=large-performance --default-time-limit=300
```

The large-performance suite creates 1,000 owners and measures incoming-link reads, distinct destinations and memory retained across batches. It runs separately from the regular suite because it creates considerably more content.

The command starts the dedicated test project, installs dependencies inside DDEV, creates a clean Craft application, installs this checkout as a Composer path dependency, seeds plugin fixtures and runs Pest. No separate Craft site, host PHP, host Composer, database setup or `.env.testing` file is required. The root Composer `test` aliases call this same command if you already have Composer on your host.

Tests run against real Craft. The PHPUnit XML discovers PHP tests; the suite manifest in `tests/runtime/suite.json` defines intentional group exclusions. The default excludes slow, performance, large-performance and migration-plugin groups. Some plugins have additional suites listed in that manifest. Test files named `Unit` may still rely on the Craft application.

Each invocation rebuilds database, project configuration and storage under `.cache/verbb-tests/app`. Dependencies are cached between runs. The generated app loads the plugin from this checkout; developer `.env` files and paired sites are not used. Tests must not be pointed at an external database. Run serially; parallel workers are rejected until they have independent state.

The DDEV project name is stable. Re-running tests does not allocate another project. Leave the project running when it is shared with other work. To remove a disposable project that you own, use `ddev delete` from this checkout after keeping any reports you need. The next test run recreates its baseline.

Results and combined setup/test output are written to `.cache/verbb-tests/result.json` and `.cache/verbb-tests/latest.log`; Craft logs remain in the generated app's storage. A failed setup exits nonzero and does not run tests against partial state.

The runtime scaffold is committed with the plugin, so no private Verbb tooling or sibling checkout is needed. Plugin-specific test fixtures belong in this repository. Do not add database/schema repair to the PHPUnit bootstrap: fresh installation must work through the normal Craft/plugin installation path first.

The initial runtime is PHP 8.3 and MySQL 8.0. This environment is not a claim of complete coverage for every supported Craft/PHP/database version. Compatibility matrix expansion must validate the actual runtime and fixture behavior.

The test application's dependency baseline is versioned in `tests/runtime/composer.lock`. Use `ddev test --update-lock` when intentionally updating that baseline, and review the lock diff alongside the test results. This does not update the plugin's root lock. JUnit results are available in `.cache/verbb-tests/junit.xml`. Tests exceeding 60 seconds are reported as failures; annotate genuinely long-running tests with PHPUnit size metadata.

Existing performance-report and baseline-maintenance aliases also provision through this runner, using the named `--task=` entries in `suite.json`. These explicitly requested maintenance tasks report `completed-task`, not a passing Pest suite.

Browser boundary tests use production TypeScript functions and the installed Craft serializer in Chromium, with synthetic DOM fixtures and queued request responses. After provisioning the PHP test app, use Node.js 22.13 or later and run these commands from the plugin checkout:

```sh
npm ci --workspaces=false
npx tsc --noEmit
npm run build
npx playwright install chromium
npm run test:browser
```

Set `HYPER_CRAFT_PATH` to select another installed Craft package, or `HYPER_BROWSER` to `firefox` or `webkit` after installing that Playwright browser. These boundary tests do not replace acceptance tests against a running Craft control panel.

The immediate-input regression mounts the production `HyperInput` while Craft initialization is paused. It checks trusted input/change events, clearing and reverting a URL, same-event reads by a parent field, preservation of unrendered fields, and unchanged stores during passive initialization.

The Matrix add-link race stress runner uses a real, explicitly marked isolated control-panel fixture. It repeatedly inserts a server-rendered Matrix entry and probes the first 128ms after DOM insertion. A cold field must remain safely inert until Hyper discovers it; once listeners are attached, clicks during deferred Craft initialization must queue and run when the field becomes interactive. It does not save the owner, but it does add and remove unsaved Matrix entries in the supplied browser session:

```sh
HYPER_CP_BASE=https://isolated-site.ddev.site \
HYPER_CP_FIXTURE=/absolute/path/to/fixture.json \
npm run test:stress:matrix-add
```

The fixture uses the same `username`, `password`, `matrix.section`, `matrix.ownerId`, and optional `matrix.matrixHandle` shape as `cp-release.mjs`. For cold-load coverage, point it at an owner whose empty Matrix field is the only place Hyper occurs on the edit screen; the runner deliberately does not wait for Hyper's bundle before inserting the first entry. `HYPER_STRESS_ASSET_DELAY=250` can widen that first-load window by delaying Hyper's JavaScript responses. The target must expose `__audit_health` with `isolated-hyper-release`; override the expected marker with `HYPER_CP_HEALTH` only for another deliberately isolated app. Tune the run with `HYPER_STRESS_ITERATIONS`, `HYPER_STRESS_DELAYS` (comma-separated milliseconds), `HYPER_STRESS_READY_TIMEOUT`, `HYPER_STRESS_ASSET_DELAY`, `HYPER_BROWSER`, and `HYPER_HEADED=1`.

CI runs `ddev test --suite=all`, including the normal performance budgets, followed by the Chromium boundary tests. The runtime installs the pinned Vizy development dependency so its nested-content conversion test runs through normal plugin registration. Missing integration setup fails that test rather than skipping it.

Feed Me mapping tests install the declared development dependency into the disposable app and exercise its registered Hyper mapper, including persisted custom fields and relation rows. The picker condition test follows settings from Hyper's ordinary and bulk picker renderers through Craft's element-index controller and a saved selection. It exercises those server contracts without simulating browser clicks.

The lockfile uses published Plugin Kit packages, so frontend builds do not need sibling source checkouts. Keep local `npm link` changes out of the committed lockfile. Use `npm ci` without `--workspaces=false` when you also need the documentation preview.
