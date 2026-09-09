import './css/hyper.css';

import { allDefined } from '@verbb/plugin-kit-web/plugin-kit';

import { HYPER_PK_COMPONENTS } from './hyperPkComponents.js';
import { registerHyperFormHooks, registerHyperGlobals } from './globals';
import { HyperInput } from './input/HyperInput';
import { HyperSettings } from './settings/HyperSettings';

const INPUT_SELECTOR = '[data-hyper-input]';
const SETTINGS_SELECTOR = '[data-hyper-settings]';
const HYPER_FIELD_SELECTOR = `${INPUT_SELECTOR}, ${SETTINGS_SELECTOR}`;

const mountedInputs = new WeakMap<Element, HyperInput>();
const mountedSettings = new WeakSet<Element>();

registerHyperGlobals();
registerHyperFormHooks();

const mountInput = (root: Element) => {
    if (!(root instanceof HTMLElement) || mountedInputs.has(root)) {
        return;
    }

    const input = new HyperInput(root);
    input.init();
    mountedInputs.set(root, input);
};

const unmountInput = (root: Element) => {
    const input = mountedInputs.get(root);

    if (!input) {
        return;
    }

    input.destroy();
    mountedInputs.delete(root);
};

const mountSettings = (root: Element) => {
    if (!(root instanceof HTMLElement) || mountedSettings.has(root)) {
        return;
    }

    new HyperSettings(root).init();
    mountedSettings.add(root);
};

const mountAll = (scope: ParentNode = document) => {
    scope.querySelectorAll(INPUT_SELECTOR).forEach(mountInput);
    scope.querySelectorAll(SETTINGS_SELECTOR).forEach(mountSettings);
};

const startObserver = () => {
    const observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            mutation.removedNodes.forEach((node) => {
                if (!(node instanceof HTMLElement)) {
                    return;
                }

                if (node.matches(INPUT_SELECTOR)) {
                    unmountInput(node);
                }

                node.querySelectorAll(INPUT_SELECTOR).forEach(unmountInput);
            });

            mutation.addedNodes.forEach((node) => {
                if (node.nodeType !== Node.ELEMENT_NODE) {
                    return;
                }

                if (node instanceof HTMLElement) {
                    if (node.matches(INPUT_SELECTOR)) {
                        mountInput(node);
                    }

                    if (node.matches(SETTINGS_SELECTOR)) {
                        mountSettings(node);
                    }

                    node.querySelectorAll(INPUT_SELECTOR).forEach(mountInput);
                    node.querySelectorAll(SETTINGS_SELECTOR).forEach(mountSettings);
                }
            });
        });
    });

    observer.observe(document.body, {
        childList: true,
        subtree: true,
    });
};

Craft.Hyper = Craft.Hyper || {};
Craft.Hyper.mountAll = mountAll;
Craft.Hyper.startAutoMountObserver = () => {
    if (Craft.Hyper.__autoMountObserverStarted) {
        return;
    }

    Craft.Hyper.__autoMountObserverStarted = true;
    startObserver();
};

const pkMatch = (tag: string) => tag.startsWith('pk-');

/** Wait for pk-* used inside Hyper field roots (not unrelated pk-* elsewhere in the CP). */
const awaitHyperPk = async () => {
    const scopes = document.querySelectorAll(HYPER_FIELD_SELECTOR);

    if (scopes.length === 0) {
        await allDefined({
            match: pkMatch,
            additionalElements: [...HYPER_PK_COMPONENTS],
        });
        return;
    }

    // Only wait for pk-* actually present in each field root — plugin-kit-register
    // already registers HYPER_PK_COMPONENTS before this bundle executes.
    await Promise.all(
        [...scopes].map((scope) =>
            allDefined({
                match: pkMatch,
                // Kit typings only list Document|ShadowRoot; Element works at runtime for query scope.
                root: scope as unknown as Document | ShadowRoot,
            }),
        ),
    );
};

const bootstrap = async () => {
    // Register entry (plugin-kit-register) must load first; gate before querying pk-* in field DOM.
    await awaitHyperPk();

    Craft.Hyper.mountAll();
    Craft.Hyper.startAutoMountObserver();
};

void bootstrap();
