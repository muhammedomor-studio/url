# Short-Link URL Shortener

A PHP URL shortener designed for Render + GitHub Contents API. The JSON data file is stored in your repository.

## Files
- `index.php` — web UI, redirects, API and admin dashboard
- `Dockerfile` — PHP runtime for Render
- `data/links.json` — link data (must exist on the configured branch)

## GitHub setup
1. Create a repository.
2. Add `index.php`, `Dockerfile`, and `data/links.json` (`{}`).
3. Create a fine-grained GitHub token restricted to this repository, with **Contents: Read and write**.
4. Do not commit tokens or passwords.

## Render Environment Variables
- `GH_OWNER` — GitHub username or organization
- `GH_REPO` — repository name
- `GH_TOKEN` — fine-grained token
- `GH_BRANCH` — e.g. `main`
- `GH_DATA_PATH` — `data/links.json`
- `SHORTENER_API_KEY` — long, random secret used for private API and web form
- `ADMIN_PASSWORD` — strong admin password
- `APP_BASE_URL` — final Render URL, e.g. `https://your-service.onrender.com`

Deploy as a Docker Web Service. Set `APP_BASE_URL` after Render assigns the service URL, then redeploy.

## Endpoints
- `GET /api/v1/status` — public health/status
- `GET /api/docs` — API documentation
- `POST /api/v1/shorten` — private API, header `X-API-Key`
- `POST /api/v1/bulk` — private API, up to 20 links per request
- `GET /api/v1/stats/CODE` — private stats API
- `GET /CODE` — redirect
- `/admin` — admin dashboard

### Single create request
```bash
curl -X POST "https://YOUR-SERVICE.onrender.com/api/v1/shorten" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: YOUR_SHORTENER_API_KEY" \
  -d '{"url":"https://example.com","alias":"example","expires_at":"2027-12-31"}'
```

### Bulk request
```bash
curl -X POST "https://YOUR-SERVICE.onrender.com/api/v1/bulk" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: YOUR_SHORTENER_API_KEY" \
  -d '{"urls":[{"url":"https://example.com"},{"url":"https://example.org","alias":"example-org"}]}'
```

## Important limitations
- GitHub Contents API rate limits apply. Each create and best-effort click count writes the JSON file to GitHub.
- Concurrent updates can conflict; high-traffic use should move link data and click analytics to a database.
- QR images are fetched from a third-party QR service; the short URL is sent to that service.
- Public link creation is not enabled; the web form requires the configured private API key.
- Keep `GH_TOKEN`, `SHORTENER_API_KEY`, and `ADMIN_PASSWORD` private.
