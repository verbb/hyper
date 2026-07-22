import { defineScreenshotScenario } from '@verbb/docs-screenshots/api';
import { seedHyperDocsFixture } from '../.screenshots/hyper/fixtures';
import {
    createHyperPromoCleanupStep,
    createHyperPromoCropStep,
    createSelectSettingsLinkTypeStep,
} from '../.screenshots/hyper/presets';

let settingsRoute = '/admin/settings/fields';
let urlHandle = 'url';

// Classic was 1024×730; shave 100px width so the FLD reads larger in-frame.
const viewport = {
    width: 974,
    height: 730,
    deviceScaleFactor: 2,
};

export default defineScreenshotScenario({
    id: 'feature-tour-overview-settings',
    output: '_screenshots/feature-tour/overview-field-settings.png',
    route: () => settingsRoute,
    viewport,
    async setup(context) {
        const fixture = await seedHyperDocsFixture(context);
        settingsRoute = fixture.settingsRoute;
        urlHandle = fixture.urlHandle;
    },
    waitFor: [
        { type: 'selector', selector: '.hc-wrapper', state: 'attached' },
        { type: 'selector', selector: '[data-hyper-fld]', state: 'attached' },
    ],
    preSteps: [
        createHyperPromoCleanupStep(),
        createSelectSettingsLinkTypeStep(urlHandle),
        { type: 'wait', waitFor: { type: 'timeout', ms: 600 } },
        {
            type: 'evaluate',
            expression: `
                (() => {
                    // Hide URL-type settings that crowd out the FLD in the promo crop.
                    ['placeholder', 'defaultLinkValue', 'fixedLinkValue'].forEach((name) => {
                        document.querySelectorAll('[name*="[' + name + ']"]').forEach((el) => {
                            const field = el.closest('.field');
                            if (field instanceof HTMLElement) {
                                field.style.display = 'none';
                            }
                        });
                    });
                })();
            `,
        },
        { type: 'wait', waitFor: { type: 'timeout', ms: 200 } },
        createHyperPromoCropStep({
            selector: '.hc-wrapper',
            width: 974,
            height: 730,
            padding: 16,
        }),
        { type: 'wait', waitFor: { type: 'selector', selector: '#hyper-docs-screenshot-frame', state: 'attached' } },
        { type: 'wait', waitFor: { type: 'timeout', ms: 200 } },
    ],
    steps: [],
    target: {
        type: 'selector',
        selector: '#hyper-docs-screenshot-frame',
        padding: 0,
        omitBackground: true,
    },
    caption: 'Hyper field settings with link types sidebar and URL link field layout.',
    intent: 'Field settings promo — narrower crop, Label + Link Fields FLD emphasized.',
});
