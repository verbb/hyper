import { defineScreenshotScenario } from '@verbb/craft-screenshots/api';

import { seedHyperDocsFixture } from '../../support/fixtures';

let entryEditRoute = '/admin/entries';

export default defineScreenshotScenario({
    id: 'hyper-feature-tour-multi-link-field',
    output: 'feature-tour/hyper-field-multi.png',
    route: () => entryEditRoute,
    viewport: { width: 1720, height: 760, deviceScaleFactor: 2 },
    expectedOutput: { width: 1844, height: 882 },
    async setup(context) {
        entryEditRoute = (await seedHyperDocsFixture(context)).entryEditRoute;
    },
    waitFor: [
        { type: 'selector', selector: '.hyper-input-component', state: 'visible', timeout: 30000 },
    ],
    steps: [
        {
            type: 'evaluate',
            expression: `(() => {
                const field = document.querySelector('.field:has(.hyper-input-component)');

                if (field instanceof HTMLElement) {
                    field.style.width = '922px';
                    field.style.maxWidth = '922px';
                }
            })()`,
        },
        { type: 'wait', waitFor: { type: 'timeout', ms: 300 } },
    ],
    target: {
        type: 'anchoredClip',
        selector: '.field:has(.hyper-input-component)',
        x: 0,
        y: -20,
        width: 922,
        height: 441,
    },
    caption: 'Two saved links in a real multi-link Hyper field in Craft 5.',
    intent: 'Translate the production multi-link subject to the current Hyper field using saved URL and Entry links.',
});
