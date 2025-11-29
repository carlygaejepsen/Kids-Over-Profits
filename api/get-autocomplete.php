<?php
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$rawCategory = isset($_GET['category']) ? trim($_GET['category']) : '';
$query = isset($_GET['q']) ? trim($_GET['q']) : '';
$limitParam = isset($_GET['limit']) ? (int) $_GET['limit'] : 0;

if ($rawCategory === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Missing category parameter'
    ]);
    exit;
}

$categoryAliases = [
    'operators' => 'operator',
    'facilities' => 'facility',
    'facilityname' => 'facility',
    'facilitynames' => 'facility',
    'humans' => 'human',
    'people' => 'human',
    'staff' => 'human',
    'referrers' => 'referrer',
    'facilitytype' => 'type',
    'facilitytypes' => 'type',
    'types' => 'type',
    'statuses' => 'status',
    'genders' => 'gender',
    'locations' => 'location',
    'licences' => 'licensing',
    'licenses' => 'licensing',
    'licensing' => 'licensing',
    'accreditations' => 'accreditation',
    'memberships' => 'membership',
    'certifications' => 'certification',
    'investors' => 'investor',
    'roles' => 'role',
    'staffroles' => 'role',
    'operatingperiods' => 'operatingperiod',
    'operatingperiod' => 'operatingperiod',
    'operating_period' => 'operatingperiod',
    'operationyears' => 'operatingperiod',
    'operatingyears' => 'operatingperiod',
    'operation_years' => 'operatingperiod',
    'yearsofoperation' => 'operatingperiod',
    'years_of_operation' => 'operatingperiod'
];

$category = strtolower($rawCategory);
if (isset($categoryAliases[$category])) {
    $category = $categoryAliases[$category];
}

$allowedCategories = [
    'operator',
    'facility',
    'human',
    'referrer',
    'type',
    'status',
    'gender',
    'location',
    'licensing',
    'membership',
    'accreditation',
    'certification',
    'investor',
    'role',
    'operatingperiod'
];

if (!in_array($category, $allowedCategories, true)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Unsupported category parameter'
    ]);
    exit;
}

$maxResults = $limitParam > 0 ? max(1, min(200, $limitParam)) : 50;

function normalize_project_payload($json)
{
    if (!$json) {
        return null;
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return null;
    }

    $maybeData = $decoded['data'] ?? null;
    if (is_string($maybeData)) {
        $parsed = json_decode($maybeData, true);
        if (is_array($parsed)) {
            $decoded['data'] = $parsed;
        }
    }

    if (isset($decoded['data']) && is_array($decoded['data'])) {
        $data = $decoded['data'];
        // Legacy: facilities might be stringified or nested
        if (isset($data['facilities']) && is_string($data['facilities'])) {
            $parsedFacilities = json_decode($data['facilities'], true);
            if (is_array($parsedFacilities)) {
                $data['facilities'] = $parsedFacilities;
            }
        } elseif (isset($data['facilities']['facilities']) && is_array($data['facilities']['facilities'])) {
            $data['facilities'] = $data['facilities']['facilities'];
        } elseif (isset($data['facility']) && is_array($data['facility'])) {
            $data['facilities'] = $data['facility'];
        } elseif (isset($data['facility']) && is_string($data['facility'])) {
            $parsedFacilities = json_decode($data['facility'], true);
            if (is_array($parsedFacilities)) {
                $data['facilities'] = $parsedFacilities;
            }
        }
        return $data;
    }

    if (isset($decoded['project']) && is_array($decoded['project'])) {
        $project = $decoded['project'];
        if (isset($project['data']) && is_array($project['data'])) {
            return $project['data'];
        }
        if (isset($project['operator']) || isset($project['facilities'])) {
            return $project;
        }
    }

    if (isset($decoded['operator']) || isset($decoded['facilities'])) {
        return $decoded;
    }

    return null;
}

