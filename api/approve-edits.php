<?php
// approve-edits.php

// Include configuration
require_once __DIR__ . '/config.php';

// Fetch submissions from suggested_edits table
if (!$pdo) {
    die("Could not connect to the database (pdo is null).");
}

// Fetch pending suggestions (Data Form)
$stmt = $pdo->prepare("SELECT * FROM suggested_edits WHERE status = 'pending'");
$stmt->execute();
$suggestions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch pending news
$stmt = $pdo->prepare("SELECT * FROM news_submissions WHERE status = 'submitted'");
$stmt->execute();
$news_submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch pending wiki
$stmt = $pdo->prepare("SELECT * FROM wiki_submissions WHERE status = 'submitted' AND status != 'deleted'");
$stmt->execute();
$wiki_submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

function json_diff($old, $new) {
    $diff = [];
    foreach ($new as $k => $v) {
        if (!isset($old[$k])) {
            $diff[$k] = ['new' => $v];
        } else if (is_array($v) && is_array($old[$k])) {
            $sub_diff = json_diff($old[$k], $v);
            if (!empty($sub_diff)) {
                $diff[$k] = $sub_diff;
            }
        } else if ($v !== $old[$k]) {
            $diff[$k] = ['old' => $old[$k], 'new' => $v];
        }
    }
    foreach ($old as $k => $v) {
        if (!isset($new[$k])) {
            $diff[$k] = ['old' => $v];
        }
    }
    return $diff;
}

function render_diff($diff) {
    $html = '<dl>';
    foreach ($diff as $k => $v) {
        $html .= '<dt>' . htmlspecialchars($k) . '</dt>';
        if (isset($v['new']) && !isset($v['old'])) {
            $html .= '<dd class="added">' . htmlspecialchars(json_encode($v['new'])) . '</dd>';
        } else if (isset($v['old']) && !isset($v['new'])) {
            $html .= '<dd class="removed">' . htmlspecialchars(json_encode($v['old'])) . '</dd>';
        } else if (isset($v['old']) && isset($v['new'])) {
            $html .= '<dd class="changed"><span class="removed-value">' . htmlspecialchars(json_encode($v['old'])) . '</span> <span class="added-value">' . htmlspecialchars(json_encode($v['new'])) . '</span></dd>';
        } else if (is_array($v)) {
            $html .= '<dd>' . render_diff($v) . '</dd>';
        }
    }
    $html .= '</dl>';
    return $html;
}

function kop_has_meaningful_value($value) {
    if (is_string($value)) {
        return trim($value) !== '';
    }

    if (is_bool($value)) {
        return $value === true;
    }

    if (is_int($value) || is_float($value)) {
        return true;
    }

    if (is_array($value)) {
        foreach ($value as $item) {
            if (kop_has_meaningful_value($item)) {
                return true;
            }
        }
        return false;
    }

    return !empty($value);
}

function kop_collect_named_facilities($data) {
    $facilities = [];
    if (!is_array($data) || !isset($data['facilities']) || !is_array($data['facilities'])) {
        return $facilities;
    }

    foreach ($data['facilities'] as $facility) {
        $name = trim((string) ($facility['identification']['name'] ?? ''));
        if ($name !== '') {
            $facilities[] = $name;
        }
    }

    return array_values(array_unique($facilities));
}

function kop_collect_added_facilities($old_data, $new_data) {
    $old_names = kop_collect_named_facilities($old_data);
    $new_names = kop_collect_named_facilities($new_data);

    if (empty($new_names)) {
        return [];
    }

    return array_values(array_diff($new_names, $old_names));
}

function kop_consultant_display_name($consultant) {
    if (!is_array($consultant)) {
        return '';
    }

    $full_name = trim(
        ((string) ($consultant['firstName'] ?? '')) . ' ' .
        ((string) ($consultant['lastName'] ?? ''))
    );

    if ($full_name !== '') {
        return $full_name;
    }

    return trim((string) ($consultant['fullName'] ?? ''));
}

