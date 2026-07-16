# DocTrack — Automated Document Classification & Tracking System

**Capstone implementation** of *"An Automated Document Classification and Tracking System Using Machine Learning"* (GRC, BSIT, March 2026) for UJF Corporation.

Built with **Laravel 11 · Tailwind CSS · MySQL**, faithful to the Chapter 3 design: the 7-table ERD/Data Dictionary, DFD Processes 1.0–8.0, the Agile phase structure, and the ISO/IEC 25010 evaluation criteria.

---

## 1. Why this is a "drop-in" source tree

The code in this archive is the complete application layer (models, migrations, services, controllers, middleware, routes, Blade+Tailwind views, the SLA daemon command, seeders, config). It's meant to be laid over a fresh Laravel skeleton, because Composer/Laravel installers can't run in every environment.

### Fastest path (recommended)

```bash
# 1. Create a fresh Laravel 11 app
composer create-project laravel/laravel doctrack
cd doctrack

# 2. Copy this archive's contents over the skeleton (answer "yes" to overwrite)
#    app/, bootstrap/app.php, config/auth.php, database/, resources/, routes/,
#    tailwind.config.js, vite.config.js, postcss.config.js, package.json, .env.example

# 3. Frontend deps + build
npm install
npm run build          # or: npm run dev   (for hot reload while developing)

# 4. Environment
cp .env.example .env
php artisan key:generate
#   -> edit .env DB_DATABASE / DB_USERNAME / DB_PASSWORD to match phpMyAdmin

# 5. Create the database in phpMyAdmin named exactly: doctrack

# 6. Migrate + seed demo data (admin/originator/approver + workflow stages)
php artisan migrate:fresh --seed

# 7. Storage symlink (so stored documents are reachable) + run
php artisan storage:link
php artisan serve        # http://localhost:8000
```

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

## 2. Continuous operation (Section 5) — three processes this app needs running

Unlike a plain request/response app, DocTrack has real-time and scheduled behavior that
does **not** run just by pointing a web server at `public/`. There are three separate
pieces of infrastructure, and all three are required together — missing any one silently
degrades a feature instead of erroring loudly, so this section exists specifically so a
fresh deployment doesn't quietly lose functionality. Reusable install files for all three
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

## 6. Project structure

```
app/
  Console/Commands/CheckSlaDeadlines.php   # sla:check
  Http/Controllers/                        # Auth, Document, Approval, Admin
  Http/Middleware/RoleMiddleware.php       # RBAC
  Models/                                  # 7 Eloquent models (ERD)
  Services/
    TextExtractionService.php              # hybrid + OCR fallback
    ClassificationService.php              # TF-IDF pipeline
    ValidationService.php                  # template/field checks
    WorkflowService.php                    # state machine orchestrator
    SlaService.php                         # escalation + auto-approval
bootstrap/app.php                          # role alias + schedule (L11)
database/migrations/                       # 7 tables + auth support
database/seeders/DatabaseSeeder.php        # demo users + workflow stages
resources/views/                           # Tailwind Blade UI (role dashboards)
routes/web.php                             # RBAC-guarded routes
```

---

## 7. ISO/IEC 25010 evaluation (Chapter 3.6)

The evaluation instruments (Likert tables 3.6.1/3.6.2 and ML-metrics table
3.6.3) are supported by the app: model Accuracy/Precision/Recall/F1 can be
recorded on `ml_model_repository`, and the audit trail + status history provide
the objective evidence expert evaluators (the company supervisor + IT
professionals) need to score Functional Suitability, Performance Efficiency,
Reliability, Security, Compatibility, and Maintainability.
