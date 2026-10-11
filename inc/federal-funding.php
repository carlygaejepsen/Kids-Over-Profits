<?php
/**
 * Federal funding on facility records (docs/PLAN.md 3.15).
 *
 * Research of 2026-10-11 listed 133 programs that received federal money and
 * operated since October 2021: Medicaid (state rosters, the Senate Finance
 * Committee's 2024 "Warehouses of Neglect" report), federal foster care funds,
 * Office of Refugee Resettlement grants for unaccompanied children, TRICARE,
 * Paycheck Protection Program loans and Department of Education aid. Each
 * program is a proposal in js/data/federal-funding/proposals.json: its name and
 * place, the records it may be (candidates, best first, with how they matched),
 * and its funding entries, each with what the source says and its links.
 *
 * The owner reviews them in the review inbox (queue "Federal funding",
 * inc/review-inbox/federal-funding.php): Add puts the entries on the record
 * picked (the record's federalFunding field, kop_facility_federal_funding_list()
 * in inc/facility-store.php), shown on its facility page; a program with no
 * record can get one made first (kop_facdisc_create(), like State Lists).
 * Undo takes off exactly the entries it added (each carries a ref), and
 * deletes a record it made while nobody has edited it since.
 *
 * Decisions: option kop_federal_funding_review, key => {decision (applied |
 * rejected), by, at, facility_id, refs, created, doc}. Edits to an entry's
 * wording before it is added: option kop_federal_funding_edits, key => {n =>
 * {detail, years}}.
 *
 * @package KidsOverProfits
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Who found the entries, as the record's entries say it. */
const KOP_FFUND_SOURCE = 'Federal funding research, October 2026';

/** The proposals, key => proposal. */
function kop_ffund_proposals() {
    static $memo = null;
    if ($memo !== null) return $memo;
    $memo = array();
    $file = get_stylesheet_directory() . '/js/data/federal-funding/proposals.json';
    $list = is_readable($file) ? json_decode((string) file_get_contents($file), true) : array();
    foreach ((array) $list as $p) {
        if (is_array($p) && !empty($p['key']) && is_array($p['entries'] ?? null)) $memo[(string) $p['key']] = $p;
    }
    return $memo;
}

function kop_ffund_decisions() {
    $saved = get_option('kop_federal_funding_review', array());
    return is_array($saved) ? $saved : array();
}

function kop_ffund_save_decisions(array $decisions) {
    update_option('kop_federal_funding_review', $decisions, false);
}

function kop_ffund_edits() {
    $saved = get_option('kop_federal_funding_edits', array());
    return is_array($saved) ? $saved : array();
}

/** The proposal's entries as they will go on the record: the reviewer's wording, a ref and the credit. */
function kop_ffund_entries($key, array $p) {
    $edits = kop_ffund_edits()[$key] ?? array();
    $out = array();
    foreach (array_values($p['entries']) as $n => $e) {
        $e = array_merge($e, array_intersect_key((array) ($edits[$n] ?? array()), array('detail' => 1, 'years' => 1)));
        unset($e['confidence']);
        $e['ref'] = 'ff:' . $key . ':' . $n;
        $e['source'] = KOP_FFUND_SOURCE;
        $out[] = $e;
    }
    return kop_facility_federal_funding_list($out);
}

/**
 * Add entries to a document. Returns the refs added: an entry whose ref the
 * record already holds is not added twice.
 */
function kop_ffund_doc_add(array &$doc, array $entries) {
    $list = kop_facility_federal_funding_list($doc['federalFunding'] ?? array());
    $have = array();
    foreach ($list as $e) if ($e['ref'] !== '') $have[$e['ref']] = true;
    $added = array();
    foreach ($entries as $e) {
        if ($e['ref'] !== '' && isset($have[$e['ref']])) continue;
        $list[] = $e;
        if ($e['ref'] !== '') $added[] = $e['ref'];
    }
    $doc['federalFunding'] = $list;
    return $added;
}

/** Take entries off a document by ref. Returns how many came off. */
function kop_ffund_doc_remove(array &$doc, array $refs) {
    $refs = array_flip(array_map('strval', $refs));
    $before = kop_facility_federal_funding_list($doc['federalFunding'] ?? array());
    $after = array_values(array_filter($before, function ($e) use ($refs) { return !isset($refs[$e['ref']]); }));
    $doc['federalFunding'] = $after;
    return count($before) - count($after);
}

function kop_ffund_opts() {
    if (!function_exists('kop_wbf_opts')) throw new RuntimeException('The facility store is not loaded.');
    return kop_wbf_opts();
}