function kop_has_meaningful_referrer_payload($data) {
    if (!is_array($data)) {
        return false;
    }

    $agency_name = trim((string) ($data['referrerAgency']['name'] ?? ''));
    if ($agency_name !== '') {
        return true;
    }

    if (!isset($data['referrerConsultants']) || !is_array($data['referrerConsultants'])) {
        return false;
    }

    foreach ($data['referrerConsultants'] as $consultant) {
        if (!is_array($consultant)) {
            continue;
        }

        $name = kop_consultant_display_name($consultant);
        if ($name === '') {
            continue;
        }

        $extra_fields = [
            trim((string) ($consultant['role'] ?? '')),
            trim((string) ($consultant['status'] ?? '')),
            trim((string) ($consultant['education'] ?? '')),
            trim((string) ($consultant['credentials'] ?? '')),
            trim((string) ($consultant['city'] ?? '')),
            trim((string) ($consultant['state'] ?? '')),
            trim((string) ($consultant['email'] ?? '')),
            trim((string) ($consultant['phone'] ?? '')),
            trim((string) ($consultant['website'] ?? '')),
        ];

        foreach ($extra_fields as $value) {
            if (kop_has_meaningful_value($value)) {
                return true;
            }
        }

        foreach (['websites', 'affiliations', 'knownReferrals', 'pastTTIJobs', 'schoolDistricts', 'facilitiesReferred'] as $field) {
            if (kop_has_meaningful_value($consultant[$field] ?? null)) {
                return true;
            }
        }
    }

    return false;
}


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approve Submissions</title>
    <style>
        body { font-family: sans-serif; }
        .container { width: 90%; margin: 0 auto; max-width: 1200px; }
        .section { margin-bottom: 40px; border-bottom: 2px solid #eee; padding-bottom: 20px; }
        .suggestion { border: 1px solid #ccc; padding: 15px; margin-bottom: 15px; background: #fff; border-radius: 4px; }
        .suggestion h3 { margin-top: 0; }
        .actions { margin-top: 15px; padding-top: 10px; border-top: 1px solid #eee; }
        .actions button { margin-right: 10px; padding: 5px 15px; cursor: pointer; }
        .diff { border: 1px solid #eee; padding: 10px; background: #f9f9f9; overflow-x: auto; }
        .diff dt { font-weight: bold; margin-top: 5px; }
        .diff dd { margin-left: 20px; margin-bottom: 5px; font-family: monospace; }
        .added { background-color: #dfd; padding: 2px 4px; }
        .removed { background-color: #fdd; padding: 2px 4px; }
        .changed .removed-value { text-decoration: line-through; background-color: #fdd; }
        .changed .added-value { background-color: #dfd; }
        
        .badge { display: inline-block; padding: 2px 6px; border-radius: 4px; font-size: 0.8em; font-weight: bold; color: white; background: #666; }
        .badge-news { background: #007bff; }
        .badge-wiki { background: #28a745; }
        .badge-data { background: #6f42c1; }
        
        .meta-row { display: flex; gap: 20px; color: #666; font-size: 0.9em; margin-bottom: 10px; }
    </style>
</head>
<body>
    <div class="container">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h1>Approve Submissions</h1>
            <button onclick="approveAll('all')" style="background: #28a745; color: white; border: none; padding: 10px 20px; font-size: 1.1em; cursor: pointer; border-radius: 4px;">✅ Approve Everything</button>
        </div>

        <!-- Section 1: Data Form Suggestions (Suggested Edits) -->
        <div class="section">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h2>Data Form Updates <span class="badge badge-data"><?php echo count($suggestions); ?></span></h2>
                <?php if (!empty($suggestions)): ?>
                    <button onclick="approveAll('suggestion')" style="cursor: pointer;">Approve All Data Updates</button>
                <?php endif; ?>
            </div>
            <div id="suggestions-container">
                <?php if (empty($suggestions)): ?>
                    <p>No pending data suggestions.</p>
                <?php else: ?>
                    <?php foreach ($suggestions as $suggestion): ?>
                        <div class="suggestion item-suggestion" id="suggestion-<?php echo $suggestion['id']; ?>" data-id="<?php echo $suggestion['id']; ?>">
                            <?php
                            $new_data = json_decode($suggestion['edited_json_data'], true);
                            $master_id = $suggestion['master_id'];

                            // Check both facilities_master and referrers_master tables
                            $stmt = $pdo->prepare("SELECT json_data FROM facilities_master WHERE unique_name = ?");
                            $stmt->execute([$master_id]);
                            $original_data = $stmt->fetchColumn();

                            // If not found in facilities, try referrers
                            if (!$original_data) {
                                $stmt = $pdo->prepare("SELECT json_data FROM referrers_master WHERE unique_name = ?");
                                $stmt->execute([$master_id]);
                                $original_data = $stmt->fetchColumn();
                            }

                            $old_data = $original_data ? json_decode($original_data, true) : [];

                            $diff = json_diff($old_data, $new_data);

                            $operator_name = trim((string) ($new_data['operator']['name'] ?? ''));
                            $facility_label = '';
                            $facility_value = '';
                            $added_facilities = kop_collect_added_facilities($old_data, $new_data);
                            $named_facilities = kop_collect_named_facilities($new_data);

                            if (!empty($added_facilities)) {
                                $facility_label = count($added_facilities) === 1 ? 'Facility Added' : 'Facilities Added';
                                $facility_value = implode(', ', $added_facilities);
                            } elseif (count($named_facilities) === 1) {
                                $facility_label = 'Facility';
                                $facility_value = $named_facilities[0];
                            } elseif (count($named_facilities) > 1) {
                                $facility_label = 'Facilities in Project';
                                $facility_value = count($named_facilities) . ' total';
                            }

                            $referrer_agency = trim((string) ($new_data['referrerAgency']['name'] ?? ''));
                            $referrer_consultant = '';
                            $show_referrer_summary = $operator_name === '' && $facility_value === '' && kop_has_meaningful_referrer_payload($new_data);

                            if ($show_referrer_summary && !empty($new_data['referrerConsultants'][0])) {
                                $referrer_consultant = kop_consultant_display_name($new_data['referrerConsultants'][0]);
                            }
                            ?>
                            <h3>Suggestion for <?php echo htmlspecialchars($suggestion['master_id']); ?></h3>
                            <?php if ($operator_name !== ''): ?>
                                <p><strong>Operator:</strong> <?php echo htmlspecialchars($operator_name); ?></p>
                            <?php endif; ?>
                            <?php if ($facility_value !== ''): ?>
                                <p><strong><?php echo htmlspecialchars($facility_label); ?>:</strong> <?php echo htmlspecialchars($facility_value); ?></p>
                            <?php endif; ?>
                            <?php if ($show_referrer_summary && !empty($referrer_agency)): ?>
                                <p><strong>Referrer Agency:</strong> <?php echo htmlspecialchars($referrer_agency); ?></p>
                            <?php endif; ?>
                            <?php if ($show_referrer_summary && !empty($referrer_consultant)): ?>
                                <p><strong>Referrer Consultant:</strong> <?php echo htmlspecialchars($referrer_consultant); ?></p>
                            <?php endif; ?>
                            <p><strong>Reason:</strong> <?php echo htmlspecialchars($suggestion['reason']); ?></p>
                            <div class="diff">
                                <?php echo render_diff($diff); ?>
                            </div>
                            <div class="actions">
                                <button onclick="processEdit(<?php echo $suggestion['id']; ?>, 'approve', 'suggestion')">Approve</button>
                                <button onclick="processEdit(<?php echo $suggestion['id']; ?>, 'reject', 'suggestion')">Reject</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Section 2: News Submissions -->
        <div class="section">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h2>News Submissions <span class="badge badge-news"><?php echo count($news_submissions); ?></span></h2>
                <?php if (!empty($news_submissions)): ?>
                    <button onclick="approveAll('news')" style="cursor: pointer;">Approve All News</button>
                <?php endif; ?>
            </div>
            <div id="news-container">
                <?php if (empty($news_submissions)): ?>
                    <p>No pending news submissions.</p>
                <?php else: ?>
                    <?php foreach ($news_submissions as $news): ?>
                        <div class="suggestion item-news" id="news-<?php echo $news['id']; ?>" data-id="<?php echo $news['id']; ?>">
                            <h3><?php echo htmlspecialchars($news['article_title']); ?></h3>
                            <div class="meta-row">
                                <span><strong>Publication:</strong> <?php echo htmlspecialchars($news['publication_name']); ?></span>
                                <span><strong>Date:</strong> <?php echo htmlspecialchars($news['publication_date']); ?></span>
                                <span><strong>Type:</strong> <?php echo htmlspecialchars($news['article_type']); ?></span>
                            </div>
                            <p><a href="<?php echo htmlspecialchars($news['article_url']); ?>" target="_blank">Read Article</a></p>
                            
                            <div style="background: #f9f9f9; padding: 10px; font-size: 0.9em;">
                                <strong>Summary:</strong><br>
                                <?php echo nl2br(htmlspecialchars($news['summary'])); ?>
                            </div>

                            <div class="actions">
                                <button onclick="processEdit(<?php echo $news['id']; ?>, 'approve', 'news')">Approve</button>
                                <button onclick="processEdit(<?php echo $news['id']; ?>, 'reject', 'news')">Reject</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Section 3: Wiki Submissions -->
        <div class="section">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h2>Wiki Submissions <span class="badge badge-wiki"><?php echo count($wiki_submissions); ?></span></h2>
                <?php if (!empty($wiki_submissions)): ?>
                    <button onclick="approveAll('wiki')" style="cursor: pointer;">Approve All Wiki</button>
                <?php endif; ?>
            </div>
            <div id="wiki-container">
                <?php if (empty($wiki_submissions)): ?>
                    <p>No pending wiki submissions.</p>
                <?php else: ?>
                    <?php foreach ($wiki_submissions as $wiki): ?>
                        <div class="suggestion item-wiki" id="wiki-<?php echo $wiki['id']; ?>" data-id="<?php echo $wiki['id']; ?>">
                            <h3><?php echo htmlspecialchars($wiki['program_name']); ?></h3>
                            <div class="meta-row">
                                <span><strong>Location:</strong> <?php echo htmlspecialchars($wiki['city_state']); ?></span>
                                <span><strong>Type:</strong> <?php echo htmlspecialchars($wiki['program_type']); ?></span>
                                <span><strong>Years Active:</strong> <?php echo htmlspecialchars($wiki['years_active']); ?></span>
                            </div>
                            <?php if(!empty($wiki['organization'])): ?>
                                <p><strong>Organization:</strong> <?php echo htmlspecialchars($wiki['organization']); ?></p>
                            <?php endif; ?>
                            
                            <div class="actions">
                                <button onclick="processEdit(<?php echo $wiki['id']; ?>, 'approve', 'wiki')">Approve (Publish)</button>
                                <button onclick="processEdit(<?php echo $wiki['id']; ?>, 'reject', 'wiki')">Reject</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <script>
    async function approveAll(type) {
        if (!confirm(`Are you sure you want to approve ALL ${type === 'all' ? 'pending submissions' : type + ' items'}?`)) {
            return;
        }

        if (type === 'all') {
            await approveAll('suggestion');
            await approveAll('news');
            await approveAll('wiki');
            alert('All submissions processed.');
            return;
        }

        const items = document.querySelectorAll(`.item-${type}`);
        if (items.length === 0) return;

        const ids = Array.from(items).map(item => item.getAttribute('data-id'));

        if (type === 'news' || type === 'wiki') {
            // Batch process for News and Wiki
            fetch('manage-submissions.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'approve',
                    type: type,
                    ids: ids
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    items.forEach(item => item.remove());
                    // Update header count if we wanted to be fancy, but removal is enough visual feedback
                } else {
                    console.error('Batch approval failed:', data);
                    alert(`Failed to approve all ${type} items: ` + data.error);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred during batch approval.');
            });

        } else if (type === 'suggestion') {
            // Sequential process for Suggestions (due to complex merge logic in process-edit.php)
            let successCount = 0;
            for (const item of items) {
                const id = item.getAttribute('data-id');
                try {
                    const response = await fetch('process-edit.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ id: id, action: 'approve' })
                    });
                    const data = await response.json();
                    if (data.success) {
                        item.remove();
                        successCount++;
                    } else {
                        console.error(`Failed to approve suggestion ${id}:`, data.error);
                    }
                } catch (error) {
                    console.error(`Error approving suggestion ${id}:`, error);
                }
            }
            if (successCount < items.length) {
                alert(`Approved ${successCount} out of ${items.length} suggestions. Check console for errors.`);
            }
        }
    }

    function processEdit(id, action, type) {
        if (!confirm(`Are you sure you want to ${action} this ${type}?`)) {
            return;
        }

        let url = '';
        let body = {};

        if (type === 'suggestion') {
            url = 'process-edit.php';
            body = { id: id, action: action };
        } else {
            // News and Wiki use manage-submissions.php
            url = 'manage-submissions.php';
            body = {
                action: action,
                type: type,
                ids: [id]
            };
        }

        fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(body)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(`${type.charAt(0).toUpperCase() + type.slice(1)} ${action}d successfully!`);
                const elementId = (type === 'suggestion' ? 'suggestion-' : type + '-') + id;
                const element = document.getElementById(elementId);
                if (element) {
                    element.remove();
                }
            } else {
                alert('Error: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while processing the request.');
        });
    }
    </script>
</body>
</html>
