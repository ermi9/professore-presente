var token = localStorage.getItem('token');
if (!token) window.location.href = 'index.html';

var sections = ['overview', 'professors', 'students', 'contact', 'profile'];

function setActiveNav(page) {
    document.querySelectorAll('.navbar-nav .nav-link[data-page]').forEach(function (a) {
        a.classList.toggle('active-nav', a.dataset.page === page);
    });
}

function showSection(name) {
    sections.forEach(function (s) {
        document.getElementById('section-' + s).style.display = 'none';
    });
    document.getElementById('section-' + name).style.display = 'block';
    setActiveNav(name);
}

function loadPage(page) {
    showSection(page);
    if (page === 'overview')    loadOverview();
    if (page === 'professors')  loadProfessorsList();
    if (page === 'students')    loadStudentsList();
    if (page === 'profile')     loadProfile();
}

function init() {
    apiRequest('/api/auth/profile.php').then(function (data) {
        if (!data.user) return;
        var u = data.user;
        document.getElementById('sidebarName').textContent  = u.username;
        document.getElementById('sidebarEmail').textContent = u.email;
        document.getElementById('avatarCircle').textContent = u.username.charAt(0).toUpperCase();
    });

    apiRequest('/api/admin/stats.php').then(function (data) {
        if (data.professors !== undefined) {
            document.getElementById('sidebarProfessors').textContent = data.professors;
            document.getElementById('sidebarStudents').textContent   = data.students;
        }
    });

    loadPage('overview');
}

// ── OVERVIEW ───────────────────────────────────────────────────────────────

function loadOverview() {
    apiRequest('/api/admin/stats.php').then(function (data) {
        document.getElementById('statProfessors').textContent = data.professors ?? '—';
        document.getElementById('statStudents').textContent   = data.students   ?? '—';
        document.getElementById('statCourses').textContent    = data.courses    ?? '—';
        document.getElementById('statExams').textContent      = data.exams      ?? '—';
    }).catch(function () {
        // silently ignore — stats are cosmetic
    });
}

// ── PROFESSORS ─────────────────────────────────────────────────────────────

function loadProfessorsList() {
    var list = document.getElementById('professorsList');
    list.innerHTML = '<p class="text-muted small">Loading...</p>';

    apiRequest('/api/admin/professors.php').then(function (data) {
        if (!data.professors || data.professors.length === 0) {
            list.innerHTML = '<p class="text-muted small">No professors yet.</p>';
            return;
        }
        var rows = '';
        data.professors.forEach(function (p) {
            rows += '<tr>'
                + '<td class="fw-semibold">' + p.username + '</td>'
                + '<td class="text-muted small">' + p.email + '</td>'
                + '<td>' + (p.department || '—') + '</td>'
                + '<td class="text-muted small">' + new Date(p.created_at).toLocaleDateString() + '</td>'
                + '</tr>';
        });
        list.innerHTML = '<div class="table-responsive"><table class="table table-hover align-middle small mb-0">'
            + '<thead class="table-light"><tr><th>Username</th><th>Email</th><th>Department</th><th>Created</th></tr></thead>'
            + '<tbody>' + rows + '</tbody>'
            + '</table></div>';
    }).catch(function () {
        list.innerHTML = alertBox('Failed to load professors.', 'danger');
    });
}

function createProfessor() {
    var username   = document.getElementById('profUsername').value.trim();
    var email      = document.getElementById('profEmail').value.trim();
    var password   = document.getElementById('profPassword').value;
    var department = document.getElementById('profDept').value.trim();
    var msg        = document.getElementById('profMsg');

    if (!username || !email || !password) {
        msg.innerHTML = alertBox('Username, email and password are required.', 'danger');
        return;
    }

    apiRequest('/api/admin/professors.php', 'POST', { username, email, password, department })
    .then(function (data) {
        if (data.professor_id) {
            msg.innerHTML = alertBox('Account created for <strong>' + data.username + '</strong>!', 'success');
            ['profUsername','profEmail','profPassword','profDept'].forEach(function (id) {
                document.getElementById(id).value = '';
            });
            loadProfessorsList();
        } else {
            msg.innerHTML = alertBox(data.error || data.message || 'Failed.', 'danger');
        }
    }).catch(function () {
        msg.innerHTML = alertBox('Network error.', 'danger');
    });
}

// ── STUDENTS ───────────────────────────────────────────────────────────────

function loadStudentsList() {
    var list = document.getElementById('studentsList');
    list.innerHTML = '<p class="text-muted small">Loading...</p>';

    apiRequest('/api/admin/students.php').then(function (data) {
        if (!data.students || data.students.length === 0) {
            list.innerHTML = '<p class="text-muted small">No students registered yet.</p>';
            return;
        }
        var rows = '';
        data.students.forEach(function (s) {
            rows += '<tr>'
                + '<td class="fw-semibold">' + s.username + '</td>'
                + '<td class="text-muted small">' + s.email + '</td>'
                + '<td class="text-center">'
                + '<span class="badge" style="background:#e8f0fe;color:#006ec0">' + s.enrolled_courses + '</span>'
                + '</td>'
                + '<td class="text-muted small">' + new Date(s.created_at).toLocaleDateString() + '</td>'
                + '</tr>';
        });
        list.innerHTML = '<div class="table-responsive"><table class="table table-hover align-middle small mb-0">'
            + '<thead class="table-light"><tr><th>Username</th><th>Email</th><th>Courses</th><th>Registered</th></tr></thead>'
            + '<tbody>' + rows + '</tbody>'
            + '</table></div>';
    }).catch(function () {
        list.innerHTML = alertBox('Failed to load students.', 'danger');
    });
}

// ── PROFILE ────────────────────────────────────────────────────────────────

function loadProfile() {
    apiRequest('/api/auth/profile.php').then(function (data) {
        if (data.user) {
            document.getElementById('profileUsername').textContent = data.user.username;
            document.getElementById('profileEmail').textContent    = data.user.email;
            document.getElementById('profileRole').textContent     = data.user.role;
        }
    });
}

init();
