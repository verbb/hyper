# Hyper Plugin Docs

Docs markdown is consumed on [verbb.io](https://verbb.io); VitePress here is **local preview only**.

From the **plugin root** (directory with main `composer.json`):

```bash
npm install
npm run dev:plugin-docs
```

Open the local preview URL printed in the terminal.

## Screenshot automation

The plugin repository is the canonical source for reusable product screenshots. From the plugin root:

```bash
npm run screenshots -- prepare
npm run screenshots -- preview --reuse-install last --filter {scenario-id}
npm run screenshots -- capture --reuse-install last --filter {scenario-id}
```

Feature overview scenarios (classic promo dimensions):

| Scenario id | Output | Size |
| --- | --- | --- |
| `feature-tour-overview-link-input` | `screenshots/output/feature-tour/overview-link-input.png` | 1024×570 |
| `feature-tour-overview-settings` | `screenshots/output/feature-tour/overview-field-settings.png` | 1024×730 |
| `feature-tour-overview-link-tabs` | `screenshots/output/feature-tour/overview-link-tabs.png` | 1024×556 |

Requires Docker (compose runtime). Optional: `CRAFT_SCREENSHOT_DB_*` env vars; run `npx playwright install chromium` once.

## Layout

- **`screenshots/scenarios/`** — capture intent and page-specific interactions
- **`screenshots/support/`** — Hyper-specific fixtures, presets and seed helpers
- **`screenshots/output/`** — generated retina PNG output used by docs and marketing

The shared `@verbb/craft-screenshots` package owns the standard DDEV environment, Craft installation, migrations, browser profile and capture pipeline. Keep code in `support/` only when it is genuinely specific to Hyper.

List scenario ids:

```bash
rg -n "id:" screenshots/scenarios -g "*.screenshot.ts"
```
