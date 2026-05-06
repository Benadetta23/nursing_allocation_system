<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Nursing Allocation System</title>
    <link rel="stylesheet" href="css/login.css">
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <h1>Daeyang University</h1>
            <p>Nursing Allocation System</p>

            <?php
            session_start();
            if (isset($_SESSION['error'])) {
                echo '<div class="error-msg" style="background:#f8d7da; color:#721c24; padding:10px; margin-bottom:20px;">' . $_SESSION['error'] . '</div>';
                unset($_SESSION['error']);
            }
            ?>

            <form method="POST" action="actions/login_process.php">
                <div class="form-group">
                    <label for="regNumber">Registration ID</label>
                    <input type="text" id="regNumber" name="regNumber" placeholder="Enter Registration No." required>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" placeholder="Enter Password" required>
                </div>

                <button type="submit" class="btn-signin">SIGN IN</button>
            </form>

            <div class="demo-info">
                <p><strong>Demo Accounts:</strong></p>
                <p>👑 Coordinator: COORD001 | pass</p>
                <p>📖 Lecturer: LECT001 | pass</p>
                <p>🎓 Student: STU001 | pass</p>
            </div>
        </div>
    </div>
</body>
</html>