function doSignup() {
    var username = document.getElementById('username').value.trim();
    var email    = document.getElementById('email').value.trim();
    var password = document.getElementById('password').value;

    if (!username || !email || !password) {
        showMsg('Please fill in all fields.', 'danger');
        return;
    }

    fetch('/api/auth/register.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username: username, email: email, password: password })
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        if (data.user_id) {
            showMsg('Account created! Redirecting to login…', 'success');
            setTimeout(function () { window.location.href = 'index.html'; }, 2000);
        } else {
            showMsg(data.error || data.message || 'Registration failed.', 'danger');
        }
    })
    .catch(function () {
        showMsg('Network error. Please try again.', 'danger');
    });
}

function showMsg(text, type) {
    var div = document.getElementById('message');
    div.textContent = text;
    div.className = 'alert alert-' + type;
    div.classList.remove('d-none');
}