/** Add the proposal's entries to record $fid. Returns the message. */
function kop_ffund_apply($key, $fid, $reviewer) {
    $p = kop_ffund_proposals()[$key] ?? null;
    if (!$p) throw new RuntimeException('That program is not in the list any more.');
    $decisions = kop_ffund_decisions();
    if (($decisions[$key]['decision'] ?? '') === 'applied') throw new RuntimeException('Added already: undo it first.');
    $fid = (int) $fid;
    if ($fid <= 0) throw new RuntimeException('Pick the record the funding goes on.');
    $opts = kop_ffund_opts();
    $entries = kop_ffund_entries($key, $p);
    $refs = array();
    kop_v2_with_write_lock($opts['pdo'], function () use ($fid, $opts, $entries, &$refs) {
        $stored = kop_facility_load($fid, $opts);
        if (!$stored) throw new RuntimeException('Record #' . $fid . ' is not on file.');
        $doc = $stored['doc'];
        $refs = kop_ffund_doc_add($doc, $entries);
        if ($refs) kop_wbf_save($doc, $opts);
    });
    $decisions[$key] = array('decision' => 'applied', 'by' => (string) $reviewer, 'at' => gmdate('Y-m-d H:i:s'),
        'facility_id' => $fid, 'refs' => $refs, 'created' => false);
    kop_ffund_save_decisions($decisions);
    $name = wp_strip_all_tags(kop_facility_finder_label($opts['pdo'], $fid));
    return $refs
        ? 'Added ' . count($refs) . ' federal funding entr' . (count($refs) === 1 ? 'y' : 'ies') . ' to ' . $name . '. Its facility page shows them now; Undo is on the Added tab.'
        : $name . ' already has these entries; nothing was added.';
}

/** Make a record for a program KOP has none for, then add its entries. Returns the message. */
function kop_ffund_create($key, array $fields, $reviewer) {
    $p = kop_ffund_proposals()[$key] ?? null;
    if (!$p) throw new RuntimeException('That program is not in the list any more.');
    $decisions = kop_ffund_decisions();
    if (($decisions[$key]['decision'] ?? '') === 'applied') throw new RuntimeException('Added already: undo it first.');
    if (!function_exists('kop_facdisc_create')) throw new RuntimeException('The record creator (inc/facility-discovery.php) is not loaded.');
    $name = trim(preg_replace('/\s+/u', ' ', (string) ($fields['name'] ?? '')));
    $state = strtoupper(trim((string) ($fields['state'] ?? '')));
    $type = (string) ($fields['type'] ?? '');
    if ($name === '' || !preg_match('/^[A-Z]{2}$/', $state)) throw new RuntimeException('A name and a two-letter state are needed.');
    if ($type !== '' && !in_array($type, kop_facdisc_types(), true)) throw new RuntimeException('Type: pick one from the list.');
    $opts = kop_ffund_opts();
    $entry = array(
        'name' => $name, 'officialName' => $name, 'otherNames' => array(), 'city' => trim((string) ($fields['city'] ?? '')),
        'state' => $state, 'country' => 'United States', 'type' => $type, 'status' => 'Unknown', 'startYear' => null, 'endYear' => null,
        'operator' => trim((string) ($fields['operator'] ?? '')), 'gender' => '',
    );
    $first = $p['entries'][0]['sources'][0] ?? array();
    $source = array('id' => 0, 'source_note' => 'Added from ' . KOP_FFUND_SOURCE . (!empty($first['url']) ? ': ' . $first['url'] : '') . '.',
        'provenance_source' => 'federal-funding', 'provenance_category' => 'federal-funding', 'resource_links' => array());
    list($decision, $fid) = kop_facdisc_create($opts['pdo'], $entry, $source);
    if ($decision !== 'created') {
        // That name and place has a record already: the entries go on it.
        return 'That name and place already has a record. ' . kop_ffund_apply($key, $fid, $reviewer);
    }
    $refs = array();
    $doc_after = null;
    kop_v2_with_write_lock($opts['pdo'], function () use ($fid, $opts, $key, $p, &$refs, &$doc_after) {
        $stored = kop_facility_load($fid, $opts);
        $doc = $stored['doc'];
        $refs = kop_ffund_doc_add($doc, kop_ffund_entries($key, $p));
        kop_wbf_save($doc, $opts);
        $doc_after = kop_facility_load($fid, $opts)['doc'];
    });
    $decisions[$key] = array('decision' => 'applied', 'by' => (string) $reviewer, 'at' => gmdate('Y-m-d H:i:s'),
        'facility_id' => (int) $fid, 'refs' => $refs, 'created' => true, 'doc' => $doc_after);
    kop_ffund_save_decisions($decisions);
    return 'Created ' . wp_strip_all_tags(kop_facility_finder_label($opts['pdo'], $fid)) . ' with ' . count($refs)
        . ' federal funding entr' . (count($refs) === 1 ? 'y' : 'ies') . '. Undo on the Added tab deletes the record while nobody has edited it.';
}

