<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Login - Nursing Allocation System</title>
    <link rel="stylesheet" href="css/landing.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: #654321;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        
        /* Login Container */
        .login-container {
            width: 100%;
            max-width: 450px;
            margin: 20px;
            padding: 20px;
        }
        
        /* Login Card */
        .login-card {
            background: white;
            border-radius: 16px;
            padding: 40px 35px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
            border-top: 5px solid #c3a343;
        }
        
        /* Logo Section */
        .logo-section {
            text-align: center;
            margin-bottom: 35px;
        }
        
        /* Logo Image */
        .logo-img {
            max-width: 80px;
            height: auto;
            margin-bottom: 15px;
        }
        
        .logo-section h1 {
            color: #4a2f1a;
            font-size: 1.5rem;
            font-weight: 700;
            letter-spacing: -0.5px;
        }
        
        /* Form Groups */
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            color: #4a2f1a;
            letter-spacing: 0.5px;
        }
        
        .form-group input {
            width: 100%;
            padding: 14px 16px;
            border: 1.5px solid #e0e0e0;
            border-radius: 12px;
            font-size: 0.95rem;
            font-family: 'Inter', sans-serif;
            transition: all 0.3s ease;
            background: #fafafa;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #c3a343;
            background: white;
            box-shadow: 0 0 0 3px rgba(195, 163, 67, 0.1);
        }
        
        .form-group input::placeholder {
            color: #bbb;
            font-weight: 400;
        }
        
        /* Sign In Button */
        .btn-signin {
            width: 100%;
            padding: 14px;
            background: #4a2f1a;
            color: white;
            border: none;
            border-radius: 40px;
            font-size: 1rem;
            font-weight: 600;
            letter-spacing: 1px;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 10px;
            font-family: 'Inter', sans-serif;
        }
        
        .btn-signin:hover {
            background: #654321;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(101, 67, 51, 0.3);
        }
        
        /* Forgot Password */
        .forgot-password {
            text-align: center;
            margin-top: 25px;
        }
        
        .forgot-password a {
            color: #888;
            font-size: 0.8rem;
            text-decoration: none;
            transition: color 0.3s;
        }
        
        .forgot-password a:hover {
            color: #c3a343;
            text-decoration: underline;
        }
        
        /* Error Message */
        .error-msg {
            background: #f8d7da;
            color: #721c24;
            padding: 12px;
            border-radius: 10px;
            margin-bottom: 20px;
            text-align: center;
            font-size: 0.85rem;
            border-left: 4px solid #dc3545;
        }
        
        /* Responsive */
        @media (max-width: 480px) {
            .login-card {
                padding: 30px 25px;
            }
            
            .logo-img {
                max-width: 60px;
            }
            
            .logo-section h1 {
                font-size: 1.3rem;
            }
            
            .form-group input {
                padding: 12px 14px;
            }
            
            .btn-signin {
                padding: 12px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="logo-section">
                <?php if (file_exists('images/logo.jpg')): ?>
                    <img src="images/logo.jpg" alt="Daeyang University Logo" class="logo-img">
                <?php endif; ?>
                <h1>Daeyang University</h1>
            </div>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="error-msg"><?php echo $_SESSION['error']; ?></div>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>

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