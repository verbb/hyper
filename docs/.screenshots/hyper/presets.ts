import type {
    ScreenshotStep,
    ScreenshotTarget,
    ScreenshotViewport,
} from '@verbb/docs-screenshots/types';
import {
    createCpDetailViewPreset as createBaseCpDetailViewPreset,
    createCpFocusedRegionPreset as createBaseCpFocusedRegionPreset,
    createCpFullScreenPreset as createBaseCpFullScreenPreset,
    createCpModalPreset as createBaseCpModalPreset,
} from '@verbb/docs-screenshots/presets';

type CpFocusedRegionOptions = {
    selector?: string;
    viewport?: ScreenshotViewport;
    padding?: NonNullable<Extract<ScreenshotTarget, { type: 'selector' }>['padding']>;
    hidePlaceholder?: boolean;
};

type CpFullScreenOptions = {
    viewport?: ScreenshotViewport;
    hidePlaceholder?: boolean;
};

type CpModalOptions = {
    viewport?: ScreenshotViewport;
    selector?: string;
    padding?: NonNullable<Extract<ScreenshotTarget, { type: 'selector' }>['padding']>;
};

type CpDetailViewOptions = {
    viewport?: ScreenshotViewport;
    selector?: string;
    padding?: NonNullable<Extract<ScreenshotTarget, { type: 'selector' }>['padding']>;
    hidePlaceholder?: boolean;
};

/** Craft CP page wash — use when the shot should read as in-CP, not a cutout. */
export const HYPER_CP_GRAY = '#f3f7fc';

type HyperPromoCropOptions = {
    /** Selector whose bounding box defines the framed region. */
    selector: string;
    padding?: number;
    /** Fixed canvas width in CSS pixels. */
    width: number;
    /**
     * Fixed canvas height, or `'fit'` to shrink to the subject + padding
     * (avoids large empty bands under shorter Hyper 3 chrome).
     */
    height: number | 'fit';
    /** Canvas behind the subject — transparent for marketing cutouts, CP gray for in-product shots. */
    background?: string;
    /** Outer card radius. Default `8`; use `0` for square in-CP framing. */
    borderRadius?: number;
};

const hyperScrollResetSelectors = [
    'html',
    'body',
    '#content-container',
    '#main-content',
    '#content',
    '.content-pane',
    '.hc-wrapper',
    '.hyper-input',
];

function buildHyperCleanupCss({ hidePlaceholder = true }: { hidePlaceholder?: boolean }) {
    const rules = [
        'craft-global-sidebar, footer#global-footer { display: none !important; }',
        'craft-global-sidebar { width: 0 !important; min-width: 0 !important; flex: 0 0 0 !important; }',
        '#global-header * { display: none !important; }',
        '#details-container { position: static !important; }',
        'body.fixed-header #header { position: static !important; top: auto !important; }',
        'body.fixed-header #content-container { padding-top: 0 !important; }',
        '#content-container, #main-content, #content { max-width: none !important; }',
        '#content-container { padding: 24px !important; }',
        '#main-content { padding-top: 0 !important; }',
        '#page-container, #content-container, #main-content, #content, .content-pane { left: 0 !important; margin-left: 0 !important; }',
        'html, body, * { scrollbar-width: none !important; -ms-overflow-style: none !important; }',
        'html::-webkit-scrollbar, body::-webkit-scrollbar, *::-webkit-scrollbar { display: none !important; width: 0 !important; height: 0 !important; }',
    ];

    if (hidePlaceholder) {
        rules.push('.cp-placeholder, .placeholder { display: none !important; }');
    }

    return rules.join('\n');
}

