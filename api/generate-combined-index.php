<?php
/**
 * Generate Combined Index API
 * 
 * Merges data from the TTI Program Index (facilities_master) and the Wiki (wiki_master)
 * to create a unified index on the live site.
 * 
 * Usage: GET /api/generate-combined-index.php
 * Options: ?save=1 (saves to js/data/combined_index.json)
 */

header('Content-Type: application/json');
require_once __DIR__ . '/config.php';

try {
    // 1. Fetch TTI Program Index Data (facilities_master)
    $stmt = $pdo->prepare("SELECT unique_name, json_data FROM facilities_master");
    $stmt->execute();
    $facilities = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Fetch Wiki Data (wiki_master)
    $stmtWiki = $pdo->prepare("SELECT slug, program_name, city_state, program_type, organization, years_active FROM wiki_master");
    $stmtWiki->execute();
    $wikiEntries = $stmtWiki->fetchAll(PDO::FETCH_ASSOC);

    $combinedIndex = [];
    $seenNames = [];

    // Helper to normalize names for matching
    function normalizeName($name) {
        return preg_replace('/[^a-z0-9]/', '', strtolower(trim($name)));
    }

    // 3. Process TTI Facilities
    foreach ($facilities as $row) {
        $project = json_decode($row['json_data'], true);
        if (!$project) continue;

        // Handle data structure variations
        $data = $project['data'] ?? $project;
        $facList = $data['facilities'] ?? ($data['identification'] ? [$data] : []);

        foreach ($facList as $fac) {
            $name = $fac['identification']['name'] ?? $fac['name'] ?? $project['name'] ?? null;
            if (!$name) continue;

            $normName = normalizeName($name);
            
            $entry = [
                'name' => $name,
                'source' => ['tti_index'],
                'location' => $fac['location'] ?? $fac['address'] ?? '',
                'type' => $fac['facilityDetails']['type'] ?? '',
                'operator' => $data['operator']['name'] ?? $project['name'] ?? '',
                'years_active' => $fac['operatingPeriod']['text'] ?? '',
                'website' => $fac['identification']['website'] ?? '',
                'wiki_slug' => null
            ];

            if (!in_array($normName, $seenNames)) {
                $combinedIndex[] = $entry;
                $seenNames[] = $normName;
            }
        }
    }

    // 4. Merge Wiki Data
    foreach ($wikiEntries as $wiki) {
        $name = $wiki['program_name'];
        $normName = normalizeName($name);

        $matched = false;
        foreach ($combinedIndex as &$entry) {
            if (normalizeName($entry['name']) === $normName) {
                $matched = true;
                if (!in_array('wiki', $entry['source'])) {
                    $entry['source'][] = 'wiki';
                }
                $entry['wiki_slug'] = $wiki['slug'];
                
                // Enrich missing fields
                if (empty($entry['location'])) $entry['location'] = $wiki['city_state'];
                if (empty($entry['type'])) $entry['type'] = $wiki['program_type'];
                if (empty($entry['operator'])) $entry['operator'] = $wiki['organization'];
                if (empty($entry['years_active'])) $entry['years_active'] = $wiki['years_active'];
                break;
            }
        }
        unset($entry); // Break reference

        if (!$matched) {
            $combinedIndex[] = [
                'name' => $name,
                'source' => ['wiki'],
                'location' => $wiki['city_state'],
                'type' => $wiki['program_type'],
                'operator' => $wiki['organization'],
                'years_active' => $wiki['years_active'],
                'website' => '',
                'wiki_slug' => $wiki['slug']
            ];
            $seenNames[] = $normName;
        }
    }

    // Sort by Name
    usort($combinedIndex, function($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });

    // 5. Output or Save
    if (isset($_GET['save']) && $_GET['save'] == '1') {
        $savePath = dirname(__DIR__) . '/js/data/combined_index.json';
        if (file_put_contents($savePath, json_encode($combinedIndex, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))) {
            echo json_encode([
                'success' => true, 
                'message' => 'Combined index saved successfully.', 
                'count' => count($combinedIndex),
                'path' => $savePath
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to write to file system.']);
        }
    } else {
        echo json_encode([
            'success' => true,
            'count' => count($combinedIndex),
            'data' => $combinedIndex
        ]);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
