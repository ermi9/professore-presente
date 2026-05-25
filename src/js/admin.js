var token = localStorage.getItem('token');
if (!token) window.location.href = 'index.html';

var sections = ['professors', 'profile'];

function showSection(name) {
    sections.forEach(function (s) {
        document.getElementById('section-' + s).style.display = 'none';
    });
    document.getElementById('section-' + name).style.display = 'block';
}

function loadPage(page) {
    showSection(page);
    if (page === 'professors') loadProfessorsList();
    if (page === 'profile')    loadProfile();
}

function init() {
    apiRequest('/api/auth/profile.php').then(function (data) {
        if (data.user) document.getElementById('adminName').textContent = data.user.username;
    });
    loadPage('professors');
}

function loadProfessorsList() {
    var list = document.getElementById('professorsList');
    list.innerHTML = '<p>Loading...</p>';

    apiRequest('/api/admin/professors.php').then(function (data) {
        if (!data.professors || data.professors.length === 0) {
            list.innerHTML = '<p style="color:#666;">No professors yet. Create one above.</p>';
            return;
        }
        var rows = '';
        data.professors.forEach(function (p) {
            rows += '<tr>'
                + '<td>' + p.id + '</td>'
                + '<td>' + p.username + '</td>'
                + '<td>' + p.email + '</td>'
                + '<td>' + (p.department || '-') + '</td>'
                + '<td>' + new Date(p.created_at).toLocaleDateString() + '</td>'
                + '</tr>';
        });
        list.innerHTML = '<table>'
            + '<thead><tr><th>ID</th><th>Username</th><th>Email</th><th>Department</th><th>Created</th></tr></thead>'
            + '<tbody>' + rows + '</tbody>'
            + '</table>';
    }).catch(function () {
        list.innerHTML = alertBox('Failed to load professors.', 'danger');
    });
}

function createProfessor() {
    var username   = document.getElementById('profUsername').value.trim();
    var email      = document.getElementById('profEmail').value.trim();
    var password   = document.getElementById('profPassword').value;
    var department = document.getElementById('profDept').value.trim();
    var msgDiv     = document.getElementById('profMsg');

    if (!username || !email || !password) {
        msgDiv.innerHTML = alertBox('Username, email, and password are required.', 'danger');
        return;
    }

    apiRequest('/api/admin/professors.php', 'POST', {
        username: username,
        email: email,
        password: password,
        department: department
    }).then(function (data) {
        if (data.professor_id) {
            msgDiv.innerHTML = alertBox('Professor account created for <strong>' + data.username + '</strong>!', 'success');
            document.getElementById('profUsername').value = '';
            document.getElementById('profEmail').value = '';
            document.getElementById('profPassword').value = '';
            document.getElementById('profDept').value = '';
            loadProfessorsList();
        } else {
            msgDiv.innerHTML = alertBox(data.error || data.message || 'Failed to create professor.', 'danger');
        }
    }).catch(function () {
        msgDiv.innerHTML = alertBox('Network error.', 'danger');
    });
}

function loadProfile() {
    apiRequest('/api/auth/profile.php').then(function (data) {
        if (data.user) {
            document.getElementById('profileUsername').textContent = data.user.username;
            document.getElementById('profileEmail').textContent = data.user.email;
            document.getElementById('profileRole').textContent = data.user.role;
        }
    });
}

init();
