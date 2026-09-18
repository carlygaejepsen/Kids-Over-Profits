<?php
/**
 * Offline checks for api/news-tags.php (no WordPress, no database).
 *
 *   php scripts/test-news-tags.php
 */

require __DIR__ . '/../api/news-tags.php';

$failures = 0;
function check($label, $actual, $expected) {
    global $failures;
    if ($actual === $expected) {
        echo "ok   $label\n";
        return;
    }
    $failures++;
    echo "FAIL $label\n     expected " . var_export($expected, true) . "\n     got      " . var_export($actual, true) . "\n";
}

// Synonyms collapse onto one label, whatever the casing.
check('youth detention', kop_news_tag_canonical('youth detention'), 'Juvenile Justice');
check('Juvenile Detention', kop_news_tag_canonical('Juvenile Detention'), 'Juvenile Justice');
check('juvenile justice casing', kop_news_tag_canonical('juvenile justice'), 'Juvenile Justice');
check('escapes', kop_news_tag_canonical('Escapes'), 'Escape');
check('facility closure', kop_news_tag_canonical('facility closure'), 'Closure');
check('teen death', kop_news_tag_canonical('Teen Death'), 'Death');
check('lgbtq+ rights', kop_news_tag_canonical('LGBTQ+ rights'), 'LGBTQ+ Rights');
check('lgbtq rights', kop_news_tag_canonical('lgbtq rights'), 'LGBTQ+ Rights');
check('survivor story', kop_news_tag_canonical('survivor story'), 'Survivor Stories');

// U+2011 non-breaking hyphen from the AI matches the plain-hyphen spelling.
check('faith-based (U+2011)', kop_news_tag_canonical("faith\u{2011}based program"), 'Faith-Based Programs');
check('self-harm (U+2011)', kop_news_tag_canonical("self\u{2011}harm"), 'Self-Harm');

// Location auto-tags.
check('state code IN', kop_news_tag_canonical('IN'), 'Indiana');
check('state code NY', kop_news_tag_canonical('NY'), 'New York');
check('sub-state region', kop_news_tag_canonical('Southwest Missouri'), 'Missouri');
check('province -> country', kop_news_tag_canonical('Ontario'), 'Canada');

// Casing of tags with no synonym entry.
check('title case', kop_news_tag_canonical('  hyde   school '), 'Hyde School');
check('acronym kept', kop_news_tag_canonical('UHS'), 'UHS');
check('WWASP override', kop_news_tag_canonical('wwasp'), 'WWASP');
check('small word', kop_news_tag_canonical('contempt of court'), 'Contempt of Court');
check('family law merge', kop_news_tags_normalize(['Divorce', 'child support', 'Visitation Rights']), ['Family Law']);
check('empty', kop_news_tag_canonical('   '), '');

// Generic tags (and their synonyms) are hidden from the feed.
check('display hides abuse', kop_news_tag_display('Abuse'), null);
check('display hides synonym of generic', kop_news_tag_display('child sexual abuse'), null);
check('display hides USA', kop_news_tag_display('USA'), null);
check('display keeps topic', kop_news_tag_display('wilderness therapy'), 'Wilderness Therapy');

// Storage normalization: canonical, deduped, order kept, generic kept.
check(
    'normalize list',
    kop_news_tags_normalize(['youth detention', 'Juvenile Justice', 'Abuse', "faith\u{2011}based therapy", '', 'Faith-Based Program']),
    ['Juvenile Justice', 'Abuse', 'Faith-Based Programs']
);
check('normalize newline string', kop_news_tags_normalize("escapes\nRunaway\nRiot"), ['Escape', 'Riot']);
check('normalize JSON string', kop_news_tags_normalize('["Escapes","riot"]'), ['Escape', 'Riot']);
check('normalize idempotent', kop_news_tags_normalize(kop_news_tags_normalize(['teen death', 'Trial', 'mistrial'])), ['Death', 'Trial']);

// Every canonical label maps to itself (no chains, no drift on a second pass).
$drift = [];
foreach (array_unique(array_values(kop_news_tag_synonyms())) as $label) {
    if (kop_news_tag_canonical($label) !== $label) {
        $drift[] = $label . ' -> ' . kop_news_tag_canonical($label);
    }
}
check('canonical labels are fixed points', $drift, []);

echo $failures ? "\n$failures failure(s)\n" : "\nall passed\n";
exit($failures ? 1 : 0);
