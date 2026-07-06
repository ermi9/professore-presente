var token = localStorage.getItem('token');
if (!token) window.location.href = 'index.html';

var DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
var TIME_SLOTS = [
    { start: '09:00:00', end: '11:00:00', label: '09:00 – 11:00' },
    { start: '11:00:00', end: '13:00:00', label: '11:00 – 13:00' },
    { start: '14:00:00', end: '16:00:00', label: '14:00 – 16:00' },
    { start: '16:00:00', end: '18:00:00', label: '16:00 – 18:00' }
];

var sections = ['dashboard', 'courses', 'schedule', 'exams', 'roster', 'queue', 'students', 'announcements', 'profile'];

// cached course list for dropdowns (populated on first courses load)
var coursesCache = [];

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
    if (page === 'dashboard')     loadDashboard();
    if (page === 'courses')       loadCoursesList();
    if (page === 'schedule')      { populateCourseDropdown('slotCourse'); loadSchedule(); }
    if (page === 'exams')         loadExamsList();
    if (page === 'queue')         { populateExamDropdown('manageExamSelect'); }
    if (page === 'students')      { populateExamDropdown('studentsExamSelect'); }
    if (page === 'announcements') { populateCourseDropdown('announceCourse'); loadAnnouncementHistory(); }
    if (page === 'profile')       loadProfile();
}

function init() {
    apiRequest('/api/auth/profile.php').then(function (data) {
        if (!data.user) return;
        var u = data.user;
        document.getElementById('sidebarName').textContent = u.username;
        document.getElementById('sidebarEmail').textContent = u.email;
        document.getElementById('avatarCircle').textContent = u.username.charAt(0).toUpperCase();
    });

    apiRequest('/api/professor/stats.php').then(function (data) {
        if (data.courses !== undefined) {
            document.getElementById('sidebarCourses').textContent  = data.courses;
            document.getElementById('sidebarStudents').textContent = data.total_students;
        }
    });

    loadPage('dashboard');
}

//  DASHBOARD 

function loadDashboard() {
    apiRequest('/api/professor/stats.php').then(function (data) {
        document.getElementById('statCourses').textContent  = data.courses       ?? '—';
        document.getElementById('statStudents').textContent = data.total_students ?? '—';
        document.getElementById('statExams').textContent    = data.upcoming_exams ?? '—';
        document.getElementById('statQueue').textContent    = data.queue_now      ?? '—';
    });

    apiRequest('/api/professor/timetable.php').then(function (data) {
        renderTimetable(data.slots || [], 'dashboardTimetableBody');
    });
}

//  TIMETABLE RENDERING 

function renderTimetable(slots, bodyId) {
    var lookup = {};
    slots.forEach(function (s) {
        var key = s.day_of_week + '|' + s.start_time;
        lookup[key] = s;
    });

    var html = '';
    TIME_SLOTS.forEach(function (slot) {
        html += '<tr><th class="text-nowrap small bg-light">' + slot.label + '</th>';
        for (var d = 1; d <= 5; d++) {
            var cell = lookup[d + '|' + slot.start];
            if (cell) {
                html += '<td class="tt-busy">'
                    + '<strong style="font-size:0.8rem">' + cell.course_name + '</strong>'
                    + '<br><span class="text-muted" style="font-size:0.72rem">' + cell.course_code + '</span>';
                if (cell.room) html += '<br><span class="text-muted" style="font-size:0.72rem"><i class="bi bi-geo-alt"></i> ' + cell.room + '</span>';
                // delete button shows slot id
                html += '<br><button class="btn btn-link btn-sm p-0 text-danger" style="font-size:0.72rem" onclick="deleteSlot(' + cell.id + ')">'
                    + '<i class="bi bi-trash"></i> remove</button>';
                html += '</td>';
            } else {
                html += '<td class="tt-free text-muted text-center" style="font-size:0.8rem">—</td>';
            }
        }
        html += '</tr>';
    });

    var el = document.getElementById(bodyId);
    el.innerHTML = html || '<tr><td colspan="6" class="text-muted py-3 text-center">No slots scheduled yet.</td></tr>';
}

