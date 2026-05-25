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
    if (page !== 'queue') stopQueuePolling();
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

var queuePoller = null;
var activeQueueExamId = null;

function joinQueue() {
    var examId = parseInt(document.getElementById('queueExamId').value);
    var msgDiv = document.getElementById('queueMsg');

    if (!examId) {
        msgDiv.innerHTML = alertBox('Please enter an Exam ID.', 'danger');
        return;
    }

    apiRequest('/api/student/queue.php', 'POST', { exam_id: examId }).then(function (data) {
        if (data.status === 'waiting') {
            msgDiv.innerHTML = '';
            startQueuePolling(examId);
        } else {
            msgDiv.innerHTML = alertBox(data.error || data.message || 'Could not join the queue.', 'danger');
        }
    }).catch(function () {
        msgDiv.innerHTML = alertBox('Network error.', 'danger');
    });
}

function startQueuePolling(examId) {
    stopQueuePolling();
    activeQueueExamId = examId;
    document.getElementById('queueStatusCard').style.display = 'block';
    pollQueueStatus();
    queuePoller = setInterval(pollQueueStatus, 5000);
}

function pollQueueStatus() {
    if (!activeQueueExamId) return;
    apiRequest('/api/student/queue.php?exam_id=' + activeQueueExamId).then(function (data) {
        var body = document.getElementById('queueStatusBody');
        var pulse = document.getElementById('queuePulse');

        if (data.position !== undefined) {
            if (data.status === 'called') {
                clearInterval(queuePoller);
                queuePoller = null;
                activeQueueExamId = null;
                body.innerHTML = '<div style="text-align:center;padding:16px 0;">'
                    + '<p style="font-size:22px;font-weight:bold;color:#1a5276;">It\'s your turn!</p>'
                    + '<p style="color:#555;">Head to the exam room now.</p>'
                    + '</div>';
                pulse.textContent = '';
            } else if (data.status === 'attended') {
                clearInterval(queuePoller);
                queuePoller = null;
                activeQueueExamId = null;
                body.innerHTML = alertBox('You have been marked as attended. Good luck!', 'success');
                pulse.textContent = '';
            } else {
                body.innerHTML = '<table style="width:100%;">'
                    + '<tr><th style="width:140px;">Status</th><td><strong>' + data.status + '</strong></td></tr>'
                    + '<tr><th>Your position</th><td><strong>' + data.position + '</strong> of ' + data.total_waiting + ' waiting</td></tr>'
                    + '</table>';
                pulse.textContent = '● updating...';
            }
        } else {
            body.innerHTML = alertBox(data.error || 'You are not in this queue.', 'danger');
            stopQueuePolling();
        }
    }).catch(function () {
        document.getElementById('queuePulse').textContent = '● offline';
    });
}

function stopQueuePolling() {
    if (queuePoller) {
        clearInterval(queuePoller);
        queuePoller = null;
    }
    activeQueueExamId = null;
    var card = document.getElementById('queueStatusCard');
    if (card) card.style.display = 'none';
    var body = document.getElementById('queueStatusBody');
    if (body) body.innerHTML = '';
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
