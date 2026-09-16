import { debounce } from 'lodash-es';

const widgets = new Map<HTMLElement, { sync: () => void; destroy: () => void }>();
let observing = false;

/** Widgets own their listeners and pending work; no per-widget body subscriptions. */
export function mountEmbed(container: HTMLElement): void {
    if (widgets.has(container)) return;
    const input = container.querySelector<HTMLInputElement>('input:not([type="hidden"])');
    const data = container.querySelector<HTMLInputElement>('.link-embed-data');
    if (!input || !data) return;

    // Deferred Craft scripts may run after the first edit. Compare against persisted
    // metadata, not the current input, so mounting cannot acknowledge an unsaved edit.
    let current = '';
    try { current = String(JSON.parse(data.value)?.url ?? ''); } catch { current = ''; }
    let generation = 0;
    let destroyed = false;
    const spinner = container.querySelector('.spinner');
    const responseEl = container.querySelector('.hyper-embed-response');
    const owner = container.closest<HTMLElement>('[data-hyper-input]');
    const fetch = debounce((url: string, revision: number) => {
        if (destroyed || !container.isConnected || revision !== generation) return;
        Craft.sendActionRequest('GET', Craft.getActionUrl('hyper/fields/preview-embed', {
            value: url,
            fieldId: container.dataset.hyperFieldId,
            linkTypeHandle: container.dataset.hyperLinkTypeHandle,
            elementId: owner?.dataset.hyperElementId,
            inputContext: owner?.dataset.hyperInputContext,
            siteId: owner?.dataset.hyperSiteId,
        })).then((response) => {
            if (destroyed || !container.isConnected || revision !== generation || input.value !== url) return;
            const payload = response?.data?.data as Record<string, unknown> | undefined;
            if (payload) {
                data.value = JSON.stringify(payload);
                const icon = payload.icon;
                if (typeof icon === 'string' && /^https?:\/\//i.test(icon)) {
                    const wrap = document.createElement('div');
                    wrap.className = 'favicon-icon';
                    const image = document.createElement('img');
                    image.src = icon;
                    wrap.append(image);
                    container.append(wrap);
                }
                // Metadata completion must reach the owner model/autosave as well.
                data.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }).catch(({ response }) => {
            if (destroyed || !container.isConnected || revision !== generation || input.value !== url) return;
            if (responseEl && response?.data?.message) {
                const error = document.createElement('div');
                error.className = 'error';
                error.textContent = String(response.data.message);
                responseEl.replaceChildren(error);
            }
        }).finally(() => {
            if (!destroyed && revision === generation) spinner?.classList.add('hidden');
        });
    }, 500);

    const sync = () => {
        if (destroyed || input.value === current) return;
        current = input.value;
        generation++;
        fetch.cancel();
        data.value = JSON.stringify(current ? { url: current } : {});
        responseEl?.replaceChildren();
        container.querySelectorAll('.favicon-icon').forEach((node) => node.remove());
        spinner?.classList.toggle('hidden', !current);
        if (current) fetch(current, generation);
        data.dispatchEvent(new Event('change', { bubbles: true }));
    };
    // Direct listeners run before the portal's bubbling serialization handler.
    $(input).on('input.hyperEmbed change.hyperEmbed keyup.hyperEmbed blur.hyperEmbed', sync);
    const destroy = () => {
        destroyed = true;
        generation++;
        fetch.cancel();
        $(input).off('.hyperEmbed');
        widgets.delete(container);
    };
    widgets.set(container, { sync, destroy });
    sync();

    if (!observing) {
        observing = true;
        new MutationObserver(() => {
            for (const [node, widget] of widgets) {
                if (!node.isConnected) widget.destroy();
            }
        }).observe(document.body, { childList: true, subtree: true });
    }
}

export function syncEmbedWidgets(): void {
    // Submit can beat deferred block JS. Discover live widgets before flushing owners.
    document.querySelectorAll<HTMLElement>('.hyper-embed-field').forEach((node) => {
        if (!widgets.has(node)) mountEmbed(node);
    });
    widgets.forEach((widget) => widget.sync());
}

export function destroyEmbedWidgets(root: HTMLElement): void {
    for (const [node, widget] of widgets) {
        if (root === node || root.contains(node)) widget.destroy();
    }
}