//  SCHEDULE CLASSES 

function loadSchedule() {
    apiRequest('/api/professor/timetable.php').then(function (data) {
        renderTimetable(data.slots || [], 'scheduleBody');
    });
}

function addSlot() {
    var courseId = document.getElementById('slotCourse').value;
    var day      = document.getElementById('slotDay').value;
    var timePair = document.getElementById('slotTime').value.split('|');
    var room     = document.getElementById('slotRoom').value.trim();
    var msg      = document.getElementById('slotMsg');

    if (!courseId) { msg.innerHTML = alertBox('Please select a course.', 'danger'); return; }

    apiRequest('/api/professor/timetable.php', 'POST', {
        course_id:   parseInt(courseId),
        day_of_week: parseInt(day),
        start_time:  timePair[0],
        end_time:    timePair[1],
        room:        room || null
    }).then(function (data) {
        if (data.slot_id) {
            msg.innerHTML = alertBox('Slot added!', 'success');
            loadSchedule();
        } else {
            msg.innerHTML = alertBox(data.error || 'Failed to add slot.', 'danger');
        }
    }).catch(function () {
        msg.innerHTML = alertBox('Network error.', 'danger');
    });
}

function deleteSlot(slotId) {
    apiRequest('/api/professor/timetable.php', 'DELETE', { slot_id: slotId }).then(function () {
        loadSchedule();
        // also refresh dashboard timetable if it was rendered
        var dbBody = document.getElementById('dashboardTimetableBody');
        if (dbBody) {
            apiRequest('/api/professor/timetable.php').then(function (data) {
                renderTimetable(data.slots || [], 'dashboardTimetableBody');
            });
        }
    });
}

//  COURSES 

function loadCoursesList() {
    var list = document.getElementById('coursesList');
    list.innerHTML = '<p class="text-muted small">Loading...</p>';

    apiRequest('/api/professor/courses.php').then(function (data) {
        coursesCache = data.courses || [];
        if (coursesCache.length === 0) {
            list.innerHTML = '<p class="text-muted small">No courses yet.</p>';
            return;
        }
        var rows = '';
        coursesCache.forEach(function (c) {
            rows += '<tr>'
                + '<td class="fw-semibold">' + c.name + '</td>'
                + '<td><span class="badge" style="background:#e8f0fe;color:#006ec0">' + c.code + '</span></td>'
                + '<td class="text-muted small">' + (c.description || '—') + '</td>'
                + '<td class="text-muted small">' + new Date(c.created_at).toLocaleDateString() + '</td>'
                + '</tr>';
        });
        list.innerHTML = '<div class="table-responsive"><table class="table table-hover align-middle small mb-0">'
            + '<thead class="table-light"><tr><th>Name</th><th>Code</th><th>Description</th><th>Created</th></tr></thead>'
            + '<tbody>' + rows + '</tbody>'
            + '</table></div>';
    }).catch(function () {
        list.innerHTML = alertBox('Failed to load courses.', 'danger');
    });
}

function createCourse() {
    var name = document.getElementById('courseName').value.trim();
    var code = document.getElementById('courseCode').value.trim();
    var desc = document.getElementById('courseDesc').value.trim();
    var msg  = document.getElementById('courseMsg');

    if (!name || !code) { msg.innerHTML = alertBox('Course name and code are required.', 'danger'); return; }

    apiRequest('/api/professor/courses.php', 'POST', { name: name, code: code, description: desc })
    .then(function (data) {
        if (data.course_id) {
            msg.innerHTML = alertBox('Course created! Share the code <strong>' + code + '</strong> with students.', 'success');
            document.getElementById('courseName').value = '';
            document.getElementById('courseCode').value = '';
            document.getElementById('courseDesc').value = '';
            loadCoursesList();
        } else {
            msg.innerHTML = alertBox(data.error || data.message || 'Failed to create course.', 'danger');
        }
    }).catch(function () {
        msg.innerHTML = alertBox('Network error.', 'danger');
    });
}

//  EXAMS 

