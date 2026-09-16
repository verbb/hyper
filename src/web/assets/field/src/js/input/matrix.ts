import { syncAllHyperInputStores } from './registry';

export const matrixEntryData = (container: HTMLElement): unknown => {
    syncAllHyperInputStores();
    const name = $(container).data('base-input-name') as string;
    const postData = Garnish.getPostData(container);
    const owner = container.closest('[data-hyper-input]');
    for (const input of container.querySelectorAll<HTMLElement>('[name]')) {
        if (input.closest('[data-hyper-input]') === owner) continue;
        const inputName = input.getAttribute('name')!;
        for (const key of Object.keys(postData)) {
            if (key === inputName || key.startsWith(`${inputName}[`)) delete postData[key];
        }
    }
    // Keep nested hidden stores, not their authoring portals. Numeric temporary link
    // IDs otherwise become enormous sparse arrays when the clipboard is JSON-encoded.
    const values = Craft.expandPostArray(postData);
    const keys = name.match(/[^\[\]]+/g) || [];
    const exact = keys.reduce((value: any, key: string) => value?.[key], values);
    if (exact !== undefined) return exact;

    // Craft namespaces input names separately from data-base-input-name. Locate the
    // same unique entry when a containing editor has added an outer namespace.
    const entryKey = keys.at(-1);
    const findEntry = (value: any): unknown => {
        if (!value || typeof value !== 'object') return undefined;
        if (entryKey && value.entries?.[entryKey]) return value.entries[entryKey];
        for (const child of Object.values(value)) {
            const found = findEntry(child);
            if (found !== undefined) return found;
        }
        return undefined;
    };
    return findEntry(values);
};

const matrixContext = (matrix: any): any => {
    const root = matrix.$container.closest('[data-hyper-input]')[0] as HTMLElement | undefined;
    return root ? JSON.parse(root.dataset.hyperInputConfig || '{}').settings : {};
};

const jsonMatrices = new Set<any>();
const matrixClipboardKey = 'hyper.matrixClipboard.v1';
const readMatrixClipboard = (): any[] => {
    try {
        const value = JSON.parse(localStorage.getItem(matrixClipboardKey) || '[]');
        return Array.isArray(value) ? value : [];
    } catch { return []; }
};

