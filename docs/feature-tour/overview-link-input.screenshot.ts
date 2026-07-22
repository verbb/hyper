import { defineScreenshotScenario } from '@verbb/docs-screenshots/api';
import { seedHyperDocsFixture } from '../.screenshots/hyper/fixtures';
import {
    createHyperPromoCleanupStep,
    createHyperPromoCropStep,
    createHyperLinkInputPromoPolishStep,
} from '../.screenshots/hyper/presets';

let entryEditRoute = '/admin/entries';

// Tall enough to stage the field before fit-height shrinks the frame.
const viewport = {
    width: 680,
    height: 700,
    deviceScaleFactor: 2,
};

export default defineScreenshotScenario({
    id: 'feature-tour-overview-link-input',
    output: '_screenshots/feature-tour/overview-link-input.png',
    route: () => entryEditRoute,
    viewport,
    async setup(context) {
        const fixture = await seedHyperDocsFixture(context);
        entryEditRoute = fixture.entryEditRoute;
    },
    waitFor: [
        { type: 'selector', selector: '.hyper-input.hyper-input--ready', state: 'attached' },
        { type: 'selector', selector: '[data-hyper-link]:nth-child(2)', state: 'attached' },
        { type: 'selector', selector: '.hyper-input input[value*="craftcms.com"]', state: 'attached' },
    ],
    preSteps: [
        createHyperPromoCleanupStep('#ffffff'),
        createHyperLinkInputPromoPolishStep(),
        {
            type: 'evaluate',
            expression: `
                (() => {
                    document.querySelectorAll('[data-hyper-add-link]').forEach((el) => {
                        if (el instanceof HTMLElement) {
                            el.style.display = 'none';
                        }
                    });

                    // Seed can leave a blank elements_sites.title in disposable installs;
                    // normalize the chip label for the classic promo content.
                    document.querySelectorAll('.elementselect .element .label, .elementselect .chip-label, .element .label').forEach((el) => {
                        if (el instanceof HTMLElement && /untitled/i.test(el.textContent ?? '')) {
                            el.textContent = 'About Us';
                        }
                    });
                })();
            `,
        },
        { type: 'wait', waitFor: { type: 'timeout', ms: 400 } },
        createHyperPromoCropStep({
            selector: '.hyper-input',
            width: 680,
            height: 'fit',
            padding: 15,
            background: '#ffffff',
            // Square frame — reads as in-CP, not a floating promo card.
            borderRadius: 0,
        }),
        { type: 'wait', waitFor: { type: 'selector', selector: '#hyper-docs-screenshot-frame', state: 'attached' } },
        { type: 'wait', waitFor: { type: 'timeout', ms: 200 } },
    ],
    steps: [],
    target: {
        type: 'selector',
        selector: '#hyper-docs-screenshot-frame',
        padding: 0,
    },
    caption: 'Multi-link Hyper field with URL and Entry rows.',
    intent: 'Multi-link overview: white pad, 50/50 Link+Link Text, no field-body ⋯ chrome.',
});
