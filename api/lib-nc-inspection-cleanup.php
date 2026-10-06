<?php
/**
 * Undo what the old NC scraper matcher filed wrongly (seeds/nc-inspection-cleanup.json).
 *
 * nc_scraper.py matched each licence in nc_youth_facilities.xlsx to the state's
 * public records directory with loose rules: a town name inside a facility
 * name counted ("Clinton" -> an adult day centre in Clinton), and one directory
 * entry could take any number of licences. So adult-only facilities (opioid
 * clinics, adult group homes, day centres) were posted, and one facility was
 * stored once per licence that landed on it (22 times for one day treatment).
 * The rows' names and reports are the directory page's own, so nothing is
 * rewritten; rows are removed, folded into the copy kept, or relabelled:
 *
 *   delete  - every program on the facility's page is for adults
 *   merge   - the same facility under another licence: its reports and
 *             findings move to the kept row (a finding reviewed on the dropped
 *             copy keeps its review), then the row goes
 *   relabel - the kept row gets its own licence number ('' when none)
 *
 * Every row is checked against the name and licence in the plan first; a row
 * that changed since is skipped. Portable SQL, so the test runs it on SQLite.
 */

/** Rows of $table whose $col is in $ids. */
function kop_ncclean_rows(PDO $pdo, $table, $col, array $ids) {
    if (!$ids) return array();
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT * FROM $table WHERE $col IN ($in)");
    $st->execute(array_values($ids));
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function kop_ncclean_facility(PDO $pdo, $id) {
    $st = $pdo->prepare("SELECT id, facility_name, program_name FROM inspection_facilities WHERE id = ? AND state = 'NC'");
    $st->execute(array((int) $id));
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function kop_ncclean_same($row, $name, $license) {
    return $row && (string) $row['facility_name'] === (string) $name && (string) $row['program_name'] === (string) $license;
}

/** Report ids of a facility. */
function kop_ncclean_report_ids(PDO $pdo, $facility_id) {
    $st = $pdo->prepare('SELECT id FROM inspection_reports WHERE facility_id = ?');
    $st->execute(array((int) $facility_id));
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** Everything the plan touches, for the backup file. */
function kop_ncclean_backup(PDO $pdo, array $plan) {
    $ids = array();
    foreach ((array) ($plan['delete'] ?? array()) as $d) $ids[] = (int) $d['id'];
    foreach ((array) ($plan['merge'] ?? array()) as $m) { $ids[] = (int) $m['keep']; $ids[] = (int) $m['drop']; }
    foreach ((array) ($plan['relabel'] ?? array()) as $r) $ids[] = (int) $r['id'];
    $ids = array_values(array_unique($ids));
    $reports = kop_ncclean_rows($pdo, 'inspection_reports', 'facility_id', $ids);
    $report_ids = array_map(function ($r) { return (int) $r['id']; }, $reports);
    $out = array(
        'facilities' => kop_ncclean_rows($pdo, 'inspection_facilities', 'id', $ids),
        'reports'    => $reports,
        'highlights' => kop_ncclean_rows($pdo, 'inspection_highlights', 'facility_id', $ids),
    );
    foreach (array('inspection_highlight_scans', 'inspection_report_counts') as $t) {
        try { $out[$t] = kop_ncclean_rows($pdo, $t, 'report_id', $report_ids); } catch (Throwable $e) { $out[$t] = array(); }
    }
    return $out;
}

/** Remove report rows and what hangs off them. */
function kop_ncclean_drop_reports(PDO $pdo, array $report_ids) {
    if (!$report_ids) return;
    $in = implode(',', array_fill(0, count($report_ids), '?'));
    foreach (array('inspection_highlights', 'inspection_highlight_scans', 'inspection_report_counts', 'inspection_reports') as $t) {
        $col = $t === 'inspection_reports' ? 'id' : 'report_id';
        try {
            $pdo->prepare("DELETE FROM $t WHERE $col IN ($in)")->execute($report_ids);
        } catch (Throwable $e) {
            if ($t === 'inspection_reports' || $t === 'inspection_highlights') throw $e;   // the optional tables may be missing
        }
    }
}

/** Move the dropped copy's reports and findings onto the kept row. */
function kop_ncclean_merge(PDO $pdo, $keep, $drop, array &$stats) {
    $kept = array();
    $st = $pdo->prepare('SELECT id, report_id FROM inspection_reports WHERE facility_id = ?');
    $st->execute(array((int) $keep));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $kept[(string) $r['report_id']] = (int) $r['id'];

    $st->execute(array((int) $drop));
    $gone = array();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rid = (int) $r['id'];
        $target = $kept[(string) $r['report_id']] ?? 0;
        if (!$target) {
            // A report only the dropped copy has: it moves with its findings.
            $pdo->prepare('UPDATE inspection_reports SET facility_id = ? WHERE id = ?')->execute(array((int) $keep, $rid));
            $pdo->prepare('UPDATE inspection_highlights SET facility_id = ? WHERE report_id = ?')->execute(array((int) $keep, $rid));
            try { $pdo->prepare('UPDATE inspection_report_counts SET facility_id = ? WHERE report_id = ?')->execute(array((int) $keep, $rid)); } catch (Throwable $e) {}
            $kept[(string) $r['report_id']] = $rid;
            $stats['reports_moved']++;
            continue;
        }
        // The kept row has this report: carry over reviews the dropped copy holds.
        $hl = $pdo->prepare('SELECT * FROM inspection_highlights WHERE report_id = ?');
        $hl->execute(array($rid));
        foreach ($hl->fetchAll(PDO::FETCH_ASSOC) as $h) {
            $twin = $pdo->prepare('SELECT id, status FROM inspection_highlights WHERE report_id = ? AND finding_key = ?');
            $twin->execute(array($target, $h['finding_key']));
            $t = $twin->fetch(PDO::FETCH_ASSOC);
            if (!$t) {
                $pdo->prepare('UPDATE inspection_highlights SET report_id = ?, facility_id = ? WHERE id = ?')
                    ->execute(array($target, (int) $keep, (int) $h['id']));
                $stats['findings_moved']++;
            } elseif ($t['status'] === 'pending' && $h['status'] !== 'pending') {
                $pdo->prepare('UPDATE inspection_highlights SET status = ?, reviewed_by = ?, reviewed_at = ?, review_note = ? WHERE id = ?')
                    ->execute(array($h['status'], $h['reviewed_by'], $h['reviewed_at'], $h['review_note'], (int) $t['id']));
                $stats['reviews_kept']++;
            }
        }
        $gone[] = $rid;
    }
    kop_ncclean_drop_reports($pdo, $gone);
    $stats['duplicate_reports_removed'] += count($gone);
    $pdo->prepare('DELETE FROM inspection_highlights WHERE facility_id = ?')->execute(array((int) $drop));
    $pdo->prepare('DELETE FROM inspection_facilities WHERE id = ?')->execute(array((int) $drop));
}

/**
 * Apply (or, with $apply false, only check) the plan. Returns
 * array('stats' => counts, 'skipped' => reasons, 'removed' => ids, 'merged' => drop => keep).
 */
function kop_ncclean_run(PDO $pdo, array $plan, $apply) {
    $stats = array('deleted' => 0, 'merged' => 0, 'relabelled' => 0, 'reports_moved' => 0, 'findings_moved' => 0,
                   'reviews_kept' => 0, 'duplicate_reports_removed' => 0, 'reports_deleted' => 0);
    $skipped = array();
    $removed = array();
    $merged = array();

    if ($apply) $pdo->beginTransaction();
    try {
        foreach ((array) ($plan['merge'] ?? array()) as $m) {
            $drop = kop_ncclean_facility($pdo, $m['drop']);
            $keep = kop_ncclean_facility($pdo, $m['keep']);
            if (!kop_ncclean_same($drop, $m['name'], $m['drop_license'])) { $skipped[] = "merge {$m['drop']}: row changed or gone"; continue; }
            if (!$keep || $keep['facility_name'] !== $m['name']) { $skipped[] = "merge {$m['drop']} into {$m['keep']}: kept row changed or gone"; continue; }
            if ($apply) kop_ncclean_merge($pdo, (int) $m['keep'], (int) $m['drop'], $stats);
            $stats['merged']++;
            $merged[(int) $m['drop']] = (int) $m['keep'];
        }
        foreach ((array) ($plan['delete'] ?? array()) as $d) {
            $row = kop_ncclean_facility($pdo, $d['id']);
            if (!kop_ncclean_same($row, $d['name'], $d['license'])) { $skipped[] = "delete {$d['id']}: row changed or gone"; continue; }
            $reports = kop_ncclean_report_ids($pdo, $d['id']);
            if ($apply) {
                kop_ncclean_drop_reports($pdo, $reports);
                $pdo->prepare('DELETE FROM inspection_highlights WHERE facility_id = ?')->execute(array((int) $d['id']));
                $pdo->prepare('DELETE FROM inspection_facilities WHERE id = ?')->execute(array((int) $d['id']));
            }
            $stats['deleted']++;
            $stats['reports_deleted'] += count($reports);
            $removed[] = (int) $d['id'];
        }
        foreach ((array) ($plan['relabel'] ?? array()) as $r) {
            $row = kop_ncclean_facility($pdo, $r['id']);
            if (!kop_ncclean_same($row, $r['name'], $r['from'])) { $skipped[] = "relabel {$r['id']}: row changed or gone"; continue; }
            // A row the merges above fold away does not count (a dry run has not removed it).
            $clash = $pdo->prepare("SELECT id FROM inspection_facilities WHERE state = 'NC' AND facility_name = ? AND program_name = ? AND id <> ?");
            $clash->execute(array($r['name'], $r['to'], (int) $r['id']));
            $clashing = array_diff(array_map('intval', $clash->fetchAll(PDO::FETCH_COLUMN)), array_keys($merged));
            if ($clashing) { $skipped[]= "relabel {$r['id']}: another row already has {$r['to']}"; continue; }
            if ($apply) $pdo->prepare('UPDATE inspection_facilities SET program_name = ? WHERE id = ?')->execute(array($r['to'], (int) $r['id']));
            $stats['relabelled']++;
        }
        if ($apply) $pdo->commit();
    } catch (Throwable $e) {
        if ($apply && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return array('stats' => $stats, 'skipped' => $skipped, 'removed' => $removed, 'merged' => $merged);
}

/** Inspection Links (inc/inspection-links.php) point at inspection_facilities ids: follow the merges, forget the deleted. */
function kop_ncclean_remap_links(array $stored, array $removed, array $merged) {
    $changed = 0;
    foreach (array('links', 'rejected') as $k) {
        foreach ((array) ($stored[$k] ?? array()) as $fid => $ids) {
            $new = array();
            foreach ((array) $ids as $rid) {
                $rid = (int) $rid;
                if (in_array($rid, $removed, true)) { $changed++; continue; }
                if (isset($merged[$rid])) { $rid = $merged[$rid]; $changed++; }
                $new[] = $rid;
            }
            $stored[$k][$fid] = array_values(array_unique($new));
        }
    }
    return array($stored, $changed);
}
