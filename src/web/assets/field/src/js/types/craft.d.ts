declare const Craft: CraftGlobal;
declare const Garnish: GarnishGlobal;
declare const $: JQueryStatic;

interface JQueryStatic {
    (element: Element | Document | string): JQuery;
}

interface JQuery {
    on(events: string, handler?: string | (() => void)): JQuery;
    off(namespace?: string): JQuery;
    data(key: string): unknown;
}

interface CraftGlobal {
    t(category: string, message: string, params?: Record<string, string>): string;
    randomString(length: number): string;
    initUiElements(el: Element): void;
    appendBodyHtml(html: string): void;
    appendHeadHtml(html: string): void;
    expandPostArray(data: Record<string, unknown>): Record<string, unknown>;
    getActionUrl(action: string, params?: Record<string, unknown>): string;
    sendActionRequest(method: string, url: string, options?: { data?: Record<string, unknown> }): Promise<{ data: Record<string, unknown> }>;
    /** Current CP site id when editing an element. */
    siteId?: number;
    createElementSelectorModal?(
        elementType: string,
        settings: {
            multiSelect?: boolean;
            sources?: string | string[] | null;
            criteria?: Record<string, unknown>;
            condition?: Record<string, unknown> | null;
            showSiteMenu?: boolean | string;
            storageKey?: string;
            onSelect?: (elements: Array<{ id: number; siteId?: number; label?: string }>) => void;
        },
    ): unknown;
    cp?: {
        displayNotice?(message: string): void;
        displayError?(message: string): void;
    };
    CpScreenSlideout: new (action: string, options: { params: Record<string, unknown> }) => {
        open(): void;
        on(event: string, callback: (event: { response: { data: Record<string, unknown> } }) => void): void;
    };
    HandleGenerator: new (
        source: string | Element,
        target: string | Element,
        settings?: Record<string, unknown>,
    ) => {
        updateTarget(): void;
        destroy(): void;
    };
    Hyper: {
        __globalsRegistered?: boolean;
        __formHookObserverStarted?: boolean;
        __autoMountObserverStarted?: boolean;
        syncInputStores?: () => void;
        mountAll(scope?: ParentNode): void;
        startAutoMountObserver(): void;
    };
}

interface GarnishGlobal {
    getPostData(el: Element): Record<string, unknown>;
    Base: {
        extend(definition: Record<string, unknown>): new (...args: unknown[]) => unknown;
    };
    MenuBtn: new (trigger: JQuery) => {
        destroy(): void;
    };
    Modal: new (
        container: Element,
        settings?: Record<string, unknown>,
    ) => GarnishModal;
}

interface GarnishModal {
    show(): void;
    hide(): void;
    on(event: string, callback: () => void): void;
}
