export const getId = function(prefix = '') {
    return prefix + Craft.randomString(10);
};

export const namespaceString = function(ns, name) {
    name = name.replace(/]/g, '').split('[').join('][');

    return `${ns}[${name}]`;
};
