// professor.js - Professor Dashboard
// Requires common.js ....loaded before this file in the HTML

var token = localStorage.getItem('token');
if (!token) window.location.href = 'index.html';

//Navigation

var sections = ['courses', 'exams', 'roster', 'queue', 'profile'];

function showSection(name) {
    sections.forEach(function (s) {
        document.getElementById('section-' + s).style.display = 'none';
    });
    document.getElementById('section-' + name).style.display = 'block';
}

function loadPage(page) {
    showSection(page);
    if (page === 'courses') loadCoursesList();
    if (page === 'exams')   loadExamsList();
    if (page === 'profile') loadProfile();
}

//Init

function init() {
    apiRequest('/api/auth/profile.php').then(function (data) {
        if (data.user) document.getElementById('profName').textContent = data.user.username;
    });
    loadPage('courses');
}

//COURSES

function loadCoursesList() {
    var list = document.getElementById('coursesList');
    list.innerHTML = '<p>Loading...</p>';

    apiRequest('/api/professor/courses.php').then(function (data) {
        if (!data.courses || data.courses.length === 0) {
            list.innerHTML = '<p style="color:#666;">No courses yet. Create one above.</p>';
            return;
        }
        var rows = '';
        data.courses.forEach(function (c) {
            rows += '<tr>'
                + '<td>' + c.id + '</td>'
                + '<td>' + c.name + '</td>'
                + '<td>' + c.code + '</td>'
                + '<td>' + (c.description || '-') + '</td>'
                + '<td>' + new Date(c.created_at).toLocaleDateString() + '</td>'
                + '</tr>';
        });
        list.innerHTML = '<table>'
            + '<thead><tr><th>ID</th><th>Name</th><th>Code</th><th>Description</th><th>Created</th></tr></thead>'
            + '<tbody>' + rows + '</tbody>'
            + '</table>';
    }).catch(function () {
        list.innerHTML = alertBox('Failed to load courses.', 'danger');
    });
}

function createCourse() {
    var name = document.getElementById('courseName').value.trim();
    var code = document.getElementById('courseCode').value.trim();
    var description = document.getElementById('courseDesc').value.trim();
    var msgDiv = document.getElementById('courseMsg');

    if (!name || !code) {
        msgDiv.innerHTML = alertBox('Course name and code are required.', 'danger');
        return;
    }

    apiRequest('/api/professor/courses.php', 'POST', { name: name, code: code, description: description })
    .then(function (data) {
        if (data.course_id) {
            msgDiv.innerHTML = alertBox('Course created! ID: <strong>' + data.course_id + '</strong>. Share this with students so they can enroll.', 'success');
            document.getElementById('courseName').value = '';
            document.getElementById('courseCode').value = '';
            document.getElementById('courseDesc').value = '';
            loadCoursesList();
        } else {
            msgDiv.innerHTML = alertBox(data.error || data.message || 'Failed to create course.', 'danger');
        }
    }).catch(function () {
        msgDiv.innerHTML = alertBox('Network error.', 'danger');
    });
}

// EXAMS

function loadExamsList() {
    var list = document.getElementById('examsList');
    list.innerHTML = '<p>Loading...</p>';

    apiRequest('/api/professor/exams.php').then(function (data) {
        if (!data.exams || data.exams.length === 0) {
            list.innerHTML = '<p style="color:#666;">No exams yet. Create one above.</p>';
            return;
        }
        var rows = '';
        data.exams.forEach(function (e) {
            rows += '<tr>'
                + '<td>' + e.id + '</td>'
                + '<td>' + e.course_name + ' (' + e.course_code + ')</td>'
                + '<td>' + e.exam_date + '</td>'
                + '<td>' + e.exam_time + '</td>'
                + '<td>' + (e.room_number || '-') + '</td>'
                + '<td>' + e.status + '</td>'
                + '</tr>';
        });
        list.innerHTML = '<table>'
            + '<thead><tr><th>ID</th><th>Course</th><th>Date</th><th>Time</th><th>Room</th><th>Status</th></tr></thead>'
            + '<tbody>' + rows + '</tbody>'
            + '</table>';
    }).catch(function () {
        list.innerHTML = alertBox('Failed to load exams.', 'danger');
    });
}

function createExam() {
    var courseCode  = document.getElementById('examCourseCode').value.trim();
    var examDate    = document.getElementById('examDate').value;
    var examTime    = document.getElementById('examTime').value;
    var roomId      = document.getElementById('examRoomId').value;
    var description = document.getElementById('examDesc').value.trim();
    var msgDiv      = document.getElementById('examMsg');

    if (!courseCode || !examDate || !examTime) {
        msgDiv.innerHTML = alertBox('Course code, date, and time are required.', 'danger');
        return;
    }

    var body = {
        course_code: courseCode,
        exam_date:   examDate,
        exam_time:   examTime + ':00',
        description: description
    };
    if (roomId) body.room_id = parseInt(roomId);

    msgDiv.innerHTML = alertBox('Creating exam...', 'info');

    apiRequest('/api/professor/exams.php', 'POST', body).then(function (data) {
        if (data.exam_id) {
            msgDiv.innerHTML = alertBox('Exam created! ID: <strong>' + data.exam_id + '</strong>. Use this to upload a roster and manage the queue.', 'success');
            document.getElementById('examCourseCode').value = '';
            document.getElementById('examDate').value = '';
            document.getElementById('examTime').value = '';
            document.getElementById('examRoomId').value = '';
            document.getElementById('examDesc').value = '';
            loadExamsList();
        } else {
            msgDiv.innerHTML = alertBox(data.error || data.message || 'Failed to create exam.', 'danger');
        }
    }).catch(function (err) {
        msgDiv.innerHTML = alertBox('Network error: ' + err.message, 'danger');
    });
}

