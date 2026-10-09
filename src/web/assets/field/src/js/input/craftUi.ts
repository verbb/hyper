import { mountEmbed } from './embed';
import type { HyperInputSettings } from '../types';

const deferredInputNamespace = '__HYPER_INPUT_NAMESPACE__';

type DeferredInputNamespace = Pick<HyperInputSettings, 'inputNamePrefix' | 'inputIdPrefix'>;

export function initMenuBtn(trigger: HTMLElement): Garnish.MenuBtn {
    return new Garnish.MenuBtn($(trigger));
}

export function initBlockCraftUi(root: HTMLElement): void {
    Craft.initUiElements(root);
    // Initialize Hyper widgets with the block, rather than waiting for deferred scripts.
    root.querySelectorAll<HTMLElement>('.hyper-embed-field').forEach(mountEmbed);
}

export function resolveDeferredFieldJs(js: string, namespace: DeferredInputNamespace): string {
    const inputNameNamespace = namespace.inputNamePrefix
        ? `${namespace.inputNamePrefix}[hyperData]`
        : 'hyperData';
    const inputIdNamespace = namespace.inputIdPrefix
        ? `${namespace.inputIdPrefix}-`
        : '';

    // PHP cannot know the final Matrix or nested-field namespace when it captures
    // deferred widget scripts. Resolve both input names and DOM IDs just before mount.
    return js
        .split(`${deferredInputNamespace}[hyperData]`).join(inputNameNamespace)
        .split(`${deferredInputNamespace}-`).join(inputIdNamespace);
}

export function appendBlockJs(js: string | undefined, namespace?: DeferredInputNamespace): void {
    if (!js?.trim()) {
        return;
    }

    Craft.appendBodyHtml(namespace ? resolveDeferredFieldJs(js, namespace) : js);
}