/** Put back what Add did. Returns the message. */
function kop_ffund_undo($key) {
    $decisions = kop_ffund_decisions();
    $d = $decisions[$key] ?? null;
    if (!$d) throw new RuntimeException('Nothing to undo.');
    $msg = 'Undone. It is waiting for review again.';
    if (($d['decision'] ?? '') === 'applied') {
        $opts = kop_ffund_opts();
        $fid = (int) $d['facility_id'];
        $stored = kop_facility_load($fid, $opts);
        if ($stored && !empty($d['created']) && !empty($d['doc']) && kop_facility_same_document($d['doc'], $stored['doc'])) {
            kop_ffund_delete_record($opts, $fid);
            $msg = 'Undone: the record made for it is deleted, and it is waiting for review again.';
        } elseif ($stored) {
            $removed = 0;
            kop_v2_with_write_lock($opts['pdo'], function () use ($fid, $opts, $d, &$removed) {
                $doc = kop_facility_load($fid, $opts)['doc'];
                $removed = kop_ffund_doc_remove($doc, (array) ($d['refs'] ?? array()));
                if ($removed) kop_wbf_save($doc, $opts);
            });
            $msg = 'Undone: ' . $removed . ' entr' . ($removed === 1 ? 'y' : 'ies') . ' came off the record'
                . (!empty($d['created']) ? ' (it was edited since it was made, so the record stays)' : '') . '. It is waiting for review again.';
        }
    }
    unset($decisions[$key]);
    kop_ffund_save_decisions($decisions);
    return $msg;
}

/** Delete a record Add made (the rule of kop_sl_delete_created(): only while nothing points at it). */
function kop_ffund_delete_record(array $opts, $fid) {
    $pdo = $opts['pdo'];
    foreach (array('news_facility_links' => 'an article', 'lawsuit_facility_links' => 'a lawsuit') as $t => $what) {
        try {
            $q = $pdo->prepare("SELECT COUNT(*) FROM `{$t}` WHERE facility_id = ?");
            $q->execute(array((int) $fid));
            if ((int) $q->fetchColumn() > 0) throw new RuntimeException('The record has ' . $what . ' linked to it now; unlink it first, or keep the record.');
        } catch (PDOException $e) {
            // That table is not on this install.
        }
    }
    $t = kop_migration_tables($opts['prefix']);
    kop_v2_with_write_lock($pdo, function () use ($pdo, $t, $fid) {
        foreach (array($t['facility_locations'], $t['operator_facilities'], $t['identity']) as $table) {
            try {
                $pdo->prepare("DELETE FROM `{$table}` WHERE facility_id = ?")->execute(array((int) $fid));
            } catch (PDOException $e) {
                // Not on this install.
            }
        }
        $pdo->prepare("DELETE FROM `{$t['facilities']}` WHERE id = ?")->execute(array((int) $fid));
    });
}

function kop_ffund_reject($key, $reviewer) {
    if (!isset(kop_ffund_proposals()[$key])) throw new RuntimeException('That program is not in the list any more.');
    $decisions = kop_ffund_decisions();
    if (($decisions[$key]['decision'] ?? '') === 'applied') throw new RuntimeException('Added already: undo it first.');
    $decisions[$key] = array('decision' => 'rejected', 'by' => (string) $reviewer, 'at' => gmdate('Y-m-d H:i:s'));
    kop_ffund_save_decisions($decisions);
    return 'Left off. No record changed.';
}

/** Save the reviewer's wording for a proposal's entries ($fields: detail_N, years_N). */
function kop_ffund_save_edits($key, array $fields) {
    $p = kop_ffund_proposals()[$key] ?? null;
    if (!$p) throw new RuntimeException('That program is not in the list any more.');
    if ((kop_ffund_decisions()[$key]['decision'] ?? '') === 'applied') throw new RuntimeException('Undo it before changing the wording.');
    $all = kop_ffund_edits();
    $mine = (array) ($all[$key] ?? array());
    foreach (array_keys($p['entries']) as $n) {
        foreach (array('detail', 'years') as $f) {
            if (!array_key_exists($f . '_' . $n, $fields)) continue;
            $v = trim((string) $fields[$f . '_' . $n]);
            if ($f === 'years' && $v !== '' && !preg_match('/^\d{4}(-\d{4})?$/', $v)) throw new RuntimeException('Years: write one year (2022) or a range (2020-2024).');
            if ($v === (string) ($p['entries'][$n][$f] ?? '')) unset($mine[$n][$f]);
            else $mine[$n][$f] = $v;
        }
        if (empty($mine[$n])) unset($mine[$n]);
    }
    if ($mine) $all[$key] = $mine;
    else unset($all[$key]);
    update_option('kop_federal_funding_edits', $all, false);
    return 'Saved.';
}