// Matrix entries inside a link remain part of its JSON value. Let Hyper own
// serialization instead of asking Craft to draft a synthetic Link element ID.
export function createMatrixInput(...args: unknown[]): unknown {
    const nativeMatrix = (Craft as any).MatrixInput;
    if (!nativeMatrix.__hyperEntryLifecycle) {
        nativeMatrix.__hyperEntryLifecycle = true;
        window.addEventListener('storage', (event) => {
            if (event.key === matrixClipboardKey) for (const matrix of jsonMatrices) matrix.updatePasteBtn();
        });
        nativeMatrix.Entry = nativeMatrix.Entry.extend({
            collapse(animate: boolean) {
                if (this.matrix.hyperJsonOwner) {
                    this.id = `uid:${this.$container.data('uid')}`;
                    this.isNew = true;
                }
                return this.base(animate);
            },
            duplicate() {
                if (!this.matrix.hyperJsonOwner) return this.base();
                return this.matrix.addEntry(this.$container.data('type'), this.$container.next('.matrixblock'), true, {
                    entryData: matrixEntryData(this.$container[0]),
                });
            },
            init(...entryArgs: unknown[]) {
                this.base(...entryArgs);
                if (!this.matrix.hyperJsonOwner) return;
                this.id = `uid:${this.$container.data('uid')}`;
                this.isNew = true;
                this.actionDisclosure.on('show', () => {
                    const paste = this.$actionMenu.find('[data-action="paste"]')[0];
                    if (paste && this.matrix.canPaste(readMatrixClipboard())) this.actionDisclosure.showItem(paste);
                });
            },
            onActionSelect(button: HTMLElement) {
                if (!this.matrix.hyperJsonOwner) return this.base(button);
                const action = $(button).data('action');
                if (action === 'copy') {
                    const bulk = typeof this.bulkActionMode === 'function'
                        ? this.bulkActionMode()
                        : this.matrix.entrySelect.totalSelected > 1 && this.matrix.entrySelect.isSelected(this.$container);
                    const entries = bulk ? Array.from(this.matrix.entrySelect.getSelectedItems()) as HTMLElement[] : [this.$container[0]];
                    try {
                        localStorage.setItem(matrixClipboardKey, JSON.stringify(entries.map((node: HTMLElement) => ({
                            hyperMatrix: true,
                            entryTypeId: $(node).data('type-id'),
                            data: matrixEntryData(node),
                        }))));
                    } catch {
                        (Craft as any).cp.displayError(Craft.t('hyper', 'Unable to copy these blocks. Browser storage is unavailable or full.'));
                        return;
                    }
                    for (const matrix of jsonMatrices) matrix.updatePasteBtn();
                    (Craft as any).cp.displaySuccess(Craft.t('app', 'Copied to clipboard.'));
                    this.actionDisclosure.hide();
                    return;
                }
                if (action === 'paste') {
                    this.matrix.pasteEntries(this.$container);
                    this.actionDisclosure.hide();
                    return;
                }
                return this.base(button);
            },
            async updateFieldLayout(serialized: string) {
                if (!this.matrix.hyperJsonOwner) return this.base(serialized);
                // The owning form already contains the final JSON snapshot. Native
                // Matrix rejects this late observer callback while submitting.
                if (this.matrix.elementEditor?.submittingForm) return;
                const version = this.hyperLayoutVersion = (this.hyperLayoutVersion || 0) + 1;
                const namespace = this.$container.data('base-input-name');
                const context = matrixContext(this.matrix);
                const selectedTab = this.$fieldsContainer.children('[data-layout-tab]:not(.hidden)').data('id');
                // These entries exist only in the owning link JSON. The native endpoint
                // looks their UUID up in the elements table and rejects valid edits.
                const response = await Craft.sendActionRequest('POST', 'hyper/fields/matrix-field-layout', {
                    data: {
                        hyperFieldId: context.fieldId,
                        elementId: context.elementId,
                        inputContext: context.inputContext,
                        fieldId: this.matrix.settings.fieldId,
                        entryTypeId: this.$container.data('type-id'),
                        siteId: this.matrix.settings.siteId,
                        namespace,
                        entryData: matrixEntryData(this.$container[0]),
                        visibleLayoutElements: this.visibleLayoutElements,
                        staticLayoutElements: this.staticLayoutElements,
                        selectedTab,
                    },
                });
                if (version !== this.hyperLayoutVersion || !this.$container[0].isConnected || this.matrix.elementEditor?.submittingForm) return;
                return this._afterUpdateFieldLayout(serialized, selectedTab, namespace, response);
            },
        });
    }
    const Matrix = (Craft as any).MatrixInput.extend({
        hyperJsonOwner: true,
        canPaste(entries: any[]) {
            return entries.length > 0 && this.canAddMoreEntries(entries.length) && entries.every(entry => (
                entry.hyperMatrix === true && entry.data && this.entryTypes.some((type: any) => type.id === entry.entryTypeId)
            ));
        },
        updatePasteBtn() {
            return this.base(readMatrixClipboard());
        },
        async pasteEntries(before = null) {
            const entries = readMatrixClipboard();
            if (!this.canPaste(entries)) return;
            for (const entry of entries) {
                const type = this.entryTypes.find((candidate: any) => candidate.id === entry.entryTypeId);
                await this.addEntry(type.handle, before, true, {entryData: entry.data});
            }
        },
        destroy() {
            jsonMatrices.delete(this);
            return this.base();
        },
        async addEntry(type: string, before: unknown, autofocus = true, params = {}) {
            const context = matrixContext(this);
            const requestParams = {...params} as Record<string, unknown>;
            if (requestParams.duplicate) {
                const source = this.$entriesContainer.children().toArray().find((node: HTMLElement) => (
                    String(($(node).data('entry') as {id?: string} | undefined)?.id) === String(requestParams.duplicate)
                ));
                if (!source) throw new Error('The Matrix block to duplicate is no longer available.');
                requestParams.entryData = matrixEntryData(source);
                delete requestParams.duplicate;
            }
            return await this.base(type, before, autofocus, {
                ...requestParams,
                hyperFieldId: context.fieldId,
                elementId: context.elementId,
                inputContext: context.inputContext,
            });
        },
    });
    const matrix = new Matrix(...args);
    jsonMatrices.add(matrix);
    matrix.on('afterInit', () => {
        const editor = matrix.elementEditor;
        if (!editor) return;

        // Native addEntry resolves before its animation's resume callback runs.
        // Keep one stable editor facade throughout that lifecycle: parent autosave
        // still pauses normally, but a JSON link must never be drafted as an element.
        matrix.elementEditor = new Proxy(editor, {
            get(target, property) {
                if (property === 'setFormValue') return async () => {};
                const value = Reflect.get(target, property, target);
                return typeof value === 'function' ? value.bind(target) : value;
            },
        });
    });
    return matrix;
}
