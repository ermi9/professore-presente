var token = localStorage.getItem('token');
if (!token) window.location.href = 'index.html';

var DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
var TIME_SLOTS = [
    { start: '09:00:00', end: '11:00:00', label: '09:00 – 11:00' },
    { start: '11:00:00', end: '13:00:00', label: '11:00 – 13:00' },
    { start: '14:00:00', end: '16:00:00', label: '14:00 – 16:00' },
    { start: '16:00:00', end: '18:00:00', label: '16:00 – 18:00' }
];

var sections = ['timetable', 'exams', 'courses', 'queue', 'announcements', 'contact', 'profile'];

var queuePoller = null;
var activeQueueExamId = null;

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
    if (page !== 'queue') stopQueuePolling();
    showSection(page);
    if (page === 'timetable')     loadTimetable();
    if (page === 'exams')         loadMyExams();
    if (page === 'courses')       loadCoursesList();
    if (page === 'announcements') loadAnnouncements();
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

    // load course count for sidebar
    apiRequest('/api/student/courses.php').then(function (data) {
        if (data.count !== undefined) {
            document.getElementById('sidebarCourses').textContent = data.count;
        }
    });

    loadPage('timetable');
}

//  TIMETABLE 

function loadTimetable() {
    apiRequest('/api/student/timetable.php').then(function (data) {
        renderTimetable(data.slots || [], 'timetableBody');
    }).catch(function () {
        document.getElementById('timetableBody').innerHTML =
            '<tr><td colspan="6">' + alertBox('Failed to load timetable.', 'danger') + '</td></tr>';
    });
}

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
                    + '<strong class="d-block" style="font-size:0.8rem">' + cell.course_name + '</strong>'
                    + '<span class="text-muted" style="font-size:0.72rem">' + (cell.professor_name || cell.course_code) + '</span>';
                if (cell.room) {
                    html += '<br><span class="text-muted" style="font-size:0.72rem"><i class="bi bi-geo-alt"></i> ' + cell.room + '</span>';
                }
                html += '</td>';
            } else {
                html += '<td class="tt-free">—</td>';
            }
        }
        html += '</tr>';
    });

    document.getElementById(bodyId).innerHTML = html || '<tr><td colspan="6" class="text-muted py-3">No classes scheduled for your courses yet.</td></tr>';
}

//MY EXAMS

function loadMyExams() {
    var container = document.getElementById('examsList');
    container.innerHTML = '<p class="text-muted">Loading...</p>';

    apiRequest('/api/student/exams.php').then(function (data) {
        if (!data.exams || data.exams.length === 0) {
            container.innerHTML = '<div class="card shadow-sm border-0 rounded-3"><div class="card-body text-muted small">No exams found for your enrolled courses.</div></div>';
            return;
        }

        var html = '';
        data.exams.forEach(function (e) {
            var statusBadge = e.status === 'in_progress'
                ? '<span class="badge bg-success">In Progress</span>'
                : e.status === 'closed'
                ? '<span class="badge bg-secondary">Closed</span>'
                : '<span class="badge" style="background:#e8f0fe;color:#006ec0">Upcoming</span>';

            var rosterBadge = e.on_roster
                ? '<span class="badge bg-success ms-1">On Roster</span>'
                : '<span class="badge bg-warning text-dark ms-1">Not on Roster</span>';

            var queueInfo = '';
            if (e.queue_status === 'waiting') {
                queueInfo = '<span class="badge badge-waiting ms-1">Waiting in Queue</span>';
            } else if (e.queue_status === 'called') {
                queueInfo = '<span class="badge badge-called ms-1">Called!</span>';
            } else if (e.queue_status === 'attended') {
                queueInfo = '<span class="badge badge-attended ms-1">Attended</span>';
            }

            var joinBtn = (e.on_roster && e.status === 'in_progress' && !e.queue_status)
                ? '<button class="btn btn-sm btn-primary ms-2" style="background:#006ec0;border-color:#006ec0" onclick="goToQueue(' + e.id + ')"><i class="bi bi-people me-1"></i>Join Queue</button>'
                : '';

            html += '<div class="card shadow-sm border-0 rounded-3 mb-3">'
                + '<div class="card-body">'
                + '<div class="d-flex align-items-start justify-content-between flex-wrap gap-2">'
                + '<div>'
                + '<h6 class="fw-bold mb-1">' + e.course_name + ' <small class="text-muted fw-normal">(' + e.course_code + ')</small></h6>'
                + '<div class="text-muted small mb-2">'
                + '<i class="bi bi-person me-1"></i>' + e.professor_name
                + '<span class="mx-2">·</span>'
                + '<i class="bi bi-calendar3 me-1"></i>' + e.exam_date
                + '<span class="mx-2">·</span>'
                + '<i class="bi bi-clock me-1"></i>' + e.exam_time.slice(0,5)
                + (e.room_number ? '<span class="mx-2">·</span><i class="bi bi-geo-alt me-1"></i>' + e.room_number : '')
                + '</div>'
                + '<div>' + statusBadge + rosterBadge + queueInfo + '</div>'
                + '</div>'
                + '<div class="d-flex align-items-center">' + joinBtn + '</div>'
                + '</div>'
                + (e.description ? '<p class="text-muted small mt-2 mb-0"><i class="bi bi-info-circle me-1"></i>' + e.description + '</p>' : '')
                + '</div></div>';
        });

        container.innerHTML = html;
    }).catch(function () {
        container.innerHTML = alertBox('Failed to load exams.', 'danger');
    });
}

