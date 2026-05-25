-- Users table: stores all users (admin, professors, students)
CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL CHECK (role IN ('admin', 'professor', 'student')),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Professors table: links users to professor role
CREATE TABLE IF NOT EXISTS professors (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL UNIQUE,
    department VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Students table: links users to student role
CREATE TABLE IF NOT EXISTS students (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL UNIQUE,
    student_id_number VARCHAR(20) UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Rooms table: physical exam rooms
CREATE TABLE IF NOT EXISTS rooms (
    id SERIAL PRIMARY KEY,
    room_number VARCHAR(50) NOT NULL UNIQUE,
    capacity INTEGER,
    location VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Courses table: courses created by professors
CREATE TABLE IF NOT EXISTS courses (
    id SERIAL PRIMARY KEY,
    professor_id INTEGER NOT NULL,
    name VARCHAR(200) NOT NULL,
    code VARCHAR(50),
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (professor_id) REFERENCES professors(id) ON DELETE CASCADE
);

-- Student course enrollment
CREATE TABLE IF NOT EXISTS student_courses (
    id SERIAL PRIMARY KEY,
    student_id INTEGER NOT NULL,
    course_id INTEGER NOT NULL,
    enrolled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    UNIQUE(student_id, course_id)
);

-- Exams table: exam sessions for courses
CREATE TABLE IF NOT EXISTS exams (
    id SERIAL PRIMARY KEY,
    course_id INTEGER NOT NULL,
    room_id INTEGER,
    exam_date DATE NOT NULL,
    exam_time TIME NOT NULL,
    description TEXT,
    status VARCHAR(20) DEFAULT 'not_started' CHECK (status IN ('not_started', 'in_progress', 'closed')),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL
);

-- Exam list: tracks which students are eligible for this exam
CREATE TABLE IF NOT EXISTS exam_list (
    id SERIAL PRIMARY KEY,
    exam_id INTEGER NOT NULL,
    student_id INTEGER NOT NULL,
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    UNIQUE(exam_id, student_id)
);

-- Queue table: active queue for each exam
CREATE TABLE IF NOT EXISTS queue (
    id SERIAL PRIMARY KEY,
    exam_id INTEGER NOT NULL,
    student_id INTEGER NOT NULL,
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    attended_at TIMESTAMP,
    status VARCHAR(20) DEFAULT 'waiting' CHECK (status IN ('waiting', 'called', 'attended', 'absent')),
    FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    UNIQUE(exam_id, student_id)
);

-- Login attempts tracking for rate limiting (IP-based)
CREATE TABLE IF NOT EXISTS login_attempts (
    id SERIAL PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL UNIQUE,
    failed_attempts INTEGER DEFAULT 1,
    last_attempt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    locked_until TIMESTAMP
);

-- Insert initial admin user
-- Username: admin, Password: admin123 (hashed with bcrypt)
INSERT INTO users (username, email, password_hash, role)
VALUES (
    'admin',
    'admin@professore.it',
    '$2y$10$N9qo8uLOickgx2ZMRZoMye6c4i6vkBTUfQZPKy6mDYhTUWdZHpDf2',
    'admin'
) ON CONFLICT (username) DO NOTHING;

-- Insert sample rooms
INSERT INTO rooms (room_number, capacity, location)
VALUES 
    ('A101', 30, 'Building A, Floor 1'),
    ('A102', 35, 'Building A, Floor 1'),
    ('B201', 25, 'Building B, Floor 2'),
    ('B202', 40, 'Building B, Floor 2'),
    ('C301', 50, 'Building C, Floor 3')
ON CONFLICT (room_number) DO NOTHING;
