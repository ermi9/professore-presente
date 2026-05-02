# Professore Presente - Exam Queue Management System

A web application for managing exam queues. Professors upload a file with eligible students, students join the queue, and professors mark attendance.

## Tech Stack
- **Backend:** PHP 8.2 + Apache
- **Database:** PostgreSQL 15
- **Frontend:** HTML/CSS/Bootstrap + Vanilla JavaScript
- **Authentication:** JWT (JSON Web Tokens)
- **Authorization:** Role-Based Access Control (RBAC)

---

## Setup Instructions

### 1. Clone/Setup the Project
```bash
cd /path/to/professore_presente
```

### 2. Start Docker Containers
```bash
docker-compose up -d
```

This will:
- Start PostgreSQL service (`db`) on port 5432
- Start PHP/Apache service (`php`) on port 80
- Both will be ready automatically (health checks configured)

### 3. Initialize the Database
Wait a few seconds for PostgreSQL to be ready, then:

```bash
docker exec professore_presente_db psql -U professor -d professore_presente -f /dev/stdin < src/config/schema.sql
```

Or manually:
```bash
docker exec -it professore_presente_db psql -U professor -d professore_presente
```
Then paste the contents of `src/config/schema.sql`

### 4. Test Database Connection
```bash
docker exec professore_presente_php php /var/www/html/test_db_connection.php
```

You should see:
```
✓ Database connection successful!
Connected to: professore_presente
```

### 5. Access the App
Open your browser and go to:
```
http://localhost
```

---

## Project Structure
```
src/
├── config/
│   ├── Database.php       # Database connection class
│   └── schema.sql         # Database schema
├── test_db_connection.php # Connection test
└── index.php              # (to be created)
```

---

## Database Overview

### Tables:
1. **users** - All users (admin, professor, student)
2. **professors** - Links users to professor role
3. **students** - Links users to student role
4. **exams** - Exam sessions created by professors
5. **exam_list** - Students eligible for each exam (from uploaded file)
6. **queue** - Active queue for each exam

### Relationships:
- users → professors/students (one-to-one)
- professors → exams (one-to-many)
- exams → exam_list (one-to-many)
- exams → queue (one-to-many)

---

## Next Steps
1. Build authentication (register, login, JWT)
2. Implement RBAC middleware
3. Create admin dashboard
4. Build professor exam flow
5. Build student queue flow

---

## Useful Docker Commands

**View logs:**
```bash
docker-compose logs php
docker-compose logs db
```

**Stop containers:**
```bash
docker-compose down
```

**Access PostgreSQL CLI:**
```bash
docker exec -it professore_presente_db psql -U professor -d professore_presente
```

**Restart:**
```bash
docker-compose restart
```

---

## Development Notes

- PHP files go in `src/`
- Apache serves from `/var/www/html` (mapped to `src/`)
- PostgreSQL runs on port 5432 (accessible as `db` hostname from PHP container)
- Connection details in `docker-compose.yml` (change if needed)

---

## Security Notes

- All passwords are hashed with `password_hash()` + `PASSWORD_BCRYPT`
- All SQL queries use prepared statements (PDO) to prevent SQL injection
- JWT tokens validate request authorization
- RBAC middleware enforces role-based access control
- CSRF tokens on all forms

---

Created: 2025