// navigates to the queue tab pre-filled with the exam id
function goToQueue(examId) {
    document.getElementById('queueExamId').value = examId;
    loadPage('queue');
}

//  COURSES 

function loadCoursesList() {
    var list = document.getElementById('coursesList');
    list.innerHTML = '<p class="text-muted small">Loading...</p>';

    apiRequest('/api/student/courses.php').then(function (data) {
        if (!data.courses || data.courses.length === 0) {
            list.innerHTML = '<p class="text-muted small">You have not enrolled in any courses yet.</p>';
            return;
        }
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
        document.getElementById('sidebarCourses').textContent = data.count || data.courses.length;
    }).catch(function () {
        list.innerHTML = alertBox('Failed to load courses.', 'danger');
    });
}

function enrollCourse() {
    var code = document.getElementById('enrollId').value.trim();
    var msg  = document.getElementById('enrollMsg');
    if (!code) { msg.innerHTML = alertBox('Please enter a course code.', 'danger'); return; }

    apiRequest('/api/student/courses.php', 'POST', { course_code: code }).then(function (data) {
        if (data.course_id) {
            msg.innerHTML = alertBox('Enrolled successfully!', 'success');
            document.getElementById('enrollId').value = '';
            loadCoursesList();
        } else {
            msg.innerHTML = alertBox(data.error || data.message || 'Enrollment failed.', 'danger');
        }
    }).catch(function () {
        msg.innerHTML = alertBox('Network error.', 'danger');
    });
}

//  QUEUE 

function joinQueue() {
    var examId = parseInt(document.getElementById('queueExamId').value);
    var msg    = document.getElementById('queueMsg');
    if (!examId) { msg.innerHTML = alertBox('Please enter an Exam ID.', 'danger'); return; }

    apiRequest('/api/student/queue.php', 'POST', { exam_id: examId }).then(function (data) {
        if (data.status === 'waiting' || (data.error && data.error.indexOf('already in the queue') !== -1)) {
            msg.innerHTML = '';
            startQueuePolling(examId);
        } else {
            msg.innerHTML = alertBox(data.error || data.message || 'Could not join queue.', 'danger');
        }
    }).catch(function () {
        msg.innerHTML = alertBox('Network error.', 'danger');
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
        var body  = document.getElementById('queueStatusBody');
        var pulse = document.getElementById('queuePulse');

        if (data.position === undefined) {
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
            pulse.innerHTML = '';
            body.innerHTML = alertBox('<i class="bi bi-check-circle me-1"></i>You have been marked as attended. Good luck!', 'success');
        } else {
            pulse.innerHTML = '<span class="spinner-grow spinner-grow-sm text-primary me-1" role="status"></span>updating…';
            body.innerHTML = '<div class="table-responsive"><table class="table table-borderless small mb-0">'
                + '<tr><td class="text-muted" style="width:160px">Status</td><td><span class="badge badge-waiting">' + data.status + '</span></td></tr>'
                + '<tr><td class="text-muted">Your position</td><td><strong>' + data.position + '</strong> of ' + data.total_waiting + ' waiting</td></tr>'
                + '</table></div>';
        }
    }).catch(function () {
        document.getElementById('queuePulse').textContent = '● offline';
    });
}

function stopQueuePolling() {
    if (queuePoller) { clearInterval(queuePoller); queuePoller = null; }
    activeQueueExamId = null;
    var card = document.getElementById('queueStatusCard');
    if (card) card.style.display = 'none';
    var body = document.getElementById('queueStatusBody');
    if (body) body.innerHTML = '';
}

//  ANNOUNCEMENTS 

function loadAnnouncements() {
    var container = document.getElementById('announcementsList');
    container.innerHTML = '<p class="text-muted">Loading...</p>';

    apiRequest('/api/student/announcements.php').then(function (data) {
        if (!data.announcements || data.announcements.length === 0) {
            container.innerHTML = '<div class="card shadow-sm border-0 rounded-3"><div class="card-body text-muted small">No announcements yet.</div></div>';
            return;
        }
        var html = '';
        data.announcements.forEach(function (a) {
            var dt = new Date(a.created_at);
            html += '<div class="card shadow-sm border-0 rounded-3 mb-3"><div class="card-body">'
                + '<div class="d-flex gap-3">'
                + '<div class="avatar-circle flex-shrink-0" style="width:40px;height:40px;font-size:16px">'
                + a.professor_name.charAt(0).toUpperCase()
                + '</div>'
                + '<div class="flex-grow-1">'
                + '<div class="fw-semibold small mb-0">' + a.professor_name + '</div>'
                + '<div class="text-muted" style="font-size:0.75rem">'
                + a.course_name + ' · ' + dt.toLocaleDateString() + ' ' + dt.toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'})
                + '</div>'
                + '<div class="announcement-bubble mt-2">' + escapeHtml(a.message) + '</div>'
                + '</div></div>'
                + '</div></div>';
        });
        container.innerHTML = html;
    }).catch(function () {
        container.innerHTML = alertBox('Failed to load announcements.', 'danger');
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

//  UTILS 

init();
