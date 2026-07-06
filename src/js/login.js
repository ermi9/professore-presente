// allow pressing Enter in either field to submit
document.addEventListener('DOMContentLoaded', function () {
    ['email', 'password'].forEach(function (id) {
        document.getElementById(id).addEventListener('keydown', function (e) {
            if (e.key === 'Enter') doLogin();
        });
    });
});

function doLogin() {
    var email    = document.getElementById('email').value.trim();
    var password = document.getElementById('password').value;
    var errDiv   = document.getElementById('error-message');

    errDiv.classList.add('d-none');

    if (!email || !password) {
        showError('Please enter your email and password.');
        return;
    }

    fetch('/api/auth/login.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email: email, password: password })
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        if (data.success) {
            localStorage.setItem('token', data.token);
            localStorage.setItem('user_type', data.user_type);
            if (data.user_type === 'student')   window.location.href = 'student-dashboard.html';
            else if (data.user_type === 'professor') window.location.href = 'professor-dashboard.html';
            else if (data.user_type === 'admin')     window.location.href = 'admin-dashboard.html';
        } else {
            showError(data.error || 'Login failed.');
            document.getElementById('password').value = '';
        }
    })
    .catch(function () {
        showError('Network error. Please try again.');
    });
}

function showError(msg) {
    var div = document.getElementById('error-message');
    div.textContent = msg;
    div.classList.remove('d-none');
}
