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
$stmt = $pdo->prepare("SELECT * FROM suggestions WHERE status = 'pending'");
$stmt->execute();
$suggestions = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
                        <h3>Suggestion for <?php echo htmlspecialchars($suggestion['facility_name']); ?></h3>
                        <p><strong>Field:</strong> <?php echo htmlspecialchars($suggestion['field_name']); ?></p>
                        <p><strong>Old Value:</strong> <?php echo htmlspecialchars($suggestion['old_value']); ?></p>
                        <p><strong>New Value:</strong> <?php echo htmlspecialchars($suggestion['new_value']); ?></p>
                        <div class="actions">
                            <button onclick="processEdit(<?php echo $suggestion['id']; ?>, 'approved')">Approve</button>
                            <button onclick="processEdit(<?php echo $suggestion['id']; ?>, 'rejected')">Reject</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
    function processEdit(id, status) {
        if (!confirm(`Are you sure you want to ${status} this suggestion?`)) {
            return;
        }

        const formData = new FormData();
        formData.append('id', id);
        formData.append('status', status);

        fetch('process-edit.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Suggestion ' + status + ' successfully!');
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
