import { defineScreenshotScenario } from '@verbb/docs-screenshots/api';
import { seedHyperDocsFixture } from '../.screenshots/hyper/fixtures';
import {
    createHyperPromoCleanupStep,
    createHyperPromoCropStep,
    createHyperLinkInputPromoPolishStep,
    createSelectLinkLayoutTabStep,
} from '../.screenshots/hyper/presets';

let entryEditRoute = '/admin/entries';

const viewport = {
    width: 680,
    height: 700,
    deviceScaleFactor: 2,
};

export default defineScreenshotScenario({
    id: 'feature-tour-overview-link-tabs',
    output: '_screenshots/feature-tour/overview-link-tabs.png',
    route: () => entryEditRoute,
    viewport,
    async setup(context) {
        const fixture = await seedHyperDocsFixture(context);
        entryEditRoute = fixture.entryEditRoute;
    },
    waitFor: [
        { type: 'selector', selector: '.hyper-input.hyper-input--ready', state: 'attached' },
        { type: 'selector', selector: '[data-hyper-link]', state: 'attached' },
        { type: 'selector', selector: '[data-hyper-layout-tabs]', state: 'attached' },
    ],
    preSteps: [
        createHyperPromoCleanupStep('#ffffff'),
        createHyperLinkInputPromoPolishStep(),
        {
            type: 'evaluate',
            expression: `
                (() => {
                    document.querySelectorAll('[data-hyper-link]').forEach((el, index) => {
                        if (index > 0 && el instanceof HTMLElement) {
                            el.style.display = 'none';
                        }
                    });
                    document.querySelectorAll('[data-hyper-add-link]').forEach((el) => {
                        if (el instanceof HTMLElement) {
                            el.style.display = 'none';
                        }
                    });
                })();
            `,
        },
        // Switch Advanced before fit-crop so the frame height matches the visible pane.
        createSelectLinkLayoutTabStep(1),
        {
            type: 'evaluate',
            expression: `
                (() => {
                    const link = document.querySelector('[data-hyper-link]');
                    if (!(link instanceof HTMLElement)) {
                        return;
                    }

                    link.querySelectorAll('[data-hyper-tab-index]').forEach((tab) => {
                        if (!(tab instanceof HTMLElement)) {
                            return;
                        }
                        const active = tab.getAttribute('data-hyper-tab-index') === '1';
                        tab.classList.toggle('is-active', active);
                        tab.setAttribute('aria-selected', active ? 'true' : 'false');
                    });

                    const portal = link.querySelector('[data-hyper-portal]');
                    if (portal instanceof HTMLElement) {
                        portal.querySelectorAll(':scope > .flex-fields').forEach((pane, index) => {
                            pane.classList.toggle('hidden', index !== 1);
                        });
                    }
                })();
            `,
        },
        { type: 'wait', waitFor: { type: 'timeout', ms: 300 } },
        createHyperPromoCropStep({
            selector: '[data-hyper-link]',
            width: 680,
            height: 'fit',
            padding: 15,
            background: '#ffffff',
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
    caption: 'Hyper link block with inline Content/Advanced layout tabs (Advanced selected).',
    intent: 'Inline Advanced tabs — white pad 15px, 680 wide, header ⋯ only.',
});