function loadExamsList() {
    var list = document.getElementById('examsList');
    list.innerHTML = '<p class="text-muted small">Loading...</p>';

    apiRequest('/api/professor/exams.php').then(function (data) {
        if (!data.exams || data.exams.length === 0) {
            list.innerHTML = '<p class="text-muted small">No exams yet.</p>';
            return;
        }
        var rows = '';
        data.exams.forEach(function (e) {
            var statusBadge = e.status === 'in_progress'
                ? '<span class="badge bg-success">In Progress</span>'
                : e.status === 'closed'
                ? '<span class="badge bg-secondary">Closed</span>'
                : '<span class="badge" style="background:#e8f0fe;color:#006ec0">Upcoming</span>';
            rows += '<tr>'
                + '<td class="fw-semibold text-muted small">#' + e.id + '</td>'
                + '<td>' + e.course_name + ' <small class="text-muted">(' + e.course_code + ')</small></td>'
                + '<td>' + e.exam_date + '</td>'
                + '<td>' + e.exam_time.slice(0,5) + '</td>'
                + '<td>' + (e.room_number || '—') + '</td>'
                + '<td>' + statusBadge + '</td>'
                + '</tr>';
        });
        list.innerHTML = '<div class="table-responsive"><table class="table table-hover align-middle small mb-0">'
            + '<thead class="table-light"><tr><th>ID</th><th>Course</th><th>Date</th><th>Time</th><th>Room</th><th>Status</th></tr></thead>'
            + '<tbody>' + rows + '</tbody>'
            + '</table></div>';
    }).catch(function () {
        list.innerHTML = alertBox('Failed to load exams.', 'danger');
    });
}

function createExam() {
    var code    = document.getElementById('examCourseCode').value.trim();
    var date    = document.getElementById('examDate').value;
    var time    = document.getElementById('examTime').value;
    var roomId  = document.getElementById('examRoomId').value;
    var desc    = document.getElementById('examDesc').value.trim();
    var msg     = document.getElementById('examMsg');

    if (!code || !date || !time) { msg.innerHTML = alertBox('Course code, date, and time are required.', 'danger'); return; }

    var body = { course_code: code, exam_date: date, exam_time: time + ':00', description: desc };
    if (roomId) body.room_id = parseInt(roomId);

    msg.innerHTML = alertBox('Creating…', 'info');

    apiRequest('/api/professor/exams.php', 'POST', body).then(function (data) {
        if (data.exam_id) {
            msg.innerHTML = alertBox('Exam created! ID: <strong>' + data.exam_id + '</strong>', 'success');
            ['examCourseCode','examDate','examTime','examRoomId','examDesc'].forEach(function (id) {
                document.getElementById(id).value = '';
            });
            loadExamsList();
        } else {
            msg.innerHTML = alertBox(data.error || data.message || 'Failed to create exam.', 'danger');
        }
    }).catch(function (err) {
        msg.innerHTML = alertBox('Network error: ' + err.message, 'danger');
    });
}

//  ROSTER 

function uploadRoster() {
    var examId    = document.getElementById('rosterExamId').value;
    var fileInput = document.getElementById('rosterFile');
    var msg       = document.getElementById('rosterMsg');

    if (!examId)           { msg.innerHTML = alertBox('Please enter an Exam ID.', 'danger'); return; }
    if (!fileInput.files[0]) { msg.innerHTML = alertBox('Please select a CSV file.', 'danger'); return; }

    var form = new FormData();
    form.append('csv_file', fileInput.files[0]);
    msg.innerHTML = alertBox('Uploading…', 'info');

    fetch('/api/professor/roster.php?exam_id=' + examId, {
        method: 'POST',
        headers: { 'Authorization': 'Bearer ' + token },
        body: form
    }).then(function (r) { return r.json(); }).then(function (data) {
        if (data.added !== undefined) {
            var type = data.total_errors > 0 ? 'warning' : 'success';
            var txt  = 'Upload complete — added: ' + data.added + ' students.';
            if (data.total_errors > 0) txt += ' Errors: ' + data.total_errors;
            msg.innerHTML = alertBox(txt, type);
            if (data.errors && data.errors.length) {
                msg.innerHTML += '<ul class="small mt-1">' + data.errors.map(function (e) { return '<li>' + e + '</li>'; }).join('') + '</ul>';
            }
        } else {
            msg.innerHTML = alertBox(data.error || data.message || 'Upload failed.', 'danger');
        }
    }).catch(function () {
        msg.innerHTML = alertBox('Network error.', 'danger');
    });
}

