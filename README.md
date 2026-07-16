# DocTrack — Automated Document Classification & Tracking System

**Capstone implementation** of *"An Automated Document Classification and Tracking System Using Machine Learning"* (GRC, BSIT, March 2026) for UJF Corporation.

Built with **Laravel 11 · Tailwind CSS · MySQL**, faithful to the Chapter 3 design: the 7-table ERD/Data Dictionary, DFD Processes 1.0–8.0, the Agile phase structure, and the ISO/IEC 25010 evaluation criteria.

---

## 1. Setting up on a new device

This is a complete Laravel 11 application — clone (or copy) the whole repo, including
`.git/`, rather than the older "lay this over a fresh skeleton" approach; `composer.json`
already declares every PHP dependency this project actually uses.

### Requirements

- **PHP 8.2+** with the standard extensions Laravel itself needs: `mbstring`, `openssl`,
  `pdo_mysql`, `tokenizer`, `xml`, `ctype`, `fileinfo`. All of these ship by default with
  XAMPP/Laragon on Windows and with `php8.2-*`/`php8.3-*` packages on Ubuntu/Debian — this
  is only worth checking if `composer install` fails with a "requires ext-xxx" error.
- **MySQL 8.x** (or MariaDB) — not bundled with this repo. XAMPP/Laragon include one;
  otherwise `sudo apt install mysql-server` on Linux or the official MySQL installer.
- **Node.js 18+** and npm — needed only for step 3 (compiling the Tailwind/Vite frontend).
- **Composer 2.x**.

### Full setup checklist

Check these off in order. **Steps 1–7 get a runnable demo; steps 8–11 are required
before treating the install as actually done** — skip them and the app still looks
complete but silently degrades (see the note after each).

- [ ] **1. Get the code**
  ```bash
  git clone <this-repo-url> docuwisev1      # or copy the folder, .git included
  cd docuwisev1
  ```

- [ ] **2. PHP dependencies** — nothing below works without this
  ```bash
  composer install
  ```

- [ ] **3. Frontend deps + build**
  ```bash
  npm install
  npm run build          # or: npm run dev   (for hot reload while developing)
  ```

