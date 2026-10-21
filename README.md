# Short-Link

A PHP URL shortener with a polished responsive dashboard and GitHub JSON storage. The app is configured in `config.php`, so **no Render Environment Variables are required**.

## Before deployment (one-time setup)
1. In your GitHub repository `muhammedomor-studio/url`, create a fine-grained Personal Access Token.
2. Grant access only to this repository and give **Contents: Read and write** permission.
3. Open `config.php` and replace:
   - `PASTE_YOUR_GITHUB_TOKEN_HERE` with your token
   - `CHANGE_THIS_TO_A_LONG_RANDOM_SECRET` with a long random API key
   - `CHANGE_THIS_ADMIN_PASSWORD` with a strong admin password
4. Confirm owner `muhammedomor-studio`, repo `url`, branch `main`. Change them in `config.php` if your branch differs.
5. Ensure `data/links.json` exists in that repository and contains `{}`.
6. Commit/upload the files to GitHub.

## Render
- Create a **Web Service** from your GitHub repository.
- Runtime: **Docker** (Render detects the `Dockerfile`).
- No environment variables are needed.
- Deploy. Your short links will use the actual incoming host automatically.

## Use
- Visit `/` to create links and see previously created aliases. Original destination URLs are not shown in the public history.
- Visit `/admin` and enter the admin password to see full destination URLs and details.
- Each link is `https://your-render-domain/alias`.
- For API use, send `X-API-Key: your-configured-api-key`:
  - `POST /api/v1/shorten` JSON `{"url":"https://example.com/long","alias":"my-link"}`
  - `POST /api/v1/bulk` JSON `{"links":[{"url":"https://example.com","alias":"example"}]}`
  - `GET /api/v1/stats/my-link`

## Security notes
- Never commit or share your GitHub token publicly. If you have already exposed a real token, revoke it and create a new one.
- The public page lists aliases, short URLs and click counts only; it does not display destination URLs.
- GitHub API rate limits apply. This project saves click counts back to GitHub on each redirect.