function buildHyperCleanupStep({ hidePlaceholder = true }: { hidePlaceholder?: boolean } = {}): ScreenshotStep {
    const css = buildHyperCleanupCss({ hidePlaceholder });

    return {
        type: 'evaluate',
        expression: `
            (() => {
                const styleId = 'hyper-docs-screenshot-cleanup';
                let style = document.getElementById(styleId);

                if (!(style instanceof HTMLStyleElement)) {
                    style = document.createElement('style');
                    style.id = styleId;
                    document.head.appendChild(style);
                }

                style.textContent = ${JSON.stringify(css)};

                ${JSON.stringify(hyperScrollResetSelectors)}.forEach((selector) => {
                    document.querySelectorAll(selector).forEach((element) => {
                        if (element instanceof HTMLElement) {
                            element.scrollTop = 0;
                            element.scrollLeft = 0;
                        }
                    });
                });
            })();
        `,
    };
}

/**
 * Strip Craft chrome for focused Hyper field / settings crops.
 * Stage background stays transparent — marketing composites the blue (or other) bg.
 */
export function createHyperPromoCleanupStep(background = 'transparent'): ScreenshotStep {
    const css = [
        buildHyperCleanupCss({ hidePlaceholder: true }),
        '#header-container { display: none !important; }',
        '#details-toggle-wrapper, #details-container { display: none !important; }',
        `html, body, #page-container, #main, #content-container { background: ${background} !important; }`,
        '#content-container { padding: 0 !important; }',
        '#content-container, #main-content, #content, .content-pane { overflow: visible !important; }',
        '#main-content { gap: 0 !important; align-items: stretch !important; padding-top: 0 !important; }',
        '#content.content-pane { padding: 0 !important; background: transparent !important; box-shadow: none !important; border: none !important; }',
        // Field settings: keep only the Hyper configurator.
        '#content .field { display: none !important; }',
        '#content .field:has(.hc-wrapper), #content .hyper-configurator, #content .hc-wrapper { display: block !important; }',
        '#content .hyper-configurator { margin: 0 !important; }',
        '#content .field:has(.hyper-input) { display: block !important; }',
        '#content .field:has(.hyper-input) > .heading,',
        '#content .field:has(.hyper-input) > .instructions { display: none !important; }',
    ].join('\n');

    return {
        type: 'evaluate',
        expression: `
            (() => {
                const styleId = 'hyper-docs-screenshot-promo-cleanup';
                let style = document.getElementById(styleId);

                if (!(style instanceof HTMLStyleElement)) {
                    style = document.createElement('style');
                    style.id = styleId;
                    document.head.appendChild(style);
                }

                style.textContent = ${JSON.stringify(css)};

                ${JSON.stringify(hyperScrollResetSelectors)}.forEach((selector) => {
                    document.querySelectorAll(selector).forEach((element) => {
                        if (element instanceof HTMLElement) {
                            element.scrollTop = 0;
                            element.scrollLeft = 0;
                        }
                    });
                });

                window.scrollTo(0, 0);
            })();
        `,
    };
}

/**
 * Pin the live subject onto a fixed (or content-fitted) canvas.
 * Moves the real node (no clone) so Craft widgets / shadow DOM stay intact.
 */
