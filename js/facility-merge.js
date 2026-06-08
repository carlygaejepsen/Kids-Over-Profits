// Shared render-time facility merge helpers for directory pages.
(function() {
    const cloneValue = value => {
        if (Array.isArray(value)) return value.map(item => cloneValue(item));
        if (value && typeof value === 'object') {
            const out = {};
            Object.keys(value).forEach(key => {
                out[key] = cloneValue(value[key]);
            });
            return out;
        }
        return value;
    };

    const getProjectData = project => {
        let projectData = (project && project.data && typeof project.data === 'object') ? project.data : project;
        let unwrapGuard = 0;
        while (
            projectData
            && typeof projectData === 'object'
            && !projectData.operator
            && !projectData.facilities
            && projectData.data
            && typeof projectData.data === 'object'
            && unwrapGuard < 2
        ) {
            projectData = projectData.data;
            unwrapGuard += 1;
        }
        return projectData;
    };

    const isOperatorLike = project => {
        const projectData = getProjectData(project);
        return !!(projectData && typeof projectData === 'object' && (projectData.operator || projectData.facilities));
    };

    const normalizeProjectCategory = project => {
        if (!project || typeof project !== 'object') return 'unknown';

        const operatorLike = isOperatorLike(project);

        let rawCategory = '';
        if (typeof project.category === 'string' && project.category) {
            rawCategory = project.category;
        } else if (project.data && typeof project.data === 'object' && typeof project.data.category === 'string' && project.data.category) {
            rawCategory = project.data.category;
        }

        if (!rawCategory) {
            return operatorLike ? 'companies' : 'unknown';
        }

        return rawCategory.toLowerCase();
    };

    const isOperatorCategory = project => {
        if (!isOperatorLike(project)) return false;
        const category = normalizeProjectCategory(project);
        return category === 'operators' || category === 'operator' || category === 'companies' || category === 'company';
    };

    const isLocationCategory = project => {
        const category = normalizeProjectCategory(project);
        return category === 'locations' || category === 'location';
    };

    const createApi = options => {
        const {
            toArray,
            cleanText,
            isValueEmpty,
            normalizeTextKey,
            getFacilityDisplayName,
            getValueFromKeys,
        } = options || {};

        if (
            typeof toArray !== 'function'
            || typeof cleanText !== 'function'
            || typeof isValueEmpty !== 'function'
            || typeof normalizeTextKey !== 'function'
            || typeof getFacilityDisplayName !== 'function'
            || typeof getValueFromKeys !== 'function'
        ) {
            throw new Error('KOPFacilityMerge.createApi missing required callbacks');
        }

        const normalizeFacilityId = value => {
            const text = cleanText(value);
            if (!text) return '';
            return text.toLowerCase();
        };

        const getFacilityId = facility => {
            if (!facility || typeof facility !== 'object') return '';
            return normalizeFacilityId(
                getValueFromKeys(facility, [
                    'facility_id', 'facilityId',
                    'identification.facility_id', 'identification.facilityId'
                ])
            );
        };

        const countNonEmptyLeaves = value => {
            if (isValueEmpty(value)) return 0;
            if (Array.isArray(value)) {
                return value.reduce((sum, item) => sum + countNonEmptyLeaves(item), 0);
            }
            if (value && typeof value === 'object') {
                return Object.keys(value).reduce((sum, key) => sum + countNonEmptyLeaves(value[key]), 0);
            }
            return 1;
        };

        const mergeUniqueArrayValues = (left, right) => {
            const combined = [];
            const seen = new Set();

            const pushItem = item => {
                if (isValueEmpty(item)) return;

                const key = (item && typeof item === 'object')
                    ? `obj:${JSON.stringify(item)}`
                    : `txt:${normalizeTextKey(item) || cleanText(item)}`;

                if (!key || seen.has(key)) return;
                seen.add(key);
                combined.push(cloneValue(item));
            };

            toArray(left).forEach(pushItem);
            toArray(right).forEach(pushItem);
            return combined;
        };

        const mergeFacilityObjects = (primary, secondary) => {
            if (isValueEmpty(primary)) return cloneValue(secondary);
            if (isValueEmpty(secondary)) return cloneValue(primary);

            if (Array.isArray(primary) || Array.isArray(secondary)) {
                return mergeUniqueArrayValues(primary, secondary);
            }

            if (primary && typeof primary === 'object' && secondary && typeof secondary === 'object') {
                const merged = {};
                const keys = new Set([...Object.keys(primary), ...Object.keys(secondary)]);
                keys.forEach(key => {
                    merged[key] = mergeFacilityObjects(primary[key], secondary[key]);
                });
                return merged;
            }

            return cloneValue(primary);
        };

        const mergeFacilityPair = (leftFacility, rightFacility) => {
            const left = leftFacility && typeof leftFacility === 'object' ? leftFacility : {};
            const right = rightFacility && typeof rightFacility === 'object' ? rightFacility : {};

            const leftScore = countNonEmptyLeaves(left);
            const rightScore = countNonEmptyLeaves(right);

            const primary = leftScore >= rightScore ? left : right;
            const secondary = leftScore >= rightScore ? right : left;
            return mergeFacilityObjects(primary, secondary);
        };

        const buildCategoryFacilityMapById = (projects, predicate) => {
            const map = new Map();

            toArray(projects)
                .filter(predicate)
                .forEach(project => {
                    const projectData = getProjectData(project);
                    const facilities = toArray(projectData && projectData.facilities);

                    facilities.forEach(facility => {
                        const facilityId = getFacilityId(facility);
                        if (!facilityId) return;

                        if (map.has(facilityId)) {
                            map.set(facilityId, mergeFacilityPair(map.get(facilityId), facility));
                        } else {
                            map.set(facilityId, cloneValue(facility));
                        }
                    });
                });

            return map;
        };

        const mergeAndDedupeFacilities = (rawFacilities, facilitiesById) => {
            const mergedFacilities = [];
            const indexById = new Map();
            const indexByName = new Map();

            toArray(rawFacilities).forEach(rawFacility => {
                if (!rawFacility || typeof rawFacility !== 'object') return;

                const facilityId = getFacilityId(rawFacility);
                const linkedFacility = facilityId && facilitiesById && facilitiesById.has(facilityId)
                    ? facilitiesById.get(facilityId)
                    : null;

                const baseFacility = linkedFacility
                    ? mergeFacilityPair(rawFacility, linkedFacility)
                    : cloneValue(rawFacility);

                const baseNameKey = normalizeTextKey(getFacilityDisplayName(baseFacility));

                let existingIndex = null;
                if (facilityId && indexById.has(facilityId)) {
                    existingIndex = indexById.get(facilityId);
                } else if (!facilityId && baseNameKey && indexByName.has(baseNameKey)) {
                    existingIndex = indexByName.get(baseNameKey);
                }

                if (existingIndex !== null) {
                    const merged = mergeFacilityPair(mergedFacilities[existingIndex], baseFacility);
                    mergedFacilities[existingIndex] = merged;

                    const mergedId = getFacilityId(merged);
                    const mergedNameKey = normalizeTextKey(getFacilityDisplayName(merged));
                    if (mergedId) indexById.set(mergedId, existingIndex);
                    if (mergedNameKey) indexByName.set(mergedNameKey, existingIndex);
                    return;
                }

                const nextIndex = mergedFacilities.length;
                mergedFacilities.push(baseFacility);
                if (facilityId) indexById.set(facilityId, nextIndex);
                if (baseNameKey) indexByName.set(baseNameKey, nextIndex);
            });

            return mergedFacilities;
        };

        return {
            getFacilityId,
            mergeFacilityPair,
            buildCategoryFacilityMapById,
            mergeAndDedupeFacilities,
        };
    };

    window.KOPFacilityMerge = {
        getProjectData,
        normalizeProjectCategory,
        isOperatorCategory,
        isLocationCategory,
        createApi,
    };
})();
