(function bootstrapKidsOverProfitsThemeBase() {
    'use strict';

    const DEFAULT_THEME_BASE = 'https://kidsoverprofits.org/themes/child';
    const LEGACY_THEME_BASE = 'https://kidsoverprofits.org/wp-content/themes/child';
    const DEFAULT_LOCAL_BASE = '../themes/child';
    const LEGACY_LOCAL_BASE = '../wp-content/themes/child';
    const THEME_BASE_PATTERN = /(^\.\.\/(?:wp-content\/)?themes\/)|\/themes\//i;

    function cleanBase(value) {
        if (typeof value !== 'string') {
            return '';
        }

        return value.trim().replace(/\/+$/, '');
    }

    function isValidThemeBase(value) {
        const normalized = cleanBase(value);
        return Boolean(normalized && THEME_BASE_PATTERN.test(normalized));
    }

    function expandThemeBaseVariants(base) {
        const normalized = cleanBase(base);

        if (!normalized) {
            return [];
        }

        const variants = [normalized];
        const wpSegment = '/wp-content/themes/';
        const altSegment = '/themes/';

        if (normalized.includes(wpSegment)) {
            variants.push(normalized.replace(wpSegment, altSegment));
        } else if (normalized.includes(altSegment)) {
            variants.push(normalized.replace(altSegment, wpSegment));
        }

        const unique = new Set();

        return variants
            .map(cleanBase)
            .filter((value) => {
                if (!value || unique.has(value)) {
                    return false;
                }

                unique.add(value);
                return true;
            });
    }

    function detectThemeBaseFromDataAttribute() {
        try {
            const node = document.querySelector('[data-kop-theme-base]');

            if (node && node.dataset && node.dataset.kopThemeBase) {
                return node.dataset.kopThemeBase.trim();
            }
        } catch (error) {
            console.warn('Kids Over Profits: unable to read theme base from dataset.', error);
        }

        return '';
    }

    function detectThemeBaseFromMeta() {
        try {
            const meta = document.querySelector('meta[name="kids-over-profits-theme-base"]');

            if (meta && meta.content) {
                return meta.content.trim();
            }
        } catch (error) {
            console.warn('Kids Over Profits: unable to read meta theme base.', error);
        }

        return '';
    }

    function detectThemeBaseFromDomAssets() {
        const selectors = ['link[href*="/wp-content/themes/" i]', 'script[src*="/wp-content/themes/" i]'];
        const matches = [];

        try {
            selectors.forEach((selector) => {
                document.querySelectorAll(selector).forEach((element) => {
                    const attribute = element.getAttribute('href') || element.getAttribute('src') || '';

                    if (attribute) {
                        matches.push(attribute);
                    }
                });
            });
        } catch (error) {
            console.warn('Kids Over Profits: unable to detect theme base from DOM assets.', error);
        }

        const normalized = matches
            .map((value) => value.replace(/[#?].*$/, ''))
            .map((value) => value.replace(/\/+$/, ''))
            .map((value) => value.replace(/\/js\/.*$/, '/'))
            .map((value) => value.replace(/\/css\/.*$/, '/'))
            .map(cleanBase)
            .filter(isValidThemeBase);

        return normalized.length ? normalized[0] : '';
    }

    function detectThemeBaseFromLocation() {
        try {
            if (window.location && window.location.origin) {
                return cleanBase(window.location.origin + '/themes/child');
            }
        } catch (error) {
            console.warn('Kids Over Profits: unable to derive theme base from location.', error);
        }

        return '';
    }

    function normalizeBase(preferredValue, fallbackValue) {
        const normalizedPreferred = cleanBase(preferredValue);

        if (isValidThemeBase(normalizedPreferred)) {
            return normalizedPreferred;
        }

        const normalizedFallback = cleanBase(fallbackValue);

        return isValidThemeBase(normalizedFallback) ? normalizedFallback : '';
    }

    const detectedThemeBase = cleanBase(
        window.KOP_THEME_BASE ||
        detectThemeBaseFromDataAttribute() ||
        detectThemeBaseFromMeta() ||
        detectThemeBaseFromDomAssets()
    );

    const resolvedThemeBase = normalizeBase(detectedThemeBase, DEFAULT_THEME_BASE);
    const resolvedLocalBase = normalizeBase(window.KOP_LOCAL_BASE, detectedThemeBase || DEFAULT_LOCAL_BASE) || DEFAULT_LOCAL_BASE;

    const additionalBases = [
        resolvedThemeBase,
        resolvedLocalBase,
        detectThemeBaseFromLocation(),
        DEFAULT_THEME_BASE,
        LEGACY_THEME_BASE,
        DEFAULT_LOCAL_BASE,
        LEGACY_LOCAL_BASE,
    ];

    const preservedThemeBases = Array.isArray(window.KOP_THEME_BASES) ? window.KOP_THEME_BASES : [];
    const combinedBases = additionalBases
        .concat(preservedThemeBases)
        .reduce((list, value) => list.concat(expandThemeBaseVariants(value)), [])
        .concat(additionalBases);

    const uniqueThemeBases = [];
    const seen = new Set();

    combinedBases
        .map(cleanBase)
        .filter(isValidThemeBase)
        .forEach((value) => {
            if (seen.has(value)) {
                return;
            }

            seen.add(value);
            uniqueThemeBases.push(value);
        });

    window.KOP_THEME_BASE = window.KOP_THEME_BASE || resolvedThemeBase || DEFAULT_THEME_BASE;
    window.KOP_LOCAL_BASE = window.KOP_LOCAL_BASE || resolvedLocalBase;
    window.KOP_DETECTED_THEME_BASE = detectedThemeBase || window.KOP_DETECTED_THEME_BASE || '';
    window.KOP_RESOLVED_THEME_BASE = resolvedThemeBase || window.KOP_RESOLVED_THEME_BASE || '';
    window.KOP_RESOLVED_LOCAL_BASE = resolvedLocalBase || window.KOP_RESOLVED_LOCAL_BASE || '';
    window.KOP_THEME_BASES = uniqueThemeBases;

    if (typeof window.KOP_EXPAND_THEME_BASE !== 'function') {
        window.KOP_EXPAND_THEME_BASE = expandThemeBaseVariants;
    }

    if (typeof window.KOP_IS_VALID_THEME_BASE !== 'function') {
        window.KOP_IS_VALID_THEME_BASE = isValidThemeBase;
    }
})();
