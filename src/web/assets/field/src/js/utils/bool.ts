const boolMatch = (value: string, matchers: Array<string | RegExp>): boolean => {
    const down = value.toLowerCase();

    return matchers.some((matcher) => {
        if (!matcher) {
            return false;
        }

        if (typeof matcher === 'object' && 'test' in matcher) {
            return matcher.test(value);
        }

        return String(matcher).toLowerCase() === down;
    });
};

export const toBoolean = (
    value: unknown,
    trueValues?: Array<string | RegExp>,
    falseValues?: Array<string | RegExp>,
): boolean => {
    if (typeof value === 'number') {
        return boolMatch(String(value), trueValues || ['true', '1']);
    }

    if (typeof value !== 'string') {
        return !!value;
    }

    const normalized = value.trim();

    if (boolMatch(normalized, trueValues || ['true', '1'])) {
        return true;
    }

    if (boolMatch(normalized, falseValues || ['false', '0'])) {
        return false;
    }

    return false;
};