//  QUEUE 

function populateExamDropdown(selectId) {
    apiRequest('/api/professor/exams.php').then(function (data) {
        var sel = document.getElementById(selectId);
        sel.innerHTML = '<option value="">Select an exam…</option>';
        (data.exams || []).forEach(function (e) {
            sel.innerHTML += '<option value="' + e.id + '">'
                + e.course_name + ' — ' + e.exam_date + ' ' + e.exam_time.slice(0,5)
                + '</option>';
        });
    });
}

function loadQueueList() {
    var examId = parseInt(document.getElementById('manageExamSelect').value);
    var area   = document.getElementById('queueListArea');
    if (!examId) return;

    area.innerHTML = '<p class="text-muted small">Loading queue…</p>';

    apiRequest('/api/professor/queue.php?exam_id=' + examId).then(function (data) {
        if (!data.queue) {
            area.innerHTML = alertBox(data.error || 'No queue data.', 'danger');
            return;
        }
        if (data.queue.length === 0) {
            area.innerHTML = '<div class="card shadow-sm border-0 rounded-3"><div class="card-body text-muted small">No students in the queue yet.</div></div>';
            return;
        }

        var rows = '';
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
            rows += '<tr>'
                + '<td>' + s.position + '</td>'
                + '<td class="fw-semibold">' + s.username + '</td>'
                + '<td class="text-muted small">' + s.email + '</td>'
                + '<td><span class="badge ' + bc + '">' + s.status + '</span></td>'
                + '<td class="text-muted small">' + new Date(s.joined_at).toLocaleTimeString() + '</td>'
                + '<td>' + action + '</td>'
                + '</tr>';
        });

        area.innerHTML = '<div class="card shadow-sm border-0 rounded-3"><div class="card-body">'
            + '<h6 class="fw-bold mb-3">Queue — ' + data.count + ' student(s)</h6>'
            + '<div class="table-responsive"><table class="table table-hover align-middle small mb-0">'
            + '<thead class="table-light"><tr><th>#</th><th>Name</th><th>Email</th><th>Status</th><th>Joined</th><th>Action</th></tr></thead>'
            + '<tbody>' + rows + '</tbody>'
            + '</table></div></div></div>';
    }).catch(function () {
        area.innerHTML = alertBox('Failed to load queue.', 'danger');
    });
}

function callStudent(examId, studentId) {
    apiRequest('/api/professor/queue.php', 'PUT', { exam_id: examId, student_id: studentId })
    .then(function (data) {
        if (data.status === 'called') loadQueueList();
        else alert(data.error || 'Failed to call student.');
    });
}

function markAttended(examId, studentId) {
    apiRequest('/api/professor/queue.php', 'PATCH', { exam_id: examId, student_id: studentId })
    .then(function (data) {
        if (data.status === 'attended') loadQueueList();
        else alert(data.error || 'Failed to mark attended.');
    });
}

//  ENROLLED STUDENTS 

