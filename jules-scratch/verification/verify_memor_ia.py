import os
from playwright.sync_api import sync_playwright, expect

def run(playwright):
    browser = playwright.chromium.launch(headless=True)
    page = browser.new_page()

    # Navigate to the local server
    page.goto('http://localhost:8081')

    # Wait for a key element to be visible to ensure the page has rendered
    expect(page.get_by_role("heading", name="memor.ia.br — Todos")).to_be_visible()

    # Check that the new "Undo" button exists in the DOM.
    # It is expected to be hidden by default (`display:none`).
    undo_button = page.locator('#undo')
    expect(undo_button).to_be_hidden()
    expect(undo_button).to_have_text('Undo')

    # Take a screenshot
    current_dir = os.path.dirname(os.path.abspath(__file__))
    screenshot_path = os.path.join(current_dir, 'memor_ia_verification.png')
    page.screenshot(path=screenshot_path)

    browser.close()
    print(f"Screenshot saved to {screenshot_path}")

with sync_playwright() as playwright:
    run(playwright)
