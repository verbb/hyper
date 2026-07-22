export function initMenuBtn(trigger: HTMLElement): Garnish.MenuBtn {
    return new Garnish.MenuBtn($(trigger));
}

export function initBlockCraftUi(root: HTMLElement): void {
    Craft.initUiElements(root);
}

export function appendBlockJs(js: string | undefined): void {
    if (!js?.trim()) {
        return;
    }

    Craft.appendBodyHtml(js);
}