export function createHyperPromoCropStep(options: HyperPromoCropOptions): ScreenshotStep {
    const padding = options.padding ?? 24;
    const background = options.background ?? 'transparent';
    const borderRadius = options.borderRadius ?? 8;
    const fitHeight = options.height === 'fit';
    // Tall enough to host the subject before an optional fit-shrink pass.
    const initialHeight = fitHeight ? 1200 : options.height;

    return {
        type: 'evaluate',
        expression: `
            (() => {
                document.getElementById('hyper-docs-screenshot-frame')?.remove();
                document.getElementById('hyper-docs-screenshot-stage')?.remove();

                const target = document.querySelector(${JSON.stringify(options.selector)});

                if (!(target instanceof HTMLElement)) {
                    return;
                }

                const inset = ${padding};
                const frameWidth = ${options.width};
                const fitHeight = ${fitHeight ? 'true' : 'false'};
                let frameHeight = ${initialHeight};
                const innerWidth = frameWidth - inset * 2;
                const stageBackground = ${JSON.stringify(background)};
                const cardRadius = ${borderRadius};

                const stage = document.createElement('div');
                stage.id = 'hyper-docs-screenshot-stage';
                stage.style.cssText = [
                    'position:fixed',
                    'left:0',
                    'top:0',
                    'width:' + frameWidth + 'px',
                    'height:' + frameHeight + 'px',
                    'z-index:2147483640',
                    'background:' + stageBackground,
                    'padding:' + inset + 'px',
                    'box-sizing:border-box',
                    'overflow:hidden',
                ].join(';');

                const card = document.createElement('div');
                card.id = 'hyper-docs-screenshot-card';
                card.style.cssText = [
                    'width:' + innerWidth + 'px',
                    'max-height:' + (frameHeight - inset * 2) + 'px',
                    'overflow:auto',
                    'background:#fff',
                    'border-radius:' + cardRadius + 'px',
                    'box-sizing:border-box',
                ].join(';');

                // Keep the live DOM node so element-select / FLD stay hydrated.
                card.appendChild(target);
                target.style.width = '100%';
                target.style.maxWidth = '100%';
                target.style.margin = '0';
                target.style.boxSizing = 'border-box';

                stage.appendChild(card);
                document.body.appendChild(stage);

                // Shrink the canvas to the subject so Hyper 3's shorter chrome does not
                // leave a large empty band under the field.
                if (fitHeight) {
                    // Prefer the live subject's box — the card can include trailing margins.
                    const subjectBox = target.getBoundingClientRect();
                    const contentHeight = Math.ceil(subjectBox.height);
                    frameHeight = contentHeight + inset * 2;
                    stage.style.height = frameHeight + 'px';
                    card.style.maxHeight = 'none';
                    card.style.height = contentHeight + 'px';
                    card.style.overflow = 'hidden';
                }

                // Hide the rest of the CP so transparent padding does not sample Craft chrome
                // when Playwright captures with omitBackground.
                document.documentElement.style.background = stageBackground === 'transparent'
                    ? 'transparent'
                    : stageBackground;
                document.body.style.background = stageBackground === 'transparent'
                    ? 'transparent'
                    : stageBackground;
                Array.from(document.body.children).forEach((child) => {
                    if (
                        child instanceof HTMLElement
                        && child.id !== 'hyper-docs-screenshot-stage'
                        && child.id !== 'hyper-docs-screenshot-frame'
                    ) {
                        child.style.visibility = 'hidden';
                    }
                });

                const frame = document.createElement('div');
                frame.id = 'hyper-docs-screenshot-frame';
                frame.style.cssText = [
                    'position:fixed',
                    'left:0',
                    'top:0',
                    'width:' + frameWidth + 'px',
                    'height:' + frameHeight + 'px',
                    'pointer-events:none',
                    'z-index:2147483646',
                    'background:transparent',
                ].join(';');
                document.body.appendChild(frame);

                window.scrollTo(0, 0);
            })();
        `,
    };
}

/**
 * Promo polish for multi-link overview shots:
 * - Force Link / Link Text `.width-50` side-by-side even when the crop is under the 50rem container query.
 * - Hide field-label / chip ⋯ chrome inside the body; keep `.hyper-header-settings` in the block header.
 */
