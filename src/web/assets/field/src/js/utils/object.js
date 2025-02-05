export const clone = function(value) {
    if (value === undefined) {
        return undefined;
    }

    return JSON.parse(JSON.stringify(value));
};

export const normalizeJson = function(data) {
    if (Array.isArray(data)) {
        return data.map((item) => {
            return normalizeJson(item);
        });
    }

    // Ensure that we check for valid numbers before casting it.
    // For example, a phone number `+44...` would strip `+` and be considered a number.
    const isConvertibleNumber = function(value) {
        return /^[0-9]+(\.[0-9]+)?$/.test(value);
    };

    if (data && typeof data === 'object') {
        const normalized = {};

        for (const [key, value] of Object.entries(data)) {
            if (typeof value === 'object' && value !== null && Object.keys(value).length === 0) {
                normalized[key] = []; // Convert empty objects to empty arrays
            } else if (value === '') {
                normalized[key] = null; // Convert empty strings to null
            } else if (typeof value === 'string' && isConvertibleNumber(value)) {
                normalized[key] = Number(value); // Convert only safe numeric strings
            } else {
                normalized[key] = normalizeJson(value);
            }
        }

        return normalized;
    }

    return data;
};
