<?php
// approve-edits.php

// Include configuration
require_once 'config.php';

// Create a PDO database connection
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Could not connect to the database: " . $e->getMessage());
}

// Fetch pending suggestions
$stmt = $pdo->prepare("SELECT * FROM suggested_edits WHERE status = 'pending'");
$stmt->execute();
$suggestions = $stmt->fetchAll(PDO::FETCH_ASSOC);

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


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approve Edits</title>
    <style>
        body { font-family: sans-serif; }
        .container { width: 80%; margin: 0 auto; }
        .suggestion { border: 1px solid #ccc; padding: 10px; margin-bottom: 10px; }
        .suggestion h3 { margin-top: 0; }
        .actions { margin-top: 10px; }
        .actions button { margin-right: 10px; }
        .diff { border: 1px solid #eee; padding: 10px; }
        .diff dt { font-weight: bold; }
        .diff dd { margin-left: 20px; }
        .added { background-color: #dfd; }
        .removed { background-color: #fdd; }
        .changed .removed-value { text-decoration: line-through; background-color: #fdd; }
        .changed .added-value { background-color: #dfd; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Approve Edits</h1>
        <div id="suggestions-container">
            <?php if (empty($suggestions)): ?>
                <p>No pending suggestions.</p>
            <?php else: ?>
                <?php foreach ($suggestions as $suggestion): ?>
                    <div class="suggestion" id="suggestion-<?php echo $suggestion['id']; ?>">
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

                        // Determine display names based on data type (facility vs referrer)
                        $operator_name = $new_data['operator']['name'] ?? 'N/A';
                        $facility_name = $new_data['facilities'][0]['identification']['name'] ?? 'N/A';
                        $referrer_agency = $new_data['referrerAgency']['name'] ?? '';
                        $referrer_consultant = '';
                        if (!empty($new_data['referrerConsultants'][0]['firstName']) || !empty($new_data['referrerConsultants'][0]['lastName'])) {
                            $referrer_consultant = trim(
                                ($new_data['referrerConsultants'][0]['firstName'] ?? '') . ' ' .
                                ($new_data['referrerConsultants'][0]['lastName'] ?? '')
                            );
                        }
                        ?>
                        <h3>Suggestion for <?php echo htmlspecialchars($suggestion['master_id']); ?></h3>
                        <?php if ($operator_name !== 'N/A'): ?>
                            <p><strong>Operator:</strong> <?php echo htmlspecialchars($operator_name); ?></p>
                        <?php endif; ?>
                        <?php if ($facility_name !== 'N/A'): ?>
                            <p><strong>Facility:</strong> <?php echo htmlspecialchars($facility_name); ?></p>
                        <?php endif; ?>
                        <?php if (!empty($referrer_agency)): ?>
                            <p><strong>Referrer Agency:</strong> <?php echo htmlspecialchars($referrer_agency); ?></p>
                        <?php endif; ?>
                        <?php if (!empty($referrer_consultant)): ?>
                            <p><strong>Referrer Consultant:</strong> <?php echo htmlspecialchars($referrer_consultant); ?></p>
                        <?php endif; ?>
                        <p><strong>Reason:</strong> <?php echo htmlspecialchars($suggestion['reason']); ?></p>
                        <div class="diff">
                            <?php echo render_diff($diff); ?>
                        </div>
                        <div class="actions">
                            <button onclick="processEdit(<?php echo $suggestion['id']; ?>, 'approve')">Approve</button>
                            <button onclick="processEdit(<?php echo $suggestion['id']; ?>, 'reject')">Reject</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
    function processEdit(id, action) {
        if (!confirm(`Are you sure you want to ${action} this suggestion?`)) {
            return;
        }

        const data = { id: id, action: action };

        fetch('process-edit.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(data)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Suggestion ' + action + 'd successfully!');
                const suggestionElement = document.getElementById('suggestion-' + id);
                if (suggestionElement) {
                    suggestionElement.remove();
                }
            } else {
                alert('Error: ' + data.error);
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
