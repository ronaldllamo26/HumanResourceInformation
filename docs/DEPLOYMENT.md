# Deploying Core 2 to Hostforge

Written in the order you actually do it. Each step has a way to check it
worked, because the failures here are mostly silent — a wrong document root
serves your source code instead of your app, and a missing `storage:link`
breaks employee photos on a screen nobody opens until a demo.

**What ships:** one PHP app. `frontend/` and `backend/` separate the
dependencies, not the deployment — `frontend/` compiles into
`backend/public/build` and Laravel serves those files. **Node is not needed on
the server.** `landing/` is the only genuinely separate deployment, and it goes
to Vercel, not here.

---

## 0. Before you touch the server

**Build the assets and commit them.** The server has no Node, so the compiled
files have to arrive in the repository:

```bash
cd frontend
npm run build          # writes into ../backend/public/build
cd ..
git add -A && git commit -m "Build assets for deploy" && git push
```

Check: `git ls-files backend/public/build | wc -l` should be ~110, not 0.

**Rotate anything that has been pasted into a chat, a screenshot, or a
document.** The Gmail app password and the Gemini API key both qualify. New
Gmail app password: myaccount.google.com → Security → App passwords. New
Gemini key: https://aistudio.google.com/apikey.

---

## 1. Point the document root at `backend/public`

**This is the step that most often goes wrong, and getting it wrong is a
security problem, not just a broken page.** If the document root is the
repository root, then `https://your-domain/backend/.env` serves your database
password, mail password and API key as plain text to anyone who asks.

Three ways, best first — use the first one the panel allows:

### A. Set the document root directly (preferred)

In the Hostforge panel, find the domain's *Document Root* / *Web Root* /
*Public Directory* setting and set it to:

```
backend/public
```

Check: `https://your-domain/` loads the login page, and
`https://your-domain/backend/.env` returns **404**, not a file.

### B. Symlink the fixed root (for panels that won't let you change it)

Many shared hosts pin the root to `public_html` or `htdocs`. Replace that
directory with a link:

```bash
rm -rf ~/public_html
ln -s ~/path-to-repo/backend/public ~/public_html
```

Check: `ls -l ~/public_html` shows an arrow pointing at `backend/public`.

### C. Last resort — a forwarding shim, plus hardening

Only if A and B are both impossible. Create `index.php` at the repository root:

```php
<?php
// The host will not let the document root move, so this forwards to Laravel's
// real entry point. Everything in the DENY rules below then has to stay there:
// without them the repository root is web-served and .env is public.
require __DIR__.'/backend/public/index.php';
```

And `.htaccess` beside it:

```apache
RewriteEngine On

# Anything that is not a real file in backend/public goes to the shim.
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^ index.php [L]

# Without these the source tree is readable over HTTP.
RedirectMatch 404 ^/\.env
RedirectMatch 404 ^/(backend|frontend|landing|docs|tests|storage|vendor|node_modules)/
```

**This only works on Apache.** `.htaccess` is ignored by nginx entirely, so if
Hostforge runs nginx, option C gives you no protection at all — in that case
insist on A or B rather than deploying. Ask support: *"can I set the document
root to a subdirectory?"*

Check, whichever option you used:

```bash
curl -si https://your-domain/backend/.env | head -1      # want 404
curl -si https://your-domain/.env        | head -1      # want 404
curl -si https://your-domain/            | head -1      # want 200
```

If either `.env` check returns 200, **stop and fix it before going further.**

---

## 2. Install PHP dependencies

```bash
cd backend
composer install --no-dev --optimize-autoloader
```

`--no-dev` leaves out PHPUnit, Pint and Faker — they have no business on a
production server. `--optimize-autoloader` is a real speed difference.

**If this fails naming an extension**, that is the check working. `composer.json`
requires `ext-gd`, `ext-intl`, `ext-pdo`, `ext-mbstring` and `ext-openssl`, so a
host missing one fails here loudly instead of the app breaking later on one
screen — a photo upload crashing inside `PhotoStore`, or Scanner Accuracy
throwing. Enable the extension in the panel (usually PHP → Extensions) and
re-run.

PHP must be **8.2 or newer** (`composer.json` requires `^8.2`).

---

## 3. Create `.env`

```bash
cd backend
cp .env.production.example .env
php artisan key:generate
```

Then open `.env` and fill in every `[FILL IN]`: the four database values from
the panel, the three mail values, and the Gemini key. Everything else in that
file is already correct for production — it is commented with *why*, including
which values are dangerous if copied from your local `.env`.

