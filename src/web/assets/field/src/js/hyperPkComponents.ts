/** Tags Hyper waits on via `allDefined` after the register entry runs. */
export const HYPER_PK_COMPONENTS = [
    'pk-button',
    'pk-copy-button',
    'pk-dialog',
    'pk-dropdown-menu',
    'pk-dropdown-item',
    'pk-dropdown-separator',
    'pk-icon',
    'pk-input-group',
    'pk-input-group-addon',
] as const;

export type HyperPkComponentTag = (typeof HYPER_PK_COMPONENTS)[number];