function add_value(array &$set, $value)
{
    if ($value === null) {
        return;
    }

    if (is_object($value)) {
        $value = (array) $value;
    }

    if (is_array($value)) {
        $handled = false;
        foreach (['name', 'value', 'label', 'title', 'text'] as $key) {
            if (!empty($value[$key])) {
                add_value($set, $value[$key]);
                $handled = true;
            }
        }

        if ($handled) {
            return;
        }

        foreach ($value as $nested) {
            if (is_scalar($nested) || (is_object($nested) && method_exists($nested, '__toString'))) {
                add_value($set, $nested);
            }
        }

        return;
    }

    $stringValue = trim((string) $value);
    if ($stringValue === '') {
        return;
    }

    $normalized = function_exists('mb_strtolower') ? mb_strtolower($stringValue, 'UTF-8') : strtolower($stringValue);
    if (!isset($set[$normalized])) {
        $set[$normalized] = $stringValue;
    }
}

function add_values(array &$set, $values)
{
    if (!is_array($values)) {
        return;
    }

    foreach ($values as $value) {
        if (is_array($value)) {
            if (isset($value['name'])) {
                add_value($set, $value['name']);
                continue;
            }
            if (isset($value['value'])) {
                add_value($set, $value['value']);
                continue;
            }
            if (isset($value['label'])) {
                add_value($set, $value['label']);
                continue;
            }
        }

        add_value($set, $value);
    }
}

function collect_operator_values(array $data, array &$set)
{
    if (!empty($data['operator']) && is_array($data['operator'])) {
        $operator = $data['operator'];
        add_value($set, $operator['name'] ?? null);
        add_value($set, $operator['currentName'] ?? null);
        add_values($set, $operator['otherNames'] ?? []);
        add_values($set, $operator['parentCompanies'] ?? []);
        add_values($set, $operator['previousNames'] ?? []);
    }

    if (!empty($data['facilities']) && is_array($data['facilities'])) {
        foreach ($data['facilities'] as $facility) {
            if (!is_array($facility)) {
                continue;
            }
            $identification = $facility['identification'] ?? [];
            if (is_array($identification)) {
                add_value($set, $identification['currentOperator'] ?? null);
            }
            add_values($set, $facility['otherOperators'] ?? []);
        }
    }
}

function collect_facility_values(array $data, array &$set)
{
    // Collect from facilities array
    if (!empty($data['facilities']) && is_array($data['facilities'])) {
        foreach ($data['facilities'] as $facility) {
            if (!is_array($facility)) {
                continue;
            }
            $identification = $facility['identification'] ?? [];
            if (is_array($identification)) {
                add_value($set, $identification['name'] ?? null);
                add_value($set, $identification['currentName'] ?? null);
                add_values($set, $identification['otherNames'] ?? []);
            }
        }
    }

    // Collect from referrer consultants' facilitiesReferred (shared autocomplete)
    if (!empty($data['referrerConsultants']) && is_array($data['referrerConsultants'])) {
        foreach ($data['referrerConsultants'] as $consultant) {
            if (!is_array($consultant)) {
                continue;
            }
            add_values($set, $consultant['facilitiesReferred'] ?? []);
        }
    }

    // Also check referrerIndividual for facilitiesReferred
    if (!empty($data['referrerIndividual']) && is_array($data['referrerIndividual'])) {
        add_values($set, $data['referrerIndividual']['facilitiesReferred'] ?? []);
    }

    // Check referrer array entries
    if (!empty($data['referrer']) && is_array($data['referrer'])) {
        foreach ($data['referrer'] as $referrerEntry) {
            if (!is_array($referrerEntry)) {
                continue;
            }
            add_values($set, $referrerEntry['facilitiesReferred'] ?? []);
        }
    }
}

