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
// Kit has no synonym aliases — register only canonical curated names (or custom glyphs below).
import {
    arrowDown,
    arrowUp,
    arrowUpRightFromSquare,
    chevronDown,
    clipboard,
    copy,
    ellipsis,
    gripMove,
    plus,
    registerIcons,
    xmark,
    type PkIcon,
} from '@verbb/plugin-kit-icons';

// Cut menu only — `scissors` is not in the curated kit set (paste uses kit `clipboard`).
const scissors: PkIcon = {
    width: 512,
    height: 512,
    path: 'M256 192l-39.5-39.5c4.9-12.6 7.5-26.2 7.5-40.5C224 50.1 173.9 0 112 0S0 50.1 0 112s50.1 112 112 112c14.3 0 27.9-2.7 40.5-7.5L192 256l-39.5 39.5c-12.6-4.9-26.2-7.5-40.5-7.5C50.1 288 0 338.1 0 400s50.1 112 112 112s112-50.1 112-112c0-14.3-2.7-27.9-7.5-40.5L499.2 76.8c7.1-7.1 7.1-18.5 0-25.6c-28.3-28.3-74.1-28.3-102.4 0L256 192zm22.6 150.6L396.8 460.8c28.3 28.3 74.1 28.3 102.4 0c7.1-7.1 7.1-18.5 0-25.6L342.6 278.6l-64 64zM64 112a48 48 0 1 1 96 0 48 48 0 1 1 -96 0zm48 240a48 48 0 1 1 0 96 48 48 0 1 1 0-96z',
};

registerIcons({
    arrowDown,
    arrowUp,
    arrowUpRightFromSquare,
    chevronDown,
    clipboard,
    copy,
    ellipsis,
    gripMove,
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
