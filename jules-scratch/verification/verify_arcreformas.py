import os
from playwright.sync_api import sync_playwright, expect

def run(playwright):
    # Get the absolute path to the HTML file
    current_dir = os.path.dirname(os.path.abspath(__file__))
    # Go up three levels from jules-scratch/verification to the repo root
    repo_root = os.path.abspath(os.path.join(current_dir, '..', '..'))
    file_path = os.path.join(repo_root, 'arcreformas.com.br', 'index.html')

    # Ensure the file exists before trying to navigate
    if not os.path.exists(file_path):
        print(f"Error: File not found at {file_path}")
        return

    browser = playwright.chromium.launch(headless=True)
    page = browser.new_page()

    # Navigate to the local HTML file
    page.goto(f'file://{file_path}')

    # Wait for a key element to be visible to ensure the page has rendered
    expect(page.get_by_role("heading", name="Advanced File Storage & Pastebin")).to_be_visible()

    # Take a screenshot
    screenshot_path = os.path.join(current_dir, 'arcreformas_verification.png')
    page.screenshot(path=screenshot_path)

    browser.close()
    print(f"Screenshot saved to {screenshot_path}")

with sync_playwright() as playwright:
    run(playwright)
