import '@verbb/plugin-kit-web/plugin-kit.css';

// Named deep imports — family barrels only re-export `dist/chunks/*` (outside
// package sideEffects), so bare side-effect imports get dropped by Vite/Rollup.
// Referencing the classes inside the called registrar keeps the decorator modules.
import { PkButton } from '@verbb/plugin-kit-web/components/button/pk-button.js';
import { PkCopyButton } from '@verbb/plugin-kit-web/components/copy-button/pk-copy-button.js';
import { PkDialog } from '@verbb/plugin-kit-web/components/dialog/pk-dialog.js';
import { PkDropdownItem } from '@verbb/plugin-kit-web/components/dropdown-menu/pk-dropdown-item.js';
import { PkDropdownMenu } from '@verbb/plugin-kit-web/components/dropdown-menu/pk-dropdown-menu.js';
import { PkDropdownSeparator } from '@verbb/plugin-kit-web/components/dropdown-menu/pk-dropdown-separator.js';
import { PkIcon } from '@verbb/plugin-kit-web/components/icon/pk-icon.js';
import { PkInputGroup } from '@verbb/plugin-kit-web/components/input-group/pk-input-group.js';
import { PkInputGroupAddon } from '@verbb/plugin-kit-web/components/input-group/pk-input-group-addon.js';

// Opt-in glyphs for Twig `<pk-icon icon="…">` (JS camelCase keys → kebab lookup names).
import {
    arrowDown,
    arrowUp,
    arrowUpRightFromSquare,
    chevronDown,
    ellipsis,
    gear,
    plus,
    registerIcons,
    xmark,
    type PkIcon,
} from '@verbb/plugin-kit-icons';

// Font Awesome Free solid path data — not yet in the curated kit set / overridden glyphs.
const copy: PkIcon = {
    width: 512,
    height: 512,
    path: 'M288 448l-224 0 0-224 48 0 0-64-48 0c-35.3 0-64 28.7-64 64L0 448c0 35.3 28.7 64 64 64l224 0c35.3 0 64-28.7 64-64l0-48-64 0 0 48zm-64-96l224 0c35.3 0 64-28.7 64-64l0-224c0-35.3-28.7-64-64-64L224 0c-35.3 0-64 28.7-64 64l0 224c0 35.3 28.7 64 64 64z',
};

const scissors: PkIcon = {
    width: 512,
    height: 512,
    path: 'M256 192l-39.5-39.5c4.9-12.6 7.5-26.2 7.5-40.5C224 50.1 173.9 0 112 0S0 50.1 0 112s50.1 112 112 112c14.3 0 27.9-2.7 40.5-7.5L192 256l-39.5 39.5c-12.6-4.9-26.2-7.5-40.5-7.5C50.1 288 0 338.1 0 400s50.1 112 112 112s112-50.1 112-112c0-14.3-2.7-27.9-7.5-40.5L499.2 76.8c7.1-7.1 7.1-18.5 0-25.6c-28.3-28.3-74.1-28.3-102.4 0L256 192zm22.6 150.6L396.8 460.8c28.3 28.3 74.1 28.3 102.4 0c7.1-7.1 7.1-18.5 0-25.6L342.6 278.6l-64 64zM64 112a48 48 0 1 1 96 0 48 48 0 1 1 -96 0zm48 240a48 48 0 1 1 0 96 48 48 0 1 1 0-96z',
};

const paste: PkIcon = {
    width: 640,
    height: 640,
    path: 'M128 64C92.7 64 64 92.7 64 128L64 448C64 483.3 92.7 512 128 512L240 512L240 288C240 226.1 290.1 176 352 176L416 176L416 128C416 92.7 387.3 64 352 64L128 64zM312 176L168 176C154.7 176 144 165.3 144 152C144 138.7 154.7 128 168 128L312 128C325.3 128 336 138.7 336 152C336 165.3 325.3 176 312 176zM352 224C316.7 224 288 252.7 288 288L288 512C288 547.3 316.7 576 352 576L512 576C547.3 576 576 547.3 576 512L576 346.5C576 329.5 569.3 313.2 557.3 301.2L498.8 242.7C486.8 230.7 470.5 224 453.5 224L352 224z',
};

registerIcons({
    arrowDown,
    arrowUp,
    arrowUpRightFromSquare,
    chevronDown,
    copy,
    ellipsis,
    gear,
    paste,
    plus,
    scissors,
    xmark,
});

import { HYPER_PK_COMPONENTS } from './hyperPkComponents.js';

/** Constructors whose modules run `@customElement` — must stay reachable from the registrar. */
const HYPER_PK_CTORS = [
    PkButton,
    PkCopyButton,
    PkDialog,
    PkDropdownItem,
    PkDropdownMenu,
    PkDropdownSeparator,
    PkIcon,
    PkInputGroup,
    PkInputGroupAddon,
] as const;

let registered = false;

/** Entry hook for the plugin-kit-register bundle. */
export async function registerHyperPluginKit(): Promise<void> {
    if (registered) {
        return;
    }

    // Keep constructor bindings live so Rollup cannot DCE the define side effects.
    for (const Ctor of HYPER_PK_CTORS) {
        if (typeof Ctor !== 'function') {
            throw new Error('Hyper Plugin Kit constructor missing from bundle');
        }
    }

    await Promise.all(HYPER_PK_COMPONENTS.map((tag) => customElements.whenDefined(tag)));
    registered = true;
}