**Back up `APP_KEY` somewhere safe.** It encrypts the
government identifiers and bank accounts on every employee record. Lose it and
that data is unrecoverable, not merely inaccessible.

Check:

```bash
php artisan about | grep -E "Environment|Debug Mode"
```

Want `production` and `OFF`. Run locally right now it prints `local` and
`ENABLED` — those are the exact two values that have to flip, so if the server
still shows them, the file was not read or not edited.

Confirm the database separately, since it is the other thing that silently
stays local:

```bash
php artisan tinker --execute="echo config('database.connections.pgsql.host');"
```

---

## 4. Migrate and seed

```bash
php artisan migrate --force
php artisan db:seed --force
```

`--force` is required because both refuse to run unprompted in production —
that guard exists so nobody drops a live database by pasting a command.

**Write down the passwords the seeder prints.** Outside `local` it does not use
`password`; it generates a distinct random one per login and prints each once,
to the console, and nowhere else. They are hashed on the way in and cannot be
recovered afterwards — if you lose them, reset from Users & Access or re-seed.

Check: `php artisan tinker --execute="echo App\Models\User::count();"` should
print a number, not an exception.

---

## 5. Link storage, or photos 404

```bash
php artisan storage:link
```

Employee photos are served from `asset('storage/...')` in five places, and the
symlink is gitignored, so it does not arrive with your code — it has to be
created on the server.

Check: `ls -l public/storage` shows an arrow to `storage/app/public`.

**If the host blocks symlinks** (some shared hosts do), you have two options:

1. Ask support to enable them — this is a normal Laravel requirement.
2. Copy instead of link, and re-copy whenever a photo is uploaded:
   `cp -r storage/app/public/* public/storage/`. Workable but it drifts; only
   do this if option 1 is genuinely refused.

---

## 6. Cache the config

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

All three are verified to work on this codebase — `route:cache` in particular
fails on a lot of Laravel apps because of closure routes, and it does not fail
here.

**Re-run `config:cache` every time you edit `.env`.** Once cached, Laravel stops
reading the file, so an edited value simply has no effect — which looks like the
setting being ignored. `php artisan config:clear` undoes it.

---

## 7. Make sure storage is writable

```bash
chmod -R 775 storage bootstrap/cache
```

Laravel writes sessions, cache, compiled views and logs here. If it cannot, you
get a 500 with nothing useful on screen — and with `APP_DEBUG=false`, correctly,
no explanation either. Read `storage/logs/laravel.log` when a 500 has no cause.

---

## 8. Verify it actually works

In a **browser**, not with curl. A 200 means the server sent correct HTML; it
says nothing about whether React mounted — and a blank white page with a 200 is
exactly what a missing asset looks like.

| Check | Expect |
|---|---|
| Open `https://your-domain/` | the login screen, styled |
| Sign in with a seeded account | the dashboard |
| Open an employee record | the 201 file, photo visible |
| `https://your-domain/backend/.env` | 404 |
| Open dev tools → Console | no red errors |
| Open dev tools → Network | no 404s on `/build/assets/*` |

**A styled page that will not log in** is the session-cookie trap: check
`SESSION_SAME_SITE=lax` and `SESSION_SECURE_COOKIE=true` in `.env`, then
`config:clear && config:cache`. The tell is the audit log — if there is no
`login_failed` row for your attempt, the request 419'd before authentication
was even tried, which is a cookie problem and not a password problem.

**An unstyled page** means the CSS 404'd — check `backend/public/build/` exists
on the server and that `git ls-files` actually included it.

---

## Redeploying after a change

```bash
# locally
cd frontend && npm run build && cd ..
git add -A && git commit -m "..." && git push

# on the server
cd backend
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

Skip the frontend build only if you changed no JSX. Skip `migrate` only if you
added no migration — running it when there is nothing to run is harmless.

---

## What will not work, and that is expected

- **The document scanner, without `GEMINI_API_KEY`.** It goes dark cleanly:
  the Scan button is not drawn and the endpoint 404s. Filing 201-file documents
  by hand works exactly as before.
- **Password resets, without mail credentials.** Nothing crashes; the reset
  email simply never arrives, and an admin resets the password from Settings →
  Users & Access instead. Signing in never needs mail — it is a username and a
  password.
  until you have confirmed a real send *from the server*.
- **`php artisan scanner:check`** is worth running once after deploying — it
  sends one real image through the configured driver and reports *switched off*
  and *configured but broken* as the different things they are. `isEnabled()`
  only reads config, so a present-but-revoked key otherwise looks fine until
  every scan silently fails.
