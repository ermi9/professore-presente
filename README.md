# 🎓 Professore Presente

A secure, full-stack web application for managing university exam queues, built with a strong focus on System Security and RESTful Web Programming principles.

---

## 🚀 Overview

Professore Presente is designed to optimize the interaction between students and professors during exam sessions.

It allows:
- Students to enroll and join exam queues
- Professors to manage queues in real time
- Admins to control system entities

The system is built as a decoupled RESTful architecture with strong security-by-design principles.

---

## 🏗️ Architecture

### Backend
- PHP 8.2 (RESTful API)
- Apache Server
- PDO for database interaction
- Custom security layer (JWTHandler, RBAC)

### Frontend
- HTML5 + CSS3
- Vanilla JavaScript
- Fetch API for async requests
- LocalStorage for JWT persistence

### Database
- PostgreSQL
- Fully normalized relational schema

### Infrastructure
- Docker + Docker Compose

## ✨ Core Features

### 👨‍🎓 Student
- Register and login
- Enroll in courses
- Join exam queues
- View queue status

### 👨‍🏫 Professor
- Create exams
- Manage queue (call next, mark attended)
- Validate ownership of exams

### 👑 Admin
- Manage professors and system entities

---

## 🔐 Security Implementation

### JWT Authentication
- Stateless authentication
- Token includes user_id, email, role, expiration
- HMAC-SHA256 signature

### Password Security
- bcrypt hashing
- automatic salting

### SQL Injection Prevention
- PDO prepared statements

### RBAC
- Centralized permission enforcement

### Rate Limiting
- Protects login endpoint from brute-force attacks

---

## 🔄 System Workflow

### Authentication
1. User logs in
2. Password verified
3. JWT generated
4. Token stored in LocalStorage
5. Sent in Authorization header

### Queue System
1. Student joins queue
2. Backend validates request
3. Stored in database
4. Professor manages queue
5. Updates applied

---

## 🗄️ Database Design

### Tables

| Table | Description |
|---|---|
| `users` | All users (admin, professor, student) with role and credentials |
| `professors` | Links users to professor role; stores department |
| `students` | Links users to student role; stores student ID number |
| `rooms` | Physical exam rooms with capacity and location |
| `courses` | Courses created by professors |
| `student_courses` | Enrollment join table (student ↔ course) |
| `exams` | Exam sessions linked to a course and room; tracks status (`not_started`, `in_progress`, `closed`) |
| `exam_list` | Students eligible to sit a specific exam |
| `queue` | Active queue entries per exam; tracks status (`waiting`, `called`, `attended`, `absent`) |
| `login_attempts` | IP-based brute-force rate limiting records |

All tables use foreign keys with cascading deletes where appropriate.

---

## ⚙️ Setup

### Run with Dockerdocker-compose up --build

Open:
http://localhost

---

## ⚖️ Design Decisions

- JWT → scalable authentication
- Vanilla JS → DOM mastery
- Docker → consistent environment

---

## 📉 Limitations

- Polling-based queue updates (no WebSocket push)
- Basic frontend UI
- No modern framework

---

## 🚀 Future Improvements

- WebSockets
- React frontend
- Notifications
- Monitoring tools

---

## 📚 Academic Context

Developed for:
- Web Programming
- System Security

## 📄 License

Academic use only
