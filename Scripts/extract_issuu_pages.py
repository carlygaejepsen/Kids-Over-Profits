import os
import time
from PIL import Image, ImageChops
from selenium import webdriver
from selenium.webdriver.common.by import By
from selenium.webdriver.common.keys import Keys
from selenium.webdriver.chrome.options import Options

# --- Settings ---
OUTPUT_DIR = "screenshots"
WAIT = 2.5
MAX_PAGES = 100
os.makedirs(OUTPUT_DIR, exist_ok=True)

# --- Attach to existing Chrome ---
options = Options()
options.add_experimental_option("debuggerAddress", "localhost:9222")
driver = webdriver.Chrome(options=options)

html = driver.find_element(By.TAG_NAME, "html")
html.click()

prev_image = None

for i in range(1, MAX_PAGES + 1):
    time.sleep(WAIT)
    filename = os.path.join(OUTPUT_DIR, f"page_{i:03}.png")
    driver.save_screenshot(filename)
    print(f"Saved {filename}")

    if prev_image:
        img1 = Image.open(prev_image)
        img2 = Image.open(filename)
        diff = ImageChops.difference(img1, img2)
        if not diff.getbbox():  # identical
            print("No visual change. Done.")
            os.remove(filename)
            break

    prev_image = filename
    html.send_keys(Keys.ARROW_RIGHT)

driver.quit()
