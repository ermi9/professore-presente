// dashboard.js - Student Dashboard

var token = localStorage.getItem('token');
if (!token) window.location.href = 'index.html';

//Helpers

function apiRequest(url, method, body) {
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

function alertBox(msg, type) {
    return '<div class="alert alert-' + (type || 'info') + '">' + msg + '</div>';
}

function logout() {
    localStorage.removeItem('token');
    localStorage.removeItem('user_type');
    window.location.href = 'index.html';
}

//Navigation
var sections = ['courses', 'queue', 'profile'];

function showSection(name) {
    sections.forEach(function (s) {
        document.getElementById('section-' + s).style.display = 'none';
    });
    document.getElementById('section-' + name).style.display = 'block';
}

function loadPage(page) {
    showSection(page);
    if (page === 'courses') loadCoursesList();
    if (page === 'profile') loadProfile();
}

//Init ─

function init() {
    apiRequest('/api/auth/profile.php').then(function (data) {
        if (data.user) document.getElementById('studentName').textContent = data.user.username;
    });
    loadPage('courses');
}

//COURSES

function loadCoursesList() {
    var list = document.getElementById('coursesList');
    list.innerHTML = '<p>Loading...</p>';

    apiRequest('/api/student/courses.php').then(function (data) {
        if (!data.courses || data.courses.length === 0) {
            list.innerHTML = '<p style="color:#666;">You have not enrolled in any courses yet.</p>';
            return;
        }
        var rows = '';
        data.courses.forEach(function (c) {
            rows += '<tr>'
                + '<td>' + c.id + '</td>'
                + '<td>' + c.name + '</td>'
                + '<td>' + c.code + '</td>'
                + '<td>' + (c.professor_name || '-') + '</td>'
                + '<td>' + new Date(c.enrolled_at).toLocaleDateString() + '</td>'
                + '</tr>';
        });
        list.innerHTML = '<table>'
            + '<thead><tr><th>ID</th><th>Name</th><th>Code</th><th>Professor</th><th>Enrolled On</th></tr></thead>'
            + '<tbody>' + rows + '</tbody>'
            + '</table>';
    }).catch(function () {
        list.innerHTML = alertBox('Failed to load courses.', 'danger');
    });
}

function enrollCourse() {
    var courseId = parseInt(document.getElementById('enrollId').value);
    var msgDiv = document.getElementById('enrollMsg');

    if (!courseId) {
        msgDiv.innerHTML = alertBox('Please enter a Course ID.', 'danger');
        return;
    }

    apiRequest('/api/student/courses.php', 'POST', { course_id: courseId }).then(function (data) {
        if (data.course_id) {
            msgDiv.innerHTML = alertBox('Enrolled successfully!', 'success');
            document.getElementById('enrollId').value = '';
            loadCoursesList();
        } else {
            msgDiv.innerHTML = alertBox(data.error || data.message || 'Enrollment failed.', 'danger');
        }
    }).catch(function () {
        msgDiv.innerHTML = alertBox('Network error.', 'danger');
    });
}

//QUEUE 

function joinQueue() {
    var examId = parseInt(document.getElementById('queueExamId').value);
    var msgDiv = document.getElementById('queueMsg');

    if (!examId) {
        msgDiv.innerHTML = alertBox('Please enter an Exam ID.', 'danger');
        return;
    }

    apiRequest('/api/student/queue.php', 'POST', { exam_id: examId }).then(function (data) {
        if (data.status === 'waiting') {
            msgDiv.innerHTML = alertBox('You joined the queue! Status: <strong>waiting</strong>', 'success');
        } else {
            msgDiv.innerHTML = alertBox(data.error || data.message || 'Could not join the queue.', 'danger');
        }
    }).catch(function () {
        msgDiv.innerHTML = alertBox('Network error.', 'danger');
    });
}

function checkQueueStatus() {
    var examId = parseInt(document.getElementById('queueExamId').value);
    var msgDiv = document.getElementById('queueMsg');

    if (!examId) {
        msgDiv.innerHTML = alertBox('Please enter an Exam ID.', 'danger');
        return;
    }

    apiRequest('/api/student/queue.php', 'GET', { exam_id: examId }).then(function (data) {
        if (data.position !== undefined) {
            msgDiv.innerHTML = alertBox(
                'Status: <strong>' + data.status + '</strong> &nbsp;|&nbsp; '
                + 'Position: <strong>' + data.position + '</strong> of ' + data.total_waiting + ' waiting',
                'info'
            );
        } else {
            msgDiv.innerHTML = alertBox(data.error || data.message || 'You are not in this queue.', 'danger');
        }
    }).catch(function () {
        msgDiv.innerHTML = alertBox('Network error.', 'danger');
    });
}

//PROFILE

function loadProfile() {
    apiRequest('/api/auth/profile.php').then(function (data) {
        if (data.user) {
            document.getElementById('profileUsername').textContent = data.user.username;
            document.getElementById('profileEmail').textContent = data.user.email;
            document.getElementById('profileRole').textContent = data.user.role;
        }
    });
}

//Start 
init();