//ROSTER 

function uploadRoster() {
    var examId    = document.getElementById('rosterExamId').value;
    var fileInput = document.getElementById('rosterFile');
    var msgDiv    = document.getElementById('rosterMsg');

    if (!examId) { msgDiv.innerHTML = alertBox('Please enter an Exam ID.', 'danger'); return; }
    if (!fileInput.files[0]) { msgDiv.innerHTML = alertBox('Please select a CSV file.', 'danger'); return; }

    var formData = new FormData();
    formData.append('csv_file', fileInput.files[0]);
    msgDiv.innerHTML = alertBox('Uploading...', 'info');

    // File upload uses FormData, not JSON, so we call fetch directly
    fetch('/api/professor/roster.php?exam_id=' + examId, {
        method: 'POST',
        headers: { 'Authorization': 'Bearer ' + token },
        body: formData
    })
    .then(function (res) { return res.json(); })
    .then(function (data) {
        if (data.added !== undefined) {
            var type = data.total_errors > 0 ? 'warning' : 'success';
            var msg  = 'Upload complete! Added: ' + data.added + ' students.';
            if (data.total_errors > 0) msg += ' Errors: ' + data.total_errors;
            msgDiv.innerHTML = alertBox(msg, type);
            if (data.errors && data.errors.length > 0) {
                var errorItems = data.errors.map(function (e) { return '<li>' + e + '</li>'; }).join('');
                msgDiv.innerHTML += '<ul style="font-size:13px;margin-top:8px;">' + errorItems + '</ul>';
            }
        } else {
            msgDiv.innerHTML = alertBox(data.error || data.message || 'Upload failed.', 'danger');
        }
    }).catch(function () {
        msgDiv.innerHTML = alertBox('Network error during upload.', 'danger');
    });
}

// queue

function loadQueueList() {
    var examId = parseInt(document.getElementById('manageExamId').value);
    var area = document.getElementById('queueListArea');
    if (!examId) return;

    area.innerHTML = '<p style="color:#666;">Loading queue...</p>';

    apiRequest('/api/professor/queue.php?exam_id=' + examId).then(function (data) {
        if (!data.queue) {
            area.innerHTML = alertBox(data.error || 'No queue data found for this exam.', 'danger');
            return;
        }
        if (data.queue.length === 0) {
            area.innerHTML = '<div class="card"><p style="color:#666;">No students in the queue yet.</p></div>';
            return;
        }

        var rows = '';
        data.queue.forEach(function (s) {
            var badgeClass = s.status === 'attended' ? 'badge-attended' : s.status === 'absent' ? 'badge-absent' : 'badge-waiting';
            var action = s.status === 'waiting'
                ? '<button class="btn btn-success btn-sm" onclick="markAttended(' + examId + ',' + s.student_id + ')">Mark Attended</button>'
                : '<span style="color:#aaa;font-size:12px;">done</span>';
            rows += '<tr>'
                + '<td>' + s.position + '</td>'
                + '<td>' + s.username + '</td>'
                + '<td>' + s.email + '</td>'
                + '<td><span class="badge ' + badgeClass + '">' + s.status + '</span></td>'
                + '<td>' + new Date(s.joined_at).toLocaleTimeString() + '</td>'
                + '<td>' + action + '</td>'
                + '</tr>';
        });

        area.innerHTML = '<div class="card">'
            + '<h3>Queue for Exam ' + examId + ' (' + data.count + ' students)</h3>'
            + '<table>'
            + '<thead><tr><th>#</th><th>Username</th><th>Email</th><th>Status</th><th>Joined At</th><th>Action</th></tr></thead>'
            + '<tbody>' + rows + '</tbody>'
            + '</table>'
            + '</div>';
    }).catch(function () {
        area.innerHTML = alertBox('Failed to load queue.', 'danger');
    });
}

function markAttended(examId, studentId) {
    apiRequest('/api/professor/queue.php', 'PATCH', { exam_id: examId, student_id: studentId })
    .then(function (data) {
        if (data.status === 'attended') {
            loadQueueList();
        } else {
            alert(data.error || data.message || 'Failed to mark attendance.');
        }
    });
}

// profile

function loadProfile() {
    apiRequest('/api/auth/profile.php').then(function (data) {
        if (data.user) {
            document.getElementById('profileUsername').textContent = data.user.username;
            document.getElementById('profileEmail').textContent = data.user.email;
            document.getElementById('profileRole').textContent = data.user.role;
        }
    });
}

// 
init();
