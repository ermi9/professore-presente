# Professore Presente — Full Code Walkthrough

*A file-by-file, flow-by-flow explanation of the exam-queue platform. Security mechanisms (JWT internals, RBAC internals, hashing, rate limiting) are deliberately **not** the focus here — they appear only where they sit inside a flow. Everything else is fair game.*

---

## Table of contents

1. [The one-paragraph mental model](#1-the-one-paragraph-mental-model)
2. [The runtime picture: what actually runs where](#2-the-runtime-picture-what-actually-runs-where)
3. [Boot sequence: `start.sh`, Docker, schema](#3-boot-sequence-startsh-docker-schema)
4. [The data model — and the one idea that explains half the code](#4-the-data-model--and-the-one-idea-that-explains-half-the-code)
5. [The anatomy of an endpoint (the 6-step preamble)](#5-the-anatomy-of-an-endpoint-the-6-step-preamble)
6. [Flow 1 — Register → Login → Dashboard](#6-flow-1--register--login--dashboard)
7. [Flow 2 — Professor creates a course, student enrolls](#7-flow-2--professor-creates-a-course-student-enrolls)
8. [Flow 3 — Exam creation and the auto-roster](#8-flow-3--exam-creation-and-the-auto-roster)
9. [Flow 4 — CSV roster upload (multipart, not JSON)](#9-flow-4--csv-roster-upload-multipart-not-json)
10. [Flow 5 — The queue: the heart of the system](#10-flow-5--the-queue-the-heart-of-the-system)
11. [Flow 6 — Timetable rendering](#11-flow-6--timetable-rendering)
12. [Flow 7 — Announcements](#12-flow-7--announcements)
13. [Flow 8 — Admin: stats, professors, students](#13-flow-8--admin-stats-professors-students)
14. [The frontend architecture](#14-the-frontend-architecture)
15. [The CSS layer](#15-the-css-layer)
16. [Cross-cutting concepts glossary](#16-cross-cutting-concepts-glossary)
17. [Known gaps, bugs and things to be ready to defend](#17-known-gaps-bugs-and-things-to-be-ready-to-defend)

---

## 1. The one-paragraph mental model

A professor creates a **course** with a code (`MATH101`). Students **enroll** using that code. The professor schedules an **exam** for that course; at the moment of creation, everyone currently enrolled is snapshotted into an **exam roster** (`exam_list`). On exam day, students on the roster **join a queue**. The professor sees the queue in order of arrival and walks it: **call** a student → mark them **attended**. The student's browser **polls** every 5 seconds and shows "It's your turn!" when their status flips to `called`.

Everything else — timetable, announcements, stats, admin panel — is supporting cast.

The five nouns to keep in your head:

```
course ──< exam ──< exam_list (who may sit it)
   │                    │
   └──< student_courses │
                        └──< queue (who is actually here, in order)
```

---

## 2. The runtime picture: what actually runs where

Three processes, two containers:

| Piece | Where it runs | What it does |
|---|---|---|
| **Browser** (HTML/CSS/vanilla JS) | The user's machine | Renders the UI, holds the JWT in `localStorage`, calls the API with `fetch` |
| **Apache + PHP 8.2** | `professore_presente_php` container | Serves the static HTML/CSS/JS **and** executes the `.php` API files |
| **PostgreSQL 15** | `professore_presente_db` container | Stores everything |

### The single most important structural difference from Spring Boot

There is **no router, no dispatcher, no `@RestController`**. In Spring Boot, one servlet receives every request and a routing table decides which method handles it. In this project, **the URL path *is* the filesystem path**, and Apache is the router.

```
GET /api/student/queue.php?exam_id=5
        │
        └── Apache looks up  /var/www/html/api/student/queue.php  on disk
            → hands the file to the PHP interpreter
            → PHP executes the file top-to-bottom
            → whatever the script `echo`s becomes the HTTP response body
            → process state is destroyed
```

Consequences you'll feel throughout the codebase:

- **PHP is shared-nothing.** Every request starts with a completely empty memory space. There is no singleton `Database` bean, no connection pool, no application context. That's why *every single endpoint* opens its own PDO connection (`new Database()`) and re-verifies the token from scratch. Nothing is remembered between requests.
- **There is no framework filter chain**, so cross-cutting concerns (auth, permission checks) cannot be applied centrally — they are *copy-pasted* into the top of every API file. That repetition is not sloppiness; it's the direct consequence of the per-file execution model. (In Spring you'd write one `OncePerRequestFilter`; here you write the same 25 lines 15 times.)
- **HTTP method dispatch is a manual `if`**. Since the file is the endpoint, one file handles GET/POST/PUT/PATCH/DELETE with a branch on `$_SERVER['REQUEST_METHOD']`.

```php
if ($request_method === 'PATCH')      markAttended($conn, $professor_id);
elseif ($request_method === 'PUT')    callStudent($conn, $professor_id);
elseif ($request_method === 'GET')    viewQueue($conn, $professor_id);
else { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); }
```

That block *is* your `@GetMapping` / `@PutMapping` / `@PatchMapping`, hand-rolled.

### The two files that make this work

**`Dockerfile`** — takes the official `php:8.2-apache` image (Apache with PHP already wired in as a module) and adds the one thing missing: the PostgreSQL driver.

```dockerfile
FROM php:8.2-apache
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql \
    && apt-get clean
RUN a2enmod rewrite
```

- `pdo` = the generic database abstraction layer (the interface).
- `pdo_pgsql` = the PostgreSQL-specific driver behind it (the implementation). Without this, `new PDO("pgsql:...")` throws "could not find driver".
- `a2enmod rewrite` = enable URL rewriting. **Nothing in this project actually uses it** — the `.htaccess` has no rewrite rules. It's dead weight left in for "later".

**`src/.htaccess`** — per-directory Apache config, read at request time:

```apache
<IfModule mod_dir.c>
    DirectoryIndex index.html index.php
</IfModule>
```

This is why hitting `http://localhost:8080/` serves `index.html` (the login page) rather than a directory listing. The `<IfModule>` wrapper means "only apply this if the module is loaded" — it fails silently instead of crashing Apache if it isn't.

**`docker-compose.yml`** — wires the two containers together. Three details worth naming:

```yaml
php:
  volumes:
    - ./src:/var/www/html          # ① bind mount: edit on host, live in container
  environment:
    DB_HOST: db                    # ② service name = DNS hostname
  depends_on:
    db:
      condition: service_healthy   # ③ wait for the healthcheck, not just the process
```

1. **Bind mount** — `./src` on your laptop *is* `/var/www/html` in the container. No rebuild needed to see a code change; PHP re-reads the file on every request. This is the reason the dev loop is fast.
2. **Docker DNS** — `db` isn't a hostname you configured anywhere; Compose creates a network where each service name resolves to its container IP. `DB_HOST: db` is how PHP finds Postgres.
3. **`condition: service_healthy`** — without this, the PHP container would start while Postgres is still initializing and the first requests would fail. It's tied to the `healthcheck` block that runs `pg_isready` every 10s.

Note the port asymmetry: Postgres is published on **5440** on the host (to avoid clashing with a local Postgres on 5432), but *inside* the Docker network PHP still talks to port **5432**. Both facts appear in the file and they are not in conflict.

---

## 3. Boot sequence: `start.sh`, Docker, schema

`start.sh` is a convenience wrapper. Its interesting part is **idempotent schema application** — it must be safe to run twice.

```bash
TABLES_EXIST=$(docker exec professore_presente_db \
    psql -U professor -d professore_presente -tAc \
    "SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema='public' AND table_name='users';")

if [ "$TABLES_EXIST" = "0" ] || [ -z "$TABLES_EXIST" ]; then
    docker exec -i professore_presente_db \
        psql -U professor -d professore_presente < src/config/schema.sql
fi
```

- `information_schema.tables` is the SQL-standard catalog — a table *about* tables. Querying it is how you ask a database "do you already know about `users`?"
- `-tAc` = **t**uples only (no headers), **A**ligned off (no padding), **c**ommand. It makes the output a bare `0` or `1` so bash can compare it.
- `docker exec -i` with `< schema.sql` pipes the file into `psql`'s stdin. The `-i` (interactive/keep stdin open) is what makes the redirect work.

Two forms of idempotency stack here: the shell check *and* `CREATE TABLE IF NOT EXISTS` / `ON CONFLICT DO NOTHING` inside the SQL itself. Belt and braces.

The startup script also calls `test_db_connection.php`, **which does not exist in the repo** — it was deleted but never removed from the script. It prints an error and the script carries on. First thing to fix if you ever demo this.

---

## 4. The data model — and the one idea that explains half the code

`src/config/schema.sql`. Ten-ish tables. But there is **one design decision** you need to internalize, because if you don't, half the PHP looks like pointless busywork.

### Identity is split across two tables

```sql
CREATE TABLE users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL CHECK (role IN ('admin','professor','student')),
    ...
);

CREATE TABLE students (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL UNIQUE,          -- ← 1:1 back to users
    student_id_number VARCHAR(20) UNIQUE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE professors (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL UNIQUE,
    department VARCHAR(100),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

This is the **class-table inheritance** pattern (in Java terms: `User` is the base class, `Student` and `Professor` are subclasses with extra fields, and each gets its own table linked 1:1).

**The consequence:** `users.id` and `students.id` are *different numbers for the same human*. The JWT carries `user_id`. But every domain table (`queue`, `exam_list`, `student_courses`) references `students.id`. So every student endpoint must do a translation step:

```php
// The JWT says who you are as a *user*. The database wants to know who you are as a *student*.
$student_stmt = $conn->prepare("SELECT id FROM students WHERE user_id = :user_id");
$student_stmt->execute([':user_id' => $payload['user_id']]);
$student_id = (int)$student_stmt->fetch(PDO::FETCH_ASSOC)['id'];
```

You will see this translation — or its professor twin (`SELECT id FROM professors WHERE user_id = ...`) — at the top of **every single role-scoped endpoint**. Now you know why.

It also doubles as a sanity check: if the lookup returns zero rows, the token *claims* the role but there's no matching row, and the endpoint returns 403. (`role` in the token and the existence of a `students` row are two independent facts; the code requires both.)

### The rest of the tables

| Table | Purpose | Key constraint and why |
|---|---|---|
| `rooms` | Physical exam rooms, seeded with 5 rows | `room_number UNIQUE` |
| `courses` | Owned by a professor | FK → `professors(id)` `ON DELETE CASCADE` — delete a professor, their courses go |
| `student_courses` | Enrollment join table | `UNIQUE(student_id, course_id)` — the DB itself refuses double enrollment |
| `exams` | One sitting of one course | `room_id` FK is `ON DELETE SET NULL` — deleting a room shouldn't delete the exam, just orphan the location. Note the deliberate asymmetry with the CASCADEs |
| `exam_list` | **Who is allowed to sit this exam** | `UNIQUE(exam_id, student_id)` |
| `queue` | **Who has actually shown up, and their state** | `UNIQUE(exam_id, student_id)` — you cannot be in the same queue twice |
| `timetable_slots` | Recurring weekly class slot | `day_of_week SMALLINT CHECK (BETWEEN 1 AND 5)` — Mon–Fri only, enforced by the DB. `UNIQUE(course_id, day_of_week, start_time)` prevents double-booking a course |
| `announcements` | Professor → course broadcast | FK to both `professor_id` and `course_id` (denormalized: the professor is derivable from the course, but stored anyway) |
| `login_attempts` | Rate limiting (out of scope here) | |

### `exam_list` vs `student_courses` — why both?

This is a **snapshot vs live-view** distinction, and it's the sharpest design idea in the schema.

- `student_courses` = "I am *taking* this course." Changes whenever anyone enrolls.
- `exam_list` = "I was eligible for the exam on 12 July." Frozen at the moment the exam was created (plus manual CSV additions).

If you used `student_courses` for exam eligibility, a student who enrolls the night before could walk into an exam they were never registered for, and a student who drops the course would vanish from the historical record. The snapshot is the point.

### Status as a state machine, enforced by CHECK

```sql
status VARCHAR(20) DEFAULT 'waiting'
    CHECK (status IN ('waiting','called','attended','absent'))
```

The queue row's `status` is a finite state machine. `CHECK` means an invalid state can't even be written — the DB is the last line of defence, independent of any PHP validation. Same pattern on `exams.status` (`not_started` / `in_progress` / `closed`) and `users.role`.

**Note:** `absent` exists in the schema but **no code path ever sets it**. It's a designed-for-but-unimplemented state. Worth knowing before someone asks.

---

## 5. The anatomy of an endpoint (the 6-step preamble)

Nearly every file in `src/api/` opens with the same six steps. Learn it once and you can read any of the 15 endpoints at a glance. Here it is, from `api/professor/exams.php`:

```php
<?php
require_once __DIR__ . '/../../config/Database.php';
require_once __DIR__ . '/../../security/JWTHandler.php';
require_once __DIR__ . '/../../security/RBAC.php';

header('Content-Type: application/json');                    // ① declare JSON

$request_method = $_SERVER['REQUEST_METHOD'];

$jwt_handler = new JWTHandler();                             // ② extract the token
$token = $jwt_handler->getTokenFromHeader();
if (!$token) { http_response_code(401); echo json_encode(['error' => '...']); exit; }

$payload = $jwt_handler->verify($token);                     // ③ verify it
if (!$payload) { http_response_code(401); ... exit; }

RBAC::enforce($payload['role'], 'professor.create_exam');    // ④ can this role do this?

$database = new Database();                                  // ⑤ open a connection
$conn = $database->connect();

$prof_stmt = $conn->prepare("SELECT id FROM professors WHERE user_id = :user_id");
$prof_stmt->execute([':user_id' => $payload['user_id']]);    // ⑥ user_id → professor_id
if ($prof_stmt->rowCount() === 0) { http_response_code(403); ... exit; }
$professor_id = $prof_stmt->fetch(PDO::FETCH_ASSOC)['id'];

// ── only now does the actual work begin ──
if ($request_method === 'POST')     createExam($conn, $professor_id);
elseif ($request_method === 'GET')  listExams($conn, $professor_id);
else { http_response_code(405); ... }
```

Reading it step by step:

1. **`header('Content-Type: application/json')`** — must be sent *before any output*. PHP buffers headers until the first byte of body is echoed; a stray space before `<?php` would ruin this. (This is why the `?>` closing tag is often omitted in modern PHP — trailing whitespace after it becomes output.)
2. **`require_once`** — PHP's `import`, but it literally *executes the file inline*. `__DIR__` is the absolute path of the current file, so `__DIR__ . '/../../config/Database.php'` is a path relative to *this file*, not to the working directory. Getting this wrong is the classic PHP include bug.
3. **`exit`** — there is no `return ResponseEntity.status(401)`. You write the status code, echo the body, and **kill the script**. `exit` is the return statement of the HTTP layer. Forgetting it means execution continues and you emit *two* JSON bodies.
4. **`RBAC::enforce`** — a static call that either returns silently or `exit`s with 403. It's a guard clause, not a boolean.
5. **`new Database()` → `connect()`** — a fresh TCP connection to Postgres, per request. In `Database.php`:

```php
$this->host = getenv('DB_HOST') ?: 'db';   // env var, or fall back to 'db'
...
$this->conn = new PDO($dsn, $this->db_user, $this->db_password, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,   // throw, don't return false
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC          // rows as ['col' => val]
]);
```

   - `?:` is the **Elvis operator** — "use the left side unless it's falsy." It's what makes the container env vars override the hard-coded defaults.
   - `ERRMODE_EXCEPTION` is the setting that makes every `try/catch (PDOException $e)` in the codebase meaningful. Without it, PDO silently returns `false` on failure and you'd get mysterious null errors instead.
   - `FETCH_ASSOC` is why you write `$row['username']` everywhere instead of `$row[0]`.

6. **The identity translation** described in §4.

### The PDO idiom used everywhere

```php
$stmt = $conn->prepare("SELECT id FROM courses WHERE LOWER(code) = LOWER(:course_code)");
$stmt->execute([':course_code' => $course_code]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);     // one row, or false
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC); // array of rows
$n = $stmt->rowCount();                    // how many rows matched/affected
$id = $conn->lastInsertId();               // the SERIAL id just generated
```

Three of these carry real weight:

- **`rowCount()` after an UPDATE is used as a business-logic signal**, not just a stat. See the queue section — it's the concurrency-safety trick of the whole project.
- **`lastInsertId()`** works because `SERIAL` in Postgres is backed by a sequence; PDO reads `lastval()`.
- **Named placeholders (`:course_code`)** are the parameterization mechanism. Beyond the SQL-injection story (not our topic), they also mean *you never have to think about quoting or type conversion* — PDO sends the value out-of-band from the query text.

### Reading the request body

```php
$data = json_decode(file_get_contents('php://input'), true);
```

`php://input` is the raw request body stream. PHP auto-populates `$_POST` **only** for `application/x-www-form-urlencoded` and `multipart/form-data`. Since the frontend sends `Content-Type: application/json`, `$_POST` is *empty* and you must read the raw stream yourself. The `true` second argument to `json_decode` means "give me an associative array, not a `stdClass` object."

The one endpoint where this is different is the CSV upload — see §9.

---

## 6. Flow 1 — Register → Login → Dashboard

### The UI

`index.html` is the login page. It is deliberately **not a `<form>`** — there's no `<form>` element, no submit event, no page reload. Just inputs and a button with an `onclick`:

```html
<div class="input-group">
    <span class="input-group-text bg-light border-end-0"><i class="bi bi-envelope text-muted"></i></span>
    <input type="email" id="email" class="form-control border-start-0" placeholder="your@email.com" required>
</div>
...
<button id="loginBtn" class="btn-unime btn mb-3" onclick="doLogin()">
    <i class="bi bi-box-arrow-in-right me-2"></i>Login
</button>
```

Everything is Bootstrap 5 utility classes (`d-flex`, `mb-3`, `text-muted`, `fw-semibold`) plus Bootstrap Icons (`<i class="bi bi-envelope">`), both loaded from a CDN. The `required` attribute on the input is decorative here — with no `<form>` wrapping it, the browser never runs native validation. All validation is manual in JS.

### `js/login.js` — the request

```js
function doLogin() {
    var email    = document.getElementById('email').value.trim();
    var password = document.getElementById('password').value;

    if (!email || !password) { showError('Please enter your email and password.'); return; }

    fetch('/api/auth/login.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email: email, password: password })
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        if (data.success) {
            localStorage.setItem('token', data.token);
            localStorage.setItem('user_type', data.user_type);
            if (data.user_type === 'student')        window.location.href = 'student-dashboard.html';
            else if (data.user_type === 'professor') window.location.href = 'professor-dashboard.html';
            else if (data.user_type === 'admin')     window.location.href = 'admin-dashboard.html';
        } else {
            showError(data.error || 'Login failed.');
            document.getElementById('password').value = '';   // clear the field on failure
        }
    })
    .catch(function () { showError('Network error. Please try again.'); });
}
```

Concepts in play:

- **The two-`.then()` chain.** `fetch` resolves as soon as the *headers* arrive — the body may still be streaming. So the first `.then` gets a `Response` object, and `r.json()` returns *another* Promise (parse the body as JSON). Returning a Promise from inside `.then` makes the chain wait for it, which is why the second `.then` receives the parsed data. This is the single most important JS idiom in the whole frontend, and it's repeated in every API call.
- **`.catch` only fires on network failure**, not on HTTP 401/500. `fetch` considers "the server answered, with an error" a *success* at the transport level. That's why the code checks `data.success` / `data.error` rather than `r.ok`. A 401 lands in the `.then`, not the `.catch`.
- **Client-side routing by role.** The server returns `user_type`; the browser picks the dashboard file. This means the three dashboards are three separate HTML pages, not one app.
- **`localStorage`** — a synchronous, string-only, origin-scoped key/value store that survives tab closes. Two keys: `token` and `user_type`.

There's also a small quality-of-life listener registered on `DOMContentLoaded`:

```js
['email', 'password'].forEach(function (id) {
    document.getElementById(id).addEventListener('keydown', function (e) {
        if (e.key === 'Enter') doLogin();
    });
});
```

`DOMContentLoaded` fires when the HTML is parsed (before images finish loading). It's needed because the `<script>` tag sits at the bottom but the listeners must attach to elements that exist. The `e` parameter is the **Event object** the browser hands to every listener; `e.key` is the standard modern way to read which key was pressed.

### `api/auth/login.php` — the response

Skipping the rate-limit and password-verify machinery, the shape is:

```php
$token = $jwt_handler->create([
    'user_id'  => $user['id'],
    'username' => $user['username'],
    'email'    => $user['email'],
    'role'     => $user['role']
]);

echo json_encode([
    'success'   => true,
    'token'     => $token,
    'user_type' => $user['role'],
    'user'      => [ 'id' => ..., 'username' => ..., 'email' => ..., 'role' => ... ]
]);
```

The important *flow* fact: the token **carries the role and the user_id inside it**. That's the reason no endpoint ever needs a session lookup — the identity travels with the request. Everything downstream (`$payload['user_id']`, `$payload['role']`) reads from this payload.

### `api/auth/register.php`

Straight-line script: validate → check duplicates → hash → insert into `users` → insert into `students` **or** `professors` depending on role.

```php
$user_id = $conn->lastInsertId();

if ($role === 'student') {
    $conn->prepare("INSERT INTO students (user_id) VALUES (:user_id)")
         ->execute([':user_id' => $user_id]);
}
if ($role === 'professor') {
    $conn->prepare("INSERT INTO professors (user_id) VALUES (:user_id)")
         ->execute([':user_id' => $user_id]);
}
```

This is the write-side of the class-table-inheritance model from §4: **two inserts, one logical entity**. Note there is *no transaction* around them — if the second insert failed, you'd be left with a `users` row and no `students` row, i.e. a user who can log in but gets 403 on everything. A `$conn->beginTransaction()` / `commit()` pair belongs here.

The frontend (`signup.js`) never sends a `role` field, so signups default to `student`:

```php
$role = isset($data['role']) ? $data['role'] : 'student';
```

…but the API *accepts* one. Professors are supposed to be created by an admin (`api/admin/professors.php`), which is the same code path with `role` forced to `'professor'` and a `department` column. Keep that asymmetry in mind — it's a hole, and it's listed in §17.

### `api/auth/profile.php` — the odd one out

This is the only endpoint that **never touches the database**. It verifies the token and echoes the payload back:

```php
$payload = $jwt_handler->verify($token);
echo json_encode([ 'user' => [
    'id' => $payload['user_id'], 'username' => $payload['username'],
    'email' => $payload['email'], 'role' => $payload['role']
]]);
```

That's the whole point of a self-contained token: the data was already in the request. But note the trade-off — if a user changes their email, the profile page keeps showing the old one until the token expires (24h). **Stale claims** are the standard cost of stateless auth. Every dashboard calls this endpoint on load to populate the sidebar avatar and name.

---

## 7. Flow 2 — Professor creates a course, student enrolls

### Professor side — `api/professor/courses.php` (POST)

```php
// duplicate check is scoped to THIS professor, case-insensitively
$check_stmt = $conn->prepare("
    SELECT id FROM courses WHERE professor_id = :professor_id AND LOWER(code) = LOWER(:code)
");
...
if ($check_stmt->rowCount() > 0) {
    http_response_code(409);   // Conflict
    echo json_encode(['error' => 'You already have a course with the code "' . $code . '"']);
    exit;
}

$stmt = $conn->prepare("INSERT INTO courses (professor_id, name, code, description)
                        VALUES (:professor_id, :name, :code, :description)");
```

Two things to notice:

- **`LOWER(code) = LOWER(:code)`** — case-insensitive matching done in SQL rather than PHP. It works, but it means Postgres cannot use a plain B-tree index on `code` (it would need a *functional index* on `LOWER(code)`). At this scale it doesn't matter; at scale it's a sequential scan.
- **Course code uniqueness is per-professor, not global.** Two professors *can* both have `MATH101`. This is a latent bug, because the student enrollment endpoint looks up the code **globally**:

```php
// api/student/courses.php — no professor scoping!
$verify_stmt = $conn->prepare("SELECT id FROM courses WHERE LOWER(code) = LOWER(:course_code)");
```

If two professors use the same code, the student silently enrolls in whichever row comes back first. The schema has no `UNIQUE` on `courses.code` to prevent this. Flag it in §17.

### Student side — `api/student/courses.php`

One file, three behaviours, dispatched on method + query string:

```php
if ($request_method === 'POST') {
    enrollInCourse($conn, $student_id);
}
elseif ($request_method === 'GET') {
    if (isset($_GET['browse'])) listAllCourses($conn, $student_id);   // ?browse
    else                        listEnrolledCourses($conn, $student_id);
}
```

`isset($_GET['browse'])` is a **presence check** — the *value* doesn't matter, only that `?browse` appears in the URL. (In practice the frontend never calls `?browse`; `listAllCourses` is dead code. Worth knowing.)

The enrollment sequence: look up course by code → 404 if missing → check for existing enrollment → 409 if already enrolled → insert.

```php
$enroll_stmt = $conn->prepare("INSERT INTO student_courses (student_id, course_id)
                               VALUES (:student_id, :course_id)");
```

Note the **double protection against double enrollment**: the explicit `SELECT` check *and* the `UNIQUE(student_id, course_id)` constraint in the schema. The SELECT gives a friendly 409 message; the constraint is what would actually save you under a race condition (two clicks in the same millisecond). This "check-then-act" pattern is *not* atomic on its own — the DB constraint is what makes it safe.

### The `listAllCourses` query — the `LEFT JOIN` + `CASE` idiom

Even though it's unused by the UI, it's the clearest example of a pattern that appears three more times in the codebase:

```sql
SELECT c.id, c.name, c.code, c.description,
       u.username AS professor_name,
       CASE WHEN sc.id IS NOT NULL THEN true ELSE false END AS enrolled
FROM courses c
JOIN professors p ON c.professor_id = p.id
JOIN users u      ON p.user_id = u.id
LEFT JOIN student_courses sc ON sc.course_id = c.id AND sc.student_id = :student_id
ORDER BY c.name ASC
```

Read it as: *"give me every course; and, if this particular student happens to have an enrollment row, flag it."*

- The **two inner `JOIN`s** (`courses → professors → users`) are the price of the class-table inheritance from §4. The professor's *name* lives in `users`, not `professors`, so you always hop twice to get it. You'll see this three-table hop in almost every SELECT in the project.
- The **`LEFT JOIN` with the condition in the `ON` clause, not the `WHERE`** is the crux. If `sc.student_id = :student_id` were in the `WHERE`, the LEFT JOIN would collapse into an inner join and you'd only get courses the student is already in. In the `ON` clause, it filters *what gets joined*, while still keeping every course row.
- **`CASE WHEN sc.id IS NOT NULL`** converts "did the join find anything?" into a boolean column. This is how you compute a per-row flag in SQL rather than doing an N+1 query loop in PHP.

Then, in PHP:

```php
foreach ($courses as &$c) {
    $c['enrolled'] = (bool)$c['enrolled'];
}
```

The `&$c` is a **reference** — without the `&`, `$c` is a copy and the mutation is thrown away. This exists because PDO returns Postgres booleans as the strings `"t"`/`"f"` (or `"1"`/`""`), which `json_encode` would emit as strings, and JS's `if (e.on_roster)` would then be truthy for `"f"`. Casting to a real bool in PHP is what makes the JSON contain `true`/`false`. **This is a genuinely easy bug to miss and a good thing to be able to explain.**

### The UI for enrollment

```html
<div class="col-md-8">
    <input type="text" id="enrollId" class="form-control" placeholder="e.g. MATH101">
</div>
<div class="col-md-4">
    <button class="btn btn-primary w-100" onclick="enrollCourse()">
        <i class="bi bi-plus-circle me-1"></i>Enroll
    </button>
</div>
<div id="enrollMsg" class="mt-2"></div>
```

```js
function enrollCourse() {
    var code = document.getElementById('enrollId').value.trim();
    var msg  = document.getElementById('enrollMsg');
    if (!code) { msg.innerHTML = alertBox('Please enter a course code.', 'danger'); return; }

    apiRequest('/api/student/courses.php', 'POST', { course_code: code }).then(function (data) {
        if (data.course_id) {                       // success is inferred from the payload shape
            msg.innerHTML = alertBox('Enrolled successfully!', 'success');
            document.getElementById('enrollId').value = '';
            loadCoursesList();                      // re-fetch the table
        } else {
            msg.innerHTML = alertBox(data.error || data.message || 'Enrollment failed.', 'danger');
        }
    });
}
```

The pattern repeats verbatim across the whole frontend:

1. read inputs → 2. validate locally → 3. `apiRequest(...)` → 4. **detect success by checking for the presence of an expected key** (`data.course_id`, `data.exam_id`, `data.slot_id`, `data.status === 'waiting'`) → 5. paint a message div → 6. clear the form → 7. re-fetch the list.

Step 4 is worth criticizing: the frontend never looks at the HTTP status code. It infers success from the *shape* of the JSON. It works, but it's brittle — a backend that renamed `course_id` to `id` would break the UI silently. The `data.error || data.message || 'fallback'` chain is the **`||` default-value idiom**: JS's `||` returns the first *truthy* operand, so it walks down a list of increasingly generic fallbacks.

---

## 8. Flow 3 — Exam creation and the auto-roster

`api/professor/exams.php` (POST) is the most interesting write in the project, because it does **two things at once**.

### Step 1: validate the inputs by hand

```php
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $exam_date)) { http_response_code(400); ... }
if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $exam_time)) { http_response_code(400); ... }

$room_id = null;
if (isset($data['room_id']) && $data['room_id'] !== '' && $data['room_id'] !== null) {
    if (!ctype_digit((string)$data['room_id'])) { http_response_code(400); ... }
    $room_id = (int)$data['room_id'];
}
```

- `preg_match` with `^...$` anchors = format validation only. It checks *shape*, not *validity* — `9999-99-99` passes the regex and gets rejected later by Postgres's `DATE` type. Belt-and-braces again.
- The `room_id` block is doing **tri-state handling**: absent / empty-string / actual value. It must distinguish "the professor didn't pick a room" (→ store SQL `NULL`) from "the professor picked room 3". `ctype_digit` on the stringified value guards against `"3; DROP TABLE"` style garbage before the cast. Note the schema allows `room_id` to be null (`ON DELETE SET NULL`), so null is a legal domain value, not an error.

### Step 2: ownership check *before* the write

```php
$verify_stmt = $conn->prepare("
    SELECT id FROM courses
    WHERE LOWER(code) = LOWER(:course_code) AND professor_id = :professor_id
");
```

The `AND professor_id = :professor_id` is the load-bearing clause. It means the lookup is *scoped to the caller* — you physically cannot create an exam on someone else's course, because the course lookup won't find it. This is **object-level authorization done in the WHERE clause**, and it's the dominant pattern in the professor endpoints (queue, roster, timetable, announcements all do the equivalent). It's more robust than fetching the row and then comparing IDs in PHP, because there's no window where you hold a row you're not allowed to touch.

### Step 3: create the exam, then snapshot the roster

```php
$stmt = $conn->prepare("
    INSERT INTO exams (course_id, room_id, exam_date, exam_time, description, status)
    VALUES (:course_id, :room_id, :exam_date, :exam_time, :description, 'not_started')
");
$stmt->execute([...]);
$exam_id = $conn->lastInsertId();

// Auto-populate exam_list from all students enrolled in this course
$roster_stmt = $conn->prepare("
    INSERT INTO exam_list (exam_id, student_id)
    SELECT :exam_id, sc.student_id
    FROM student_courses sc
    WHERE sc.course_id = :course_id
    ON CONFLICT (exam_id, student_id) DO NOTHING
");
$roster_stmt->execute([':exam_id' => $exam_id, ':course_id' => $course_id]);
$enrolled_count = $roster_stmt->rowCount();
```

This is the snapshot from §4, made concrete. Three SQL concepts stacked:

- **`INSERT ... SELECT`** — bulk insert whose rows come from a query, not from literals. One round-trip to the database instead of `SELECT` + a PHP loop of N `INSERT`s. `:exam_id` is a constant projected into every row.
- **`ON CONFLICT (exam_id, student_id) DO NOTHING`** — Postgres's *upsert* clause. It names the unique constraint that might be violated and says "if a row already exists, skip it silently instead of throwing." This is what makes the operation **idempotent**: run it twice, no error, no duplicates.
- **`rowCount()` after the bulk insert** = how many students were actually added, returned to the UI as `enrolled_count`.

**The gap:** these two INSERTs are also not wrapped in a transaction. If the roster insert failed, you'd have an exam with no roster and no way to know.

### The UI

```js
function createExam() {
    var code   = document.getElementById('examCourseCode').value.trim();
    var date   = document.getElementById('examDate').value;      // <input type="date"> → "2026-07-14"
    var time   = document.getElementById('examTime').value;      // <input type="time"> → "14:30"
    var roomId = document.getElementById('examRoomId').value;

    var body = { course_code: code, exam_date: date, exam_time: time + ':00', description: desc };
    if (roomId) body.room_id = parseInt(roomId);

    msg.innerHTML = alertBox('Creating…', 'info');

    apiRequest('/api/professor/exams.php', 'POST', body).then(function (data) {
        if (data.exam_id) {
            msg.innerHTML = alertBox('Exam created! ID: <strong>' + data.exam_id + '</strong>', 'success');
            ...
        }
    });
}
```

Note `time + ':00'` — the native `<input type="time">` yields `HH:MM`, but the backend's regex demands `HH:MM:SS`. The frontend patches the seconds on. That's a **contract mismatch papered over in the client**, and it's exactly the kind of thing that breaks when a second client (a mobile app, curl) talks to the same API. The right fix is to make the backend accept both.

The returned `exam_id` is then **surfaced to the professor as a human-readable number** ("Exam created! ID: 7"), because the roster upload and the student join-queue box both ask you to type an exam ID by hand. The exam ID is the shared secret that stitches the three screens together.

---

## 9. Flow 4 — CSV roster upload (multipart, not JSON)

`api/professor/roster.php` is the one endpoint that does **not** speak JSON on the way in. That single fact changes everything about how it's called.

### Why `FormData` and not `JSON.stringify`

```js
function uploadRoster() {
    var examId    = document.getElementById('rosterExamId').value;
    var fileInput = document.getElementById('rosterFile');

    var form = new FormData();
    form.append('csv_file', fileInput.files[0]);

    fetch('/api/professor/roster.php?exam_id=' + examId, {
        method: 'POST',
        headers: { 'Authorization': 'Bearer ' + token },   // ← note: NO Content-Type
        body: form
    })
    .then(function (r) { return r.json(); })
    .then(function (data) { ... });
}
```

Four things here, all of them exam-worthy:

1. **JSON cannot carry binary.** A file is bytes; JSON is text. To send a file over HTTP you use `multipart/form-data`, which is a wire format that splits the body into parts separated by a random boundary string.
2. **`FormData` builds that multipart body for you.** `fileInput.files[0]` is a `File` object handed over by the browser's file picker (a subclass of `Blob`).
3. **You must NOT set `Content-Type` manually.** If you write `'Content-Type': 'multipart/form-data'`, the browser uses your string verbatim — and it's missing the `boundary=----WebKitFormBoundaryXyz` parameter, which PHP needs to split the parts. The result is an empty `$_FILES` and a very confusing debugging session. Leaving the header off lets the browser generate `multipart/form-data; boundary=...` correctly. **This is the single most common file-upload bug and this code gets it right.**
4. **This is why `uploadRoster` bypasses `apiRequest()`** — the shared helper hard-codes `'Content-Type': 'application/json'`. It has to call `fetch` directly and re-attach the `Authorization` header by hand.

Also note: **`exam_id` travels in the query string**, not the body, because the body is entirely occupied by the file.

### The backend read

```php
if (!isset($_FILES['csv_file'])) { http_response_code(400); ... }
$file = $_FILES['csv_file'];
if ($file['error'] !== UPLOAD_ERR_OK) { http_response_code(400); ... }
if (pathinfo($file['name'], PATHINFO_EXTENSION) !== 'csv') { http_response_code(400); ... }

$handle = fopen($file['tmp_name'], 'r');
fgetcsv($handle);                          // skip the header row

while (($row = fgetcsv($handle)) !== false) {
    $row_num++;
    $identifier = trim($row[0] ?? '');
    ...
}
fclose($handle);
```

- **`$_FILES`** is the superglobal PHP populates for multipart uploads. `tmp_name` is a temporary path on the container's disk where PHP has already spooled the bytes; the file is auto-deleted when the script ends. `name` is the *client-supplied* original filename — which is why checking the extension via `pathinfo($file['name'])` is validation theatre (a client can name anything `.csv`); it's a UX guard, not a security one.
- **`fgetcsv`** reads one line and returns it as an array of fields — handling quoting and escaping properly, which a naive `explode(',', $line)` would not. The bare `fgetcsv($handle);` with no assignment is the idiom for **skipping the header row**.
- **`$row[0] ?? ''`** — the *null coalescing operator*. Returns `''` if the index doesn't exist. Protects against a ragged CSV with an empty line.
- The loop reads **only column 0** and treats it as an "identifier" of unknown type.

### The flexible identifier lookup

```php
$student_stmt = $conn->prepare("
    SELECT s.id FROM students s
    JOIN users u ON s.user_id = u.id
    WHERE u.email = :identifier
       OR u.username = :identifier
       OR s.student_id_number = :identifier
    LIMIT 1
");
```

One placeholder, reused three times — PDO allows this in emulated-prepares mode. The design intent: *the professor's CSV can contain emails, usernames, or matriculation numbers and it just works.* Nice UX, but ambiguous — if someone's username happened to equal another person's student number, `LIMIT 1` picks arbitrarily. Also `student_id_number` is never populated by any code path in this repo, so that third branch can never match today.

### Per-row error accumulation

This is the structural pattern worth naming. The upload does **not** fail atomically. It processes each row independently and collects failures:

```php
$added = 0;
$errors = [];

while (($row = fgetcsv($handle)) !== false) {
    ...
    if ($student_stmt->rowCount() === 0) {
        $errors[] = "Row $row_num: Student not found ($identifier)";
        continue;                          // ← skip, don't abort
    }
    if ($check_stmt->rowCount() > 0) continue;   // already on roster, silently skip
    $insert_stmt->execute([...]);
    $added++;
}

echo json_encode([
    'added'        => $added,
    'total_errors' => count($errors),
    'errors'       => $errors
]);
```

`continue` (skip this iteration) vs `exit` (kill the request) is the whole decision. A partial success is a legitimate outcome here: "I added 27 of your 30 students, and here are the 3 I couldn't find." An all-or-nothing transaction would be *worse* UX — one typo would reject a 300-row file.

The frontend renders that error list with a `.map().join('')`:

```js
if (data.errors && data.errors.length) {
    msg.innerHTML += '<ul class="small mt-1">'
        + data.errors.map(function (e) { return '<li>' + e + '</li>'; }).join('')
        + '</ul>';
}
var type = data.total_errors > 0 ? 'warning' : 'success';
```

`.map()` transforms each string into an `<li>` string, giving an *array of strings*; `.join('')` glues them into one string with no separator. If you `+`'d an array onto a string directly, JS would insert commas. This map/join pair is the standard vanilla-JS "render a list" idiom and it shows up everywhere in this codebase.

---

## 10. Flow 5 — The queue: the heart of the system

Everything before this was setup. This is the actual product.

### The state machine

```
                  student POSTs                professor PUTs           professor PATCHes
   (not in queue) ────────────→ waiting ──────────────────→ called ──────────────────→ attended
                                   │
                                   └──→ absent   ← designed, never implemented
```

The `queue` row's `status` column is the single source of truth. `UNIQUE(exam_id, student_id)` guarantees one row per student per exam, so the state machine has exactly one instance per (student, exam) pair.

### Step 1 — Student joins: `api/student/queue.php` (POST)

Four gates in sequence, each a separate query:

```php
// 1. Does the exam exist, and is it open?
$verify_stmt = $conn->prepare("SELECT id, status FROM exams WHERE id = :exam_id");
...
if ($exam['status'] === 'closed') { http_response_code(403); echo json_encode(['error' => 'This exam is closed']); exit; }

// 2. Is this student on the roster? (the exam_list snapshot from §8)
$eligible_stmt = $conn->prepare("SELECT id FROM exam_list WHERE exam_id = :exam_id AND student_id = :student_id");
if ($eligible_stmt->rowCount() === 0) { http_response_code(403); echo json_encode(['error' => 'You are not eligible for this exam']); exit; }

// 3. Already queued?
$check_stmt = $conn->prepare("SELECT id, status FROM queue WHERE exam_id = :exam_id AND student_id = :student_id");
if ($check_stmt->rowCount() > 0) { http_response_code(409); ... exit; }

// 4. Join.
$join_stmt = $conn->prepare("INSERT INTO queue (exam_id, student_id, status) VALUES (:exam_id, :student_id, 'waiting')");
```

Gate 2 is where `exam_list` earns its existence. Being *enrolled in the course* is not enough — you must be on *this exam's* roster. And `joined_at` defaults to `CURRENT_TIMESTAMP`, which is the **only** thing that establishes queue order. There's no explicit position column; position is *derived from arrival time* on every read. That's a deliberate choice: a stored position column would need to be renumbered every time someone left, and would be a race-condition magnet.

### Step 2 — Student polls: `api/student/queue.php` (GET)

The position calculation:

```php
// how many people are ahead of me?
$position_stmt = $conn->prepare("
    SELECT COUNT(*) as position FROM queue
    WHERE exam_id = :exam_id
      AND status = 'waiting'
      AND joined_at < :joined_at
");
$position_stmt->execute([':exam_id' => $exam_id, ':joined_at' => $student_queue['joined_at']]);
$position = $position_result['position'] + 1;    // 1-indexed for humans
```

Read it literally: **"count the people who are still waiting and who arrived before me, then add one."** That's your position. The `+ 1` converts a 0-based count into a 1-based rank.

The subtlety: because the count filters on `status = 'waiting'`, a student who gets **called** disappears from the denominator, and everyone behind them shifts up by one automatically. **The queue advances as a side effect of the professor changing one row's status.** No renumbering, no recalculation job. That's elegant, and it's the payoff for not storing positions.

The professor's view of the same queue uses a different technique — a **window function**:

```sql
SELECT q.id, q.student_id, u.username, u.email, q.status, q.joined_at,
       ROW_NUMBER() OVER (ORDER BY q.joined_at) as position
FROM queue q
JOIN students s ON q.student_id = s.id
JOIN users u    ON s.user_id = u.id
WHERE q.exam_id = :exam_id
ORDER BY q.joined_at
```

`ROW_NUMBER() OVER (ORDER BY ...)` assigns 1, 2, 3… to the result rows **after** the WHERE has been applied, in the order given inside `OVER`. It's the SQL way of saying "number these rows."

**The two views disagree, on purpose:** the professor's `ROW_NUMBER()` numbers *everyone* in the queue including already-called and attended students (it doesn't filter on status), so it's a stable ledger of arrival order. The student's `COUNT(*)` only counts people still waiting, so it's a live "how many ahead of me." Same table, two different questions. If you were asked "why not use the same query for both?" — that's the answer.

### Step 3 — Professor calls: `api/professor/queue.php` (PUT)

```php
$update_stmt = $conn->prepare("
    UPDATE queue SET status = 'called'
    WHERE exam_id = :exam_id AND student_id = :student_id AND status = 'waiting'
");
$update_stmt->execute([...]);

if ($update_stmt->rowCount() === 0) {
    http_response_code(409);
    echo json_encode(['error' => 'Student is not in waiting status']);
    exit;
}
```

**This is the sharpest piece of code in the repository and you should be able to explain it cold.**

The `AND status = 'waiting'` in the `WHERE` of an `UPDATE` is a **conditional update / compare-and-swap**. It fuses "check the current state" and "change the state" into a *single atomic statement*. Postgres guarantees the row is locked for the duration of the UPDATE, so:

- If the student was `waiting`, the update matches → `rowCount() === 1` → success.
- If someone else already called them (or they've been marked attended), the `status = 'waiting'` predicate fails → `rowCount() === 0` → 409.

Compare with the naive alternative: `SELECT status` … `if ($status === 'waiting')` … `UPDATE`. Between the SELECT and the UPDATE, another request could sneak in. That's a **TOCTOU** (time-of-check to time-of-use) race, and with two professors sharing an account, or one professor double-clicking, you'd double-call a student. The conditional UPDATE makes the race impossible.

`rowCount()` being used as a **business signal** ("did the state transition actually happen?") rather than a statistic is the key insight.

Interestingly, `markAttended` (PATCH) does **not** do this — it does a SELECT-then-UPDATE and its UPDATE has no status guard:

```php
$check_stmt = $conn->prepare("SELECT id FROM queue WHERE exam_id = :exam_id AND student_id = :student_id");
if ($check_stmt->rowCount() === 0) { http_response_code(404); ... }

$update_stmt = $conn->prepare("
    UPDATE queue SET status = 'attended', attended_at = NOW()
    WHERE exam_id = :exam_id AND student_id = :student_id
");
```

So you can mark someone `attended` who was never `called`, and you can re-mark an already-attended student (resetting their `attended_at`). It's a looser transition. Whether that's a bug or a feature is arguable — "professor needs to be able to fix mistakes" — but the *inconsistency* with `callStudent` is the honest answer: it's a bug, and the fix is `AND status = 'called'` (or `AND status IN ('waiting','called')`).

### Why PUT vs PATCH here

`callStudent` is PUT, `markAttended` is PATCH. Both are partial updates of one field, so strictly both should be PATCH. The distinction is being used as a **cheap operation selector** — two different verbs on one URL, to avoid inventing `/queue/call.php` and `/queue/attend.php`. It's pragmatic, and it's the kind of thing an examiner will poke at. The REST-pure alternative is either two endpoints, or one PATCH taking `{"status": "called"}` in the body.

### Step 4 — The polling loop (`js/dashboard.js`)

There are no WebSockets. The student's browser asks, over and over.

```js
var queuePoller = null;
var activeQueueExamId = null;

function startQueuePolling(examId) {
    stopQueuePolling();                                   // ① kill any previous timer
    activeQueueExamId = examId;
    document.getElementById('queueStatusCard').style.display = 'block';
    pollQueueStatus();                                    // ② fire immediately…
    queuePoller = setInterval(pollQueueStatus, 5000);     // ③ …then every 5 seconds
}

function stopQueuePolling() {
    if (queuePoller) { clearInterval(queuePoller); queuePoller = null; }
    activeQueueExamId = null;
    document.getElementById('queueStatusCard').style.display = 'none';
}
```

- **`setInterval` returns a numeric handle**, stored in `queuePoller`. You need it to call `clearInterval` later. Losing the handle = an orphan timer you can never stop, hammering the server forever. That's why `startQueuePolling` calls `stopQueuePolling()` first — **guarding against stacking two intervals** if the user clicks "Join Queue" twice.
- **The immediate `pollQueueStatus()` before the interval** is a small but important detail: `setInterval` doesn't fire until *after* the first delay, so without this the user would stare at an empty card for 5 seconds.
- **The poller is also cleared on navigation**, in `loadPage`: `if (page !== 'queue') stopQueuePolling();`. Otherwise you'd keep polling in the background after switching to the Timetable tab.

And the poll body — a **self-terminating** loop:

```js
function pollQueueStatus() {
    if (!activeQueueExamId) return;
    apiRequest('/api/student/queue.php?exam_id=' + activeQueueExamId).then(function (data) {
        var body  = document.getElementById('queueStatusBody');
        var pulse = document.getElementById('queuePulse');

        if (data.position === undefined) {          // error shape → give up
            body.innerHTML = alertBox(data.error || 'Not in this queue.', 'danger');
            stopQueuePolling();
            return;
        }

        if (data.status === 'called') {
            clearInterval(queuePoller); queuePoller = null; activeQueueExamId = null;
            pulse.innerHTML = '';
            body.innerHTML = '<div class="text-center py-3">'
                + '<i class="bi bi-bell-fill fs-1 text-primary mb-2 d-block"></i>'
                + '<p class="fw-bold fs-5" style="color:#006ec0">It\'s your turn!</p>'
                + '<p class="text-muted">Head to the exam room now.</p>'
                + '</div>';
        } else if (data.status === 'attended') {
            clearInterval(queuePoller); queuePoller = null; activeQueueExamId = null;
            body.innerHTML = alertBox('You have been marked as attended. Good luck!', 'success');
        } else {
            pulse.innerHTML = '<span class="spinner-grow spinner-grow-sm text-primary me-1"></span>updating…';
            body.innerHTML = '<table class="table table-borderless small mb-0">'
                + '<tr><td class="text-muted">Status</td><td><span class="badge badge-waiting">' + data.status + '</span></td></tr>'
                + '<tr><td class="text-muted">Your position</td><td><strong>' + data.position + '</strong> of ' + data.total_waiting + ' waiting</td></tr>'
                + '</table>';
        }
    }).catch(function () {
        document.getElementById('queuePulse').textContent = '● offline';
    });
}
```

The loop **stops itself** when it reaches a terminal state (`called` / `attended`) — the polling exists only to detect the transition, so once detected there's nothing left to watch. `data.position === undefined` is the "the backend returned an error shape" check (recall §7: the frontend infers outcome from JSON shape, not status code).

The `.catch` sets `● offline` but **does not stop the timer** — so a transient network blip shows "offline" and then silently recovers on the next tick. That's deliberate and correct.

**The trade-offs of polling**, since the README calls this out as the main limitation:

| | Polling (what this does) | WebSocket / SSE |
|---|---|---|
| Latency | up to 5s | ~instant |
| Server load | N students × 1 request / 5s, forever | 1 persistent connection each |
| Complexity | ~15 lines, works everywhere | needs a stateful server; PHP-FPM is a bad fit |
| Failure mode | self-healing (next tick just works) | needs reconnect/backoff logic |

For a room with 30 students that's 6 requests/second — completely fine. The honest defence of polling here is that **PHP's shared-nothing model makes push genuinely hard**: there is no long-lived process to hold a connection. You'd need to bolt on a separate Node/Ratchet process. Polling is the right call for the scale and the stack; say so, don't apologise for it.

### The professor's queue UI

```js
function loadQueueList() {
    var examId = parseInt(document.getElementById('manageExamSelect').value);
    apiRequest('/api/professor/queue.php?exam_id=' + examId).then(function (data) {
        data.queue.forEach(function (s) {
            var bc = s.status === 'attended' ? 'badge-attended'
                   : s.status === 'absent'   ? 'badge-absent'
                   : s.status === 'called'   ? 'badge-called'
                   : 'badge-waiting';

            var action = s.status === 'waiting'
                ? '<button class="btn btn-warning btn-sm" onclick="callStudent(' + examId + ',' + s.student_id + ')">Call</button>'
                : s.status === 'called'
                ? '<button class="btn btn-success btn-sm" onclick="markAttended(' + examId + ',' + s.student_id + ')">Attended</button>'
                : '<span class="text-muted small">done</span>';
            ...
        });
    });
}
```

**The UI is a projection of the state machine.** `waiting` → offer "Call". `called` → offer "Attended". Anything else → no action. The chained ternaries are picking both a CSS badge class and an action button from the same status string. The button that's shown *is* the legal transition. That's a clean, honest way to build a state-machine UI, and it means the frontend never has to know the rules twice.

Note the professor's queue **does not auto-refresh** — it reloads only when an action succeeds (`if (data.status === 'called') loadQueueList();`). So a professor won't see new students joining until they act. Adding a `setInterval` here would be the obvious symmetry fix.

---

## 11. Flow 6 — Timetable rendering

The backend is unremarkable — the *frontend* is where the concept lives.

`timetable_slots` stores sparse rows: `(course_id, day_of_week 1–5, start_time, end_time, room)`. The UI needs a dense 4×5 grid. The bridge is a **lookup map**.

```js
var TIME_SLOTS = [
    { start: '09:00:00', end: '11:00:00', label: '09:00 – 11:00' },
    { start: '11:00:00', end: '13:00:00', label: '11:00 – 13:00' },
    { start: '14:00:00', end: '16:00:00', label: '14:00 – 16:00' },
    { start: '16:00:00', end: '18:00:00', label: '16:00 – 18:00' }
];

function renderTimetable(slots, bodyId) {
    var lookup = {};
    slots.forEach(function (s) {
        lookup[s.day_of_week + '|' + s.start_time] = s;      // ① composite key
    });

    var html = '';
    TIME_SLOTS.forEach(function (slot) {                      // ② rows = time slots
        html += '<tr><th class="text-nowrap small bg-light">' + slot.label + '</th>';
        for (var d = 1; d <= 5; d++) {                        // ③ cols = Mon..Fri
            var cell = lookup[d + '|' + slot.start];          // ④ O(1) hit or miss
            if (cell) {
                html += '<td class="tt-busy"><strong>' + cell.course_name + '</strong>'
                      + '<span class="text-muted">' + cell.course_code + '</span></td>';
            } else {
                html += '<td class="tt-free">—</td>';
            }
        }
        html += '</tr>';
    });
    document.getElementById(bodyId).innerHTML = html;
}
```

The idea: **build an index keyed by `"day|time"`, then iterate the grid and look each cell up.** The alternative — for each of the 20 cells, scan the whole slots array — is O(rows × slots). This is O(rows + slots). At this size the performance difference is nil; the *readability* difference is the real win.

The `'|'` is just a separator to make a composite key out of two values, because JS object keys are strings. (`{3: ..., '09:00:00': ...}` can't express a pair; `'3|09:00:00'` can.)

Hard-coding `TIME_SLOTS` in JS is a **schema-in-two-places** smell: the DB will happily accept a slot at 10:30, but the grid has no row for it, so it would be silently invisible. The professor's "add slot" dropdown only offers the four canonical slots, so in practice it's consistent — but only by convention, not by constraint.

`renderTimetable` is duplicated, near-identically, in **both** `dashboard.js` and `professor.js`. The professor's version adds a delete button inside each busy cell:

```js
html += '<br><button class="btn btn-link btn-sm p-0 text-danger" onclick="deleteSlot(' + cell.id + ')">'
      + '<i class="bi bi-trash"></i> remove</button>';
```

Same function, one extra branch, copy-pasted into two files. In a framework you'd share it; in vanilla JS with no module system and no bundler, this is what you get. It's the honest cost of "no framework."

The professor's `deleteSlot` sends the id in a **DELETE body**:

```js
apiRequest('/api/professor/timetable.php', 'DELETE', { slot_id: slotId })
```

DELETE with a request body is legal but unusual (many proxies strip it). `?slot_id=3` in the query string would be more conventional. The backend reads it with the same `json_decode(file_get_contents('php://input'))` as everything else, so it works here.

---

## 12. Flow 7 — Announcements

The simplest CRUD in the app, and therefore the cleanest example of the **scoping-by-join** pattern that runs through the whole backend.

**Professor writes** (`api/professor/announcements.php`, POST) — ownership check first, then insert:

```php
$chk = $conn->prepare("SELECT id FROM courses WHERE id = :cid AND professor_id = :pid");
$chk->execute([':cid' => (int)$data['course_id'], ':pid' => $professor_id]);
if ($chk->rowCount() === 0) { http_response_code(403); echo json_encode(['error' => 'Course not found']); exit; }

$ins = $conn->prepare("INSERT INTO announcements (professor_id, course_id, message) VALUES (:pid, :cid, :msg)");
```

Note the 403 message says *"Course not found"* — deliberately vague, so a professor can't probe which course IDs exist by watching for 403-vs-404. Small thing, good instinct.

**Student reads** (`api/student/announcements.php`, GET) — and here's the whole security model of the read side, expressed as a JOIN:

```sql
SELECT a.id, a.message, a.created_at,
       c.name AS course_name, c.code AS course_code,
       u.username AS professor_name
FROM announcements a
JOIN courses c        ON a.course_id = c.id
JOIN professors p     ON a.professor_id = p.id
JOIN users u          ON p.user_id = u.id
JOIN student_courses sc ON sc.course_id = c.id      -- ← the gate
WHERE sc.student_id = :sid                          -- ← the gate
ORDER BY a.created_at DESC
```

The last JOIN + WHERE pair is doing all the authorization work. **You see an announcement if and only if there exists an enrollment row linking you to its course.** There is no `if (student can see this)` in PHP — the visibility rule *is* the join. This is the same shape as the student timetable query (`JOIN student_courses sc ... WHERE sc.student_id = :sid`) and it's the pattern to point at if anyone asks "how do you stop students reading other courses' data?"

### The rendering, and the one place XSS is handled

```js
html += '<div class="announcement-bubble mt-2">' + escapeHtml(a.message) + '</div>';
```

```js
function escapeHtml(str) {
    return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
```

Announcement text is the only **free-form, user-authored, cross-user-visible** string in the app — a professor types it, other people's browsers render it. Everything else rendered via `innerHTML` (course names, usernames, emails) is *also* user-supplied but the code doesn't escape it. So `escapeHtml` is applied exactly where the author noticed the risk, and nowhere else. That inconsistency is worth being upfront about: `innerHTML` with concatenated data is a stored-XSS vector *everywhere* it appears, and the correct blanket fix is to escape at every interpolation point (or build DOM nodes with `textContent` instead of gluing HTML strings). Order of replacement matters too — `&` must be escaped first, or you'd double-escape the `&` in `&lt;`.

The announcement card itself is a nice bit of UI construction — an avatar built from the first letter of the professor's name:

```js
'<div class="avatar-circle flex-shrink-0" style="width:40px;height:40px;font-size:16px">'
+ a.professor_name.charAt(0).toUpperCase()
+ '</div>'
```

paired with the CSS:

```css
.announcement-bubble {
    background: #f0f7ff;
    border-left: 3px solid #006ec0;
    border-radius: 0 8px 8px 0;
    padding: 10px 14px;
}
```

The `border-radius: 0 8px 8px 0` (top-left 0, everything else 8px) plus the left border is what makes it read as a chat bubble anchored to the avatar. Small detail, big effect.

---

## 13. Flow 8 — Admin: stats, professors, students

The admin surface is thin: three read endpoints and one create.

**`api/admin/stats.php`** — a loop over a map of label → SQL:

```php
foreach ([
    'professors' => "SELECT COUNT(*) FROM professors",
    'students'   => "SELECT COUNT(*) FROM students",
    'courses'    => "SELECT COUNT(*) FROM courses",
    'exams'      => "SELECT COUNT(*) FROM exams"
] as $key => $sql) {
    $stats[$key] = (int)$conn->query($sql)->fetchColumn();
}
```

- `$conn->query()` (not `prepare()` + `execute()`) is used because there are **no parameters** — nothing to inject. `prepare` exists to safely bind user input; with a hard-coded string there's nothing to bind. Using `query()` here is correct, not lazy.
- `fetchColumn()` grabs the first column of the first row — the idiomatic way to read a scalar aggregate.
- Four separate round-trips. A single `SELECT (SELECT COUNT(*) FROM professors) AS professors, (SELECT ...) ...` would be one. At 4 queries it doesn't matter; know that you *could*.

**`api/professor/stats.php`** is the same idea but every count is **scoped to the caller** by joining back to `courses.professor_id`:

```php
$s = $conn->prepare("
    SELECT COUNT(DISTINCT sc.student_id)
    FROM student_courses sc
    JOIN courses c ON sc.course_id = c.id
    WHERE c.professor_id = :pid
");
```

`COUNT(DISTINCT sc.student_id)` — because a student enrolled in three of your courses is **one** student, not three. Without `DISTINCT` the "Students" tile on the dashboard would inflate. That's the entire reason the keyword is there.

And the "queue now" tile:

```php
"SELECT COUNT(*) FROM queue q
 JOIN exams e   ON q.exam_id = e.id
 JOIN courses c ON e.course_id = c.id
 WHERE c.professor_id = :pid AND q.status IN ('waiting','called')"
```

A **three-hop join** to get from a queue entry back to its owning professor (`queue → exams → courses → professor_id`). `IN ('waiting','called')` = "people who are still actively in play," excluding `attended`/`absent`. That's the definition of "live queue size."

**`api/admin/students.php`** — `GROUP BY` with a `LEFT JOIN` count:

```sql
SELECT s.id, u.username, u.email, u.created_at,
       COUNT(DISTINCT sc.course_id) AS enrolled_courses
FROM students s
JOIN users u             ON s.user_id = u.id
LEFT JOIN student_courses sc ON sc.student_id = s.id
GROUP BY s.id, u.username, u.email, u.created_at
ORDER BY u.created_at DESC
```

The `LEFT JOIN` is essential: with an inner join, a student with **zero** enrollments would vanish from the admin list entirely. With LEFT JOIN they appear with `enrolled_courses = 0`. And every non-aggregated selected column must appear in `GROUP BY` — that's why the GROUP BY clause is four columns long rather than just `s.id`. (Postgres is strict about this; MySQL historically was not, which is a classic portability trap.)

**`api/admin/professors.php`** (POST) is `register.php` with the role hard-coded and a `department` field. Same two-insert pattern (`users` then `professors`), same missing transaction.

The admin dashboard JS (`admin.js`) is structurally identical to the other two — `init()` → fetch profile → fetch stats → `loadPage('overview')`. Its one distinctive touch:

```js
}).catch(function () {
    // silently ignore — stats are cosmetic
});
```

An explicit decision that a failed stats call should not produce an error banner. Correct instinct: don't alarm the user over decoration.

---

## 14. The frontend architecture

Three dashboards (`student-dashboard.html`, `professor-dashboard.html`, `admin-dashboard.html`), three JS files (`dashboard.js`, `professor.js`, `admin.js`), one shared helper (`common.js`). No framework, no build step, no modules — just `<script src>` tags in order.

### The "SPA without a framework" pattern

Every dashboard is **one HTML file containing every screen, all hidden**, plus a function that toggles one visible.

```html
<div id="section-timetable" style="display:none;"> ... </div>
<div id="section-exams"     style="display:none;"> ... </div>
<div id="section-courses"   style="display:none;"> ... </div>
<div id="section-queue"     style="display:none;"> ... </div>
```

```js
var sections = ['timetable', 'exams', 'courses', 'queue', 'announcements', 'contact', 'profile'];

function showSection(name) {
    sections.forEach(function (s) {
        document.getElementById('section-' + s).style.display = 'none';   // hide all
    });
    document.getElementById('section-' + name).style.display = 'block';   // show one
    setActiveNav(name);
}

function loadPage(page) {
    if (page !== 'queue') stopQueuePolling();
    showSection(page);
    if (page === 'timetable')     loadTimetable();
    if (page === 'exams')         loadMyExams();
    if (page === 'courses')       loadCoursesList();
    if (page === 'announcements') loadAnnouncements();
    if (page === 'profile')       loadProfile();
}
```

This is a **client-side router** in fifteen lines. The separation is worth naming explicitly:

- **`showSection`** = pure view state. Which `<div>` is visible. No network.
- **`loadPage`** = view state **+ data fetching** + side-effect cleanup (stopping the poller).

The convention `'section-' + name` — deriving a DOM id from a string — is what lets one loop handle N screens. Every section div is named to match its entry in the `sections` array. Break the naming convention and `getElementById` returns `null` and the whole thing throws.

Navigation is just `onclick` in the markup:

```html
<a class="nav-link" href="#" data-page="exams" onclick="loadPage('exams')">
    <i class="bi bi-clipboard-check me-1"></i>My Exams
</a>
```

And the active-highlight uses the `data-page` attribute:

```js
function setActiveNav(page) {
    document.querySelectorAll('.navbar-nav .nav-link[data-page]').forEach(function (a) {
        a.classList.toggle('active-nav', a.dataset.page === page);
    });
}
```

- **`data-*` attributes** are the standard way to attach custom metadata to HTML. `data-page="exams"` becomes `a.dataset.page === 'exams'` in JS.
- **`classList.toggle(cls, force)`** with a second boolean argument means "add if true, remove if false" — so one call handles both the element being activated and the six being deactivated. Without the second arg it would *flip* each class, which is not what you want.
- `href="#"` keeps the anchor styled and keyboard-focusable, while `onclick` does the real work. (It does put a `#` in the URL. A `<button>` would be more semantically honest.)

**What this architecture gives up:** the URL never changes, so there's no deep-linking, no browser back button, and a refresh always dumps you on the default tab. That's the price of `display:none` routing without the History API.

### `common.js` — the two functions everything depends on

```js
function apiRequest(url, method, body) {
    var token = localStorage.getItem('token');
    var options = {
        method: method || 'GET',
        headers: {
            'Content-Type': 'application/json',
            'Authorization': 'Bearer ' + token
        }
    };
    if (body) options.body = JSON.stringify(body);
    return fetch(url, options).then(function (res) { return res.json(); });
}
```

This is the **single choke point** for every authenticated call in the app. It does four things:

1. Reads the token from `localStorage` **fresh on every call** (not cached in a variable).
2. `method || 'GET'` — the `||` default idiom again; called with two args, it's a GET.
3. Attaches `Authorization: Bearer <token>`, which is what the backend's `getTokenFromHeader()` parses.
4. **Returns the already-`.json()`-parsed Promise**, so callers write `.then(function (data) { ... })` and get the payload directly, skipping the `r.json()` step. That's why every call site in `dashboard.js` has one `.then` while `login.js` has two — `login.js` predates/bypasses the helper because it has no token yet.

The one exception is `uploadRoster()`, which cannot use it (§9 — the hard-coded `Content-Type` would break multipart).

```js
function alertBox(msg, type) {
    return '<div class="alert alert-' + (type || 'info') + '">' + msg + '</div>';
}

function logout() {
    localStorage.removeItem('token');
    localStorage.removeItem('user_type');
    window.location.href = 'index.html';
}
```

`alertBox` **returns a string, it doesn't touch the DOM** — that's why every call site does `someDiv.innerHTML = alertBox(...)`. It's a template function, and the `alert-` + type concatenation maps directly onto Bootstrap's `alert-success` / `alert-danger` / `alert-warning` / `alert-info` classes.

`logout` is purely client-side: **delete the token, go home.** Nothing is sent to the server. That's inherent to stateless tokens — the server has no session to destroy, and the JWT remains technically valid until it expires. (Real revocation needs a denylist, which is a whole other design.)

### The auth guard

Every dashboard JS file opens with the same two lines:

```js
var token = localStorage.getItem('token');
if (!token) window.location.href = 'index.html';
```

That's the client-side guard. Note two things: it runs at *script parse time*, not inside a function, so it fires the instant the file loads. And it only checks that a token **exists** — not that it's valid, not that the role matches the page. A student who manually navigates to `professor-dashboard.html` will see the professor UI render — and then every API call it makes will come back 403, so all the tables will show "Failed to load." **The UI is not the security boundary; the API is.** That's the correct architecture (the client guard is a UX convenience, not a control), and it's the right way to say it if you're asked.

### The rendering strategy: `innerHTML` string building

There is exactly one way data becomes UI in this app, repeated a dozen times:

```js
var rows = '';
data.courses.forEach(function (c) {
    rows += '<tr>'
        + '<td class="fw-semibold">' + c.name + '</td>'
        + '<td><span class="badge" style="background:#e8f0fe;color:#006ec0">' + c.code + '</span></td>'
        + '<td>' + (c.professor_name || '—') + '</td>'
        + '<td class="text-muted small">' + new Date(c.enrolled_at).toLocaleDateString() + '</td>'
        + '</tr>';
});
list.innerHTML = '<div class="table-responsive"><table class="table table-hover align-middle small mb-0">'
    + '<thead class="table-light"><tr><th>Course</th><th>Code</th><th>Professor</th><th>Enrolled On</th></tr></thead>'
    + '<tbody>' + rows + '</tbody>'
    + '</table></div>';
```

Accumulate an HTML string in a loop, assign it **once**. The single assignment matters: `innerHTML` triggers a parse + full re-render of that subtree, so doing it inside the loop would be N re-renders instead of 1.

Supporting concepts visible in that snippet:

- `(c.professor_name || '—')` — the null-display idiom.
- `new Date(iso).toLocaleDateString()` — Postgres returns an ISO-ish timestamp string; `Date` parses it and `toLocale*` formats it in the *user's* locale. All date formatting is client-side; the server never formats.
- `data.exams.forEach` etc. is preceded everywhere by an **empty check**:

```js
if (!data.exams || data.exams.length === 0) {
    container.innerHTML = '<div class="card ..."><div class="card-body text-muted small">No exams found.</div></div>';
    return;
}
```

The `!data.exams ||` half also doubles as the error case (an error response has no `exams` key), which is why an API failure and an empty list often render the same message.

- **Loading states** are set before every fetch: `container.innerHTML = '<p class="text-muted">Loading...</p>';`. Cheap, and it's what keeps the UI from looking frozen.

### The exam card — where several ideas land at once

`loadMyExams()` in `dashboard.js` is the densest bit of rendering, and it's driven entirely by the flags the backend computed in SQL (`on_roster`, `queue_status` — remember the `LEFT JOIN` + `CASE` from §7):

```js
var statusBadge = e.status === 'in_progress' ? '<span class="badge bg-success">In Progress</span>'
                : e.status === 'closed'      ? '<span class="badge bg-secondary">Closed</span>'
                : '<span class="badge" style="background:#e8f0fe;color:#006ec0">Upcoming</span>';

var rosterBadge = e.on_roster
    ? '<span class="badge bg-success ms-1">On Roster</span>'
    : '<span class="badge bg-warning text-dark ms-1">Not on Roster</span>';

var queueInfo = '';
if (e.queue_status === 'waiting')       queueInfo = '<span class="badge badge-waiting ms-1">Waiting in Queue</span>';
else if (e.queue_status === 'called')   queueInfo = '<span class="badge badge-called ms-1">Called!</span>';
else if (e.queue_status === 'attended') queueInfo = '<span class="badge badge-attended ms-1">Attended</span>';

var joinBtn = (e.on_roster && e.status === 'in_progress' && !e.queue_status)
    ? '<button class="btn btn-sm btn-primary" onclick="goToQueue(' + e.id + ')">Join Queue</button>'
    : '';
```

The `joinBtn` condition is a **three-way guard**: you must be on the roster, **and** the exam must be in progress, **and** you must not already be queued. It mirrors, on the client, exactly the gates the backend enforces in §10 — which is good defensive design (don't offer an action the server will reject).

Except: **`e.status` can never be `'in_progress'`**, because no endpoint in the codebase ever transitions an exam out of `not_started`. So this button is dead. See §17 — this is the biggest functional bug in the project.

And the little cross-tab navigation trick:

```js
function goToQueue(examId) {
    document.getElementById('queueExamId').value = examId;   // prefill the other tab's input
    loadPage('queue');                                       // then switch to it
}
```

Because every section lives in the same DOM, you can write into a hidden section's input *before* revealing it. That's a genuinely nice affordance of the single-page-with-hidden-divs design, and it's why the professor's "Exam created! ID: 7" message matters less than it otherwise would.

### `e.exam_time.slice(0,5)`

Appears in three places. Postgres `TIME` comes back as `"14:30:00"`; the UI wants `"14:30"`. A `.slice(0,5)` is the cheapest possible fix. It's a string operation, not a date parse — worth knowing that the code never turns times into `Date` objects, only timestamps.

---

## 15. The CSS layer

Two files, ~130 lines total. Everything else is Bootstrap 5 from a CDN.

The whole design system is **one colour** — `#006ec0`, the University of Messina blue — applied consistently:

```css
.navbar-unime { background-color: #006ec0; }

.navbar-unime .nav-link {
    color: rgba(255, 255, 255, 0.85) !important;
    border-radius: 6px;
    padding: 6px 14px !important;
    transition: background 0.15s;
}

.navbar-unime .nav-link:hover,
.navbar-unime .nav-link.active-nav {
    background: rgba(255, 255, 255, 0.16) !important;
    color: #fff !important;
}
```

- The `!important`s are there to **beat Bootstrap's own specificity** — Bootstrap's `.navbar-dark .nav-link` rule would otherwise win. This is the standard (if ugly) way to override a CDN framework you can't recompile.
- `rgba(255,255,255,0.16)` rather than a solid colour means the hover state is a *translucent white veil* over the blue, so it stays on-brand automatically without you picking a second colour.
- `.active-nav` is the class toggled by `setActiveNav()` — this is where the JS meets the CSS.

The status badges are a **semantic colour map**, and they're the reason `js` can write `'badge-' + s.queue_status` and get the right colour for free:

```css
.badge-waiting  { background: #fff3cd; color: #856404; }   /* amber  */
.badge-called   { background: #cce5ff; color: #004085; }   /* blue   */
.badge-attended { background: #d4edda; color: #155724; }   /* green  */
.badge-absent   { background: #f8d7da; color: #721c24; }   /* red    */
```

The class name is derived from the database enum value. **The state machine's states are the CSS class names.** That's a tidy piece of coupling — add a state to the schema, add a line here, and the rendering just works.

```css
.avatar-circle {
    width: 72px; height: 72px;
    background: #006ec0;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-size: 28px; font-weight: 700;
    margin: 0 auto;
}
```

The **flex-centering trio** (`display:flex` + `align-items:center` + `justify-content:center`) is the modern way to centre a single character both horizontally and vertically inside a box. `border-radius: 50%` on an equal-width-and-height box gives a perfect circle. `margin: 0 auto` centres the circle itself in its parent.

```css
.page-title-bar {
    border-left: 4px solid #006ec0;
    padding-left: 12px;
    color: #006ec0;
    font-weight: 700;
}
```

Every screen title gets a blue accent bar. `border-left` + `padding-left` together — the padding is what stops the text touching the bar. This single class is what makes the seven different sections feel like one product.

Layout is entirely Bootstrap's 12-column grid:

```html
<div class="row g-4">
    <aside class="col-lg-3"> ... profile sidebar ... </aside>
    <main class="col-lg-9">  ... section divs ...   </main>
</div>
```

`col-lg-3` / `col-lg-9` = 3+9 = 12 columns on large screens; **below the `lg` breakpoint they each become full-width and stack**, which is the entire responsive story. `g-4` is the gutter (gap) between columns. Same for the stat tiles: `col-sm-6 col-xl-3` = 2-up on small, 4-up on extra-large.

---

## 16. Cross-cutting concepts glossary

Every concept the codebase relies on, in one place. If you can explain each of these in two sentences, you know the project.

### PHP

| Concept | Where | What it means |
|---|---|---|
| Shared-nothing execution | everywhere | Each request gets a fresh process. No state, no singletons, no connection pool survives a request. |
| File-as-endpoint | `api/**/*.php` | The URL path is the filesystem path. Apache is the router. No dispatcher. |
| `require_once __DIR__ . '/...'` | top of every API file | Inline execution of another file, path resolved relative to the *current file*. |
| Superglobals | `$_SERVER`, `$_GET`, `$_FILES` | Auto-populated arrays. `$_SERVER['REQUEST_METHOD']`, `$_GET['exam_id']`, `$_FILES['csv_file']`. |
| `php://input` | every POST/PUT/PATCH handler | The raw request body. Required because `$_POST` is empty for `application/json`. |
| `exit` | after every error response | The HTTP layer's `return`. Without it, execution continues and you emit two bodies. |
| `http_response_code(n)` | error paths | Sets the status line. Must precede body output. |
| Elvis `?:` | `Database.php` | `getenv('DB_HOST') ?: 'db'` — left side unless falsy. |
| Null coalescing `??` | `roster.php` | `$row[0] ?? ''` — right side if the left is unset/null. Does *not* fire on `''` or `0`. |
| Reference `&$c` in foreach | `student/courses.php`, `student/exams.php` | Mutate the array in place. Without `&`, you're editing a copy. |
| `password_hash` / `password_verify` | auth | bcrypt with an automatic per-user salt embedded in the hash string. |

### PDO

| Concept | What it means |
|---|---|
| `prepare()` + named placeholders | Query text and data travel separately. Also removes all quoting/escaping concerns. |
| `query()` | For SQL with no parameters (the stats counts). Not a shortcut for laziness. |
| `ERRMODE_EXCEPTION` | Set once in `Database.php`. It's what makes `catch (PDOException)` blocks fire at all. |
| `FETCH_ASSOC` | Rows come back as `['column' => value]`, not numeric arrays. |
| `rowCount()` | Rows matched/affected. **Used as a business signal** — "did the conditional UPDATE actually fire?" |
| `lastInsertId()` | The `SERIAL` id just generated. Needed by `register.php` and `exams.php`. |
| `fetchColumn()` | First column of the first row — the scalar-aggregate idiom. |

### SQL / PostgreSQL

| Concept | Where | Why |
|---|---|---|
| `SERIAL PRIMARY KEY` | every table | Auto-incrementing surrogate key backed by a sequence. |
| `CHECK (x IN (...))` | `role`, `status`, `day_of_week` | The DB is the final guard on the state machine. Invalid states are unwritable. |
| `UNIQUE(a, b)` composite | `queue`, `exam_list`, `student_courses`, `timetable_slots` | Prevents duplicates *at the storage layer*, which is what actually makes check-then-act safe under concurrency. |
| `ON DELETE CASCADE` | most FKs | Delete a professor → their courses, exams, rosters, queues all go. Prevents orphans. |
| `ON DELETE SET NULL` | `exams.room_id` | Deliberate asymmetry: deleting a room must not delete the exam. |
| `LEFT JOIN` + condition in `ON` | `student/courses.php`, `student/exams.php`, `enrolled-students.php` | "Give me all of X, and flag the ones that also have a Y." Putting the condition in `WHERE` instead would silently turn it into an inner join. |
| `CASE WHEN x IS NOT NULL THEN true` | same files | Turns a join hit/miss into a boolean column. Avoids an N+1 query loop. |
| `COALESCE(q.status, 'not in queue')` | `enrolled-students.php` | Default value for a LEFT JOIN miss, computed in SQL. |
| `INSERT ... SELECT` | `professor/exams.php` | Bulk insert sourced from a query. One round-trip instead of N. |
| `ON CONFLICT ... DO NOTHING` | `exams.php`, `schema.sql` seeds | Upsert. Makes the operation idempotent — run it twice, no error. |
| `ROW_NUMBER() OVER (ORDER BY ...)` | `professor/queue.php` | Window function. Numbers the result rows 1..N after filtering. |
| `COUNT(DISTINCT x)` | `professor/stats.php` | A student in 3 of your courses is 1 student. |
| `GROUP BY` with every non-aggregate column | `admin/students.php` | Postgres requires it; MySQL historically didn't. Classic portability trap. |
| Conditional `UPDATE ... WHERE status = 'waiting'` | `professor/queue.php` | **Compare-and-swap.** Fuses check and act into one atomic statement. Kills the TOCTOU race. |
| Scoping by JOIN | student read endpoints | The authorization rule *is* the join. `JOIN student_courses ... WHERE sc.student_id = :sid`. |
| Scoping in the `WHERE` of a lookup | professor write endpoints | `AND professor_id = :pid` — you can't act on a row you can't find. |

### JavaScript

| Concept | What it means |
|---|---|
| `fetch` returns a Promise of `Response` | Resolves on headers, not body. `r.json()` returns a *second* Promise — hence the two-`.then()` chain. |
| `.catch` fires on network errors only | A 401 or 500 is a *successful* fetch. That's why the code checks payload shape, not `r.ok`. |
| Returning a Promise from `.then` | Makes the chain wait for it. The mechanism behind the whole async flow. |
| `localStorage` | Synchronous, string-only, origin-scoped, survives tab close. Holds `token` and `user_type`. |
| `setInterval` / `clearInterval` | The handle must be stored or the timer can never be stopped. Guard against stacking. |
| `innerHTML` string accumulation | Build the whole HTML string in a loop, assign once. Assigning inside the loop = N re-renders. |
| `.map().join('')` | Array of objects → array of HTML strings → one string. The vanilla-JS list-render idiom. |
| `||` default value | `data.error \|\| data.message \|\| 'fallback'` — first truthy operand wins. |
| `??` nullish coalescing | `data.courses ?? '—'` in `professor.js` — unlike `\|\|`, it does **not** fire on `0`. That matters for a stat tile showing zero. |
| `data-*` attributes + `.dataset` | Custom metadata on HTML, read as `el.dataset.page`. |
| `classList.toggle(cls, bool)` | Add-if-true / remove-if-false in one call. |
| `FormData` | Builds a `multipart/form-data` body. **Never set `Content-Type` yourself** — the boundary would be missing. |
| `new Date(iso).toLocaleDateString()` | All date formatting is client-side, in the user's locale. |
| Event object `e` | `e.key === 'Enter'` in `login.js`. The browser passes it to every listener. |

---

## 17. Known gaps, bugs and things to be ready to defend

Being able to name your own project's weaknesses is worth more than pretending it has none. In rough order of severity:

### 1. Exams can never enter `in_progress` — the Join Queue button is dead

`exams.status` is created as `'not_started'` and **no endpoint anywhere updates it.** But the student UI only renders the join button when the status is `in_progress`:

```js
var joinBtn = (e.on_roster && e.status === 'in_progress' && !e.queue_status) ? '<button ...>' : '';
```

So the primary user journey is unreachable through the "My Exams" screen. It *does* still work if the student types the exam ID into the Queue tab manually (the backend only rejects `closed`), which is presumably how it was demoed. **The missing piece is a `PATCH /api/professor/exams.php` that transitions `not_started → in_progress → closed`, plus Start/Close buttons on the professor's exam table.** This is the first thing to fix.

### 2. `register.php` accepts a `role` field

```php
$role = isset($data['role']) ? $data['role'] : 'student';
if (!in_array($role, ['student', 'professor', 'admin'])) { $role = 'student'; }
```

The frontend never sends it, but a raw `curl` with `{"role":"admin"}` creates an admin. Privilege escalation by unauthenticated request. The whole point of `api/admin/professors.php` existing is that professor accounts should be admin-created. The fix is one line: hard-code `$role = 'student'` in `register.php`.

### 3. Multi-step writes have no transactions

Three places do two dependent INSERTs with nothing wrapping them:
- `register.php`: `users` + `students`/`professors`
- `admin/professors.php`: `users` + `professors`
- `professor/exams.php`: `exams` + `exam_list`

If the second fails, you're left with an inconsistent half-state (a user who can log in but 403s on everything; an exam with no roster). `$conn->beginTransaction()` / `$conn->commit()` / `rollBack()` in the catch. PDO supports it; the code just doesn't use it.

### 4. `markAttended` has no state guard

`callStudent` uses a compare-and-swap (`AND status = 'waiting'`), `markAttended` doesn't. So you can mark someone attended who was never called, and you can re-mark and overwrite `attended_at`. Inconsistent with its sibling. Add `AND status = 'called'`.

### 5. Course codes are unique per-professor, but looked up globally

`courses.code` has no `UNIQUE` constraint. Two professors can both create `MATH101`. But `api/student/courses.php` enrolls via `WHERE LOWER(code) = LOWER(:course_code)` with no professor scoping and no `LIMIT`, so the student lands in whichever row Postgres returns first. Either add a global `UNIQUE(code)` or make enrollment take `(professor, code)`.

### 6. `innerHTML` + concatenated user data = stored XSS

`escapeHtml()` is applied to announcement bodies and **nowhere else**. Course names, usernames, emails, descriptions and CSV error strings all go straight into `innerHTML`. A professor could name a course `<img src=x onerror=alert(1)>` and it would execute in every enrolled student's browser. The fix is to escape at *every* interpolation point, or stop building HTML from strings.

### 7. "Leave Queue" doesn't leave the queue

```html
<button class="btn btn-outline-secondary btn-sm mt-3" onclick="stopQueuePolling()">
    <i class="bi bi-x-circle me-1"></i>Leave Queue
</button>
```

`stopQueuePolling()` clears the timer and hides the card. **It sends nothing to the server.** The `queue` row still exists, the student still holds their position, and the professor still sees them. There is no `DELETE /api/student/queue.php`. The button is a lie; either implement the endpoint or rename the button to "Stop watching."

### 8. The professor's queue view doesn't auto-refresh

Students poll; the professor doesn't. `loadQueueList()` runs only on dropdown change and after a successful call/attend. A professor watching the screen won't see new arrivals. Given the polling machinery already exists in `dashboard.js`, this is a 10-line fix.

### 9. `start.sh` calls a file that doesn't exist

`docker exec professore_presente_php php /var/www/html/test_db_connection.php` — that file isn't in the repo. Every startup prints an error. Cosmetic, but it's the first thing anyone running the project sees.

### 10. Smaller ones worth knowing

- **`absent` status is never set** by any code path, though it's in the schema and has a CSS badge.
- **`student_id_number` is never populated**, so the CSV roster's third identifier branch is dead.
- **`listAllCourses` (`?browse`) is dead code** — no UI calls it.
- **`a2enmod rewrite`** is enabled but no rewrite rules exist.
- **DB credentials are hard-coded** in `docker-compose.yml` *and* as fallbacks in `Database.php`.
- **`renderTimetable` is duplicated** verbatim in `dashboard.js` and `professor.js`.
- **The frontend infers success from JSON shape** (`if (data.course_id)`) rather than reading HTTP status codes. Works, but brittle.
- **`TIME_SLOTS` is hard-coded in JS** but the DB accepts any `TIME`. Schema in two places.
- **No pagination anywhere** — every list endpoint returns everything.

---

## Where to look for what — quick index

| I want to understand… | Read |
|---|---|
| How a request reaches PHP at all | §2, `Dockerfile`, `docker-compose.yml`, `.htaccess` |
| Why every endpoint repeats 25 lines | §2 (shared-nothing), §5 (the 6-step preamble) |
| Why there are two IDs for one person | §4 (class-table inheritance), `schema.sql` |
| The core product loop | §10, `api/student/queue.php`, `api/professor/queue.php`, `js/dashboard.js` |
| The cleverest single line | §10 — `UPDATE queue SET status='called' WHERE ... AND status='waiting'` |
| How the frontend fakes an SPA | §14, `showSection` / `loadPage` |
| Why the CSV upload is special | §9 — `FormData`, no manual `Content-Type` |
| How students are stopped from reading other courses' data | §12 — the authorization rule *is* the JOIN |
| What I should fix first | §17.1 — exam status transitions |
