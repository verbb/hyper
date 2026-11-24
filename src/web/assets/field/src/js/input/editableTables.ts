/**
 * Craft's EditableTable names cell inputs `{baseName}[{rowId}][{colId}]` and never renames
 * them when a row is deleted or moved, and a new row takes `biggestId + 1`. Craft's own form
 * is unaffected, because PHP reads posted rows in body order and Table fields apply
 * `array_values`. `Craft.expandPostArray` instead builds a JS array indexed by row ID, which
 * restores the original row order and leaves a hole (stored as a `null` row) for each deleted
 * row. Renumber each table's rows in DOM order before expanding, so Hyper's JSON gets the rows
 * that Craft's form submission would save.
 */
export function renumberEditableTableRows(
    container: HTMLElement,
    postData: Record<string, unknown>,
): Record<string, unknown> {
    const prefixes = new Map<string, string>();

    container.querySelectorAll<HTMLTableElement>('table').forEach((table) => {
        // Only tables managed by Craft's EditableTable change rows after render. Its
        // `baseName` is the real input prefix, which may itself contain numeric keys
        // (such as a saved link's ID), so the row segment can't be guessed from names.
        const baseName = ($(table).data('editable-table') as { baseName?: unknown } | undefined)?.baseName;

        if (typeof baseName !== 'string' || baseName === '') {
            return;
        }

        const rows = Array.from(table.querySelectorAll<HTMLTableRowElement>(':scope > tbody > tr[data-id]'));

        // Prefixed row IDs (`rowIdPrefix`) expand to an object in DOM order already.
        if (!rows.every((row) => /^\d+$/.test(row.dataset.id ?? ''))) {
            return;
        }

        rows.forEach((row, index) => {
            prefixes.set(`${baseName}[${row.dataset.id}]`, `${baseName}[${index}]`);
        });
    });

    if (prefixes.size === 0) {
        return postData;
    }

    // Build a new object so a renamed key never overwrites a row that hasn't been renamed yet.
    const renamed: Record<string, unknown> = {};

    for (const [key, value] of Object.entries(postData)) {
        renamed[renameRowKey(key, prefixes)] = value;
    }

    return renamed;
}

// Check each bracketed prefix of the key (`a[b]`, `a[b][2]`, `a[b][2][col1]`, ...) for a row.
const renameRowKey = (key: string, prefixes: Map<string, string>): string => {
    for (let end = key.indexOf(']'); end !== -1; end = key.indexOf(']', end + 1)) {
        const next = prefixes.get(key.slice(0, end + 1));

        if (next !== undefined) {
            return next + key.slice(end + 1);
        }
    }

    return key;
};