function collect_human_values(array $data, array &$set)
{
    if (!empty($data['operator']) && is_array($data['operator'])) {
        $operator = $data['operator'];
        add_value($set, $operator['ceo'] ?? null);
        if (!empty($operator['keyStaff']) && is_array($operator['keyStaff'])) {
            $keyStaff = $operator['keyStaff'];
            add_value($set, $keyStaff['ceo'] ?? null);
            add_values($set, $keyStaff['founders'] ?? []);
            add_values($set, $keyStaff['keyExecutives'] ?? []);
            add_values($set, $keyStaff['boardMembers'] ?? []);
        }
    }

    if (empty($data['facilities']) || !is_array($data['facilities'])) {
        return;
    }

    foreach ($data['facilities'] as $facility) {
        if (!is_array($facility)) {
            continue;
        }

        $staff = $facility['staff'] ?? [];
        if (!empty($staff['administrator']) && is_array($staff['administrator'])) {
            foreach ($staff['administrator'] as $admin) {
                if (is_array($admin)) {
                    if (isset($admin['name'])) {
                        add_value($set, $admin['name']);
                    }
                    if (isset($admin['person'])) {
                        add_value($set, $admin['person']);
                    }
                } else {
                    add_value($set, $admin);
                }
            }
        }

        if (!empty($staff['notableStaff']) && is_array($staff['notableStaff'])) {
            foreach ($staff['notableStaff'] as $staffMember) {
                if (is_array($staffMember)) {
                    if (isset($staffMember['name'])) {
                        add_value($set, $staffMember['name']);
                    }
                    if (isset($staffMember['person'])) {
                        add_value($set, $staffMember['person']);
                    }
                } else {
                    add_value($set, $staffMember);
                }
            }
        }
    }
}

function collect_referrer_values(array $data, array &$set)
{
    // Collect from facility knownReferrers
    if (!empty($data['facilities']) && is_array($data['facilities'])) {
        foreach ($data['facilities'] as $facility) {
            if (!is_array($facility)) {
                continue;
            }
            $identification = $facility['identification'] ?? [];
            if (is_array($identification)) {
                add_values($set, $identification['knownReferrers'] ?? []);
            }
        }
    }

    // Collect referrer agency name
    if (!empty($data['referrerAgency']) && is_array($data['referrerAgency'])) {
        add_value($set, $data['referrerAgency']['name'] ?? null);
    }

    // Also check referrerGroup (alias)
    if (!empty($data['referrerGroup']) && is_array($data['referrerGroup'])) {
        add_value($set, $data['referrerGroup']['name'] ?? null);
    }

    // Collect from referrer consultants
    if (!empty($data['referrerConsultants']) && is_array($data['referrerConsultants'])) {
        foreach ($data['referrerConsultants'] as $consultant) {
            if (!is_array($consultant)) {
                continue;
            }
            // Build full name from firstName + lastName or use fullName
            $fullName = $consultant['fullName'] ?? null;
            if (!$fullName) {
                $firstName = trim($consultant['firstName'] ?? '');
                $lastName = trim($consultant['lastName'] ?? '');
                if ($firstName || $lastName) {
                    $fullName = trim("$firstName $lastName");
                }
            }
            add_value($set, $fullName);
        }
    }

    // Also check referrerIndividual
    if (!empty($data['referrerIndividual']) && is_array($data['referrerIndividual'])) {
        $individual = $data['referrerIndividual'];
        $fullName = $individual['fullName'] ?? null;
        if (!$fullName) {
            $firstName = trim($individual['firstName'] ?? '');
            $lastName = trim($individual['lastName'] ?? '');
            if ($firstName || $lastName) {
                $fullName = trim("$firstName $lastName");
            }
        }
        add_value($set, $fullName);
    }

    // Check referrer array entries
    if (!empty($data['referrer']) && is_array($data['referrer'])) {
        foreach ($data['referrer'] as $referrerEntry) {
            if (!is_array($referrerEntry)) {
                continue;
            }
            add_value($set, $referrerEntry['agency'] ?? null);
            add_value($set, $referrerEntry['individual'] ?? null);
            add_value($set, $referrerEntry['name'] ?? null);
        }
    }
}

