// signup.js - handles the registration form

document.addEventListener('DOMContentLoaded', function () {

    var form = document.getElementById('signupForm');
    var msgDiv = document.getElementById('message');

    form.addEventListener('submit', function (e) {
        e.preventDefault(); // stop the page from refreshing

        // Read the form values
        var username = document.getElementById('username').value.trim();
        var email = document.getElementById('email').value.trim();
        var password = document.getElementById('password').value;

        // Basic check before sending to server
        if (!username || !email || !password) {
            showMessage('Please fill in all fields.', 'danger');
            return;
        }

        // Send the registration request to the server
        fetch('/api/auth/register.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                username: username,
                email: email,
                password: password
            })
        })
        .then(function (response) {
            return response.json();
        })
        .then(function (data) {
            if (data.user_id) {
                // Registration worked, show success and redirect to login
                showMessage('Account created successfully! Redirecting to login...', 'success');
                setTimeout(function () {
                    window.location.href = 'index.html';
                }, 2000);
            } else {
                // Something went wrong, show the error
                showMessage(data.error || data.message || 'Registration failed.', 'danger');
            }
        })
        .catch(function (error) {
            console.error('Network error:', error);
            showMessage('Network error. Please try again.', 'danger');
        });
    });

    // show a message box with the given style (success / danger)
    function showMessage(text, type) {
        msgDiv.textContent = text;
        msgDiv.className = 'alert alert-' + type;
        msgDiv.style.display = 'block';
    }

});
