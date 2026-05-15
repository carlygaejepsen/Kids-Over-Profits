(function() {
    if (window.KOP_DataNormalizer) {
        return; // Already loaded
    }

    /**
     * Normalize project data to handle different field name variations
     * and ensure default structures exist for all required fields.
     * This function is highly defensive to cope with a wide variety of
     * legacy data shapes from different database schemas and JSON files.
     * 
     * @param {object|string} data The raw project data.
     * @returns {object} The normalized project data.
     */
    function normalizeProjectData(data) {
        // If the payload is stringified JSON, parse it first
        if (typeof data === 'string') {
            try {
                data = JSON.parse(data);
            } catch (err) {
                console.warn('normalizeProjectData: failed to parse string data', err);
                data = {};
            }
        }

        // Handle null/undefined data - create default structure
        if (!data) {
            return typeof createNewProjectData === 'function' ? createNewProjectData() : {
                            operator: {
                                name: "",
                                otherNames: [],
                                profitStatus: "for-profit",
                                websites: [], investors: [], keyStaff: { ceo: "", founders: [], keyExecutives: [] }, notes: [] },
                facilities: [],
                referrerAgency: typeof createDefaultReferrerGroup === 'function' ? createDefaultReferrerGroup() : { name: "", affiliations: [], keyPersonnel: [], notes: "", fieldNotes: {} },
                referrerConsultants: [typeof createDefaultReferrerIndividual === 'function' ? createDefaultReferrerIndividual() : { firstName: "", lastName: "", affiliations: [], facilitiesReferred: [], fieldNotes: {} }],
                fieldNotes: {}
            };
        }
        
        if (typeof data !== 'object') return data;

        // Helper to normalize fieldNotes entries - ensures all values are arrays, not objects
        // This fixes a bug where database can store empty notes as {} instead of []
        const normalizeFieldNotesEntries = (fieldNotesObj) => {
            if (!fieldNotesObj || typeof fieldNotesObj !== 'object') return;
            Object.keys(fieldNotesObj).forEach(key => {
                const value = fieldNotesObj[key];
                if (!Array.isArray(value)) {
                    // Convert non-array to array
                    if (value === null || value === undefined) {
                        fieldNotesObj[key] = [];
                    } else if (typeof value === 'object') {
                        // Extract values from object (handles {} case)
                        const values = Object.values(value);
                        fieldNotesObj[key] = values.filter(v => v !== null && v !== undefined && `${v}`.trim() !== '');
                    } else if (`${value}`.trim() !== '') {
                        fieldNotesObj[key] = [`${value}`];
                    } else {
                        fieldNotesObj[key] = [];
                    }
                }
            });
        };

        // Helper to get value trying different key variations
        const getValue = (obj, ...keys) => {
            for (const key of keys) {
                if (obj && obj[key] !== undefined) return obj[key];
            }
            return undefined;
        };

        // Helper to normalize an object's keys
        const normalizeObject = (obj, fieldMap) => {
            if (!obj) return obj;
            const normalized = { ...obj };

            Object.entries(fieldMap).forEach(([targetKey, sourceKeys]) => {
                const value = getValue(obj, ...sourceKeys);
                if (value !== undefined && normalized[targetKey] === undefined) {
                    normalized[targetKey] = value;
                }
            });

            return normalized;
        };

        // Normalize root keys to handle snake_case or legacy variations
        data = normalizeObject(data, {
            operator: ['operator', 'Operator'],
            facilities: ['facilities', 'Facilities', 'facility'],
            referrerAgency: ['referrerAgency', 'referrer_agency', 'referrerGroup', 'referrer_group'],
            referrerConsultants: ['referrerConsultants', 'referrer_consultants', 'consultants'],
            referrerIndividual: ['referrerIndividual', 'referrer_individual', 'consultant'],
            isIndependentConsultant: ['isIndependentConsultant', 'is_independent_consultant', 'independent'],
            transporterCompany: ['transporterCompany', 'transporter_company', 'transporterAgency', 'transporter_agency', 'transporterGroup', 'transporter_group'],
            transporters: ['transporters', 'transporter_individuals', 'transporterIndividuals'],
            transporterIndividual: ['transporterIndividual', 'transporter_individual'],
            isIndependentTransporter: ['isIndependentTransporter', 'is_independent_transporter']
        });

        // Normalize referrer individual data (legacy support)
        if (data.referrerIndividual) {
            data.referrerIndividual = normalizeObject(data.referrerIndividual, {
                firstName: ['firstName', 'first_name', 'FirstName'],
                lastName: ['lastName', 'last_name', 'LastName'],
                fullName: ['fullName', 'full_name', 'name', 'Name', 'consultantName', 'consultant_name'],
                role: ['role', 'Role', 'title', 'Title'],
                email: ['email', 'Email'],
                phone: ['phone', 'Phone'],
                website: ['website', 'Website'],
                notes: ['notes', 'Notes'],
                knownReferrals: ['knownReferrals', 'known_referrals', 'facilitiesReferred', 'facilities_referred'],
                facilitiesReferred: ['facilitiesReferred', 'facilities_referred', 'knownReferrals', 'known_referrals'],
                affiliations: ['affiliations', 'Affiliations']
            });
            
            // Ensure arrays
            ['knownReferrals', 'facilitiesReferred', 'affiliations'].forEach(field => {
                 if (data.referrerIndividual[field] && !Array.isArray(data.referrerIndividual[field])) {
                     data.referrerIndividual[field] = typeof data.referrerIndividual[field] === 'string' ? [data.referrerIndividual[field]] : [];
                 } else if (!data.referrerIndividual[field]) {
                     data.referrerIndividual[field] = [];
                 }
            });

            // Sync referrals
            if (data.referrerIndividual.knownReferrals.length === 0 && data.referrerIndividual.facilitiesReferred.length > 0) {
                data.referrerIndividual.knownReferrals = data.referrerIndividual.facilitiesReferred.slice();
            } else if (data.referrerIndividual.facilitiesReferred.length === 0 && data.referrerIndividual.knownReferrals.length > 0) {
                data.referrerIndividual.facilitiesReferred = data.referrerIndividual.knownReferrals.slice();
            }
        }

        // Normalize operator data
        if (data.operator) {
            data.operator = normalizeObject(data.operator, {
                name: ['name', 'Name', 'companyName', 'company_name', 'operatorName', 'operator_name'],
                currentName: ['currentName', 'current_name', 'CurrentName'],
                location: ['location', 'Location'],
                locationCity: ['locationCity', 'location_city', 'city'],
                locationState: ['locationState', 'location_state', 'state'],
                headquarters: ['headquarters', 'Headquarters', 'hq'],
                headquartersCity: ['headquartersCity', 'headquarters_city'],
                headquartersState: ['headquartersState', 'headquarters_state'],
                founded: ['founded', 'Founded', 'foundedYear', 'founded_year'],
                operatingPeriod: ['operatingPeriod', 'operating_period', 'OperatingPeriod'],
                status: ['status', 'Status'],
                notes: ['notes', 'Notes'],
                otherNames: ['otherNames', 'other_names', 'aliases', 'alternateNames'],
                parentCompanies: ['parentCompanies', 'parent_companies', 'parents'],
                websites: ['websites', 'Websites', 'urls'],
                investors: ['investors', 'Investors']
            });

            // Normalize array fields to ensure they're arrays
            ['otherNames', 'parentCompanies', 'websites', 'investors'].forEach(field => {
                if (data.operator[field] && !Array.isArray(data.operator[field])) {
                    // Convert string to array
                    if (typeof data.operator[field] === 'string') {
                        data.operator[field] = [data.operator[field]];
                    } else {
                        data.operator[field] = [];
                    }
                } else if (!data.operator[field]) {
                    data.operator[field] = [];
                }
            });

            // Normalize nested keyStaff
            if (data.operator.keyStaff) {
                data.operator.keyStaff = normalizeObject(data.operator.keyStaff, {
                    ceo: ['ceo', 'CEO', 'chiefExecutiveOfficer'],
                    founders: ['founders', 'Founders'],
                    keyExecutives: ['keyExecutives', 'key_executives', 'executives']
                });

                // Ensure keyStaff arrays are actually arrays
                ['founders', 'keyExecutives'].forEach(field => {
                    if (data.operator.keyStaff[field] && !Array.isArray(data.operator.keyStaff[field])) {
                        if (typeof data.operator.keyStaff[field] === 'string') {
                            data.operator.keyStaff[field] = [data.operator.keyStaff[field]];
                        } else {
                            data.operator.keyStaff[field] = [];
                        }
                    } else if (!data.operator.keyStaff[field]) {
                        data.operator.keyStaff[field] = [];
                    }
                });
        } else {
            // Create keyStaff if it doesn't exist
            data.operator.keyStaff = {
                ceo: '',
                founders: [],
                keyExecutives: []
            };
        }

            // Normalize operator fieldNotes entries
            if (data.operator.fieldNotes && typeof data.operator.fieldNotes === 'object') {
                normalizeFieldNotesEntries(data.operator.fieldNotes);
            }
    }

        // Accept legacy facilities shapes (stringified, nested, or alternate keys)
        if (!Array.isArray(data.facilities)) {
            let facilitiesCandidate = [];

            if (typeof data.facilities === 'string') {
                try {
                    const parsed = JSON.parse(data.facilities);
                    if (Array.isArray(parsed)) facilitiesCandidate = parsed;
                } catch (err) {
                    console.warn('normalizeProjectData: failed to parse string facilities', err);
                }
            } else if (data.facilities && typeof data.facilities === 'object' && Array.isArray(data.facilities.facilities)) {
                facilitiesCandidate = data.facilities.facilities;
            } else if (Array.isArray(data.facility)) {
                facilitiesCandidate = data.facility;
            } else if (typeof data.facility === 'string') {
                try {
                    const parsed = JSON.parse(data.facility);
                    if (Array.isArray(parsed)) facilitiesCandidate = parsed;
                } catch (err) {
                    console.warn('normalizeProjectData: failed to parse string facility', err);
                }
            }

            data.facilities = Array.isArray(data.facilities) ? data.facilities : facilitiesCandidate;
        }

        // Normalize facilities array
        if (Array.isArray(data.facilities)) {
            data.facilities = data.facilities.map(facility => {
                const normalized = { ...facility };

                // Normalize identification
                if (normalized.identification) {
                    normalized.identification = normalizeObject(normalized.identification, {
                        name: ['name', 'Name', 'facilityName', 'facility_name'],
                        currentName: ['currentName', 'current_name', 'CurrentName'],
                        currentOperator: ['currentOperator', 'current_operator', 'CurrentOperator'],
                        currentOwner: ['currentOwner', 'current_owner', 'owner'],
                        currentOwners: ['currentOwners', 'current_owners', 'owners'],
                        otherNames: ['otherNames', 'other_names', 'aliases'],
                        pastNames: ['pastNames', 'past_names', 'formerNames'],
                        knownReferrers: ['knownReferrers', 'known_referrers', 'referrers']
                    });

                    // Normalize current owners to an array and keep legacy single value in sync
                    if (normalized.identification.currentOwners && !Array.isArray(normalized.identification.currentOwners)) {
                        if (typeof normalized.identification.currentOwners === 'string') {
                            normalized.identification.currentOwners = normalized.identification.currentOwners.trim() ? [normalized.identification.currentOwners.trim()] : [];
                        } else {
                            normalized.identification.currentOwners = [];
                        }
                    } else if (!normalized.identification.currentOwners) {
                        normalized.identification.currentOwners = [];
                    }
                    if (normalized.identification.currentOwner && normalized.identification.currentOwners.length === 0) {
                        normalized.identification.currentOwners = [normalized.identification.currentOwner];
                    }
                    if (!normalized.identification.currentOwner && normalized.identification.currentOwners.length > 0) {
                        normalized.identification.currentOwner = normalized.identification.currentOwners[0];
                    }
                }

                // Normalize facilityDetails - ensure it exists and check for root-level type fields
                if (!normalized.facilityDetails) {
                    normalized.facilityDetails = {};
                }
                // Check for type at root level and move to facilityDetails
                if (!normalized.facilityDetails.type) {
                    const rootType = getValue(normalized, 'type', 'Type', 'facilityType', 'facility_type', 'programType', 'program_type');
                    if (rootType) {
                        normalized.facilityDetails.type = rootType;
                    }
                }
                normalized.facilityDetails = normalizeObject(normalized.facilityDetails, {
                    type: ['type', 'Type', 'facilityType', 'facility_type', 'programType', 'program_type'],
                    capacity: ['capacity', 'Capacity'],
                    currentCensus: ['currentCensus', 'current_census', 'census'],
                    gender: ['gender', 'Gender'],
                    ageRange: ['ageRange', 'age_range', 'AgeRange']
                });

                // Normalize operatingPeriod
                if (normalized.operatingPeriod) {
                    normalized.operatingPeriod = normalizeObject(normalized.operatingPeriod, {
                        startYear: ['startYear', 'start_year', 'opened'],
                        endYear: ['endYear', 'end_year', 'closed'],
                        status: ['status', 'Status'],
                        notes: ['notes', 'Notes']
                    });
                }

                // Normalize staff
                if (normalized.staff) {
                    normalized.staff = normalizeObject(normalized.staff, {
                        administrator: ['administrator', 'Administrator', 'administrators'],
                        notableStaff: ['notableStaff', 'notable_staff', 'NotableStaff', 'staff'],
                        pastTTIJobs: ['pastTTIJobs', 'past_tti_jobs', 'pastTTIRoles']
                    });
                }

                // Normalize accreditations
                if (normalized.accreditations) {
                    normalized.accreditations = normalizeObject(normalized.accreditations, {
                        current: ['current', 'Current', 'currentAccreditations'],
                        past: ['past', 'Past', 'pastAccreditations', 'former']
                    });
                }

                // Normalize location fields
                normalized.location = getValue(normalized, 'location', 'Location');
                normalized.address = getValue(normalized, 'address', 'Address');

                // Normalize structured location details (city/state/country + additional locations)
                if (!normalized.locationDetails || typeof normalized.locationDetails !== 'object') {
                    normalized.locationDetails = {};
                }
                normalized.locationDetails = normalizeObject(normalized.locationDetails, {
                    city: ['city', 'City'],
                    state: ['state', 'State'],
                    country: ['country', 'Country'],
                    additionalLocations: ['additionalLocations', 'additional_locations']
                });
                const additionalLocations = normalized.locationDetails.additionalLocations;
                if (Array.isArray(additionalLocations)) {
                    normalized.locationDetails.additionalLocations = additionalLocations.map(item => {
                        if (typeof item === 'string') {
                            return { city: '', address: item };
                        }
                        return {
                            city: (item && typeof item.city === 'string') ? item.city : '',
                            address: (item && typeof item.address === 'string') ? item.address : ''
                        };
                    });
                } else if (typeof additionalLocations === 'string' && additionalLocations.trim()) {
                    normalized.locationDetails.additionalLocations = [{ city: '', address: additionalLocations.trim() }];
                } else {
                    normalized.locationDetails.additionalLocations = [];
                }

                // Ensure array fields are actually arrays
                const arrayFields = ['otherOperators', 'memberships', 'certifications', 'licensing', 'profileLinks', 'notes'];
                arrayFields.forEach(field => {
                    if (normalized[field] && !Array.isArray(normalized[field])) {
                        if (typeof normalized[field] === 'string') {
                            normalized[field] = [normalized[field]];
                        } else {
                            normalized[field] = [];
                        }
                    } else if (!normalized[field]) {
                        normalized[field] = [];
                    }
                });

                // Ensure nested array fields are arrays
                if (normalized.identification) {
                    ['otherNames', 'pastNames', 'knownReferrers'].forEach(field => {
                        if (normalized.identification[field] && !Array.isArray(normalized.identification[field])) {
                            if (typeof normalized.identification[field] === 'string') {
                                normalized.identification[field] = [normalized.identification[field]];
                            } else {
                                normalized.identification[field] = [];
                            }
                        } else if (!normalized.identification[field]) {
                            normalized.identification[field] = [];
                        }
                    });
                }

                if (normalized.operatingPeriod && normalized.operatingPeriod.notes) {
                    if (!Array.isArray(normalized.operatingPeriod.notes)) {
                        if (typeof normalized.operatingPeriod.notes === 'string') {
                            normalized.operatingPeriod.notes = [normalized.operatingPeriod.notes];
                        } else {
                            normalized.operatingPeriod.notes = [];
                        }
                    }
                } else if (normalized.operatingPeriod) {
                    normalized.operatingPeriod.notes = [];
                }

                if (normalized.staff) {
                    ['administrator', 'notableStaff', 'pastTTIJobs'].forEach(field => {
                        if (normalized.staff[field] && !Array.isArray(normalized.staff[field])) {
                            if (typeof normalized.staff[field] === 'string') {
                                normalized.staff[field] = [normalized.staff[field]];
                            } else {
                                normalized.staff[field] = [];
                            }
                        } else if (!normalized.staff[field]) {
                            normalized.staff[field] = [];
                        }
                    });

                    if (normalized.staff.pastTTIJobs) {
                        normalized.staff.pastTTIJobs = normalized.staff.pastTTIJobs.map(item => {
                            const role = (item && typeof item.role === 'string') ? item.role : '';
                            const org = (item && typeof item.organization === 'string')
                                ? item.organization
                                : (item && typeof item.employer === 'string')
                                    ? item.employer
                                    : (item && typeof item.name === 'string' ? item.name : (typeof item === 'string' ? item : ''));

                            return {
                                role,
                                organization: org,
                                employer: org
                            };
                        });
                    }
                }

                if (normalized.accreditations) {
                    ['current', 'past'].forEach(field => {
                        if (normalized.accreditations[field] && !Array.isArray(normalized.accreditations[field])) {
                            if (typeof normalized.accreditations[field] === 'string') {
                                normalized.accreditations[field] = [normalized.accreditations[field]];
                            } else {
                                normalized.accreditations[field] = [];
                            }
                        } else if (!normalized.accreditations[field]) {
                            normalized.accreditations[field] = [];
                        }
                    });
                }

                if (normalized.resources && normalized.resources.notes) {
                    if (!Array.isArray(normalized.resources.notes)) {
                        if (typeof normalized.resources.notes === 'string') {
                            normalized.resources.notes = [normalized.resources.notes];
                        } else {
                            normalized.resources.notes = [];
                        }
                    }
                } else if (normalized.resources) {
                    normalized.resources.notes = [];
                }

                // Normalize fieldNotes entries for each facility
                if (normalized.fieldNotes && typeof normalized.fieldNotes === 'object') {
                    normalizeFieldNotesEntries(normalized.fieldNotes);
                }

                return normalized;
            });
        }

        // Normalize referrer agency/group data
        if (data.referrerAgency) {
            data.referrerAgency = normalizeObject(data.referrerAgency, {
                name: ['name', 'Name', 'organizationName', 'organization_name'],
                city: ['city', 'City'],
                state: ['state', 'State'],
                website: ['website', 'Website', 'url'],
                address: ['address', 'Address'],
                founded: ['founded', 'Founded'],
                notes: ['notes', 'Notes'],
                affiliations: ['affiliations', 'Affiliations']
            });

            // Ensure affiliations is an array
            if (data.referrerAgency.affiliations && !Array.isArray(data.referrerAgency.affiliations)) {
                if (typeof data.referrerAgency.affiliations === 'string') {
                    data.referrerAgency.affiliations = [data.referrerAgency.affiliations];
                } else {
                    data.referrerAgency.affiliations = [];
                }
            } else if (!data.referrerAgency.affiliations) {
                data.referrerAgency.affiliations = [];
            }
            if (typeof data.referrerAgency.website === 'string' && data.referrerAgency.website.trim()) {
                if (!Array.isArray(data.referrerAgency.websites) || data.referrerAgency.websites.length === 0) {
                    data.referrerAgency.websites = [{ url: data.referrerAgency.website.trim(), displayText: "" }];
                }
            }
            if (!Array.isArray(data.referrerAgency.websites)) {
                data.referrerAgency.websites = [];
            }
            if (data.referrerAgency.websites.length && (!data.referrerAgency.website || typeof data.referrerAgency.website !== 'string')) {
                data.referrerAgency.website = data.referrerAgency.websites[0]?.url || "";
            }
        }

        // Normalize referrer group (alternative field)
        if (data.referrerGroup) {
            data.referrerGroup = normalizeObject(data.referrerGroup, {
                name: ['name', 'Name', 'organizationName', 'organization_name'],
                city: ['city', 'City'],
                state: ['state', 'State'],
                website: ['website', 'Website', 'url'],
                address: ['address', 'Address'],
                founded: ['founded', 'Founded'],
                notes: ['notes', 'Notes'],
                affiliations: ['affiliations', 'Affiliations']
            });

            // Ensure affiliations is an array
            if (data.referrerGroup.affiliations && !Array.isArray(data.referrerGroup.affiliations)) {
                if (typeof data.referrerGroup.affiliations === 'string') {
                    data.referrerGroup.affiliations = [data.referrerGroup.affiliations];
                } else {
                    data.referrerGroup.affiliations = [];
                }
            } else if (!data.referrerGroup.affiliations) {
                data.referrerGroup.affiliations = [];
            }
            if (typeof data.referrerGroup.website === 'string' && data.referrerGroup.website.trim()) {
                if (!Array.isArray(data.referrerGroup.websites) || data.referrerGroup.websites.length === 0) {
                    data.referrerGroup.websites = [{ url: data.referrerGroup.website.trim(), displayText: "" }];
                }
            }
            if (!Array.isArray(data.referrerGroup.websites)) {
                data.referrerGroup.websites = [];
            }
            if (data.referrerGroup.websites.length && (!data.referrerGroup.website || typeof data.referrerGroup.website !== 'string')) {
                data.referrerGroup.website = data.referrerGroup.websites[0]?.url || "";
            }
        }

        // Normalize consultants array
        if (Array.isArray(data.referrerConsultants)) {
            data.referrerConsultants = data.referrerConsultants.map(consultant => {
                const normalized = normalizeObject(consultant, {
                    fullName: ['fullName', 'full_name', 'name', 'Name'],
                    firstName: ['firstName', 'first_name', 'FirstName'],
                    lastName: ['lastName', 'last_name', 'LastName'],
                    role: ['role', 'Role', 'title', 'Title'],
                    status: ['status', 'Status'],
                    education: ['education', 'Education', 'credentials', 'Credentials'],
                    credentials: ['credentials', 'Credentials', 'education', 'Education'],
                    city: ['city', 'City'],
                    state: ['state', 'State'],
                    email: ['email', 'Email'],
                    phone: ['phone', 'Phone', 'phoneNumber'],
                    website: ['website', 'Website', 'url'],
                    lawsuits: ['lawsuits', 'Lawsuits'],
                    notes: ['notes', 'Notes'],
                    affiliations: ['affiliations', 'Affiliations'],
                    knownReferrals: ['knownReferrals', 'known_referrals', 'facilitiesReferred', 'facilities_referred'],
                    facilitiesReferred: ['facilitiesReferred', 'facilities_referred', 'knownReferrals', 'known_referrals'],
                    pastTTIJobs: ['pastTTIJobs', 'past_tti_jobs', 'pastJobs'],
                    schoolDistricts: ['schoolDistricts', 'school_districts']
                });

                // Ensure arrays are arrays
                ['affiliations', 'knownReferrals', 'facilitiesReferred', 'pastTTIJobs', 'schoolDistricts'].forEach(field => {
                    if (normalized[field] && !Array.isArray(normalized[field])) {
                        normalized[field] = typeof normalized[field] === 'string' ? [normalized[field]] : [];
                    } else if (!normalized[field]) {
                        normalized[field] = [];
                    }
                });

                // Keep knownReferrals and facilitiesReferred in sync
                if (normalized.knownReferrals.length === 0 && normalized.facilitiesReferred.length > 0) {
                    normalized.knownReferrals = normalized.facilitiesReferred.slice();
                } else if (normalized.facilitiesReferred.length === 0 && normalized.knownReferrals.length > 0) {
                    normalized.facilitiesReferred = normalized.knownReferrals.slice();
                }

                return normalized;
            });
        }

        // Ensure default structures exist (merged from previous duplicate function)
        if (!data.operator) {
            data.operator = {
                name: "", currentName: "", otherNames: [], location: "", headquarters: "",
                founded: "", operatingPeriod: "", status: "", parentCompanies: [],
                websites: [], investors: [], keyStaff: { ceo: "", founders: [], keyExecutives: [] },
                notes: []
            };
        }

        if (!data.facilities || !Array.isArray(data.facilities)) {
            data.facilities = [];
        }

        if (!data.referrerAgency || typeof data.referrerAgency !== 'object') {
            data.referrerAgency = typeof createDefaultReferrerGroup === 'function' ? createDefaultReferrerGroup() : { name: "", affiliations: [], keyPersonnel: [], notes: "", fieldNotes: {} };
        } else {
            const defaults = typeof createDefaultReferrerGroup === 'function' ? createDefaultReferrerGroup() : {};
            data.referrerAgency = Object.assign(defaults, data.referrerAgency);
            if (!Array.isArray(data.referrerAgency.keyPersonnel)) {
                data.referrerAgency.keyPersonnel = [];
            }
            if (typeof data.referrerAgency.website === 'string' && data.referrerAgency.website.trim()) {
                if (!Array.isArray(data.referrerAgency.websites) || data.referrerAgency.websites.length === 0) {
                    data.referrerAgency.websites = [{ url: data.referrerAgency.website.trim(), displayText: "" }];
                }
            }
            if (!Array.isArray(data.referrerAgency.websites)) {
                data.referrerAgency.websites = [];
            }
            if (data.referrerAgency.websites.length && (!data.referrerAgency.website || typeof data.referrerAgency.website !== 'string')) {
                data.referrerAgency.website = data.referrerAgency.websites[0]?.url || "";
            }
            if (!data.referrerAgency.fieldNotes || typeof data.referrerAgency.fieldNotes !== 'object') {
                data.referrerAgency.fieldNotes = {};
            }
            normalizeFieldNotesEntries(data.referrerAgency.fieldNotes);
        }

        // Recover legacy referrerIndividual if referrerConsultants is missing
        if ((!Array.isArray(data.referrerConsultants) || data.referrerConsultants.length === 0) && data.referrerIndividual && typeof data.referrerIndividual === 'object') {
            data.referrerConsultants = [data.referrerIndividual];
        }

        if (!Array.isArray(data.referrerConsultants) || data.referrerConsultants.length === 0) {
            const defaultIndividual = typeof createDefaultReferrerIndividual === 'function' ? createDefaultReferrerIndividual() : { firstName: "", lastName: "", affiliations: [], facilitiesReferred: [], knownReferrals: [], pastTTIJobs: [], schoolDistricts: [], fieldNotes: {} };
            data.referrerConsultants = [defaultIndividual];
        } else {
            data.referrerConsultants = data.referrerConsultants.map(consultant => {
                const defaults = typeof createDefaultReferrerIndividual === 'function' ? createDefaultReferrerIndividual() : {};
                const merged = Object.assign(defaults, consultant || {});
                // Ensure all array fields are arrays
                ['affiliations', 'knownReferrals', 'facilitiesReferred', 'pastTTIJobs', 'schoolDistricts'].forEach(field => {
                    if (!Array.isArray(merged[field])) merged[field] = [];
                });
                if (typeof merged.website === 'string' && merged.website.trim()) {
                    if (!Array.isArray(merged.websites) || merged.websites.length === 0) {
                        merged.websites = [{ url: merged.website.trim(), displayText: "" }];
                    }
                }
                if (!Array.isArray(merged.websites)) {
                    merged.websites = [];
                }
                if (merged.websites.length && (!merged.website || typeof merged.website !== 'string')) {
                    merged.website = merged.websites[0]?.url || "";
                }
                // Keep knownReferrals and facilitiesReferred in sync
                if (merged.knownReferrals.length === 0 && merged.facilitiesReferred.length > 0) {
                    merged.knownReferrals = merged.facilitiesReferred.slice();
                } else if (merged.facilitiesReferred.length === 0 && merged.knownReferrals.length > 0) {
                    merged.facilitiesReferred = merged.knownReferrals.slice();
                }
                if (!merged.fieldNotes || typeof merged.fieldNotes !== 'object') merged.fieldNotes = {};
                normalizeFieldNotesEntries(merged.fieldNotes);
                return merged;
            });
        }

        if (typeof data.isIndependentConsultant === 'undefined') {
            data.isIndependentConsultant = false;
        }

        // Preserve or set referrerType based on existing data
        if (!data.referrerType) {
            data.referrerType = data.isIndependentConsultant ? 'individual' : 'group';
        }

        // Keep referrerGroup in sync with referrerAgency
        if (data.referrerAgency && !data.referrerGroup) {
            data.referrerGroup = data.referrerAgency;
        } else if (data.referrerGroup && !data.referrerAgency) {
            data.referrerAgency = data.referrerGroup;
        }

        // Keep referrerIndividual in sync with current consultant
        if (Array.isArray(data.referrerConsultants) && data.referrerConsultants.length > 0) {
            data.referrerIndividual = data.referrerConsultants[0];
        }

        // ---- Transporter normalization (mirrors referrer logic) ----
        if (!data.transporterCompany || typeof data.transporterCompany !== 'object') {
            data.transporterCompany = typeof createDefaultTransporterCompany === 'function' ? createDefaultTransporterCompany() : { name: "", affiliations: [], keyPersonnel: [], serviceAreas: [], vehicleTypes: [], notes: "", fieldNotes: {} };
        } else {
            const transporterDefaults = typeof createDefaultTransporterCompany === 'function' ? createDefaultTransporterCompany() : {};
            data.transporterCompany = Object.assign(transporterDefaults, data.transporterCompany);
            [
                'otherNames', 'parentCompanies', 'affiliations', 'keyPersonnel',
                'serviceAreas', 'vehicleTypes', 'pickupMethods', 'restraintPractices',
                'licensing', 'knownFacilities', 'knownReferrers', 'lawsuits', 'sourceUrls', 'socialMedia'
            ].forEach(field => {
                if (!Array.isArray(data.transporterCompany[field])) {
                    data.transporterCompany[field] = [];
                }
            });
            if (typeof data.transporterCompany.website === 'string' && data.transporterCompany.website.trim()) {
                if (!Array.isArray(data.transporterCompany.websites) || data.transporterCompany.websites.length === 0) {
                    data.transporterCompany.websites = [{ url: data.transporterCompany.website.trim(), displayText: "" }];
                }
            }
            if (!Array.isArray(data.transporterCompany.websites)) {
                data.transporterCompany.websites = [];
            }
            if (data.transporterCompany.websites.length && (!data.transporterCompany.website || typeof data.transporterCompany.website !== 'string')) {
                data.transporterCompany.website = data.transporterCompany.websites[0]?.url || "";
            }
            if (!data.transporterCompany.fieldNotes || typeof data.transporterCompany.fieldNotes !== 'object') {
                data.transporterCompany.fieldNotes = {};
            }
            normalizeFieldNotesEntries(data.transporterCompany.fieldNotes);
        }

        // Recover legacy transporterIndividual if transporters array is missing
        if ((!Array.isArray(data.transporters) || data.transporters.length === 0) && data.transporterIndividual && typeof data.transporterIndividual === 'object') {
            data.transporters = [data.transporterIndividual];
        }

        if (!Array.isArray(data.transporters) || data.transporters.length === 0) {
            const defaultTransporter = typeof createDefaultTransporterIndividual === 'function' ? createDefaultTransporterIndividual() : { firstName: "", lastName: "", affiliations: [], pastTTIJobs: [], affiliatedCompanies: [], fieldNotes: {} };
            data.transporters = [defaultTransporter];
        } else {
            data.transporters = data.transporters.map(transporter => {
                const transporterDefaults = typeof createDefaultTransporterIndividual === 'function' ? createDefaultTransporterIndividual() : {};
                const merged = Object.assign(transporterDefaults, transporter || {});
                ['affiliations', 'pastTTIJobs', 'affiliatedCompanies'].forEach(field => {
                    if (!Array.isArray(merged[field])) merged[field] = [];
                });
                if (typeof merged.website === 'string' && merged.website.trim()) {
                    if (!Array.isArray(merged.websites) || merged.websites.length === 0) {
                        merged.websites = [{ url: merged.website.trim(), displayText: "" }];
                    }
                }
                if (!Array.isArray(merged.websites)) {
                    merged.websites = [];
                }
                if (merged.websites.length && (!merged.website || typeof merged.website !== 'string')) {
                    merged.website = merged.websites[0]?.url || "";
                }
                if (!merged.fieldNotes || typeof merged.fieldNotes !== 'object') merged.fieldNotes = {};
                normalizeFieldNotesEntries(merged.fieldNotes);
                return merged;
            });
        }

        if (typeof data.isIndependentTransporter === 'undefined') {
            data.isIndependentTransporter = false;
        }

        if (!data.transporterType) {
            data.transporterType = data.isIndependentTransporter ? 'individual' : 'company';
        }

        // Keep transporterAgency/Group legacy aliases in sync with transporterCompany
        if (data.transporterCompany && !data.transporterAgency) {
            data.transporterAgency = data.transporterCompany;
        }
        if (data.transporterCompany && !data.transporterGroup) {
            data.transporterGroup = data.transporterCompany;
        }

        if (Array.isArray(data.transporters) && data.transporters.length > 0) {
            data.transporterIndividual = data.transporters[0];
        }

        if (!data.fieldNotes || typeof data.fieldNotes !== 'object') {
            data.fieldNotes = {};
        }
        normalizeFieldNotesEntries(data.fieldNotes);

        // Build referrer entries from the data
        if (typeof buildReferrerEntries === 'function') {
            data.referrer = buildReferrerEntries(data);
        }

        // Build transporter entries from the data
        if (typeof buildTransporterEntries === 'function') {
            data.transporter = buildTransporterEntries(data);
        }

        return data;
    }

    // Expose the public API
    window.KOP_DataNormalizer = {
        normalizeProjectData: normalizeProjectData,
    };
})();