export const clone = function(value) {
    if (value === undefined) {
        return undefined;
    }

    return JSON.parse(JSON.stringify(value));
};

export const normalizeJson = function(data, reference = null) {
    // Ensure that we check for valid numbers before casting it.
    // For example, a phone number `+44...` would strip `+` and be considered a number.
    const isConvertibleNumber = function(value) {
        return /^[0-9]+(\.[0-9]+)?$/.test(value);
    };

    const isEmptyObject = function(obj) {
        return typeof obj === 'object' && obj !== null && !Array.isArray(obj) && Object.keys(obj).length === 0;
    };

    if (Array.isArray(data)) {
        return data.map((item, index) => {
            if (typeof item === 'string' && isConvertibleNumber(item)) {
                return Number(item); // Convert valid numeric strings to numbers
            }

            return normalizeJson(item, reference?.[index]); // Recursively normalize each item in the array
        });
    }

    if (data && typeof data === 'object') {
        const normalized = {};

        for (const [key, value] of Object.entries(data)) {
            // Check for a reference value to see if something empty has just been typed to something also empty
            // For example, `[]` changing to `null` for some components like an editable table.
            const refValue = reference?.[key];

            if (isEmptyObject(value)) {
                normalized[key] = [];
            } else if ((value === null || value === '') && Array.isArray(refValue)) {
                normalized[key] = [];
            } else if (value === '') {
                // Don't do this, as `null` is considered a no-value-set, rather than empty value
                // This causes issues with a Lightswitch field with a default value set.
                // normalized[key] = null; // Convert empty strings to null
            } else if (Array.isArray(value)) {
                normalized[key] = normalizeJson(value, refValue); // Normalize arrays properly
            } else if (typeof value === 'string' && isConvertibleNumber(value)) {
                normalized[key] = Number(value); // Convert only safe numeric strings
            } else {
                normalized[key] = normalizeJson(value, refValue);
            }
        }

        return normalized;
    }

    return data;
};
