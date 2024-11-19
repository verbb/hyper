export const clone = function(value) {
    if (value === undefined) {
        return undefined;
    }

    return JSON.parse(JSON.stringify(value));
};

export const normalizeJson = function(data) {
    if (Array.isArray(data)) {
        return data.map((item) => {
            if (typeof item === 'string' && !isNaN(item) && item.trim() !== '') {
                // Convert string numbers to actual numbers
                return Number(item);
            }

            return normalizeJson(item);
        });
    }

    if (data && typeof data === 'object') {
        const normalized = {};

        for (const [key, value] of Object.entries(data)) {
            if (typeof value === 'object' && value !== null && Object.keys(value).length === 0) {
                // Normalize empty objects to empty arrays
                normalized[key] = [];
            } else if (value === '') {
                // Convert empty strings to null
                normalized[key] = null;
            } else if (typeof value === 'string' && !isNaN(value) && value.trim() !== '') {
                // Ensure numbers are properly cast (e.g., "123" -> 123)
                normalized[key] = Number(value);
            } else {
                normalized[key] = normalizeJson(value);
            }
        }

        return normalized;
    }

    return data;
};
