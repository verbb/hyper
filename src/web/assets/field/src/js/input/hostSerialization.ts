import { syncAllHyperInputStores } from './registry';

/** Parent JSON editors must collect Hyper stores, not Hyper's temporary authoring inputs. */
export function registerHostSerialization(): void {
    const original = Garnish.getPostData;
    let collecting = false;

    Garnish.getPostData = function(container: HTMLElement): Record<string, unknown> {
        const root = $(container)[0];
        const owner = root instanceof HTMLElement ? root.closest('[data-hyper-input]') : null;

        // Hyper's own portal serialization must retain that portal's authoring inputs.
        if (!collecting && root instanceof HTMLElement && root.querySelector('[data-hyper-input]')) {
            collecting = true;
            try {
                syncAllHyperInputStores();
            } finally {
                collecting = false;
            }
        }

        const data = original.call(this, container);
        if (root instanceof HTMLElement) {
            root.querySelectorAll<HTMLElement>('[name]').forEach((input) => {
                const inputOwner = input.closest('[data-hyper-input]');
                if (inputOwner && inputOwner !== owner) {
                    const name = input.getAttribute('name')!;
                    if (name.endsWith('[]')) {
                        const prefix = name.slice(0, -1);
                        Object.keys(data).forEach((key) => {
                            if (key.startsWith(prefix)) delete data[key];
                        });
                    } else {
                        delete data[name];
                    }
                }
            });
        }
        return data;
    };
}
