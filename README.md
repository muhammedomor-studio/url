# Short-Link — Professional UI update

This version keeps the existing Render Environment Variables and GitHub JSON storage. It is intended as a UI refresh; do not replace or rename the environment variables already configured in Render.

## Existing Render Environment Variables
- `GH_OWNER`
- `GH_REPO`
- `GH_TOKEN`
- `GH_BRANCH` (defaults to `main` if already omitted)
- `GH_DATA_PATH` (defaults to `data/links.json` if already omitted)
- `SHORTENER_API_KEY`
- `ADMIN_PASSWORD` (optional; if it is not already set, the existing `SHORTENER_API_KEY` is used for the admin login)
- `APP_BASE_URL` (if already configured; otherwise the app derives the public host)

No secrets are hardcoded in the source. No new variables are required by this UI update. Keep the existing values exactly as they are.

## Deploy
Upload/commit the files in this ZIP to the same GitHub repository and let the existing Render Web Service redeploy. Keep the existing Docker runtime and Render Environment Variables.

## Features
- White/blue glassmorphism design responsive for mobile
- Lists previously created aliases and short URLs without showing destination URLs publicly
- One-click copy for each short URL
- Search by alias/name
- Click counts and expiry information
- Admin dashboard at `/admin`
- Existing GitHub Contents API JSON storage
- API routes: `POST /api/v1/shorten`, `POST /api/v1/bulk`, `GET /api/v1/stats/{code}`

## Security
Never put GitHub tokens or API secrets into the code. If a token was previously exposed, revoke it and update the existing `GH_TOKEN` variable in Render.
