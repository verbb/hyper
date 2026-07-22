export type SpinnerSize = 'small' | 'lg' | 'big';

/**
 * Craft CP loading indicator (`cp.css` `.spinner`).
 * Use this instead of bespoke Hyper spinners so CP styling stays consistent.
 */
export function createSpinner(size?: SpinnerSize): HTMLDivElement {
    const spinner = document.createElement('div');
    spinner.className = ['spinner', size].filter(Boolean).join(' ');
    spinner.setAttribute('role', 'status');
    spinner.innerHTML = `<span class="visually-hidden">${Craft.t('app', 'Loading')}</span>`;

    return spinner;
}
