import { defineScreenshotScenario } from '@verbb/craft-screenshots/api';

import { seedHyperDocsFixture } from '../../support/fixtures';

let settingsRoute = '/admin/settings/fields';

export default defineScreenshotScenario({
    id: 'hyper-feature-tour-link-types',
    output: 'feature-tour/hyper-field-settings.png',
    route: () => settingsRoute,
    viewport: { width: 1646, height: 1100, deviceScaleFactor: 2 },
    expectedOutput: { width: 1900, height: 1750 },
    async setup(context) {
        settingsRoute = (await seedHyperDocsFixture(context)).settingsRoute;
    },
    waitFor: [
        { type: 'selector', selector: '.hyper-configurator .hc-sidebar-item', state: 'visible', timeout: 30000 },
        { type: 'text', text: 'Blog Entries', timeout: 30000 },
        { type: 'text', text: 'Featured Content', timeout: 30000 },
    ],
    steps: [
        { type: 'click', selector: '.hc-sidebar-item:has-text("URL")' },
        { type: 'wait', waitFor: { type: 'selector', selector: '.hyper-block-editor-layout .fld-tabs', state: 'visible', timeout: 30000 } },
        { type: 'evaluate', expression: `document.querySelector('.hyper-configurator')?.scrollIntoView({ block: 'start' })` },
        { type: 'evaluate', expression: `(() => { const wrapper = document.querySelector('.hc-wrapper'); if (wrapper instanceof HTMLElement) { wrapper.style.borderColor = 'transparent'; wrapper.style.borderRadius = '0'; } })()` },
        { type: 'evaluate', expression: `document.querySelector('.hc-pane > div:not(.hidden) input[name$="[placeholder]"]')?.setAttribute('placeholder', '')` },
        { type: 'wait', waitFor: { type: 'timeout', ms: 300 } },
    ],
    target: {
        type: 'selector',
        selector: '.hyper-configurator',
    },
    caption: 'Hyper’s real link-type configurator with standard and project-specific link types.',
    intent: 'Show the current Craft 5 link-type settings rather than reconstructing the legacy interface.',
});
