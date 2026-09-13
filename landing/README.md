# PrimePower — Landing Page

The public page for the HRIS. **A separate deployment from the system itself**,
and that is the whole point of it existing as its own folder.

| | This folder | The HRIS (repository root) |
|---|---|---|
| What it is | A static React site | Laravel + Inertia + React |
| Hosted on | **Vercel** | **Hostforge** (PHP hosting) |
| Needs at runtime | Nothing — plain files | PHP 8.2+, PostgreSQL |
| Knows about employees | No | Yes |

The two are joined by exactly one thing: the **Sign in** button, which is a
plain link to the HRIS. There is no API call between them, no shared session,
and no shared database — which is why this can live on a different host
without any of the decoupling work a real SPA split would need.

## Why this exists rather than splitting the HRIS

The project's stack listed `Vercel (frontend)`, and that is not something the
HRIS can satisfy: its React pages are **Inertia** pages, rendered by Laravel
controllers that hand over data in the same request. Moving them to Vercel
would mean building 58 API endpoints, rewriting 69 page components to fetch
over HTTP, replacing session auth with tokens — and with it, rewriting 2FA,
the emailed OTP, the idle timeout, and the forced password change, all of
which are built on the session. Roughly 1–2 weeks, and every one of those
security features broken in the meantime.

A landing page makes the same line in the stack table true, in an afternoon,
with nothing broken. That was the trade.

## Running it locally

```bash
cd landing
npm install
npm run dev      # http://localhost:5173
```

`npm run build` writes to `dist/`. Nothing here touches the Laravel project
next door — separate `package.json`, separate `node_modules`, separate build.
The root `npm run check` does not see this folder either: its scripts are
scoped to `resources/js`.

## Deploying to Vercel

1. Push the repository to GitHub (this folder comes with it).
2. In Vercel: **Add New → Project**, import the repository.
3. **Set Root Directory to `landing`.** This is the step that matters — the
   repository root is a Laravel app, and Vercel would try to build that and
   fail. `landing` is where its `package.json` is.
4. Framework preset should detect **Vite** on its own; build command
   `npm run build`, output directory `dist`. `vercel.json` states all three
   anyway, so a wrong guess in the UI is overridden.
5. Add one environment variable:

   | Name | Value |
   |---|---|
   | `VITE_HRIS_URL` | `https://core2man.primepowersystem.com` |

   This is where the Sign in button points. It is read at **build** time, not
   at run time, so changing it needs a redeploy — that is how Vite env vars
   work, and it is why there is a sensible fallback in `src/App.jsx` rather
   than a blank link.

6. Deploy. Vercel gives it a `*.vercel.app` URL; point a subdomain at it from
   the Cloudflare DNS for `primepowersystem.com` if you want a real address.

## Keeping it honest

Everything the page claims is a feature that exists in the HRIS today. It is
worth keeping that true: a marketing page is the one part of a capstone a
panel reads before seeing the system, so a claim here that the demo cannot
show is the worst place to have one.

The brand colours in `tailwind.config.js` and `src/index.css` are **copied**
from the HRIS's `resources/css/app.css`, because a separate deployment cannot
import from it. If the brand changes there, change it here too — only the
handful of tokens this page uses were carried over, so there is little to keep
in step, but it is not automatic.
