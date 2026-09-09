declare const Craft: CraftGlobal;
declare const Garnish: GarnishGlobal;
declare const $: JQueryStatic;

interface JQueryStatic {
    (element: Element | Document | Window | string | JQuery): JQuery;
    (callback: () => void): JQuery;
}

interface JQuery {
    length: number;
    on(events: string, handler?: JQueryEventHandler | string): JQuery;
    on(events: string, selector: string, handler: JQueryEventHandler): JQuery;
    on(events: string, selector: string, data: unknown, handler: JQueryEventHandler): JQuery;
    off(events?: string, handler?: JQueryEventHandler | string): JQuery;
    data(key: string): unknown;
    data(key: string, value: unknown): JQuery;
    val(): string | number | string[] | undefined;
    val(value: string | number | string[]): JQuery;
    attr(name: string): string | undefined;
    attr(name: string, value: string | number | null): JQuery;
    find(selector: string): JQuery;
    closest(selector: string): JQuery;
    append(content: string | Element | JQuery): JQuery;
    empty(): JQuery;
    remove(): JQuery;
    removeClass(className: string): JQuery;
    addClass(className: string): JQuery;
    hasClass(className: string): boolean;
    text(): string;
    text(value: string): JQuery;
    html(): string;
    html(value: string): JQuery;
    [index: number]: Element;
}

type JQueryEventHandler = (event: JQueryEventObject) => void;

interface JQueryEventObject {
    target: Element;
    currentTarget: Element;
    preventDefault(): void;
    stopPropagation(): void;
}

interface CraftCp {
    displayNotice(message: string): void;
    displayError(message: string): void;
}

interface CraftHyperNamespace {
    __globalsRegistered?: boolean;
    __formHookObserverStarted?: boolean;
    __autoMountObserverStarted?: boolean;
    syncInputStores?: () => void;
    mountAll(scope?: ParentNode): void;
    startAutoMountObserver(): void;
    ElementSelect?: new (...args: unknown[]) => unknown;
    Embed?: new (...args: unknown[]) => unknown;
}

interface CraftGlobal {
    t(category: string, message: string, params?: Record<string, string | number>): string;
    randomString(length: number): string;
    initUiElements(el: Element): void;
    appendBodyHtml(html: string): void;
    appendHeadHtml(html: string): void;
    expandPostArray(data: Record<string, unknown>): Record<string, unknown>;
    getActionUrl(action: string, params?: Record<string, unknown>): string;
    sendActionRequest(
        method: string,
        url: string,
        options?: { data?: Record<string, unknown> },
    ): Promise<{ data: Record<string, unknown> }>;
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
    cp: CraftCp;
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
    Hyper: CraftHyperNamespace;
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

declare namespace Garnish {
    type MenuBtn = InstanceType<GarnishGlobal['MenuBtn']>;
}

interface GarnishModal {
    show(): void;
    hide(): void;
    on(event: string, callback: () => void): void;
}
