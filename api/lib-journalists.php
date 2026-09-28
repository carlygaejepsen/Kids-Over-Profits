<?php
/**
 * Journalists who cover the TTI: an internal-only contact list.
 *
 * Built from the bylines on news entries (news_submissions.author). Each
 * byline is split into people ("Sally Ho and Claire Galofaro"), outlet and
 * placeholder bylines ("WSOCTV.com News Staff", "No author name available")
 * are left out, and every person is linked to the articles they wrote. The
 * outlets, article count and first/last dates on the admin page come from
 * those links, so they never go stale.
 *
 * Tables (records DB, created by api/update-schema.php):
 *   journalists          one row per person; name_key is the match key,
 *                        aliases holds other spellings (one per line).
 *                        status 'ignored' keeps a name that is not a
 *                        journalist from being re-created by the next scan.
 *   journalist_articles  journalist_id <-> news_submissions.id
 *
 * Admin-only: managed in api/manage-journalists.php, never exposed through
 * REST, search or a public page. The SQL here is kept portable so
 * scripts/test-journalists.php can run it against tmp/prod.sqlite.
 */

if (!function_exists('kop_journalist_name_key')) {

    /** Article statuses whose bylines count. Rejected entries are usually off-topic. */
    function kop_journalist_article_statuses(): array {
        return ['draft', 'submitted', 'approved', 'published'];
    }

    /** Outreach states for the admin page, value => label. */
    function kop_journalist_outreach_states(): array {
        return [
            'not_contacted'  => 'Not contacted',
            'contacted'      => 'Contacted',
            'responded'      => 'Responded',
            'ongoing'        => 'Ongoing relationship',
            'do_not_contact' => 'Do not contact',
        ];
    }

    /** Match key for a name: lowercase, accents folded, punctuation dropped. */
    function kop_journalist_name_key(?string $name): string {
        $s = trim((string) $name);
        if (function_exists('remove_accents')) {
            $s = remove_accents($s);
        } elseif (class_exists('Normalizer')) {
            $s = preg_replace('/\p{Mn}+/u', '', Normalizer::normalize($s, Normalizer::FORM_D));
        } else {
            $s = strtr($s, [
                'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'ç' => 'c',
                'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'í' => 'i', 'ì' => 'i', 'î' => 'i',
                'ï' => 'i', 'ñ' => 'n', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
                'ő' => 'o', 'ø' => 'o', 'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ű' => 'u',
                'ý' => 'y', 'ÿ' => 'y', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
                'Ñ' => 'N', 'Ç' => 'C', 'Ö' => 'O', 'Ü' => 'U',
            ]);
        }
        $s = strtolower($s);
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
        return trim(preg_replace('/\s+/', ' ', $s));
    }

    /**
     * Split one byline into the names in it. Strips "By", roles after a
     * dash or in brackets, and keeps "Jr."/"III" with the name before it.
     */
    function kop_journalist_split_byline(?string $byline): array {
        $s = trim((string) $byline);
        if ($s === '') {
            return [];
        }
        $s = preg_replace('/\s+/u', ' ', $s);
        $s = preg_replace('/\([^)]*\)|\[[^\]]*\]/u', ' ', $s);
        $s = preg_replace('/^\s*(written\s+)?by[:\s]+/iu', '', $s);

        $parts = preg_split('/\s*(?:,|;|\||&|\band\b|\s[-\x{2013}\x{2014}]\s)\s*/iu', $s);
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p, " \t.:'\"");
            $p = preg_replace('/^by\s+/iu', '', $p);
            if ($p === '') {
                continue;
            }
            if ($out && preg_match('/^(jr|sr|ii|iii|iv)\.?$/i', $p)) {
                $out[count($out) - 1] .= ', ' . $p;
                continue;
            }
            $out[] = $p;
        }
        return $out;
    }

    /**
     * Does this piece of a byline look like a person rather than an outlet,
     * a desk or a placeholder? Two to five words, no digits, no web address,
     * none of the words newsrooms sign with.
     */
    function kop_journalist_is_person_name(string $name): bool {
        if (preg_match('/[0-9@]|https?:|www\.|\.(com|org|net|tv)\b/i', $name)) {
            return false;
        }
        $key = kop_journalist_name_key($name);
        $words = $key === '' ? [] : explode(' ', $key);
        if (count($words) < 2 || count($words) > 5) {
            return false;
        }
        static $junk = null;
        if ($junk === null) {
            $junk = array_flip([
                'staff', 'newsroom', 'news', 'press', 'associated', 'reuters', 'editor', 'editors',
                'editorial', 'team', 'desk', 'report', 'reports', 'reporter', 'reporting',
                'wire', 'contributor', 'contributors', 'writer', 'writers', 'correspondent',
                'unknown', 'author', 'available', 'anonymous', 'none', 'media', 'times', 'tribune',
                'journal', 'herald', 'gazette', 'network', 'channel', 'tv', 'radio', 'daily',
                'weekly', 'magazine', 'inc', 'llc', 'cbs', 'nbc', 'abc', 'fox', 'cnn', 'npr',
                'kids', 'profits', 'the', 'india', 'online', 'digital', 'web', 'producer',
                'bureau', 'service', 'services', 'agency', 'release', 'statement', 'office',
                'department', 'county', 'state', 'university', 'foundation', 'center',
                'international', 'rights', 'newswire', 'bbc', 'amnesty', 'affairs', 'home',
                'senator', 'sen', 'representative', 'rep', 'governor', 'attorney',
            ]);
        }
        foreach ($words as $w) {
            if (isset($junk[$w])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Every journalist match key (names and aliases) => ['id', 'status'].
     */
    function kop_journalist_index(PDO $pdo): array {
        $index = [];
        foreach ($pdo->query("SELECT id, name, aliases, status FROM journalists") as $row) {
            $entry = ['id' => (int) $row['id'], 'status' => $row['status']];
            $keys = [kop_journalist_name_key($row['name'])];
            foreach (preg_split('/\r\n|\r|\n/', (string) $row['aliases']) as $alias) {
                $keys[] = kop_journalist_name_key($alias);
            }
            foreach ($keys as $k) {
                if ($k !== '' && !isset($index[$k])) {
                    $index[$k] = $entry;
                }
            }
        }
        return $index;
    }

    /**
     * Relink one article to the journalists in its byline, creating the
     * ones not seen before. Names already in the table (including ignored
     * ones and names added by hand) match even when the filter would skip
     * them. Returns ['created' => n, 'linked' => n, 'skipped' => [name, ...]].
     *
     * $index is the kop_journalist_index() map; pass it in when syncing
     * many articles so it is built once, and it is kept up to date here.
     */
    function kop_journalist_sync_article(PDO $pdo, int $newsId, ?array &$index = null): array {
        $stats = ['created' => 0, 'linked' => 0, 'skipped' => []];
        if ($index === null) {
            $index = kop_journalist_index($pdo);
        }

        $stmt = $pdo->prepare("SELECT author, status FROM news_submissions WHERE id = ?");
        $stmt->execute([$newsId]);
        $article = $stmt->fetch(PDO::FETCH_ASSOC);

        $pdo->prepare("DELETE FROM journalist_articles WHERE news_id = ?")->execute([$newsId]);
        if (!$article || !in_array($article['status'], kop_journalist_article_statuses(), true)) {
            return $stats;
        }

        $ids = [];
        foreach (kop_journalist_split_byline($article['author']) as $name) {
            $key = kop_journalist_name_key($name);
            if ($key === '') {
                continue;
            }
            if (isset($index[$key])) {
                if ($index[$key]['status'] !== 'ignored') {
                    $ids[$index[$key]['id']] = true;
                }
                continue;
            }
            if (!kop_journalist_is_person_name($name)) {
                $stats['skipped'][] = $name;
                continue;
            }
            $pdo->prepare("INSERT INTO journalists (name, name_key, source) VALUES (?, ?, 'extracted')")
                ->execute([$name, $key]);
            $id = (int) $pdo->lastInsertId();
            $index[$key] = ['id' => $id, 'status' => 'active'];
            $ids[$id] = true;
            $stats['created']++;
        }

        $link = $pdo->prepare("INSERT INTO journalist_articles (journalist_id, news_id) VALUES (?, ?)");
        foreach (array_keys($ids) as $id) {
            $link->execute([$id, $newsId]);
            $stats['linked']++;
        }
        return $stats;
    }

    /**
     * Relink every news entry. Idempotent: a second run creates nothing.
     * Returns totals plus skipped bylines as name => article count.
     */
    function kop_journalist_sync_all(PDO $pdo): array {
        $totals = ['articles' => 0, 'created' => 0, 'linked' => 0, 'skipped' => []];
        $index = kop_journalist_index($pdo);
        $ids = $pdo->query("SELECT id FROM news_submissions ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            $s = kop_journalist_sync_article($pdo, (int) $id, $index);
            $totals['articles']++;
            $totals['created'] += $s['created'];
            $totals['linked'] += $s['linked'];
            foreach ($s['skipped'] as $name) {
                $totals['skipped'][$name] = ($totals['skipped'][$name] ?? 0) + 1;
            }
        }
        arsort($totals['skipped']);
        return $totals;
    }

    /**
     * Save hook for api/save-news-submission.php. Never fails a save: the
     * tables may not exist yet on a database that predates the migration.
     */
    function kop_journalist_sync_article_safe(PDO $pdo, int $newsId): void {
        try {
            kop_journalist_sync_article($pdo, $newsId);
        } catch (Throwable $e) {
            error_log('kop journalists: article ' . $newsId . ': ' . $e->getMessage());
        }
    }

    /**
     * Fold journalist $fromId into $intoId: articles move over, the old
     * name and aliases become aliases, empty contact fields are filled and
     * notes are appended. The old row is deleted.
     */
    function kop_journalist_merge(PDO $pdo, int $intoId, int $fromId): void {
        if ($intoId === $fromId) {
            throw new InvalidArgumentException('Cannot merge a journalist into itself.');
        }
        $get = $pdo->prepare("SELECT * FROM journalists WHERE id = ?");
        $get->execute([$intoId]);
        $into = $get->fetch(PDO::FETCH_ASSOC);
        $get->execute([$fromId]);
        $from = $get->fetch(PDO::FETCH_ASSOC);
        if (!$into || !$from) {
            throw new InvalidArgumentException('Journalist not found.');
        }

        $aliases = [];
        foreach ([$into['aliases'], $from['name'], $from['aliases']] as $block) {
            foreach (preg_split('/\r\n|\r|\n/', (string) $block) as $a) {
                $a = trim($a);
                $k = kop_journalist_name_key($a);
                if ($a !== '' && $k !== $into['name_key']) {
                    $aliases[$k] = $a;
                }
            }
        }
        $fields = ['email', 'phone', 'social', 'website', 'outlet', 'location', 'beat'];
        $set = ['aliases' => implode("\n", $aliases)];
        foreach ($fields as $f) {
            $set[$f] = trim((string) $into[$f]) !== '' ? $into[$f] : $from[$f];
        }
        $notes = array_filter([trim((string) $into['notes']), trim((string) $from['notes'])]);
        $set['notes'] = implode("\n\n", $notes);
        if ($into['outreach'] === 'not_contacted' && $from['outreach'] !== 'not_contacted') {
            $set['outreach'] = $from['outreach'];
        }

        $pdo->beginTransaction();
        try {
            $cols = implode(', ', array_map(static fn($c) => "$c = ?", array_keys($set)));
            $pdo->prepare("UPDATE journalists SET $cols WHERE id = ?")
                ->execute(array_merge(array_values($set), [$intoId]));
            $pdo->prepare(
                "DELETE FROM journalist_articles WHERE journalist_id = ?
                   AND news_id IN (SELECT news_id FROM (SELECT news_id FROM journalist_articles WHERE journalist_id = ?) t)"
            )->execute([$fromId, $intoId]);
            $pdo->prepare("UPDATE journalist_articles SET journalist_id = ? WHERE journalist_id = ?")
                ->execute([$intoId, $fromId]);
            $pdo->prepare("DELETE FROM journalists WHERE id = ?")->execute([$fromId]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