function collect_type_values(array $data, array &$set)
{
    if (empty($data['facilities']) || !is_array($data['facilities'])) {
        return;
    }

    foreach ($data['facilities'] as $facility) {
        if (!is_array($facility)) {
            continue;
        }
        $details = $facility['facilityDetails'] ?? [];
        if (is_array($details)) {
            add_value($set, $details['type'] ?? null);
        }
    }
}

function collect_status_values(array $data, array &$set)
{
    if (!empty($data['operator']) && is_array($data['operator'])) {
        add_value($set, $data['operator']['status'] ?? null);
    }

    if (empty($data['facilities']) || !is_array($data['facilities'])) {
        return;
    }

    foreach ($data['facilities'] as $facility) {
        if (!is_array($facility)) {
            continue;
        }
        $period = $facility['operatingPeriod'] ?? [];
        if (is_array($period)) {
            add_value($set, $period['status'] ?? null);
        }
    }
}

function collect_gender_values(array $data, array &$set)
{
    if (empty($data['facilities']) || !is_array($data['facilities'])) {
        return;
    }

    foreach ($data['facilities'] as $facility) {
        if (!is_array($facility)) {
            continue;
        }
        $details = $facility['facilityDetails'] ?? [];
        if (is_array($details)) {
            add_value($set, $details['gender'] ?? null);
        }
    }
}

function collect_location_values(array $data, array &$set)
{
    // Location project name (for location aggregates)
    if (!empty($data['name'])) {
        add_value($set, $data['name']);
    }

    if (!empty($data['operator']) && is_array($data['operator'])) {
        add_value($set, $data['operator']['location'] ?? null);
        add_value($set, $data['operator']['headquarters'] ?? null);
        add_value($set, $data['operator']['name'] ?? null);
    }

    if (empty($data['facilities']) || !is_array($data['facilities'])) {
        return;
    }

    foreach ($data['facilities'] as $facility) {
        if (!is_array($facility)) {
            continue;
        }
        add_value($set, $facility['location'] ?? null);
        if (!empty($facility['address']) && is_array($facility['address'])) {
            $address = $facility['address'];
            $parts = [];
            foreach (['street', 'city', 'state', 'zip'] as $segment) {
                if (!empty($address[$segment])) {
                    $parts[] = trim((string) $address[$segment]);
                }
            }
            if (!empty($parts)) {
                add_value($set, implode(', ', $parts));
            }
        }
    }
}

function collect_licensing_values(array $data, array &$set)
{
    if (empty($data['facilities']) || !is_array($data['facilities'])) {
        return;
    }

    foreach ($data['facilities'] as $facility) {
        if (!is_array($facility)) {
            continue;
        }
        add_values($set, $facility['licensing'] ?? []);
    }
}

function collect_membership_values(array $data, array &$set)
{
    if (empty($data['facilities']) || !is_array($data['facilities'])) {
        return;
    }

    foreach ($data['facilities'] as $facility) {
        if (!is_array($facility)) {
            continue;
        }
        add_values($set, $facility['memberships'] ?? []);
    }
}

function collect_accreditation_values(array $data, array &$set)
{
    if (empty($data['facilities']) || !is_array($data['facilities'])) {
        return;
    }

    foreach ($data['facilities'] as $facility) {
        if (!is_array($facility)) {
            continue;
        }
        $accreditations = $facility['accreditations'] ?? [];
        if (is_array($accreditations)) {
            add_values($set, $accreditations['current'] ?? []);
            add_values($set, $accreditations['past'] ?? []);
        }
    }
}

function collect_certification_values(array $data, array &$set)
{
    if (empty($data['facilities']) || !is_array($data['facilities'])) {
        return;
    }

    foreach ($data['facilities'] as $facility) {
        if (!is_array($facility)) {
            continue;
        }
        add_values($set, $facility['certifications'] ?? []);
    }
}

