document.addEventListener('DOMContentLoaded', function() {
    const loginForm = document.getElementById('loginForm');
    const errorMessage = document.getElementById('error-message');

    loginForm.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const email = document.getElementById('email').value;
        const password = document.getElementById('password').value;
        
        fetch('/api/auth/login.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                email: email,
                password: password
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                localStorage.setItem('token', data.token);
                localStorage.setItem('user_type', data.user_type);
                
                if (data.user_type === 'student') {
                    window.location.href = 'student-dashboard.html';
                } else if (data.user_type === 'professor') {
                    window.location.href = 'professor-dashboard.html';
                } else if (data.user_type === 'admin') {
                    window.location.href = 'admin-dashboard.html';
                }
            } else {
                errorMessage.textContent = data.error || 'Login failed';
                errorMessage.style.display = 'block';
                document.getElementById('password').value = '';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            errorMessage.textContent = 'Network error';
            errorMessage.style.display = 'block';
        });
    });
});
