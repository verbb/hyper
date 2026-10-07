type FeedbackVariant = 'empty' | 'info' | 'success' | 'warning' | 'error';

type FeedbackOptions = {
    variant: FeedbackVariant;
    heading: string;
    message: string;
    details?: string | null;
    detailsLabel?: string;
    copyLabel?: string;
    copiedLabel?: string;
    action?: HTMLElement;
    announce?: 'off' | 'polite' | 'assertive';
};

type ErrorPayload = {
    message?: unknown;
    response?: {
        data?: {
            message?: unknown;
        };
    };
};

/** Extract a useful diagnostic without assuming every rejection is an Axios error. */
export function getErrorDetail(error: unknown): string | null {
    if (!error || typeof error !== 'object') {
        return typeof error === 'string' && error.trim() ? error : null;
    }

    const payload = error as ErrorPayload;
    const responseMessage = payload.response?.data?.message;

    if (typeof responseMessage === 'string' && responseMessage.trim()) {
        return responseMessage;
    }

    if (typeof payload.message === 'string' && payload.message.trim()) {
        return payload.message;
    }

    return null;
}

function applyFeedbackContent(element: HTMLElement, options: FeedbackOptions): void {
    element.setAttribute('variant', options.variant);
    element.setAttribute('size', 'sm');
    element.setAttribute('heading', options.heading);
    element.setAttribute('announce', options.announce ?? 'polite');
    element.append(document.createTextNode(options.message));

    if (options.details) {
        element.setAttribute('details-label', options.detailsLabel ?? Craft.t('app', 'Details'));
        element.setAttribute('copy-label', options.copyLabel ?? Craft.t('app', 'Copy'));
        element.setAttribute('copied-label', options.copiedLabel ?? Craft.t('app', 'Copied.'));
        element.setAttribute('copyable', '');

        const details = document.createElement('pre');
        details.slot = 'details';
        details.textContent = options.details;
        element.append(details);
    }

    if (options.action) {
        options.action.slot = 'actions';
        element.append(options.action);
    }
}

/** Build a compact contextual notice for a recoverable operation failure. */
export function createAlert(options: FeedbackOptions): HTMLElement {
    const alert = document.createElement('pk-alert');
    alert.setAttribute('appearance', 'accent');
    applyFeedbackContent(alert, options);

    return alert;
}

/** Build a replacement state for content that could not be loaded or is empty. */
export function createStatePanel(options: FeedbackOptions): HTMLElement {
    const panel = document.createElement('pk-state-panel');
    applyFeedbackContent(panel, options);

    return panel;
}