function collect_investor_values(array $data, array &$set)
{
    if (empty($data['operator']) || !is_array($data['operator'])) {
        return;
    }

    add_values($set, $data['operator']['investors'] ?? []);
}

function collect_role_values(array $data, array &$set)
{
    if (empty($data['facilities']) || !is_array($data['facilities'])) {
        return;
    }

    foreach ($data['facilities'] as $facility) {
        if (!is_array($facility)) {
            continue;
        }
        $staff = $facility['staff'] ?? [];
        $groups = ['administrator', 'notableStaff'];
        foreach ($groups as $group) {
            if (empty($staff[$group]) || !is_array($staff[$group])) {
                continue;
            }
            foreach ($staff[$group] as $member) {
                if (is_array($member) && isset($member['role'])) {
                    add_value($set, $member['role']);
                }
            }
        }
    }
}

function collect_operatingperiod_values(array $data, array &$set)
{
    if (!empty($data['operator']) && is_array($data['operator'])) {
        add_value($set, $data['operator']['operatingPeriod'] ?? null);
    }

    if (empty($data['facilities']) || !is_array($data['facilities'])) {
        return;
    }

    foreach ($data['facilities'] as $facility) {
        if (!is_array($facility)) {
            continue;
        }

        $period = $facility['operatingPeriod'] ?? [];
        if (is_array($period)) {
            add_value($set, $period['yearsOfOperation'] ?? null);
        }
    }
}

function collect_values_for_category($category, array $data, array &$set)
{
    switch ($category) {
        case 'operator':
            collect_operator_values($data, $set);
            break;
        case 'facility':
            collect_facility_values($data, $set);
            break;
        case 'human':
            collect_human_values($data, $set);
            break;
        case 'referrer':
            collect_referrer_values($data, $set);
            break;
        case 'type':
            collect_type_values($data, $set);
            break;
        case 'status':
            collect_status_values($data, $set);
            break;
        case 'gender':
            collect_gender_values($data, $set);
            break;
        case 'location':
            collect_location_values($data, $set);
            break;
        case 'licensing':
            collect_licensing_values($data, $set);
            break;
        case 'membership':
            collect_membership_values($data, $set);
            break;
        case 'accreditation':
            collect_accreditation_values($data, $set);
            break;
        case 'certification':
            collect_certification_values($data, $set);
            break;
        case 'investor':
            collect_investor_values($data, $set);
            break;
        case 'role':
            collect_role_values($data, $set);
            break;
        case 'operatingperiod':
            collect_operatingperiod_values($data, $set);
            break;
    }
}

try {
    $valueSet = [];

    $sources = [
        "SELECT json_data AS payload FROM facilities_master",
        "SELECT json_data AS payload FROM referrers_master",
        "SELECT json_data AS payload FROM locations_master",
        "SELECT edited_json_data AS payload FROM suggested_edits WHERE edited_json_data IS NOT NULL AND edited_json_data <> ''"
    ];

    foreach ($sources as $sql) {
        $stmt = $pdo->query($sql);
        if (!$stmt) {
            continue;
        }

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (empty($row['payload'])) {
                continue;
            }

            $data = normalize_project_payload($row['payload']);
            if (!$data) {
                continue;
            }

            collect_values_for_category($category, $data, $valueSet);
        }
    }

    $values = array_values($valueSet);

    if ($query !== '') {
        $values = array_values(array_filter($values, function ($value) use ($query) {
            return stripos($value, $query) !== false;
        }));
    }

    usort($values, function ($a, $b) {
        return strnatcasecmp($a, $b);
    });

    if (count($values) > $maxResults) {
        $values = array_slice($values, 0, $maxResults);
    }

    echo json_encode([
        'success' => true,
        'values' => $values,
        'count' => count($values)
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error occurred',
        'details' => $e->getMessage()
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error occurred',
        'details' => $e->getMessage()
    ]);
}

?>
