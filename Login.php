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
            <div class="logo-section">
                <h1>Daeyang University</h1>
                <p>Nursing Allocation System</p>
            </div>

            <?php
            session_start();
            if (isset($_SESSION['error'])) {
                echo '<div class="error-msg">' . $_SESSION['error'] . '</div>';
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

                <div class="forgot-password">
                    <a href="#">Forgot Password?</a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>