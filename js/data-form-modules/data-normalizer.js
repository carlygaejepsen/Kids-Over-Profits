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
                                websites: [], investors: [], owners: [], keyStaff: { ceo: "", founders: [], keyExecutives: [] }, notes: [] },
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
                investors: ['investors', 'Investors'],
                owners: ['owners', 'Owners', 'currentOwners', 'current_owners']
            });

            // Normalize array fields to ensure they're arrays
            ['otherNames', 'parentCompanies', 'websites', 'investors', 'owners'].forEach(field => {
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
                // v2 documents are edited in the legacy shape; see facilityFromV2.
                const normalized = isV2Facility(facility) ? facilityFromV2(facility) : { ...facility };

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
                    zip: ['zip', 'Zip', 'zipCode', 'ZipCode', 'postalCode', 'PostalCode', 'postal_code'],
                    additionalLocations: ['additionalLocations', 'additional_locations']
                });
                const additionalLocations = normalized.locationDetails.additionalLocations;
                if (Array.isArray(additionalLocations)) {
                    normalized.locationDetails.additionalLocations = additionalLocations.map(item => {
                        if (typeof item === 'string') {
                            return { address: item, city: '', state: '', zip: '' };
                        }
                        return {
                            address: (item && typeof item.address === 'string') ? item.address : '',
                            city: (item && typeof item.city === 'string') ? item.city : '',
                            state: (item && typeof item.state === 'string') ? item.state
                                : (item && typeof item.State === 'string') ? item.State
                                : '',
                            zip: (item && typeof item.zip === 'string') ? item.zip
                                : (item && typeof item.zipCode === 'string') ? item.zipCode
                                : (item && typeof item.postalCode === 'string') ? item.postalCode
                                : ''
                        };
                    });
                } else if (typeof additionalLocations === 'string' && additionalLocations.trim()) {
                    normalized.locationDetails.additionalLocations = [{ address: additionalLocations.trim(), city: '', state: '', zip: '' }];
                } else {
                    normalized.locationDetails.additionalLocations = [];
                }

                // formerLocations: relocation history. Each entry records a state the
                // facility used to operate in before moving — {state, city, address,
                // zip, fromYear, toYear}. Surfaced in both the old and new state hubs.
                const formerLocations = normalized.locationDetails.formerLocations;
                if (Array.isArray(formerLocations)) {
                    normalized.locationDetails.formerLocations = formerLocations
                        .map(item => {
                            if (typeof item === 'string') {
                                return { state: item, city: '', address: '', zip: '', fromYear: '', toYear: '' };
                            }
                            return {
                                state: (item && typeof item.state === 'string') ? item.state
                                    : (item && typeof item.State === 'string') ? item.State : '',
                                city: (item && typeof item.city === 'string') ? item.city : '',
                                address: (item && typeof item.address === 'string') ? item.address : '',
                                zip: (item && typeof item.zip === 'string') ? item.zip
                                    : (item && typeof item.zipCode === 'string') ? item.zipCode
                                    : (item && typeof item.postalCode === 'string') ? item.postalCode : '',
                                fromYear: (item && (typeof item.fromYear === 'string' || typeof item.fromYear === 'number')) ? String(item.fromYear) : '',
                                toYear: (item && (typeof item.toYear === 'string' || typeof item.toYear === 'number')) ? String(item.toYear) : ''
                            };
                        })
                        // Drop fully-empty rows so the form's auto-seeded blank entry never persists.
                        .filter(fl => fl.state || fl.city || fl.address || fl.zip || fl.fromYear || fl.toYear);
                } else {
                    normalized.locationDetails.formerLocations = [];
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
                websites: [], investors: [], owners: [], keyStaff: { ceo: "", founders: [], keyExecutives: [] },
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

    // ============================================
    // FACILITY DOCUMENT v2
    // ============================================
    // Port of inc/facility-store.php (docs/FACILITY-SCHEMA.md). The two must
    // agree field for field; scripts/check-facility-normalizer-parity.js runs
    // both over a database dump and diffs the output.
    //
    // The form keeps editing the legacy facility shape. v2 documents are
    // converted to it on load (facilityFromV2) and back on save (facilityToV2)
    // once the site runs the v2 data model (KOP_DATA_FORM_CONFIG.dataModel).

    const V2_SCHEMA_VERSION = 2;

    const V2_STATES = {
        AL: 'ALABAMA', AK: 'ALASKA', AZ: 'ARIZONA', AR: 'ARKANSAS', CA: 'CALIFORNIA',
        CO: 'COLORADO', CT: 'CONNECTICUT', DE: 'DELAWARE', DC: 'DISTRICT OF COLUMBIA',
        FL: 'FLORIDA', GA: 'GEORGIA', HI: 'HAWAII', ID: 'IDAHO', IL: 'ILLINOIS', IN: 'INDIANA',
        IA: 'IOWA', KS: 'KANSAS', KY: 'KENTUCKY', LA: 'LOUISIANA', ME: 'MAINE', MD: 'MARYLAND',
        MA: 'MASSACHUSETTS', MI: 'MICHIGAN', MN: 'MINNESOTA', MS: 'MISSISSIPPI', MO: 'MISSOURI',
        MT: 'MONTANA', NE: 'NEBRASKA', NV: 'NEVADA', NH: 'NEW HAMPSHIRE', NJ: 'NEW JERSEY',
        NM: 'NEW MEXICO', NY: 'NEW YORK', NC: 'NORTH CAROLINA', ND: 'NORTH DAKOTA', OH: 'OHIO',
        OK: 'OKLAHOMA', OR: 'OREGON', PA: 'PENNSYLVANIA', RI: 'RHODE ISLAND', SC: 'SOUTH CAROLINA',
        SD: 'SOUTH DAKOTA', TN: 'TENNESSEE', TX: 'TEXAS', UT: 'UTAH', VT: 'VERMONT', VA: 'VIRGINIA',
        WA: 'WASHINGTON', WV: 'WEST VIRGINIA', WI: 'WISCONSIN', WY: 'WYOMING',
        PR: 'PUERTO RICO', VI: 'VIRGIN ISLANDS', GU: 'GUAM'
    };
    const V2_STATE_BY_NAME = Object.keys(V2_STATES).reduce((acc, code) => {
        acc[V2_STATES[code]] = code;
        return acc;
    }, {});

    const V2_COUNTRIES = {
        'us': 'United States', 'usa': 'United States', 'u s': 'United States',
        'u s a': 'United States', 'united states': 'United States',
        'united states of america': 'United States', 'america': 'United States',
        'uk': 'United Kingdom', 'u k': 'United Kingdom', 'england': 'United Kingdom',
        'scotland': 'United Kingdom', 'wales': 'United Kingdom',
        'northern ireland': 'United Kingdom', 'great britain': 'United Kingdom',
        'jersey': 'United Kingdom', 'united kingdom': 'United Kingdom',
        'jerusalem': 'Israel', 'israel': 'Israel',
        'the netherlands': 'Netherlands', 'netherlands': 'Netherlands', 'holland': 'Netherlands',
        'argentina': 'Argentina', 'australia': 'Australia', 'canada': 'Canada',
        'costa rica': 'Costa Rica', 'czech republic': 'Czech Republic',
        'czechia': 'Czech Republic', 'dominican republic': 'Dominican Republic',
        'fiji': 'Fiji', 'italy': 'Italy', 'jamaica': 'Jamaica', 'mexico': 'Mexico',
        'new zealand': 'New Zealand', 'samoa': 'Samoa', 'western samoa': 'Samoa',
        'united arab emirates': 'United Arab Emirates', 'uae': 'United Arab Emirates',
        'germany': 'Germany', 'ireland': 'Ireland', 'spain': 'Spain',
        'portugal': 'Portugal', 'south africa': 'South Africa', 'kenya': 'Kenya',
        'india': 'India', 'philippines': 'Philippines', 'thailand': 'Thailand',
        'brazil': 'Brazil', 'peru': 'Peru', 'guatemala': 'Guatemala',
        'honduras': 'Honduras', 'belize': 'Belize', 'panama': 'Panama',
        'puerto rico': 'United States'
    };

    const V2_STATUSES = ['Open', 'Closed', 'Suspended', 'Transferred', 'Unknown'];

    const isPlainObject = (value) => value !== null && typeof value === 'object' && !Array.isArray(value);

    function v2Str(value) {
        if (typeof value === 'string') return value.trim();
        if (typeof value === 'number' && Number.isFinite(value)) return String(value).trim();
        if (typeof value === 'boolean') return value ? 'true' : 'false';
        return '';
    }

    function v2List(value) {
        if (value === null || value === undefined || value === '') return [];
        if (!Array.isArray(value) && !isPlainObject(value)) return [v2Str(value)];
        const items = Array.isArray(value) ? value : Object.values(value);
        const out = [];
        const seen = new Set();
        items.forEach((item) => {
            if (item === null || item === undefined) return;
            if (typeof item === 'object') {
                out.push(item);
                return;
            }
            const s = v2Str(item);
            if (s === '') return;
            const key = s.toLowerCase();
            if (seen.has(key)) return;
            seen.add(key);
            out.push(s);
        });
        return out;
    }

    function v2Map(value) {
        if (Array.isArray(value)) return value.length ? { _legacy: value.slice() } : {};
        if (!isPlainObject(value)) return {};
        return Object.keys(value).length ? value : {};
    }

    function v2Int(value, rejected, label) {
        if (value === null || value === undefined || value === '' || typeof value === 'object') return null;
        if (typeof value === 'number') return Number.isFinite(value) ? Math.trunc(value) : null;
        if (typeof value === 'boolean') return value ? 1 : null;
        const s = String(value).trim();
        if (s === '') return null;
        if (/^-?\d+$/.test(s)) return parseInt(s, 10);
        const note = () => { if (rejected && label) rejected.push(`${label}: ${s}`); };
        const year = s.match(/\d{4}/);
        if (year && parseInt(year[0], 10) > 1500 && parseInt(year[0], 10) < 2200) {
            note();
            return parseInt(year[0], 10);
        }
        const digits = s.match(/\d+/);
        note();
        return digits ? parseInt(digits[0], 10) : null;
    }

    function v2Bool(value) {
        if (value === null || value === undefined || value === '') return null;
        if (typeof value === 'boolean') return value;
        const s = String(value).trim().toLowerCase();
        if (['1', 'true', 'yes', 'y'].includes(s)) return true;
        if (['0', 'false', 'no', 'n'].includes(s)) return false;
        return null;
    }

    const NAME_LABELS = '(?:board\\s+chairperson|chairperson|administrator|executive\\s+director|director|owner|operator|date\\s+of\\s+site\\s+visit|site\\s+visit|visit\\s+date|inspection\\s+date|licensee|licensed\\s+capacity)';

    /** Same rules as kop_facility_name_key() / kop_normalize_facility_name_rules(). */
    function facilityNameKey(name) {
        let s = String(name === null || name === undefined ? '' : name).trim().toLowerCase();
        if (s === '') return '';

        const colon = s.indexOf(':');
        if (colon !== -1) {
            const before = s.slice(0, colon).trim();
            const after = s.slice(colon + 1).trim();
            const afterIsDate = /^\d{1,4}[/\-.]\d{1,2}(?:[/\-.]\d{2,4})?\b/.test(after);
            if (afterIsDate || new RegExp(NAME_LABELS + '$', 'iu').test(before)) {
                s = before;
            }
        }

        s = s.replace(new RegExp('\\s+' + NAME_LABELS + '.*$', 'iu'), '');
        s = s.replace(/\s+d\s*\/?\s*b\s*\/?\s*a\s+/gu, ' ');
        s = s.replace(/\s*[-–—]\s*/gu, ' ');
        s = s.replace(/\s*&\s*/g, ' and ');
        s = s.replace(/[^\p{L}\p{N}_\s]/gu, '');
        s = s.replace(/\s+/gu, ' ');
        s = s.trim();
        s = s.replace(/^the\s+/u, '');
        s = s.replace(/\s+(?:l\s*l\s*c|llc|inc|incorporated|ltd|limited|co|corp|corporation)$/u, '');
        return s.trim();
    }

    function facilityCityKey(city) {
        return String(city || '').trim().toLowerCase()
            .replace(/[^\p{L}\p{N}_\s]/gu, '')
            .replace(/\s+/gu, ' ')
            .trim();
    }

    function facilityStateCode(value) {
        if (typeof value !== 'string' && typeof value !== 'number') return null;
        let s = String(value).trim().toUpperCase();
        if (s === '') return null;
        s = s.replace(/[\s,]*\d{5}(?:-\d{4})?\s*$/, '');
        s = s.replace(/^[ \t.,]+|[ \t.,]+$/g, '');
        if (s === '') return null;
        if (V2_STATES[s]) return s;
        if (V2_STATE_BY_NAME[s]) return V2_STATE_BY_NAME[s];
        const collapsed = s.replace(/\./g, '').replace(/\s+/g, ' ');
        if (V2_STATES[collapsed]) return collapsed;
        if (V2_STATE_BY_NAME[collapsed]) return V2_STATE_BY_NAME[collapsed];
        return null;
    }

    function countryKey(value) {
        return value.toLowerCase().replace(/[^\p{L}\p{N}_\s]/gu, ' ').trim().replace(/\s+/gu, ' ');
    }

    function facilityCountryName(value) {
        const s = v2Str(value);
        if (s === '') return null;
        const key = countryKey(s);
        return Object.prototype.hasOwnProperty.call(V2_COUNTRIES, key) ? V2_COUNTRIES[key] : s;
    }

    function splitStreetCity(segment) {
        const text = String(segment || '').trim();
        const suffix = '(?:st|street|ave|avenue|rd|road|dr|drive|blvd|boulevard|ln|lane|way|hwy|highway|ct|court|pkwy|parkway|pl|place|cir|circle|trl|trail|loop|rte|route|pike|ter|terrace|sq|square)';
        const m = text.match(new RegExp('^(.*\\b' + suffix + '\\.?)\\s+([A-Za-z][A-Za-z .\'-]*)$', 'i'));
        if (m) {
            const city = m[2].trim();
            if (!/^(?:n|s|e|w|ne|nw|se|sw)\.?$/i.test(city)) {
                return [m[1].trim(), city];
            }
        }
        return [text, ''];
    }

    /** One-line address to {street, city, state, zip, country}. */
    function parseFacilityAddress(raw) {
        const out = { street: '', city: '', state: '', zip: '', country: '' };
        // A trailing bracketed note is not part of the address.
        const text = v2Str(raw).replace(/\s*\([^()]*\)\s*$/u, '').trim();
        if (text === '') return out;

        let parts = text.split(',').map((p) => p.trim()).filter((p) => p.length > 0);
        if (!parts.length) return out;

        let last = parts[parts.length - 1];
        if (parts.length > 1 && facilityStateCode(last) === null) {
            const key = countryKey(last);
            if (Object.prototype.hasOwnProperty.call(V2_COUNTRIES, key)) {
                out.country = V2_COUNTRIES[key];
                parts.pop();
                if (!parts.length) return out;
            }
        }

        last = parts[parts.length - 1];
        let m = last.match(/^(\d{5}(?:-\d{4})?)$/);
        if (m) {
            out.zip = m[1];
            parts.pop();
            if (!parts.length) return out;
            last = parts[parts.length - 1];
        } else if ((m = last.match(/^(.*?)\s+(\d{5}(?:-\d{4})?)$/))) {
            out.zip = m[2];
            last = m[1].trim();
            parts[parts.length - 1] = last;
        }

        let state = facilityStateCode(last);
        // "10503 Metric Dr Dallas TX 75243": no comma before the state. Only
        // read when a zip follows, so a street ending "Rd NE" is not Nebraska.
        const gm = (state === null && out.zip !== '') ? last.match(/^(.*\S)\s+([A-Z]{2})\.?$/) : null;
        if (gm && facilityStateCode(gm[2]) !== null) {
            state = facilityStateCode(gm[2]);
            parts.splice(parts.length - 1, 1, gm[1].trim(), gm[2]);
        }
        if (state !== null) {
            out.state = state;
            if (out.country === '') out.country = 'United States';
            parts.pop();
            if (parts.length) {
                out.city = parts.pop();
                out.street = parts.join(', ');
                if (out.street === '' && /^\d/.test(out.city)) {
                    [out.street, out.city] = splitStreetCity(out.city);
                }
            }
            return out;
        }

        if (parts.length === 1) {
            if (/^\d/.test(parts[0])) {
                out.street = parts[0];
            } else {
                out.city = parts[0];
            }
            return out;
        }
        out.city = parts.pop();
        out.street = parts.join(', ');
        return out;
    }

    /** Mirrors kop_facility_location_text_segments(). */
    function locationTextSegments(text) {
        const t = v2Str(text);
        if (t === '') return [];
        return t.split(/\s*[\/;|\n]\s*/u).map((p) => p.trim()).filter((p) => p.length > 0);
    }

    const escapeRegExp = (value) => value.replace(/[.*+?^${}()|[\]\\/-]/g, '\\$&');

    /**
     * The places a free-text location names. Mirrors
     * kop_facility_location_text_places(): "La Verne, CA" is California only,
     * "Viera, FL / Rutland, MA" is Florida and Massachusetts.
     */
    function locationTextPlaces(text) {
        const places = [];
        locationTextSegments(text).forEach((segment) => {
            const place = { raw: segment, city: '', state: null, country: null, country_raw: null };
            const parsed = parseFacilityAddress(segment);

            if (parsed.state !== '') {
                places.push({ ...place, state: parsed.state, country: 'United States', city: parsed.city });
                return;
            }
            if (parsed.country !== '') {
                const pieces = segment.split(',').map((p) => p.trim());
                places.push({ ...place, country: parsed.country, country_raw: pieces[pieces.length - 1], city: parsed.city });
                return;
            }
            const codeMatch = segment.match(/(?:^|[\s,])([A-Z]{2})(?:\s+\d{5}(?:-\d{4})?)?\s*$/);
            if (codeMatch && facilityStateCode(codeMatch[1]) !== null) {
                places.push({
                    ...place,
                    state: codeMatch[1],
                    country: 'United States',
                    city: segment.replace(/[\s,]*[A-Z]{2}(?:\s+\d{5}(?:-\d{4})?)?\s*$/, '').trim()
                });
                return;
            }
            const segmentKey = countryKey(segment);
            if (Object.prototype.hasOwnProperty.call(V2_COUNTRIES, segmentKey)) {
                places.push({ ...place, country: V2_COUNTRIES[segmentKey], country_raw: segment });
                return;
            }

            let haystack = ` ${segmentKey} `;
            const names = [];
            Object.keys(V2_STATES).forEach((code) => names.push([V2_STATES[code].toLowerCase(), code, null]));
            Object.keys(V2_COUNTRIES).forEach((alias) => {
                if (alias.length >= 4 && V2_COUNTRIES[alias] !== 'United States') names.push([alias, null, V2_COUNTRIES[alias]]);
            });
            names.sort((a, b) => b[0].length - a[0].length);
            names.forEach(([name, code, country]) => {
                const pattern = new RegExp(`(?<!new |west |baja )(?<= )${escapeRegExp(name)}(?= )(?! city )`, 'u');
                if (!pattern.test(haystack)) return;
                haystack = haystack.replace(new RegExp(`(?<= )${escapeRegExp(name)}(?= )`, 'u'), '_'.repeat(name.length));
                if (code !== null) {
                    places.push({ ...place, state: code, country: 'United States' });
                } else {
                    places.push({ ...place, country, country_raw: name });
                }
            });
        });
        return places;
    }

    /** Mirrors kop_facility_location_key(). */
    function facilityLocationKey(state, country) {
        const code = facilityStateCode(state === null || state === undefined ? '' : state);
        if (code !== null) return V2_STATES[code];
        const name = facilityCountryName(country !== null && country !== undefined ? country : state);
        if (name !== null && name !== '' && name !== 'United States') return name.toUpperCase();
        return null;
    }

    function normalizeFacilityStatus(raw) {
        const s = String(raw === null || raw === undefined ? '' : raw).trim();
        if (s === '') return { status: 'Unknown', note: '' };
        const direct = {
            open: 'Open', closed: 'Closed', suspended: 'Suspended', transferred: 'Transferred',
            unknown: 'Unknown', operating: 'Open', active: 'Open', defunct: 'Closed',
            'shut down': 'Closed', shutdown: 'Closed'
        };
        const hit = direct[s.toLowerCase()];
        if (hit) return { status: hit, note: '' };
        return { status: 'Unknown', note: `migration: original status "${s}"` };
    }

    function phpIsoNow() {
        return new Date().toISOString().replace(/\.\d{3}Z$/, '+00:00');
    }

    function blankFacilityV2() {
        return {
            schema_version: V2_SCHEMA_VERSION,
            facility_id: null,
            identification: {
                name: '', nameKey: '', currentName: '', otherNames: [], pastNames: [],
                currentOperator: '', currentOwners: [], otherOperators: [], pastOperators: [],
                knownReferrers: [], investors: []
            },
            location: {
                raw: '', text: '', street: '', city: '', state: null, zip: '', country: null,
                additionalLocations: [], formerLocations: []
            },
            operatingPeriod: { startYear: null, endYear: null, status: 'Unknown', yearsOfOperation: '', notes: [] },
            facilityDetails: {
                type: '', capacity: null, currentCensus: null, ageRange: { min: null, max: null },
                gender: '', isPrivatelyOwned: null
            },
            staff: { administrator: [], notableStaff: [], pastTTIJobs: [] },
            accreditations: { current: [], past: [] },
            memberships: [], certifications: [], licensing: [], profileLinks: [],
            resources: {}, treatmentTypes: {}, philosophy: {}, conditions: {}, criticalIncidents: {},
            notes: [], fieldNotes: {},
            documentFolderId: null,
            provenance: {
                sourceProject: '', sourceProjectId: null, sourceCategory: '', sourceOperator: null,
                legacyIds: [], linkedFromRef: false, kopProfileVersion: null, migratedAt: '',
                uniqueName: '', source: ''
            },
            legacy: {}
        };
    }

    const V2_KNOWN_KEYS = new Set([
        'identification', 'locationDetails', 'addressParts', 'address', 'location',
        'operatingPeriod', 'facilityDetails', 'staff', 'accreditations', 'memberships',
        'certifications', 'licensing', 'profileLinks', 'resources', 'treatmentTypes',
        'philosophy', 'conditions', 'criticalIncidents', 'notes', 'fieldNotes',
        'documentFolderId', 'otherOperators', 'pastOperators', 'investors',
        'isPrivatelyOwned', 'sourceProject', 'sourceProjectId', 'sourceCategory',
        'sourceOperator', 'linkedFromRef', 'kopProfileVersion', 'facility_id',
        'name', 'displayName', 'city', 'state', 'timestamp',
        'schema_version', 'provenance', 'legacy', '__facility_ref', 'data'
    ]);

    function isV2Facility(facility) {
        return isPlainObject(facility) && Number(facility.schema_version) >= V2_SCHEMA_VERSION && isPlainObject(facility.location);
    }

    /**
     * Any facility shape to a v2 document. Mirrors kop_facility_normalize();
     * `opts` takes facility_id, unique_name, location_key and source.
     */
    // ---- Field standards (docs/FACILITY-SCHEMA.md, "Standard shapes") ----

    /** Mirrors kop_facility_person_list(). */
    function v2PersonList(value) {
        const out = [];
        v2List(value).forEach((item) => {
            let entry;
            if (item !== null && typeof item === 'object') {
                const jobs = item.pastJobs !== undefined ? item.pastJobs : '';
                entry = {
                    name: v2Str(item.name !== undefined && item.name !== null ? item.name : item.label),
                    role: v2Str(item.role !== undefined && item.role !== null ? item.role : item.title),
                    pastJobs: Array.isArray(jobs) || isPlainObject(jobs) ? v2List(jobs).join('; ') : v2Str(jobs)
                };
            } else {
                entry = { name: v2Str(item), role: '', pastJobs: '' };
            }
            if (entry.name === '' && entry.role === '' && entry.pastJobs === '') return;
            out.push(entry);
        });
        return out;
    }

    /** Mirrors kop_facility_job_list(). */
    function v2JobList(value) {
        const out = [];
        v2List(value).forEach((item) => {
            let org;
            let role;
            if (item !== null && typeof item === 'object') {
                org = v2Str(item.organization);
                if (org === '') org = v2Str(item.employer);
                if (org === '') org = v2Str(item.name);
                role = v2Str(item.role);
            } else {
                org = v2Str(item);
                role = '';
            }
            if (org === '' && role === '') return;
            out.push({ role, organization: org, employer: org });
        });
        return out;
    }

    /** Mirrors kop_facility_link_list(). */
    function v2LinkList(value) {
        return v2List(v2List(value).map((item) => {
            if (item !== null && typeof item === 'object') {
                return v2Str(item.url !== undefined && item.url !== null ? item.url
                    : (item.href !== undefined && item.href !== null ? item.href : item.link));
            }
            return item;
        }));
    }

    const V2_RESOURCE_KEYS = {
        hasNews: false, newsDetails: '', hasPressReleases: false, pressReleasesDetails: '',
        hasInspections: false, hasStateReports: false, hasRegulatoryFilings: false,
        hasViolations: false, hasSettlements: false, hasLawsuits: false,
        hasPoliceReports: false, hasArticlesOfOrganization: false, hasPropertyRecords: false,
        hasPromotionalMaterials: false, hasEnrollmentDocuments: false, hasResearch: false,
        hasFinancial: false, hasStudent: false, studentDetails: '', hasStaff: false,
        hasParent: false, hasWebsite: false, hasSocialMedia: false, hasAudio: false,
        hasVideo: false, hasNATSAP: false, hasSurvivorStories: false, hasOther: false,
        customResources: [], notes: []
    };

    /** Mirrors kop_facility_resources(). */
    function v2Resources(value) {
        const input = v2Map(value);
        const keys = Object.keys(V2_RESOURCE_KEYS);
        Object.keys(input).forEach((k) => { if (!keys.includes(k)) keys.push(k); });
        const out = {};
        keys.forEach((key) => {
            const v = Object.prototype.hasOwnProperty.call(input, key) ? input[key] : V2_RESOURCE_KEYS[key];
            if (key === 'customResources' || key === 'notes' || key === '_legacy') {
                out[key] = v2List(v);
            } else if (key.indexOf('has') === 0) {
                out[key] = Boolean(v2Bool(v));
            } else if (key.slice(-7) === 'Details') {
                out[key] = v2Str(v);
            } else {
                out[key] = v;
            }
        });
        return out;
    }

    const V2_GENDER_WORDS = {
        male: 'Male', males: 'Male', boy: 'Male', boys: 'Male', men: 'Male',
        female: 'Female', females: 'Female', girl: 'Female', girls: 'Female', women: 'Female',
        'co-ed': 'Co-ed', coed: 'Co-ed', 'co ed': 'Co-ed', all: 'Co-ed', both: 'Co-ed', mixed: 'Co-ed'
    };

    /** Mirrors kop_facility_gender(): {gender, note}. */
    function v2Gender(raw) {
        const s = v2Str(raw);
        if (s === '') return { gender: '', note: '' };
        const key = s.toLowerCase();
        if (Object.prototype.hasOwnProperty.call(V2_GENDER_WORDS, key)) return { gender: V2_GENDER_WORDS[key], note: '' };
        let gender = '';
        const m = s.match(/\b(co-?ed|co ed|all genders|both|mixed|female|females|girls?|women|male|males|boys?|men)\b/i);
        if (m) {
            const word = m[1].toLowerCase();
            gender = Object.prototype.hasOwnProperty.call(V2_GENDER_WORDS, word) ? V2_GENDER_WORDS[word]
                : ((/^co/i.test(m[1]) || ['all genders', 'both', 'mixed'].includes(word)) ? 'Co-ed' : '');
        }
        return { gender, note: 'Gender as recorded: ' + s };
    }

    const V2_TYPE_SYNONYMS = {
        'rtc': 'Residential Treatment Center',
        'residential treatment center (rtc)': 'Residential Treatment Center',
        'residential treatment facility': 'Residential Treatment Center',
        'prtf': 'Psychiatric Residential Treatment Facility',
        'psychiatric residential treatment facility (prtf)': 'Psychiatric Residential Treatment Facility',
        'wilderness': 'Wilderness Therapy',
        'wilderness therapy program': 'Wilderness Therapy',
        'wilderness program': 'Wilderness Therapy',
        'juvenile justice residential treatment center': 'Juvenile Justice RTC',
        'therapeutic residential school': 'Therapeutic Boarding School',
        'tbs': 'Therapeutic Boarding School'
    };

    /** Mirrors kop_facility_type(). */
    function v2Type(raw) {
        const s = v2Str(raw);
        const key = s.replace(/\s+/gu, ' ').toLowerCase();
        return Object.prototype.hasOwnProperty.call(V2_TYPE_SYNONYMS, key) ? V2_TYPE_SYNONYMS[key] : s;
    }

    /** Mirrors kop_facility_operator_block(). */
    function v2OperatorBlock(value) {
        if (value === null || typeof value !== 'object' || Array.isArray(value) || !Object.keys(value).length) return null;
        const staff = isPlainObject(value.keyStaff) ? value.keyStaff : {};
        const get = (k) => (value[k] !== undefined ? value[k] : '');
        const out = {
            name: v2Str(get('name')),
            currentName: v2Str(get('currentName')),
            otherNames: v2List(value.otherNames),
            founded: v2Str(get('founded')),
            headquarters: v2Str(get('headquarters')),
            headquartersCity: v2Str(get('headquartersCity')),
            headquartersState: v2Str(get('headquartersState')),
            location: v2Str(get('location')),
            locationCity: v2Str(get('locationCity')),
            locationState: v2Str(get('locationState')),
            operatingPeriod: v2Str(get('operatingPeriod')),
            status: v2Str(get('status')),
            websites: v2LinkList(value.websites),
            parentCompanies: v2List(value.parentCompanies),
            owners: v2List(value.owners),
            investors: v2List(value.investors),
            keyStaff: {
                ceo: v2Str(staff.ceo !== undefined ? staff.ceo : ''),
                founders: v2PersonList(staff.founders),
                keyExecutives: v2PersonList(staff.keyExecutives)
            },
            notes: v2List(value.notes),
            fieldNotes: v2List(value.fieldNotes)
        };
        Object.keys(staff).forEach((k) => { if (!(k in out.keyStaff)) out.keyStaff[k] = staff[k]; });
        Object.keys(value).forEach((k) => { if (!(k in out)) out[k] = value[k]; });
        return out;
    }

    /** Mirrors kop_facility_years_from_text(): [start, end]. */
    function v2YearsFromText(text) {
        const m = v2Str(text).match(/^(\d{4})(?:\s*[-–—]\s*(\d{4}|present|current|now)?)?$/iu);
        if (!m) return [null, null];
        return [parseInt(m[1], 10), (m[2] && /^\d+$/.test(m[2])) ? parseInt(m[2], 10) : null];
    }

    function facilityToV2(raw, opts) {
        opts = opts || {};
        let f = isPlainObject(raw) ? raw : {};
        let wrapper = {};
        if (f.__facility_ref && isPlainObject(f.data)) {
            wrapper = {
                unique_name: v2Str(f.name), displayName: v2Str(f.displayName),
                city: v2Str(f.city), state: typeof f.state === 'string' ? f.state.trim() : ''
            };
            let inner = f.data;
            if (isPlainObject(inner.data) && !inner.facility) inner = inner.data;
            if (isPlainObject(inner.facility)) f = inner.facility;
            else if (Array.isArray(inner.facilities) && isPlainObject(inner.facilities[0])) f = inner.facilities[0];
            else f = {};
        }

        const doc = blankFacilityV2();
        const isV2 = f.schema_version !== undefined && Number(f.schema_version) >= V2_SCHEMA_VERSION;
        const rejected = [];

        const ident = isPlainObject(f.identification) ? f.identification : {};
        let name = v2Str(ident.name);
        if (name === '') name = v2Str(ident.currentName);
        if (name === '') name = v2Str(f.name);
        if (name === '') name = v2Str(wrapper.displayName);
        if (name === '') name = v2Str(wrapper.unique_name);

        doc.identification.name = name;
        doc.identification.nameKey = facilityNameKey(name);
        doc.identification.currentName = v2Str(ident.currentName);
        // currentName that only repeats the name says nothing.
        if (doc.identification.currentName.toLowerCase() === name.toLowerCase()) doc.identification.currentName = '';
        doc.identification.otherNames = v2List(ident.otherNames);
        doc.identification.pastNames = v2List(v2List(ident.pastNames).concat(v2List(ident.previousNames)));
        doc.identification.currentOperator = v2Str(ident.currentOperator);
        const owners = v2List(ident.currentOwners);
        const singleOwner = v2Str(ident.currentOwner);
        if (singleOwner !== '') owners.push(singleOwner);
        doc.identification.currentOwners = v2List(owners);
        doc.identification.knownReferrers = v2List(ident.knownReferrers);
        doc.identification.otherOperators = v2List(f.otherOperators !== undefined ? f.otherOperators : ident.otherOperators);
        doc.identification.pastOperators = v2List(f.pastOperators !== undefined ? f.pastOperators : ident.pastOperators);
        doc.identification.investors = v2List(f.investors !== undefined ? f.investors : ident.investors);

        const details = isPlainObject(f.locationDetails) ? f.locationDetails : {};
        const parts = isPlainObject(f.addressParts) ? f.addressParts : {};
        const addrObj = isPlainObject(f.address) ? f.address : {};
        let addrRaw = typeof f.address === 'string' ? f.address.trim() : '';
        const v2Loc = isV2 && isPlainObject(f.location) ? f.location : {};
        const hasV2Loc = Object.keys(v2Loc).length > 0;
        const locText = typeof f.location === 'string' ? f.location.trim() : v2Str(v2Loc.text);

        if (addrRaw === '' && hasV2Loc) addrRaw = v2Str(v2Loc.raw);
        const textPlaces = locationTextPlaces(locText);
        const textSegments = locationTextSegments(locText);
        const parsed = parseFacilityAddress(addrRaw !== '' ? addrRaw : (textSegments[0] || ''));
        if (addrRaw === '' && parsed.state === '' && parsed.country === '' && textPlaces.length) {
            parsed.state = textPlaces[0].state || '';
            parsed.country = textPlaces[0].country || '';
            parsed.city = textPlaces[0].city;
        }

        const firstNonEmpty = (...values) => {
            for (const v of values) {
                if (v !== '') return v;
            }
            return '';
        };

        const street = firstNonEmpty(v2Str(addrObj.street), v2Str(parts.street), v2Str(v2Loc.street), parsed.street);
        const city = firstNonEmpty(v2Str(addrObj.city), v2Str(parts.city), v2Str(details.city), v2Str(v2Loc.city), parsed.city, v2Str(wrapper.city));

        let state = facilityStateCode(parsed.state);
        if (state === null) state = facilityStateCode(addrObj.state !== undefined ? addrObj.state : '');
        if (state === null) state = facilityStateCode(parts.state !== undefined ? parts.state : '');
        if (state === null) state = facilityStateCode(details.state !== undefined ? details.state : '');
        if (state === null && hasV2Loc) state = facilityStateCode(v2Loc.state !== undefined && v2Loc.state !== null ? v2Loc.state : '');
        if (state === null) state = facilityStateCode(wrapper.state !== undefined ? wrapper.state : '');
        if (state === null && opts.location_key) state = facilityStateCode(opts.location_key);

        const zip = firstNonEmpty(v2Str(addrObj.zip), v2Str(parts.zip), v2Str(details.zip), v2Str(v2Loc.zip), parsed.zip);

        let country = facilityCountryName(details.country);
        if (country === null) country = facilityCountryName(addrObj.country);
        if (country === null && hasV2Loc) country = facilityCountryName(v2Loc.country);
        if (country === null && parsed.country !== '') country = parsed.country;
        if (country === null && state !== null) country = 'United States';
        if (country === null) {
            const fromKey = facilityCountryName(opts.location_key);
            if (fromKey !== null && facilityStateCode(opts.location_key || '') === null) country = fromKey;
        }
        if (country === null && wrapper.state && facilityStateCode(wrapper.state) === null) {
            country = facilityCountryName(wrapper.state);
        }

        Object.assign(doc.location, { raw: addrRaw, text: locText, street, city, state, zip, country });

        const additional = details.additionalLocations !== undefined ? details.additionalLocations : v2Loc.additionalLocations;
        if (Array.isArray(additional)) {
            additional.forEach((alt) => {
                if (!isPlainObject(alt)) {
                    const altRaw = v2Str(alt);
                    if (altRaw === '') return;
                    alt = { address: altRaw };
                }
                const altRaw = v2Str(alt.raw !== undefined ? alt.raw : alt.address);
                const altParsed = parseFacilityAddress(altRaw);
                const entry = {
                    raw: altRaw,
                    // Mirrors the PHP: re-derived from raw when there is one.
                    street: altRaw !== '' ? altParsed.street : v2Str(alt.street),
                    city: v2Str(alt.city) || altParsed.city,
                    state: facilityStateCode(alt.state !== undefined && alt.state !== null ? alt.state : '') || facilityStateCode(altParsed.state),
                    zip: v2Str(alt.zip) || altParsed.zip,
                    country: null
                };
                const altCountry = facilityCountryName(alt.country);
                entry.country = altCountry !== null ? altCountry
                    : (altParsed.country !== '' ? altParsed.country : (entry.state !== null ? 'United States' : null));
                if (entry.raw === '' && entry.street === '' && entry.city === '') return;
                doc.location.additionalLocations.push(entry);
            });
        }

        const listed = new Set([String(facilityLocationKey(state, country))]);
        doc.location.additionalLocations.forEach((alt) => listed.add(String(facilityLocationKey(alt.state, alt.country))));
        textPlaces.forEach((place) => {
            const key = facilityLocationKey(place.state, place.country);
            if (key === null || listed.has(key)) return;
            listed.add(key);
            const placeParsed = parseFacilityAddress(place.raw);
            doc.location.additionalLocations.push({
                raw: place.raw,
                street: placeParsed.street,
                city: placeParsed.city,
                state: place.state,
                zip: placeParsed.zip,
                country: place.country
            });
        });

        const former = details.formerLocations !== undefined ? details.formerLocations : v2Loc.formerLocations;
        if (Array.isArray(former)) {
            former.forEach((fl) => {
                if (!isPlainObject(fl)) return;
                const flRaw = v2Str(fl.raw !== undefined ? fl.raw : fl.address);
                const flParsed = parseFacilityAddress(flRaw);
                const entry = {
                    raw: flRaw,
                    city: v2Str(fl.city) || flParsed.city,
                    state: facilityStateCode(fl.state !== undefined && fl.state !== null ? fl.state : '') || facilityStateCode(flParsed.state),
                    country: facilityCountryName(fl.country),
                    fromYear: v2Int(fl.fromYear),
                    toYear: v2Int(fl.toYear)
                };
                if (entry.state === null && entry.country === null && entry.raw === '' && entry.city === '') return;
                doc.location.formerLocations.push(entry);
            });
        }

        const op = isPlainObject(f.operatingPeriod) ? f.operatingPeriod : {};
        doc.operatingPeriod.startYear = v2Int(op.startYear, rejected, 'startYear');
        doc.operatingPeriod.endYear = v2Int(op.endYear, rejected, 'endYear');
        doc.operatingPeriod.yearsOfOperation = v2Str(op.yearsOfOperation);
        doc.operatingPeriod.notes = v2List(op.notes);
        const status = normalizeFacilityStatus(v2Str(op.status));
        doc.operatingPeriod.status = status.status;
        if (status.note !== '') doc.operatingPeriod.notes.push(status.note);

        // Mirrors the PHP: an end year only for a closed facility whose start agrees.
        const [textStart, textEnd] = v2YearsFromText(doc.operatingPeriod.yearsOfOperation);
        if (doc.operatingPeriod.startYear === null && textStart !== null) doc.operatingPeriod.startYear = textStart;
        if (doc.operatingPeriod.endYear === null && textEnd !== null && status.status === 'Closed'
            && doc.operatingPeriod.startYear === textStart) {
            doc.operatingPeriod.endYear = textEnd;
        }

        const fd = isPlainObject(f.facilityDetails) ? f.facilityDetails : {};
        const age = isPlainObject(fd.ageRange) ? fd.ageRange : {};
        doc.facilityDetails.type = v2Type(fd.type);
        doc.facilityDetails.capacity = v2Int(fd.capacity, rejected, 'capacity');
        doc.facilityDetails.currentCensus = v2Int(fd.currentCensus, rejected, 'currentCensus');
        doc.facilityDetails.ageRange.min = v2Int(age.min, rejected, 'ageRange.min');
        doc.facilityDetails.ageRange.max = v2Int(age.max, rejected, 'ageRange.max');
        const gender = v2Gender(fd.gender);
        doc.facilityDetails.gender = gender.gender;
        doc.facilityDetails.isPrivatelyOwned = v2Bool(f.isPrivatelyOwned !== undefined ? f.isPrivatelyOwned : fd.isPrivatelyOwned);

        const staff = isPlainObject(f.staff) ? f.staff : {};
        doc.staff.administrator = v2PersonList(staff.administrator);
        doc.staff.notableStaff = v2PersonList(staff.notableStaff);
        doc.staff.pastTTIJobs = v2JobList(staff.pastTTIJobs);

        const acc = isPlainObject(f.accreditations) ? f.accreditations : {};
        doc.accreditations.current = v2List(acc.current);
        doc.accreditations.past = v2List(acc.past);

        doc.memberships = v2List(f.memberships);
        doc.certifications = v2List(f.certifications);
        doc.licensing = v2List(f.licensing);
        doc.profileLinks = v2LinkList(f.profileLinks);
        doc.notes = v2List(f.notes);
        if (gender.note !== '') doc.notes = v2List(doc.notes.concat([gender.note]));

        doc.resources = v2Resources(f.resources);

        doc.treatmentTypes = v2Map(f.treatmentTypes);
        doc.philosophy = v2Map(f.philosophy);
        doc.conditions = v2Map(f.conditions);
        doc.criticalIncidents = v2Map(f.criticalIncidents);
        doc.fieldNotes = v2Map(f.fieldNotes);
        doc.documentFolderId = v2Int(f.documentFolderId);

        const prov = isV2 && isPlainObject(f.provenance) ? f.provenance : {};
        const pick = (legacyValue, v2Value) => (legacyValue !== undefined && legacyValue !== null ? legacyValue : v2Value);
        doc.provenance.sourceProject = v2Str(pick(f.sourceProject, prov.sourceProject));
        doc.provenance.sourceProjectId = v2Int(pick(f.sourceProjectId, prov.sourceProjectId));
        doc.provenance.sourceCategory = v2Str(pick(f.sourceCategory, prov.sourceCategory));
        const sourceOperator = pick(f.sourceOperator, prov.sourceOperator);
        doc.provenance.sourceOperator = v2OperatorBlock(sourceOperator);
        doc.provenance.linkedFromRef = Boolean(pick(f.linkedFromRef, prov.linkedFromRef));
        doc.provenance.kopProfileVersion = v2Int(pick(f.kopProfileVersion, prov.kopProfileVersion));
        doc.provenance.legacyIds = Array.from(new Set(
            v2List(prov.legacyIds).filter((v) => typeof v !== 'object' && v !== '' && !isNaN(Number(v))).map((v) => parseInt(v, 10))
        ));
        doc.provenance.migratedAt = v2Str(prov.migratedAt) || phpIsoNow();
        if (opts.source) doc.provenance.source = v2Str(opts.source);

        doc.facility_id = v2Int(opts.facility_id !== undefined && opts.facility_id !== null ? opts.facility_id : f.facility_id);
        if (opts.unique_name) {
            doc.provenance.uniqueName = v2Str(opts.unique_name);
        } else if (wrapper.unique_name) {
            doc.provenance.uniqueName = wrapper.unique_name;
        }

        Object.keys(f).forEach((key) => {
            if (V2_KNOWN_KEYS.has(key)) return;
            doc.legacy[key] = f[key];
        });
        if (isV2 && isPlainObject(f.legacy)) {
            doc.legacy = { ...f.legacy, ...doc.legacy };
        }

        rejected.forEach((note) => doc.operatingPeriod.notes.push(`migration: unparsed ${note}`));
        doc.operatingPeriod.notes = v2List(doc.operatingPeriod.notes);

        return doc;
    }

    /**
     * v2 document to the legacy facility shape the form edits. Mirrors
     * kop_facility_to_legacy() in inc/facility-store.php.
     */
    function facilityFromV2(doc) {
        const loc = doc.location || {};
        const ident = doc.identification || {};
        const stateCode = loc.state && V2_STATES[loc.state] ? loc.state : '';
        const legacy = {
            identification: {
                name: ident.name || '',
                currentName: ident.currentName || '',
                otherNames: ident.otherNames || [],
                pastNames: ident.pastNames || [],
                currentOperator: ident.currentOperator || '',
                currentOwner: (ident.currentOwners && ident.currentOwners[0]) || '',
                currentOwners: ident.currentOwners || [],
                knownReferrers: ident.knownReferrers || []
            },
            otherOperators: ident.otherOperators || [],
            pastOperators: ident.pastOperators || [],
            investors: ident.investors || [],
            address: loc.raw || '',
            addressParts: { street: loc.street || '', city: loc.city || '', state: stateCode, zip: loc.zip || '' },
            location: loc.text || '',
            locationDetails: {
                city: loc.city || '',
                state: stateCode,
                country: loc.country || '',
                zip: loc.zip || '',
                additionalLocations: (loc.additionalLocations || []).map((alt) => ({
                    address: alt.raw || '', city: alt.city || '', state: alt.state || '', zip: alt.zip || '', country: alt.country || ''
                })),
                formerLocations: (loc.formerLocations || []).map((fl) => ({
                    state: fl.state || '', city: fl.city || '', address: fl.raw || '', zip: '',
                    fromYear: fl.fromYear === null || fl.fromYear === undefined ? '' : String(fl.fromYear),
                    toYear: fl.toYear === null || fl.toYear === undefined ? '' : String(fl.toYear)
                }))
            },
            operatingPeriod: { ...(doc.operatingPeriod || {}) },
            facilityDetails: {
                type: (doc.facilityDetails || {}).type || '',
                capacity: (doc.facilityDetails || {}).capacity ?? null,
                currentCensus: (doc.facilityDetails || {}).currentCensus ?? null,
                ageRange: (doc.facilityDetails || {}).ageRange || { min: null, max: null },
                gender: (doc.facilityDetails || {}).gender || ''
            },
            staff: doc.staff || { administrator: [], notableStaff: [], pastTTIJobs: [] },
            accreditations: doc.accreditations || { current: [], past: [] },
            memberships: doc.memberships || [],
            certifications: doc.certifications || [],
            licensing: doc.licensing || [],
            profileLinks: doc.profileLinks || [],
            resources: doc.resources || {},
            treatmentTypes: doc.treatmentTypes || {},
            philosophy: doc.philosophy || {},
            conditions: doc.conditions || {},
            criticalIncidents: doc.criticalIncidents || {},
            notes: doc.notes || [],
            fieldNotes: doc.fieldNotes || {}
        };
        delete legacy.operatingPeriod.schema_version;

        if (doc.facility_id !== null && doc.facility_id !== undefined) legacy.facility_id = doc.facility_id;
        if (doc.documentFolderId !== null && doc.documentFolderId !== undefined) legacy.documentFolderId = doc.documentFolderId;
        const privatelyOwned = (doc.facilityDetails || {}).isPrivatelyOwned;
        if (privatelyOwned !== null && privatelyOwned !== undefined) legacy.isPrivatelyOwned = privatelyOwned;
        const prov = doc.provenance || {};
        if (prov.sourceProject) legacy.sourceProject = prov.sourceProject;
        if (prov.sourceCategory) legacy.sourceCategory = prov.sourceCategory;
        if (prov.sourceOperator) legacy.sourceOperator = prov.sourceOperator;
        Object.keys(doc.legacy || {}).forEach((key) => {
            if (!(key in legacy)) legacy[key] = doc.legacy[key];
        });
        return legacy;
    }

    /** True when the page runs the v2 data model (set by PHP from phase 3). */
    function isV2DataModel() {
        const config = window.KOP_DATA_FORM_CONFIG || {};
        return config.dataModel === 'v2';
    }

    // Expose the public API
    window.KOP_DataNormalizer = {
        normalizeProjectData: normalizeProjectData,
        facilityToV2: facilityToV2,
        facilityFromV2: facilityFromV2,
        isV2Facility: isV2Facility,
        isV2DataModel: isV2DataModel,
        facilityNameKey: facilityNameKey,
        facilityCityKey: facilityCityKey,
        facilityStateCode: facilityStateCode,
        facilityCountryName: facilityCountryName,
        locationTextPlaces: locationTextPlaces,
        parseFacilityAddress: parseFacilityAddress,
        normalizeFacilityStatus: normalizeFacilityStatus,
        V2_STATUSES: V2_STATUSES
    };
})();