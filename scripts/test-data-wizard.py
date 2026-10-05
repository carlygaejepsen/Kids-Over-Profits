"""
Guided start of the public data form (js/data-form/data-wizard.js) on the live
/tti-data-submission/ page, with the working tree's wizard JS/CSS injected:
search, topics, an existing facility opened with only its topics' sections,
a new facility in its state, a new consultant; at 1280 and 390 px.
Screenshots go to tmp/data-wizard/.

    python scripts/test-data-wizard.py
"""
import os, time
from playwright.sync_api import sync_playwright

REPO = os.path.dirname(os.path.dirname(os.path.abspath(__file__))).replace(os.sep, "/") + "/"
OUT = REPO + "tmp/data-wizard/"
os.makedirs(OUT, exist_ok=True)
URL = "https://kidsoverprofits.org/tti-data-submission/"

def inject(pg):
    pg.evaluate("""() => { const h = document.querySelector('.container .admin-header');
        const d = document.createElement('div'); d.id = 'kop-wizard'; d.className = 'kop-wiz'; h.after(d); }""")
    pg.add_style_tag(path=REPO + "css/data-wizard.css")
    pg.add_script_tag(path=REPO + "js/data-form/data-wizard.js")

def visible_sections(pg):
    return pg.evaluate("""() => Array.from(document.querySelectorAll('.container .section, .facility-loader-panel, #private-ownership-toggle-section, #category-navigation, .kop-steps'))
        .filter(e => e.offsetParent !== null).map(e => e.id || e.className.split(' ')[0])""")

errors = []
with sync_playwright() as p:
    b = p.chromium.launch()
    for width in (1280, 390):
        pg = b.new_page(viewport={"width": width, "height": 900})
        pg.on("pageerror", lambda e: errors.append(str(e)))
        pg.goto(URL, wait_until="domcontentloaded", timeout=90000)
        pg.wait_for_function("window.formReady === true", timeout=120000)
        inject(pg)
        print(width, "projects:", pg.evaluate("Object.keys(window.projects||{}).length"))
        print(" pick mode visible:", visible_sections(pg))
        pg.fill("#kop-wiz-q", "turn-about")
        time.sleep(0.5)
        hits = pg.eval_on_selector_all(".kop-wiz-hit", "els => els.map(e => e.innerText.replace(/\\s+/g,' '))")
        print(" hits turn-about:", hits[:5])
        pg.fill("#kop-wiz-q", "aspen")
        time.sleep(0.5)
        print(" hits aspen:", pg.eval_on_selector_all(".kop-wiz-hit", "els => els.map(e => e.innerText.replace(/\\s+/g,' '))")[:6])
        pg.screenshot(path=OUT + f"wiz-find-{width}.png", full_page=False)
        pg.fill("#kop-wiz-q", "turn-about")
        time.sleep(0.4)
        pg.click(".kop-wiz-hit >> nth=0")
        pg.screenshot(path=OUT + f"wiz-topics-{width}.png", full_page=False)
        pg.check(".kop-wiz-topic input[value=staff]")
        pg.check(".kop-wiz-topic input[value=dates]")
        pg.click("button[data-act=go]")
        pg.wait_for_selector(".kop-wiz-bar", timeout=20000)
        time.sleep(1.5)
        print(" project:", pg.evaluate("window.currentProjectName"), "| facility:", pg.evaluate("window.formData.facilities[window.currentFacilityIndex].identification.name"),
              "| tab:", pg.evaluate("document.querySelector('.category-tab.active').dataset.category"))
        print(" focus visible:", visible_sections(pg))
        pg.screenshot(path=OUT + f"wiz-edit-{width}.png", full_page=True)
        hscroll = pg.evaluate("document.documentElement.scrollWidth > window.innerWidth")
        print(" horizontal scroll:", hscroll)
        # new facility path
        pg.click("button[data-act=restart]"); pg.click("button[data-act=restart-yes]")
        pg.click("button[data-new=facility]")
        pg.fill("#kop-wiz-name", "Test Wizard Ranch"); pg.fill("#kop-wiz-place", "Utah")
        pg.click(".kop-wiz-form button[type=submit]")
        pg.click("button[data-act=go]")
        pg.wait_for_selector(".kop-wiz-bar", timeout=20000); time.sleep(1.5)
        print(" new: project", pg.evaluate("window.currentProjectName"), "| facility", pg.evaluate("JSON.stringify([window.formData.facilities[window.currentFacilityIndex].identification.name, window.formData.facilities[window.currentFacilityIndex].locationDetails])"),
              "| name field:", pg.evaluate("document.getElementById('facility-name').value"), "| visible:", visible_sections(pg))
        # new referrer person
        pg.click("button[data-act=restart]"); pg.click("button[data-act=restart-yes]")
        pg.click("button[data-new=referrer]")
        pg.check("input[name=kop-wiz-kind][value=person]")
        pg.fill("#kop-wiz-name", "Jane Q Example")
        pg.click(".kop-wiz-form button[type=submit]")
        pg.click("button[data-act=go]")
        pg.wait_for_selector(".kop-wiz-bar", timeout=20000); time.sleep(1.5)
        print(" referrer: tab", pg.evaluate("document.querySelector('.category-tab.active').dataset.category"),
              "| consultant", pg.evaluate("JSON.stringify(window.formData.referrerConsultants && window.formData.referrerConsultants[0] && [window.formData.referrerConsultants[0].firstName, window.formData.referrerConsultants[0].lastName])"),
              "| visible:", visible_sections(pg))
        pg.screenshot(path=OUT + f"wiz-referrer-{width}.png", full_page=True)
        pg.close()
    b.close()
print("page errors:", errors[:10])
raise SystemExit(1 if errors else 0)