export function createHyperLinkInputPromoPolishStep(): ScreenshotStep {
    return {
        type: 'evaluate',
        expression: `
            (() => {
                const styleId = 'hyper-docs-screenshot-link-input-polish';
                let style = document.getElementById(styleId);

                if (!(style instanceof HTMLStyleElement)) {
                    style = document.createElement('style');
                    style.id = styleId;
                    document.head.appendChild(style);
                }

                // Bypass @container (min-width: 50rem) so 50/50 still reads in a narrow card.
                style.textContent = [
                    '.hyper-body-wrapper [data-hyper-portal] > .flex-fields > .width-50,',
                    '.hyper-body-wrapper [data-hyper-portal] > .flex-fields > :last-child.width-50 {',
                    '  width: 50% !important;',
                    '}',
                    /* Drop trailing link gap + Add CTA so fit-height is snug */
                    '.hyper-input .hyper-link:last-child,',
                    '.hyper-input [data-hyper-link]:last-child {',
                    '  margin-bottom: 0 !important;',
                    '}',
                    '.hyper-input > [data-hyper-add-link] {',
                    '  display: none !important;',
                    '  margin: 0 !important;',
                    '  height: 0 !important;',
                    '  overflow: hidden !important;',
                    '}',
                    /* Field-label copy/handle chrome and element-chip menus — not the header ⋯ */
                    '[data-hyper-body] craft-copy-attribute,',
                    '[data-hyper-body] .copytextbtn,',
                    '[data-hyper-body] .heading .menubtn,',
                    '[data-hyper-body] .heading button.menubtn,',
                    '[data-hyper-body] .element .menubtn,',
                    '[data-hyper-body] .chip .menubtn,',
                    '[data-hyper-body] .elementselect .menubtn,',
                    '[data-hyper-body] .chip-actions,',
                    '[data-hyper-body] .chip .action-btn,',
                    '[data-hyper-portal] craft-copy-attribute,',
                    '[data-hyper-portal] .copytextbtn,',
                    '[data-hyper-portal] .heading .menubtn,',
                    '[data-hyper-portal] .element .menubtn,',
                    '[data-hyper-portal] .chip .menubtn,',
                    '[data-hyper-portal] .chip-actions,',
                    '[data-hyper-portal] .chip .action-btn {',
                    '  display: none !important;',
                    '}',
                ].join('\\n');
            })();
        `,
    };
}

/** Select a link type row in the field settings sidebar. */
export function createSelectSettingsLinkTypeStep(handle: string): ScreenshotStep {
    return {
        type: 'evaluate',
        expression: `
            (() => {
                const item = document.querySelector('[data-hyper-settings-item][data-link-type-handle=${JSON.stringify(handle)}]');

                if (item instanceof HTMLElement) {
                    item.click();
                }
            })();
        `,
    };
}

/** Activate a layout tab on the first Hyper link block (0 = Content, 1 = Advanced). */
export function createSelectLinkLayoutTabStep(tabIndex: number): ScreenshotStep {
    return {
        type: 'evaluate',
        expression: `
            (() => {
                const tab = document.querySelector(
                    '[data-hyper-link] [data-hyper-tab-index="${tabIndex}"]'
                );

                if (tab instanceof HTMLElement) {
                    tab.click();
                }
            })();
        `,
    };
}

export function createCpFocusedRegionPreset(options: CpFocusedRegionOptions = {}) {
    const preset = createBaseCpFocusedRegionPreset(options);

    return {
        ...preset,
        steps: [
            buildHyperCleanupStep({ hidePlaceholder: options.hidePlaceholder }),
            ...preset.steps,
        ] satisfies ScreenshotStep[],
    };
}

export function createCpFullScreenPreset(options: CpFullScreenOptions = {}) {
    const preset = createBaseCpFullScreenPreset(options);

    return {
        ...preset,
        steps: [
            buildHyperCleanupStep({ hidePlaceholder: options.hidePlaceholder }),
            ...preset.steps,
        ] satisfies ScreenshotStep[],
    };
}

export function createCpModalPreset(options: CpModalOptions = {}) {
    return createBaseCpModalPreset(options);
}

export function createCpDetailViewPreset(options: CpDetailViewOptions = {}) {
    const preset = createBaseCpDetailViewPreset(options);

    return {
        ...preset,
        steps: [
            buildHyperCleanupStep({ hidePlaceholder: options.hidePlaceholder }),
            ...preset.steps,
        ] satisfies ScreenshotStep[],
    };
}