function loadEnrolledStudents() {
    var examId = parseInt(document.getElementById('studentsExamSelect').value);
    var area   = document.getElementById('enrolledStudentsArea');
    if (!examId) return;

    area.innerHTML = '<p class="text-muted small">Loading…</p>';

    apiRequest('/api/professor/enrolled-students.php?exam_id=' + examId).then(function (data) {
        if (!data.students || data.students.length === 0) {
            area.innerHTML = '<div class="card shadow-sm border-0 rounded-3"><div class="card-body text-muted small">No students on the roster for this exam.</div></div>';
            return;
        }
        var rows = '';
        data.students.forEach(function (s) {
            var qBadge = s.queue_status === 'not in queue'
                ? '<span class="text-muted small">—</span>'
                : '<span class="badge badge-' + s.queue_status + '">' + s.queue_status + '</span>';
            rows += '<tr>'
                + '<td class="fw-semibold">' + s.username + '</td>'
                + '<td class="text-muted small">' + s.email + '</td>'
                + '<td>' + qBadge + '</td>'
                + '<td class="text-muted small">' + (s.joined_at ? new Date(s.joined_at).toLocaleTimeString() : '—') + '</td>'
                + '</tr>';
        });
        area.innerHTML = '<div class="card shadow-sm border-0 rounded-3"><div class="card-body">'
            + '<h6 class="fw-bold mb-3">Roster — ' + data.students.length + ' student(s)</h6>'
            + '<div class="table-responsive"><table class="table table-hover align-middle small mb-0">'
            + '<thead class="table-light"><tr><th>Name</th><th>Email</th><th>Queue Status</th><th>Joined At</th></tr></thead>'
            + '<tbody>' + rows + '</tbody></table></div></div></div>';
    }).catch(function () {
        area.innerHTML = alertBox('Failed to load students.', 'danger');
    });
}

//  ANNOUNCEMENTS 

function populateCourseDropdown(selectId) {
    if (coursesCache.length > 0) {
        fillCourseDropdown(selectId, coursesCache);
        return;
    }
    apiRequest('/api/professor/courses.php').then(function (data) {
        coursesCache = data.courses || [];
        fillCourseDropdown(selectId, coursesCache);
    });
}

function fillCourseDropdown(selectId, courses) {
    var sel = document.getElementById(selectId);
    sel.innerHTML = '<option value="">Select course…</option>';
    courses.forEach(function (c) {
        sel.innerHTML += '<option value="' + c.id + '">' + c.name + ' (' + c.code + ')</option>';
    });
}

function postAnnouncement() {
    var courseId = document.getElementById('announceCourse').value;
    var message  = document.getElementById('announceMsg').value.trim();
    var result   = document.getElementById('announceResult');

    if (!courseId || !message) { result.innerHTML = alertBox('Please select a course and write a message.', 'danger'); return; }

    apiRequest('/api/professor/announcements.php', 'POST', { course_id: parseInt(courseId), message: message })
    .then(function (data) {
        if (data.announcement_id) {
            result.innerHTML = alertBox('Announcement posted!', 'success');
            document.getElementById('announceMsg').value = '';
            loadAnnouncementHistory();
        } else {
            result.innerHTML = alertBox(data.error || 'Failed to post.', 'danger');
        }
    }).catch(function () {
        result.innerHTML = alertBox('Network error.', 'danger');
    });
}

function loadAnnouncementHistory() {
    var list = document.getElementById('announceHistory');
    list.innerHTML = '<p class="text-muted small">Loading…</p>';

    apiRequest('/api/professor/announcements.php').then(function (data) {
        if (!data.announcements || data.announcements.length === 0) {
            list.innerHTML = '<p class="text-muted small">No announcements posted yet.</p>';
            return;
        }
        var html = '';
        data.announcements.forEach(function (a) {
            var dt = new Date(a.created_at);
            html += '<div class="border rounded-3 p-3 mb-3">'
                + '<div class="d-flex justify-content-between align-items-start">'
                + '<div>'
                + '<span class="badge me-2" style="background:#e8f0fe;color:#006ec0">' + a.course_name + ' (' + a.course_code + ')</span>'
                + '<small class="text-muted">' + dt.toLocaleDateString() + ' ' + dt.toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'}) + '</small>'
                + '</div>'
                + '<button class="btn btn-sm btn-outline-danger" onclick="deleteAnnouncement(' + a.id + ')">'
                + '<i class="bi bi-trash"></i></button>'
                + '</div>'
                + '<p class="mb-0 mt-2 small">' + escapeHtml(a.message) + '</p>'
                + '</div>';
        });
        list.innerHTML = html;
    }).catch(function () {
        list.innerHTML = alertBox('Failed to load history.', 'danger');
    });
}

function deleteAnnouncement(id) {
    apiRequest('/api/professor/announcements.php', 'DELETE', { announcement_id: id }).then(function () {
        loadAnnouncementHistory();
    });
}

//  PROFILE 

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
