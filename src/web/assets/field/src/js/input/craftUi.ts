import { mountEmbed } from './embed';

export function initMenuBtn(trigger: HTMLElement): Garnish.MenuBtn {
    return new Garnish.MenuBtn($(trigger));
}

export function initBlockCraftUi(root: HTMLElement): void {
    Craft.initUiElements(root);
    // Initialize Hyper widgets with the block, rather than waiting for deferred scripts.
    root.querySelectorAll<HTMLElement>('.hyper-embed-field').forEach(mountEmbed);
}

export function appendBlockJs(js: string | undefined): void {
    if (!js?.trim()) {
        return;
    }

    Craft.appendBodyHtml(js);
}
