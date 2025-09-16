# Validation Plan

This document outlines the manual and automated checks required to verify the correct deployment and configuration of the three domains.

## arcreformas.com.br (API backend + file storage)

### Static
- [ ] GET `/index.html` → returns homepage.
- [ ] GET `/style.css` → returns CSS with correct MIME type.

### API endpoints
- [ ] GET `/api/files` → returns JSON list.
- [ ] POST `/api/files` with a file upload → succeeds, metadata recorded in DB, file persisted.
- [ ] DELETE `/api/files/{id}` → returns JSON confirmation.
- [ ] GET `/api/tasks/public` → returns board JSON.
- [ ] POST `/api/tasks/public` with JSON add → adds task.
- [ ] POST `/api/links` with JSON body {url} → creates shortlink.
- [ ] GET `/api/links/{slug}` → resolves and returns JSON or 404.
- [ ] GET `/api/unknown` → returns JSON 404.

### CORS/security
- [ ] OPTIONS request to `/api/tasks/public` → returns 204 with headers.
- [ ] Verify 403/404 for `/.git`, `/src/config.php`, etc.

## memor.ia.br (Todo PWA)

- [ ] GET `/` → SPA loads `index.php`.
- [ ] GET `/?b=inbox` → loads inbox tasks via API at arcreformas.
- [ ] GET `/ics.php?b=inbox` → returns valid iCalendar.
- [ ] GET `/manifest.json` → loads JSON manifest with icons.
- [ ] GET `/sw.js` → service worker served with JS MIME type, caching strategy active.
- [ ] Add a task via POST to `https://arcreformas.com.br/api/tasks/public` and confirm it appears on `memor.ia.br`.
- [ ] Test offline → service worker caches app shell and restores tasks list.

## cut.ia.br (gateway/publisher/shortener)

- [ ] GET `/` → homepage loads with forms for shorten/publish.
- [ ] POST `/?op=new-item` with JSON {url, filename, type} → accepted, event logged, and arcreformas `/api/tasks` updated.
- [ ] POST `/?op=publish_md` with payload → returns JSON 201 if token is valid.
- [ ] GET `/?s={slug}` → redirects 302 to original URL or 404 if missing.
- [ ] GET `/?op=dash` → dashboard shows ICS + drive + site status.
- [ ] POST `/?op=bus_emit` with {type,payload} → event stored and retrievable via GET `/?op=bus_tail&n=10`.