- [ ] **4. Environment**
  ```bash
  cp .env.example .env
  php artisan key:generate
  php artisan reverb:install    # generates real REVERB_APP_ID/KEY/SECRET (§2.5) — the
                                 # .env.example placeholders are blank on purpose
  ```
  `reverb:install` also tries to `npm install` its JS packages; if that step errors out
  (some terminals/CI runners aren't interactive enough for it), that's fine — step 3
  already installed `laravel-echo`/`pusher-js` via `package.json`, and the `.env`
  credentials + config files it writes happen before that step anyway.

  Then edit `.env`: set `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` to match a MySQL
  instance you actually have (phpMyAdmin/XAMPP/Laragon, or plain `mysql`).

- [ ] **5. Create the database** — name must match `.env`'s `DB_DATABASE` (default `docuwise`)

- [ ] **6. Migrate + seed demo data** (admin/originator/approver + workflow stages)
  ```bash
  php artisan migrate:fresh --seed
  ```

- [ ] **7. Storage symlink + run**
  ```bash
  php artisan storage:link
  php artisan serve        # http://localhost:8000
  ```
  If you're running behind XAMPP/Laragon's bundled Apache instead of `php artisan serve`
  (Apache runs as a different user than whoever ran the commands above), make sure
  `storage/` and `bootstrap/cache/` are writable by that user — otherwise uploads and
  logging fail with permission errors. Not needed for plain `php artisan serve`.

  → At this point you can log in and click through the demo (see accounts below), but
  SLA escalation is delayed, dashboards only live-update on a slow fallback poll, and
  nothing is backed up yet. Continue to 8–10.

  **Optional sanity check** — confirms the whole install is wired correctly before you
  start clicking around by hand:
  ```bash
  php artisan test        # should show all tests passing
  ```

- [ ] **8. Persistent queue worker** — without this, SLA escalation still works but is
  up to 5 minutes late instead of instant (§2.1)
  ```bash
  ./deploy/install-queue-worker.sh
  ```

- [ ] **9. Reverb WebSocket server** — without this, live dashboard/notification
  updates still work but fall back to a 45-75s poll instead of instant (§2.5)
  ```bash
  ./deploy/install-reverb.sh
  ```

- [ ] **10. Cron entry for Laravel's scheduler** — without this, NEITHER the SLA
  safety-net sweep NOR nightly backups (§2.4) ever run at all (§2.2)
  ```bash
  crontab -e
  # add this line:
  * * * * * cd /path/to/docuwisev1 && php artisan schedule:run >> /dev/null 2>&1
  ```

OCR (§2.3) needs no extra step on Debian/Ubuntu amd64 — the bundled binary is already
committed and used automatically. Only run `deploy/build-tesseract-bin.sh` if you're on
a different distro/architecture, or have root and prefer a real system install instead.

- [ ] **11. Train the ML classifier** — the seeder creates users and workflow stages, but
  **not** a trained model. Without one, `ClassificationService::classify()` returns
  `Unclassified` for everything, which fails validation, which means uploaded documents
  never get routed to an approver at all — silently stuck, not erroring loudly. Sample
  documents to bootstrap with are already in `database/ml_training_samples/` (10 per
  category). Log in as `admin` → **ML Training** → for each category, select that
  category's folder of files and click its own "Add" button, then **Train Model** once
  all three show 5+ staged (see §5 for how the classifier itself works).

**All 11 steps done → the system is fully functional**, not just demo-ready: real-time
SLA escalation, live-updating dashboards/notifications, the safety-net sweep, nightly
backups, and document classification/routing all work unattended from here on.

### Demo accounts (created by the seeder)

| Role       | Username | Password    |
|------------|----------|-------------|
| Admin      | `admin`  | `admin123`  |
| Originator | `jsantos`| `jsantos123`|
| Approver   | `mreyes` | `mreyes123` |
| Approver   | `arose`  | `arose123`  |
| Approver   | `lvinz`  | `lvinz123`  |

> Change these before any real use. `DatabaseSeeder` uses `updateOrCreate()`/`firstOrCreate()`
> throughout, so `php artisan db:seed` is safe to re-run at any time without duplicate-key errors.

---

## 2. Continuous operation (Section 5) — four processes this app needs running

Unlike a plain request/response app, DocTrack has real-time and scheduled behavior that
does **not** run just by pointing a web server at `public/`. There are four separate
pieces of infrastructure, and all four are required together — missing any one silently
degrades a feature instead of erroring loudly, so this section exists specifically so a
fresh deployment doesn't quietly lose functionality. Reusable install files for all four
live in [`deploy/`](deploy/).

### 2.1 The persistent queue worker (real-time SLA escalation)

SLA breach detection is **event-driven**, not polling: when a document is routed,
`WorkflowService::assignStage()` dispatches `EscalateAssignmentJob` with a `delay()` set
to the exact moment that assignment's SLA expires (see `app/Jobs/EscalateAssignmentJob.php`).
That job only ever fires if something is continuously running `php artisan queue:work` —
without it, delayed jobs just sit in the `jobs` table forever and breach detection silently
falls back to the 5-minute safety-net sweep in `bootstrap/app.php`
(`workflow:check-parallel-slas`), which still works but is no longer "real-time."

Install it as a `systemd --user` service (no root needed for the service itself):
```bash
./deploy/install-queue-worker.sh
```
This templates `deploy/docuwise-queue-worker.service.template` with your actual project
path and PHP binary, installs it to `~/.config/systemd/user/`, and starts it immediately.

By default a `systemd --user` service only runs while you're logged in. For a demo/production
box, also enable lingering (one-time, needs sudo) so it survives logout and reboots:
```bash
sudo loginctl enable-linger $USER
```
Verify anytime with: `systemctl --user status docuwise-queue-worker.service`

### 2.2 The scheduler (safety-net sweeps)

Two backstop commands run on a timer regardless of the queue worker's health — see the
`withSchedule()` closure in `bootstrap/app.php` for exactly what and why. They only fire
if Laravel's scheduler is driven every minute:

**Production (cron — add once):**
```
* * * * * cd /path/to/doctrack && php artisan schedule:run >> /dev/null 2>&1
```

**Continuous (development, no cron needed):**
```bash
php artisan schedule:work
```

### 2.3 The bundled OCR engine (scanned-document fallback)

`TextExtractionService` OCRs scanned/image documents via the system `tesseract-ocr`
binary. If your deployment has root, just install it normally and skip the rest of this
section — the service prefers a system install on `$PATH` automatically:
```bash
sudo apt-get install -y tesseract-ocr tesseract-ocr-eng
```
If you don't have root, this repo ships a self-contained copy at `storage/tesseract-bin`
(committed to git — works out of the box on Debian/Ubuntu amd64, no install step required).
It was built without root via `apt-get download` + `dpkg-deb -x`; `TextExtractionService`
points `LD_LIBRARY_PATH`/`TESSDATA_PREFIX` at it automatically when no system binary is
found. If you're on a different distro/architecture and need to rebuild it:
```bash
./deploy/build-tesseract-bin.sh
```

### 2.4 Backup & redundancy (Section 3 hardware requirement)

The database and everything under `storage/app/` (uploaded document originals, trained
ML model artifacts) live nowhere else — losing either isn't recoverable by re-running
migrations or seeders. This project has already lost both once each this session to
out-of-band database resets, which is exactly the failure mode this closes.

`php artisan backup:run` dumps the database (`mysqldump`, gzipped) and archives
`storage/app/{documents,ml_models,ml_datasets}` (`tar.gz`) into `storage/app/backups/`,
then prunes down to the newest 14 of each (`BACKUP_KEEP` in `.env` to change). It's
scheduled nightly at 02:00 in `bootstrap/app.php`, so it runs automatically once the
cron entry from §2.2 is in place — no separate setup needed.

**Restore** (manual/emergency only — never scheduled, and destructive):
```bash
php artisan backup:restore 2026-07-16_174145
```
Prompts for confirmation unless `--force` is passed; run with no timestamp argument
that matches anything to see the list of available backups.

**Redundancy beyond this machine:** `backup:run` only writes locally, which protects
against bad deploys/accidental data loss but not against this disk failing outright. For
real redundancy, point a cron job or the backup command's output at off-machine storage
— e.g. `rsync -a storage/app/backups/ user@remote:/path/` after each run, or sync to
cloud storage — since neither this environment nor a typical student deployment target
has a second server available to replicate to directly.

### 2.5 The Reverb WebSocket server (real-time dashboard/notification push)

The approver queue, originator submissions table, admin overview, and notification bell
all update live — a new document appearing, a status changing, a notification arriving —
without anyone manually refreshing, via **Laravel Reverb** (Laravel's own first-party
WebSocket server; no third-party Pusher account needed, though it speaks the same
protocol). `app/Events/*.php` fire the instant a document/assignment/notification changes
(see each model's `booted()` hook), and `resources/js/app.js`'s `startLiveChannel()`
subscribes the browser to the relevant private channel and swaps in fresh content the
moment an event arrives — not on a timer.

Install it as a `systemd --user` service, same pattern as the queue worker in §2.1:
```bash
./deploy/install-reverb.sh
```
Also needs `BROADCAST_CONNECTION=reverb` in `.env` (already set if you copied
`.env.example`) and the `REVERB_APP_ID`/`REVERB_APP_KEY`/`REVERB_APP_SECRET` credentials
`php artisan reverb:install` generates — see §1 step 4.

**If this service isn't running**, live updates don't just slow down — this app falls
back to `startLivePoll()` (same file), a much slower 45-75s safety-net poll, mirroring
the same "instant primary + slow safety-net" pattern already used for SLA escalation
(§2.1). Verify anytime with: `systemctl --user status docuwise-reverb.service`

---

## 3. How the code maps to the thesis

| Thesis artifact | Where it lives |
|---|---|
| **Users** (Table 3.5.1) | `database/migrations/..._create_users_table.php`, `app/Models/User.php` |
| **Document_Repository** (3.5.2) | `..._create_document_repository_table.php`, `DocumentRepository.php` |
| **Workflow_Stages** (3.5.3) | `..._create_workflow_stages_table.php`, `WorkflowStage.php` |
| **Document_Assignments** (3.5.4) | `..._create_document_assignments_table.php`, `DocumentAssignment.php` |
| **ML_Model_Repository** (3.5.5) | `..._create_ml_model_repository_table.php`, `MlModelRepository.php` |
| **Audit_Logs** (3.5.6) | `..._create_audit_logs_table.php`, `AuditLog.php` |
| **Notifications** (3.5.7) | `..._create_notifications_table.php`, `NotificationRecord.php` |
| DFD 1.0/2.0 Auth & Roles | `AuthController`, `RoleMiddleware` |
| DFD 3.1 Text Extraction (hybrid + OCR fallback) | `Services/TextExtractionService.php` |
| DFD 3.2/3.3 Preprocessing + TF-IDF classification | `Services/ClassificationService.php` |
| DFD 3.4 Validation | `Services/ValidationService.php` |
| DFD 4.0 Workflow routing & priority | `Services/WorkflowService::routeToWorkflow()` |
| DFD 5.0 Approval management | `Services/WorkflowService::decide()`, `ApprovalController` |
| DFD 6.0 Notification service | `NotificationRecord::send()` calls throughout |
| DFD 7.0 System administration | `AdminController` |
| DFD 8.0 Search & retrieval | `AdminController::auditLogs()` filters + document tracking |
| Machine Learning Model Evaluation (Table 3.6.3) | `accuracy_score` on `ml_model_repository`, shown on ML dashboard |

---

## 4. Security posture (Section 3)

- **SQL-injection prevention:** every database interaction goes through Eloquent
  ORM / the Query Builder, which use PDO **parameterized prepared statements**.
  There is **no** raw SQL string built from user input anywhere in the codebase.
  Search/filter inputs (e.g. audit-log filters) are cast (`->integer()`,
  `->string()`) and bound as parameters, never concatenated.
- **Request validation on every write:** each controller action calls
  `$request->validate([...])` before touching the database — file types/sizes,
  enum whitelists for `role`/`decision`/`category`, date rules, uniqueness.
- **Strict RBAC:** `RoleMiddleware` (`role:admin`, `role:approver`,
  `role:originator`) guards every route group. Ownership checks
  (`abort_unless($assignment->approver_id === $request->user()->user_id)`)
  prevent horizontal privilege escalation.
- **Passwords:** stored as bcrypt hashes in the documented `password_hash`
  column (mapped via `User::getAuthPassword()`), never in plaintext.
- **Immutable audit trail:** `AuditLog` is insert-only; the app never updates or
  deletes rows. Every transition, approval, rejection, and override is logged
  with `actor_id`, `action_type`, `document_id`, `ip_address`, and `timestamp`.
- **Rate limiting:** login is throttled two ways — `AuthController::login()` locks
  out a specific username+IP combination for 60 seconds after 5 failed attempts
  (matching Laravel Breeze's own default), and a coarser `throttle:10,1` IP-only
  cap on the route catches an attacker sweeping many different usernames from one
  IP. Every upload endpoint (document submission/resubmission, ML training sample
  staging, legacy archive import) is capped at `throttle:20,1` per authenticated
  user — each request can trigger text extraction, OCR, and SVM classification, so
  this bounds both accidental runaway scripts and deliberate abuse. See
  `tests/Feature/RateLimitingTest.php`.

---

## 5. Machine Learning (SVM + TF-IDF)

The classifier is a real **Support Vector Machine with TF-IDF features**, matching
the thesis. It's implemented in `app/Services/ClassificationService.php` using the
**php-ai/php-ml** library (a required Composer dependency):

- `WhitespaceTokenizer` + `TokenCountVectorizer` build the vocabulary / term counts
- `TfIdfTransformer` reweights them to TF-IDF feature vectors
- `Phpml\Classification\SVC` (Support Vector Classifier, **linear kernel**) is the
  trained SVM

php-ml ships a bundled **libsvm** binary, so the linear kernel works **without**
the PECL `svm` extension. Install it with the rest of the app:

```bash
composer install     # php-ai/php-ml is already in composer.json "require"
```

**Model persistence & auditability:** on training, the fitted vectorizer + IDF
transformer are serialized to `storage/app/ml_models/pipeline_*.bin` and the SVM
to `storage/app/ml_models/svm_*.model` (via php-ml's `ModelManager`). Each version
is registered in `ml_model_repository` (Table 3.5.5) with its accuracy and sample
count, so every classified document records exactly which SVM version produced its
category. The admin ML dashboard enforces the **5–10 samples per category** rule
from Scope 1.4.

### Verified working

Both risk areas noted during initial development have since been confirmed working by
training the model against 30 real, varied sample documents (10 per category) and
classifying fresh, never-seen documents against it: inference correctly reuses the
vectorizer's frozen vocabulary from training (no `Unclassified` misfires), and
`predictProbability()` returns real, varying confidence scores (not the flat 85%
fallback) rather than a fixed value.

`TextExtractionService`'s OCR fallback (`smalot/pdfparser` + `thiagoalessio/tesseract_ocr`,
both already required in `composer.json`) degrades gracefully without a working OCR
engine — born-digital PDF/DOCX/TXT text still extracts fine either way; only scanned-image
OCR needs the system `tesseract-ocr` binary. See §2.3 above for how that binary is
provisioned (system install if you have root, bundled copy in `storage/tesseract-bin`
otherwise).

---

## 6. JSON API (mobile/integration surface)

Everything above is the browser-based UI (session cookies + CSRF). For mobile apps or
external systems that can't drive that, `routes/api.php` exposes a token-authenticated
JSON equivalent of the core flows — login, document submission/tracking, and approver
decisions — via **Laravel Sanctum**. Every API controller (`app/Http/Controllers/Api/`)
is a thin wrapper around the exact same services the web controllers use
(`WorkflowService`, `SlaService`), so classification/SLA/routing logic lives in exactly
one place regardless of which surface calls it — the API isn't a separate, parallel
implementation that could drift out of sync with the web app.

**Auth**: `POST /api/v1/login` (username/password/device_name) returns a bearer token;
send it as `Authorization: Bearer <token>` on every request after that. `POST /api/v1/logout`
revokes it.

**What's JSON and what isn't**: JSON is only used for the small instructions and answers
— "log me in," "here's my token," "approve this," "here's the status." The document
itself (a PDF, a scanned image, a Word file) is always sent as a real uploaded file,
exactly like dragging it into the website — never wrapped in JSON.

```bash
# 1. Log in — JSON in, JSON back (a token)
curl -X POST http://localhost:8000/api/v1/login -H "Content-Type: application/json" \
  -d '{"username":"jsantos","password":"jsantos123","device_name":"my-phone"}'
#   -> {"token": "...", "user": {...}}

# 2. Everything after this uses that token in the Authorization header
curl http://localhost:8000/api/v1/documents -H "Authorization: Bearer <token>"

# 3. Uploading is a real file (-F, multipart), not JSON — due_date is a plain field alongside it
curl -X POST http://localhost:8000/api/v1/documents -H "Authorization: Bearer <token>" \
  -F "files[]=@job_order.pdf" -F "due_date=2026-08-01T10:00"

curl http://localhost:8000/api/v1/assignments -H "Authorization: Bearer <token>"
curl -X POST http://localhost:8000/api/v1/assignments/1/decide -H "Authorization: Bearer <token>" \
  -d "decision=approved"
```

**Trying it without a terminal**: a GUI tool like [Postman](https://www.postman.com/) or
Insomnia works well for a demo. Send `POST /api/v1/login` with a raw-JSON body, copy the
`token` from the response, then add it as an `Authorization: Bearer <token>` header on
every request after that. For the upload request specifically, set the body type to
**form-data** (not JSON) and attach a real file to the `files[]` field.

Note that `jsantos` (originator) can upload/list documents but gets `403` trying to
decide an assignment — use `mreyes` (approver) for the `/assignments` endpoints instead;
the API enforces the same role boundaries as the website.

**RBAC** is enforced the same way as the web app — per-request ownership/role checks
inside each controller, not just route gating: originators only see/submit their own
documents, approvers only see/decide their own assignments, admins see everything. Login
is rate-limited the same way as the web login (§4), and upload/decide endpoints are
capped at `throttle:20,1` per user, matching the web app's own limits.

See `tests/Feature/ApiTest.php` for the full behavior contract (auth, RBAC, rate limits).

---

## 7. Project structure

```
app/
  Console/Commands/CheckSlaDeadlines.php   # sla:check
  Events/                                  # Reverb broadcasts (§2.5) — DocumentStatusChanged, AssignmentRouted, NotificationBroadcast
  Http/Controllers/                        # Auth, Document, Approval, Admin
  Http/Controllers/Api/                    # JSON equivalents (§6), same services underneath
  Http/Resources/                          # JSON shaping for the API
  Http/Middleware/RoleMiddleware.php       # RBAC
  Models/                                  # Eloquent models (ERD) — booted() hooks fire the Events above
  Services/
    TextExtractionService.php              # hybrid + OCR fallback
    ClassificationService.php              # TF-IDF pipeline
    ValidationService.php                  # template/field checks
    WorkflowService.php                    # state machine orchestrator
    SlaService.php                         # escalation + auto-approval
bootstrap/app.php                          # role alias + schedule + broadcasting (L11)
database/migrations/                       # tables + auth support
database/seeders/DatabaseSeeder.php        # demo users + workflow stages
resources/js/echo.js                       # Reverb/Echo client config (§2.5)
resources/js/app.js                        # startLiveChannel()/startLivePoll() — live-update mechanism
resources/views/                           # Tailwind Blade UI (role dashboards)
routes/web.php                             # RBAC-guarded browser routes
routes/api.php                             # token-guarded JSON routes (§6)
routes/channels.php                        # private-channel authorization (§2.5)
```

---

## 8. ISO/IEC 25010 evaluation (Chapter 3.6)

The evaluation instruments (Likert tables 3.6.1/3.6.2 and ML-metrics table
3.6.3) are supported by the app: model Accuracy/Precision/Recall/F1 can be
recorded on `ml_model_repository`, and the audit trail + status history provide
the objective evidence expert evaluators (the company supervisor + IT
professionals) need to score Functional Suitability, Performance Efficiency,
Reliability, Security, Compatibility, and Maintainability.
