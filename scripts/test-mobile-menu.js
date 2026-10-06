#!/usr/bin/env node
/**
 * js/mobile-menu.js against a stand-in for Kadence's mobile drawer: the
 * drawer's markup, a handler that closes it on any link tap (what Kadence
 * does), and its arrow buttons that open a submenu.
 *
 *   node scripts/test-mobile-menu.js
 *
 * Checks that tapping a "#" group label opens its list and keeps the menu
 * open, tapping it again closes the list, the arrow still works, a "#" link
 * with no list does nothing, and a real link still closes the menu.
 */
'use strict';

const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const script = fs.readFileSync(path.join(__dirname, '..', 'js', 'mobile-menu.js'), 'utf8');

const html = `<!doctype html><html><body>
<div id="mobile-drawer" class="popup-drawer show-drawer active">
  <div class="drawer-inner"><button class="menu-toggle-close">x</button>
  <nav class="mobile-navigation"><ul class="menu">
    <li class="menu-item menu-item-has-children" id="monitor">
      <div class="drawer-nav-drop-wrap"><a href="#">Monitor</a><button class="drawer-sub-toggle" aria-expanded="false">v</button></div>
      <ul class="sub-menu"><li class="menu-item"><a id="severe" href="#severe-reports">Severe Reports</a></li></ul>
    </li>
    <li class="menu-item menu-item-has-children" id="learn">
      <div class="drawer-nav-drop-wrap"><a href="/glossary/">Learn More</a><button class="drawer-sub-toggle" aria-expanded="false">v</button></div>
      <ul class="sub-menu"><li class="menu-item"><a href="#x">Glossary</a></li></ul>
    </li>
    <li class="menu-item" id="lone"><a href="#">Nothing</a></li>
  </ul></nav></div>
</div>
<script>
  // Kadence stand-ins: arrows toggle their list, any link tap closes the drawer.
  var drawer = document.getElementById('mobile-drawer');
  drawer.querySelectorAll('.drawer-sub-toggle').forEach(function (b) {
    b.addEventListener('click', function () {
      var li = b.closest('li');
      var open = li.classList.toggle('menu-item--toggled-on');
      b.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  });
  drawer.querySelectorAll('a').forEach(function (a) {
    a.addEventListener('click', function (e) {
      if (a.getAttribute('href').charAt(0) !== '/') e.preventDefault();
      drawer.classList.remove('show-drawer', 'active');
    });
  });
</script>
<script>${script}</script>
</body></html>`;

let failed = 0;
function check(ok, what) {
    console.log((ok ? '  ok   ' : '  FAIL ') + what);
    if (!ok) failed++;
}

(async () => {
    const browser = await chromium.launch();
    const page = await browser.newPage({ viewport: { width: 390, height: 800 }, hasTouch: true });
    await page.route('http://kop.test/**', (r) => r.fulfill({ contentType: 'text/html', body: html }));
    await page.goto('http://kop.test/page/');

    const state = () => page.evaluate(() => ({
        open: document.getElementById('mobile-drawer').classList.contains('show-drawer'),
        monitor: document.getElementById('monitor').classList.contains('menu-item--toggled-on'),
        expanded: document.querySelector('#monitor .drawer-sub-toggle').getAttribute('aria-expanded'),
    }));

    await page.tap('#monitor > .drawer-nav-drop-wrap > a');
    let s = await state();
    check(s.open && s.monitor && s.expanded === 'true', 'tapping the "#" label "Monitor" opens its list and the menu stays open');

    await page.tap('#monitor > .drawer-nav-drop-wrap > a');
    s = await state();
    check(s.open && !s.monitor && s.expanded === 'false', 'tapping it again closes the list, menu still open');

    await page.tap('#monitor .drawer-sub-toggle');
    s = await state();
    check(s.open && s.monitor, 'the arrow still opens the list');

    await page.tap('#lone > a');
    s = await state();
    check(s.open && page.url() === 'http://kop.test/page/', 'a "#" link with no list does nothing');

    await page.tap('#severe');
    s = await state();
    check(!s.open, 'an anchor link to a section still closes the menu (Kadence)');

    await page.evaluate(() => document.getElementById('mobile-drawer').classList.add('show-drawer', 'active'));
    await Promise.all([page.waitForURL('http://kop.test/glossary/'), page.tap('#learn > .drawer-nav-drop-wrap > a')]);
    check(page.url() === 'http://kop.test/glossary/', 'a group label with a real page still goes to that page');

    await browser.close();
    console.log(failed ? `\n${failed} failed` : '\nAll checks passed');
    process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
