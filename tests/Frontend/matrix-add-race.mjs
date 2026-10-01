// Stress the interval between a real Craft Matrix entry insertion and Hyper becoming interactive.
// This is intentionally opt-in because it mutates an explicitly supplied isolated CP fixture.
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {chromium, firefox, webkit} from 'playwright';

const base = process.env.HYPER_CP_BASE;
const fixturePath = process.env.HYPER_CP_FIXTURE;
const browserName = process.env.HYPER_BROWSER || 'chromium';
const iterations = Number.parseInt(process.env.HYPER_STRESS_ITERATIONS || '40', 10);
const clickDelays = (process.env.HYPER_STRESS_DELAYS || '0,1,4,8,16,32,64,128')
    .split(',')
    .map((value) => Number.parseInt(value.trim(), 10))
    .filter((value) => Number.isFinite(value) && value >= 0);
const readyTimeout = Number.parseInt(process.env.HYPER_STRESS_READY_TIMEOUT || '10000', 10);
const assetDelay = Number.parseInt(process.env.HYPER_STRESS_ASSET_DELAY || '0', 10);
const expectedHealth = process.env.HYPER_CP_HEALTH || 'isolated-hyper-release';

assert(base && fixturePath, 'Supply HYPER_CP_BASE and HYPER_CP_FIXTURE for an isolated CP fixture.');
assert(Number.isInteger(iterations) && iterations > 0, 'HYPER_STRESS_ITERATIONS must be a positive integer.');
assert(clickDelays.length, 'HYPER_STRESS_DELAYS must contain at least one non-negative integer.');
assert(Number.isInteger(assetDelay) && assetDelay >= 0, 'HYPER_STRESS_ASSET_DELAY must be a non-negative integer.');

const fixture = JSON.parse(fs.readFileSync(fixturePath, 'utf8'));
const browserType = {chromium, firefox, webkit}[browserName];

assert(browserType, `Unsupported HYPER_BROWSER: ${browserName}`);
assert(fixture.matrix?.section && fixture.matrix?.ownerId, 'Fixture must include matrix.section and matrix.ownerId.');

const browser = await browserType.launch({headless: process.env.HYPER_HEADED !== '1'});
const page = await browser.newPage();
const pageErrors = [];
const failedRequests = [];

page.on('pageerror', (error) => pageErrors.push({message: error.message, stack: error.stack}));
page.on('response', (response) => {
    if (response.status() >= 400) {
        failedRequests.push({status: response.status(), url: response.url()});
    }
});

