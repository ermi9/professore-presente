// common.js - Shared helpers loaded by all dashboards

function apiRequest(url, method, body) {
    var token = localStorage.getItem('token');
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
