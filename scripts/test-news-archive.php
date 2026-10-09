<?php
/**
 * An article's real link and archived copy (api/lib-news-archive.php):
 * the rules on fixed cases, PHP == js/news-archive-links.js == the extension's
 * archive.js (needs node), and the one-time split of news_submissions rows on
 * an in-memory copy of tmp/prod.sqlite (nothing written to the mirror).
 *
 *   php -d extension=pdo_sqlite scripts/test-news-archive.php [--list]
 */

require __DIR__ . '/../api/lib-news-archive.php';

$fail = 0;
$check = function ($label, $got, $want) use (&$fail) {
    if ($got === $want) return;
    $fail++;
    echo "FAIL $label\n  got:  " . json_encode($got, JSON_UNESCAPED_SLASHES) . "\n  want: " . json_encode($want, JSON_UNESCAPED_SLASHES) . "\n";
};

$wb = 'https://web.archive.org/web/20230926152235/https://www.tampabay.com/news/crime/2022/06/24/x/';
$check('wayback is archive', kop_news_is_archive_url($wb), true);
$check('archive.ph is archive', kop_news_is_archive_url('https://archive.ph/RkCxj'), true);
$check('archive.org details is not', kop_news_is_archive_url('https://archive.org/details/woodbury'), false);
$check('news site is not', kop_news_is_archive_url('https://www.sltrib.com/x'), false);
$check('unwrap', kop_news_unwrap_archive_url($wb), 'https://www.tampabay.com/news/crime/2022/06/24/x/');
$check('unwrap id_', kop_news_unwrap_archive_url('https://web.archive.org/web/2014id_/http://www.boston.com/a.html'), 'http://www.boston.com/a.html');
$check('unwrap bare host', kop_news_unwrap_archive_url('http://web.archive.org/web/2017/sequelyouthservices.com/html/x.html'), 'http://sequelyouthservices.com/html/x.html');
$check('unwrap single slash', kop_news_unwrap_archive_url('https://web.archive.org/web/2017/https:/example.com/a'), 'https://example.com/a');
$check('unwrap short link', kop_news_unwrap_archive_url('https://archive.ph/RkCxj'), '');

$check('split wayback in url box', kop_news_split_urls($wb, ''), array('url' => 'https://www.tampabay.com/news/crime/2022/06/24/x/', 'archive' => $wb));
$check('split keeps given archive', kop_news_split_urls('https://a.com/x', 'https://archive.ph/abc'), array('url' => 'https://a.com/x', 'archive' => 'https://archive.ph/abc'));
$check('split short link stays', kop_news_split_urls('https://archive.ph/RkCxj', ''), array('url' => 'https://archive.ph/RkCxj', 'archive' => 'https://archive.ph/RkCxj'));
$check('split non-archive in archive box', kop_news_split_urls('https://a.com/x', 'https://a.com/x'), array('url' => 'https://a.com/x', 'archive' => ''));
$check('split only archive box', kop_news_split_urls('', $wb), array('url' => 'https://www.tampabay.com/news/crime/2022/06/24/x/', 'archive' => $wb));

$check('links plain', kop_news_links('https://a.com/x', ''), array('original' => 'https://a.com/x', 'archive' => ''));
$check('links both', kop_news_links('https://a.com/x', $wb), array('original' => 'https://a.com/x', 'archive' => $wb));
$check('links old row', kop_news_links($wb, ''), array('original' => 'https://www.tampabay.com/news/crime/2022/06/24/x/', 'archive' => $wb));
$check('links short only', kop_news_links('https://archive.ph/RkCxj', null), array('original' => '', 'archive' => 'https://archive.ph/RkCxj'));
$check('html both', kop_news_archive_link_html('https://a.com/x', 'https://archive.ph/a'), ' <a class="kop-archived-link" href="https://archive.ph/a" target="_blank" rel="noopener noreferrer">archived copy</a>');
$check('html none for short only', kop_news_archive_link_html('https://archive.ph/RkCxj', ''), '');

