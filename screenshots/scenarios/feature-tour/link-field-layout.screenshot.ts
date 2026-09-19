import { defineScreenshotScenario } from '@verbb/craft-screenshots/api';

import { seedHyperDocsFixture } from '../../support/fixtures';

let entryEditRoute = '/admin/entries';

export default defineScreenshotScenario({
    id: 'hyper-feature-tour-link-field-layout',
    output: 'feature-tour/hyper-field-layout.png',
    route: () => entryEditRoute,
    viewport: { width: 980, height: 600, deviceScaleFactor: 2 },
    expectedOutput: { width: 1600, height: 1200 },
    async setup(context) {
        entryEditRoute = (await seedHyperDocsFixture(context)).entryEditRoute;
    },
    waitFor: [
        { type: 'selector', selector: '.hyper-input-component', state: 'visible', timeout: 30000 },
    ],
    steps: [
        { type: 'click', selector: '.hyper-link:first-child .hyper-header-settings' },
        { type: 'wait', waitFor: { type: 'selector', selector: '.tippy-box .menu-item', state: 'visible', timeout: 30000 } },
        { type: 'click', selector: '.tippy-box .menu-item:has-text("Settings")' },
        { type: 'wait', waitFor: { type: 'text', text: 'Custom Attributes', timeout: 30000 } },
        { type: 'wait', waitFor: { type: 'timeout', ms: 500 } },
    ],
    target: {
        type: 'clip',
        x: 180,
        y: 0,
        width: 800,
        height: 600,
    },
    caption: 'Extra link content being edited in Hyper’s real Craft 5 slide-out.',
    intent: 'Show how authors enter the supporting fields configured for a link, rather than showing only the field-layout designer.',
});