try {
    const health = await page.request.get(`${base}/__audit_health`);
    assert.equal(await health.text(), expectedHealth, 'Refusing to mutate a CP app without the expected health marker.');

    await page.route('**/*', async (route) => {
        const url = decodeURIComponent(route.request().url());

        if (url.includes('actions/queue/get-job-info')) {
            return route.fulfill({json: {total: 0, jobs: []}});
        }

        if (/actions\/(queue\/run|app\/check-for-updates)/.test(url)) {
            return route.fulfill({json: {}});
        }

        if (assetDelay && /\/assets\/(?:hyper|hyperPkComponents)-[^/]+\.js(?:\?|$)/.test(url)) {
            await new Promise((resolve) => setTimeout(resolve, assetDelay));
        }

        return route.continue();
    });

    await page.goto(`${base}/index.php?p=admin/login`);
    await page.locator('input[name="username"]:visible').fill(fixture.username);
    await page.locator('input[name="password"]').fill(fixture.password);
    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForURL((url) => !url.search.includes('admin/login'), {timeout: 60000});

    const editorUrl = `${base}/index.php?p=admin/entries/${fixture.matrix.section}/${fixture.matrix.ownerId}`;
    await page.goto(editorUrl);
    // Do not wait for Hyper here. An empty Matrix can be the first thing that causes Craft
    // to load Hyper's asset bundle, and that cold-load path is part of the race surface.
    await page.waitForFunction(() => window.jQuery && window.Craft?.MatrixInput);

    const matrixHandle = fixture.matrix.matrixHandle;
    await page.waitForFunction((handle) => {
        const candidates = [...document.querySelectorAll('.matrix')];
        const matrix = handle
            ? candidates.find((node) => node.closest(`[data-attribute="${CSS.escape(handle)}"]`))
            : candidates[0];

        if (!(matrix instanceof HTMLElement) || !window.jQuery(matrix).data('matrix')) {
            return false;
        }

        matrix.dataset.hyperStressMatrix = '';
        return true;
    }, matrixHandle, {timeout: readyTimeout});

    const clearMatrix = async () => {
        await page.waitForFunction(() => {
            const root = document.querySelector('[data-hyper-stress-matrix]');
            const children = window.jQuery(root).data('matrix').$entriesContainer.children();

            return [...children].every((node) => !!window.jQuery(node).data('entry'));
        });
        await page.evaluate(() => {
            const root = document.querySelector('[data-hyper-stress-matrix]');
            const matrix = window.jQuery(root).data('matrix');

            matrix.$entriesContainer.children().each((index, node) => {
                window.jQuery(node).data('entry')?.selfDestruct();
            });
        });
        await page.waitForFunction(() => {
            const root = document.querySelector('[data-hyper-stress-matrix]');
            return window.jQuery(root).data('matrix').$entriesContainer.children().length === 0;
        });
    };

    await clearMatrix();
    const results = [];

    for (let iteration = 0; iteration < iterations; iteration++) {
        const delay = clickDelays[iteration % clickDelays.length];

        // Observe the server-rendered entry at the instant Craft inserts it. The attempt is
        // scheduled independently of Hyper's ready class so this catches clicks lost during init.
        await page.evaluate(({delayMs, timeoutMs}) => {
            const root = document.querySelector('[data-hyper-stress-matrix]');
            const matrix = window.jQuery(root).data('matrix');
            const entries = matrix.$entriesContainer[0];

            window.__hyperMatrixStress = {
                state: 'waiting-for-entry',
                delayMs,
                timeoutMs,
            };

            const observer = new MutationObserver((mutations) => {
                const added = mutations.flatMap((mutation) => [...mutation.addedNodes]);
                const entry = added.find((node) => node instanceof HTMLElement && node.matches('.matrixblock'));
                const input = entry?.querySelector('[data-hyper-input]');

                if (!(input instanceof HTMLElement)) {
                    return;
                }

                observer.disconnect();
                const attachedAt = performance.now();
                window.__hyperMatrixStress = {
                    state: 'entry-attached',
                    delayMs,
                    timeoutMs,
                    attachedAt,
                };

                window.setTimeout(() => {
                    const addRoot = input.querySelector('[data-hyper-add-link]');
                    const option = addRoot?.querySelector('[data-hyper-add-type]');
                    const menu = option?.closest('pk-dropdown-menu');
                    const trigger = menu?.querySelector('[data-hyper-add-trigger]');
                    const before = input.querySelectorAll('[data-hyper-links] > [data-hyper-link]').length;
                    const readyAtAttempt = input.classList.contains('hyper-input--ready');
                    const interactiveAtAttempt = input.classList.contains('hyper-input--interactive');
                    const gatedAtAttempt = addRoot?.hasAttribute('inert') ?? false;
                    const attemptedAt = performance.now();

                    const finishAttempt = (menuOpened = null) => {
                        if (!gatedAtAttempt) {
                            option?.click();
                        }

                        window.__hyperMatrixStress = {
                            state: 'attempted',
                            delayMs,
                            timeoutMs,
                            attachedAt,
                            attemptedAt,
                            selectedAt: performance.now(),
                            readyAtAttempt,
                            interactiveAtAttempt,
                            gatedAtAttempt,
                            hadAddControl: !!option,
                            hadDropdown: !!menu,
                            menuOpened,
                            before,
                        };
                    };

                    if (gatedAtAttempt) {
                        // The cold-load state is intentionally non-interactive. Verify it becomes
                        // usable below rather than synthesizing a click that a user could not make.
                        finishAttempt();
                    } else if (menu) {
                        trigger?.click();
                        const menuOpened = menu.open === true;
                        // A real selection necessarily follows the trigger click. One frame keeps
                        // the interaction aggressive while still exercising the actual menu path.
                        requestAnimationFrame(() => finishAttempt(menuOpened));
                    } else {
                        finishAttempt();
                    }
                }, delayMs);
            });

            observer.observe(entries, {childList: true});
        }, {delayMs: delay, timeoutMs: readyTimeout});

        await page.evaluate(async () => {
            const root = document.querySelector('[data-hyper-stress-matrix]');
            const matrix = window.jQuery(root).data('matrix');
            const entryType = matrix.entryTypes[0];

            if (!entryType) {
                throw new Error('Matrix fixture has no entry types.');
            }

            await matrix.addEntry(entryType.handle);
        });

        await page.waitForFunction(() => window.__hyperMatrixStress?.state === 'attempted', null, {timeout: readyTimeout});
        const entry = page.locator('[data-hyper-stress-matrix] .matrixblock').last();
        const input = entry.locator('[data-hyper-input]').first();
        await input.waitFor({state: 'attached', timeout: readyTimeout});

        let interactive = true;
        try {
            await page.waitForFunction(
                (element) => element.classList.contains('hyper-input--interactive'),
                await input.elementHandle(),
                {timeout: readyTimeout},
            );
        } catch {
            interactive = false;
        }

        const sample = await page.evaluate(() => ({...window.__hyperMatrixStress}));
        let postReadyMenuOpened = null;

        if (sample.gatedAtAttempt && interactive) {
            postReadyMenuOpened = await input.evaluate(async (element) => {
                const addRoot = element.querySelector('[data-hyper-add-link]');
                const option = addRoot?.querySelector('[data-hyper-add-type]');
                const menu = option?.closest('pk-dropdown-menu');
                const trigger = menu?.querySelector('[data-hyper-add-trigger]');
                let menuOpened = null;

                if (menu) {
                    trigger?.click();
                    await new Promise((resolve) => requestAnimationFrame(resolve));
                    menuOpened = menu.open === true;
                }

                option?.click();
                return menuOpened;
            });
        }

        // Give the attempted add one frame after initialization to commit its DOM update.
        await page.evaluate(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))));
        const linkCount = await input.locator('[data-hyper-links] > [data-hyper-link]').count();
        const lostClick = sample.hadAddControl && interactive && linkCount === sample.before;

        results.push({
            iteration: iteration + 1,
            delayMs: delay,
            interactive,
            readyAtAttempt: sample.readyAtAttempt,
            interactiveAtAttempt: sample.interactiveAtAttempt,
            gatedAtAttempt: sample.gatedAtAttempt,
            hadAddControl: sample.hadAddControl,
            hadDropdown: sample.hadDropdown,
            menuOpened: sample.menuOpened,
            postReadyMenuOpened,
            attemptLatencyMs: Math.round((sample.attemptedAt - sample.attachedAt) * 10) / 10,
            selectionLatencyMs: Math.round((sample.selectedAt - sample.attachedAt) * 10) / 10,
            linkCount,
            lostClick,
        });

        await clearMatrix();
    }

    const lostClicks = results.filter((result) => result.lostClick);
    const readinessFailures = results.filter((result) => !result.interactive);
    const missingControls = results.filter((result) => !result.hadAddControl);
    const menuFailures = results.filter((result) => (
        result.hadDropdown
        && (result.gatedAtAttempt ? !result.postReadyMenuOpened : !result.menuOpened)
    ));
    const failuresByDelay = Object.fromEntries(clickDelays.map((delay) => [
        delay,
        lostClicks.filter((result) => result.delayMs === delay).length,
    ]));
    const unexpectedPageErrors = pageErrors.filter((error) => !(
        error.message === 'undefined'
        || error.message === 'Form already being submitted.'
        || (error.message === "Cannot read properties of undefined (reading 'data')" && error.stack?.includes('/cp.js'))
    ));
    const summary = {
        ok: !lostClicks.length
            && !readinessFailures.length
            && !missingControls.length
            && !menuFailures.length
            && !unexpectedPageErrors.length
            && !failedRequests.length,
        browser: browserName,
        iterations,
        clickDelays,
        assetDelay,
        failuresByDelay,
        lostClicks: lostClicks.length,
        readinessFailures: readinessFailures.length,
        missingControls: missingControls.length,
        menuFailures: menuFailures.length,
        firstFailures: [...readinessFailures, ...missingControls, ...menuFailures, ...lostClicks].slice(0, 10),
        unexpectedPageErrors,
        knownCraftErrors: pageErrors.length - unexpectedPageErrors.length,
        failedRequests,
    };

    console.log(JSON.stringify(summary, null, 2));
    assert.deepEqual(readinessFailures, [], 'Some inserted Hyper inputs never became interactive.');
    assert.deepEqual(missingControls, [], 'Some inserted Hyper inputs did not render an add-link control.');
    assert.deepEqual(menuFailures, [], 'Some add-link dropdown triggers did not open.');
    assert.deepEqual(lostClicks, [], 'Some add-link attempts were lost during Hyper initialization.');
    assert.deepEqual(failedRequests, [], 'The CP emitted failed requests during the stress run.');
    assert.deepEqual(unexpectedPageErrors, [], 'The CP emitted unexpected browser errors during the stress run.');
} finally {
    await browser.close();
}
