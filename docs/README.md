# Hyper Plugin Docs

Docs markdown is consumed on [verbb.io](https://verbb.io); VitePress here is **local preview only**.

From the **plugin root** (directory with main `composer.json`):

```bash
npm install
npm run dev:plugin-docs
```

Preview: [http://localhost:5487](http://localhost:5487)

## Screenshot automation

Uses **`@verbb/docs-screenshots`**. From plugin root:

```bash
npm run docs:screenshots -- prepare
npm run docs:screenshots -- preview --reuse-install last --filter {scenario-id}
npm run docs:screenshots -- capture --reuse-install last --filter {scenario-id}
```

Feature overview scenarios (classic promo dimensions):

| Scenario id | Output | Size |
| --- | --- | --- |
| `feature-tour-overview-link-input` | `_screenshots/feature-tour/overview-link-input.png` | 1024×570 |
| `feature-tour-overview-settings` | `_screenshots/feature-tour/overview-field-settings.png` | 1024×730 |
| `feature-tour-overview-link-tabs` | `_screenshots/feature-tour/overview-link-tabs.png` | 1024×556 |

Requires Docker (compose runtime). Optional: `CRAFT_SCREENSHOT_DB_*` env vars; run `npx playwright install chromium` once.

See `verbb-plugin-tooling/playbooks/docs-and-screenshots.md` for the full workflow.

## Layout

- **`*.screenshot.ts`** — colocated with the page it captures
- **`.screenshots/`** — global profile, plugin bootstrap, fixtures
- **`_screenshots/`** — generated PNG output

List scenario ids:

```bash
rg -n "id:" . -g "*.screenshot.ts"
```