// PHP == JS on every case (site script and the extension's module).
$cases = array(
    array($wb, ''), array('https://a.com/x', $wb), array('https://archive.ph/RkCxj', ''), array('', $wb),
    array('https://a.com/x', ''), array('https://web.archive.org/web/2014id_/http://www.boston.com/a.html', ''),
    array('http://web.archive.org/web/2017/sequelyouthservices.com/html/x.html', ''), array('https://archive.org/details/x', ''),
    array('https://perma.cc/AB12-CD34', 'https://a.com/y'), array('ftp://x.com/a', 'javascript:alert(1)'),
);
$node = trim((string) shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node 2>/dev/null'));
if ($node === '') {
    echo "skip: node not found, PHP == JS not checked\n";
} else {
    $root = realpath(__DIR__ . '/..');
    $site = str_replace('\\', '/', $root . '/js/news-archive-links.js');
    $ext  = str_replace('\\', '/', $root . '/browser-extension/send-to-kop/archive.js');
    $js = 'const site = require(' . json_encode($site) . ');'
        . 'import(' . json_encode('file:///' . ltrim($ext, '/')) . ').then((ext) => {'
        . 'const cases = ' . json_encode($cases) . ';'
        . 'console.log(JSON.stringify(cases.map(([a, b]) => ({ links: site.links(a, b), html: site.archiveLinkHtml(a, b),'
        . ' isA: site.isArchiveUrl(a), extIsA: ext.isArchiveUrl(a), un: site.unwrap(a), extUn: ext.unwrapArchiveUrl(a) }))));});';
    $tmp = tempnam(sys_get_temp_dir(), 'kopna') . '.cjs';
    file_put_contents($tmp, $js);
    $out = json_decode((string) shell_exec('node ' . escapeshellarg($tmp)), true);
    @unlink($tmp);
    if (!is_array($out)) {
        $fail++;
        echo "FAIL node run gave no answer\n";
    } else {
        foreach ($cases as $i => $c) {
            $label = 'php==js #' . $i . ' ' . $c[0];
            $check("$label links", $out[$i]['links'], kop_news_links($c[0], $c[1]));
            $check("$label html", $out[$i]['html'], kop_news_archive_link_html($c[0], $c[1]));
            $check("$label isArchive", $out[$i]['isA'], kop_news_is_archive_url($c[0]));
            $check("$label ext isArchive", $out[$i]['extIsA'], kop_news_is_archive_url($c[0]));
            $check("$label unwrap", $out[$i]['un'], kop_news_unwrap_archive_url($c[0]));
            $check("$label ext unwrap", $out[$i]['extUn'], kop_news_unwrap_archive_url($c[0]));
        }
    }
}

// The one-time split, on a copy of the mirror's news rows.
$mirror = __DIR__ . '/../tmp/prod.sqlite';
if (!is_file($mirror) || !extension_loaded('pdo_sqlite')) {
    echo "skip: no tmp/prod.sqlite or pdo_sqlite, split not checked\n";
} else {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("ATTACH DATABASE " . $pdo->quote(realpath($mirror)) . " AS m");
    $pdo->exec('CREATE TABLE news_submissions AS SELECT id, article_url, json_data FROM m.news_submissions');
    $pdo->exec('DETACH DATABASE m');
    $check('ensure adds the column', kop_news_archive_ensure($pdo), true);
    $dry = kop_news_archive_split_existing($pdo, false);
    $r = kop_news_archive_split_existing($pdo, true);
    $check('dry run == apply', count($dry['changed']), count($r['changed']));
    $again = kop_news_archive_split_existing($pdo, true);
    $check('second pass changes nothing', count($again['changed']), 0);
    $unwrapped = 0;
    foreach ($r['changed'] as $c) {
        if ($c['url'] !== $c['from']) $unwrapped++;
        if ($c['archive'] !== $c['from']) { $fail++; echo "FAIL archive kept for #{$c['id']}\n"; }
    }
    $left = (int) $pdo->query("SELECT COUNT(*) FROM news_submissions WHERE article_url LIKE '%web.archive.org/web/%'")->fetchColumn();
    echo 'split: ' . count($r['changed']) . ' rows had only an archive link, ' . $unwrapped . ' now point at the article, '
        . (count($r['changed']) - $unwrapped) . " short links kept; $left Wayback links left in article_url\n";
    $j = json_decode((string) $pdo->query('SELECT json_data FROM news_submissions WHERE archive_url IS NOT NULL AND json_data LIKE \'{%\' LIMIT 1')->fetchColumn(), true);
    if (is_array($j)) $check('json_data follows', isset($j['archiveUrl']) && $j['archiveUrl'] !== '', true);
    if (in_array('--list', $argv, true)) {
        foreach ($r['changed'] as $c) echo "#{$c['id']}  {$c['url']}\n        {$c['archive']}\n";
    }
}

echo $fail ? "$fail failed\n" : "all passed\n";
exit($fail ? 1 : 0);
