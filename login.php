<?php
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
// require_once __DIR__ . '/security.php';
require_once __DIR__ . '/security.php';
session_start();
$error = $_SESSION['error'] ?? null;
unset($_SESSION['error']);

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Hardware Maintenance System</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            --primary: #00416a;
            --primary-light: #0084d1;
            --background: #f8fafc;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --shadow-soft: 0 10px 40px rgba(0, 0, 0, 0.06);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', sans-serif;
        }

        body {
            min-height: 100vh;
            background: linear-gradient(rgba(241, 245, 249, 0.8), rgba(241, 245, 249, 0.9)), url('images/hms_premium_hero_v2.png');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .auth-card {
            width: 100%;
            max-width: 900px;
            background: #ffffff;
            display: flex;
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .side-panel {
            width: 45%;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            padding: 50px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            color: white;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .side-panel::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: url('https://www.transparenttextures.com/patterns/carbon-fibre.png');
            opacity: 0.1;
        }

        .side-panel img {
            width: 140px;
            height: 140px;
            border-radius: 30px;
            background: white;
            padding: 10px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
            margin-bottom: 30px;
            position: relative;
            z-index: 1;
        }

        .side-panel h2 {
            font-size: 2rem;
            font-weight: 800;
            margin-bottom: 15px;
            position: relative;
            z-index: 1;
        }

        .side-panel p {
            font-size: 1.1rem;
            opacity: 0.9;
            line-height: 1.6;
            position: relative;
            z-index: 1;
        }

        .form-panel {
            width: 55%;
            padding: 60px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .form-header {
            margin-bottom: 40px;
        }

        .form-header h1 {
            font-size: 2.2rem;
            color: var(--text-main);
            font-weight: 800;
            margin-bottom: 10px;
        }

        .form-header p {
            color: var(--text-muted);
            font-size: 1.05rem;
        }

        .input-group {
            margin-bottom: 25px;
            position: relative;
        }

        .input-group i {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 1.1rem;
            transition: var(--transition);
        }

        .input-group input {
            width: 100%;
            padding: 16px 16px 16px 50px;
            background: #f1f5f9;
            border: 2px solid transparent;
            border-radius: 14px;
            font-size: 1rem;
            color: var(--text-main);
            transition: var(--transition);
        }

        .input-group input:focus {
            outline: none;
            background: white;
            border-color: var(--primary-light);
            box-shadow: 0 0 0 4px rgba(0, 132, 209, 0.1);
        }

        .input-group input:focus+i {
            color: var(--primary-light);
        }

        .forgot-link {
            text-align: right;
            margin-bottom: 30px;
        }

        .forgot-link a {
            color: var(--primary-light);
            text-decoration: none;
            font-size: 0.95rem;
            font-weight: 600;
        }

        .forgot-link a:hover {
            text-decoration: underline;
        }

        .btn-auth {
            width: 100%;
            padding: 16px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 14px;
            font-size: 1.1rem;
            font-weight: 700;
            cursor: pointer;
            transition: var(--transition);
            box-shadow: 0 10px 20px rgba(0, 65, 106, 0.15);
        }

        .btn-auth:hover {
            background: #002b47;
            transform: translateY(-2px);
            box-shadow: 0 15px 25px rgba(0, 65, 106, 0.2);
            filter: brightness(1.2);
        }

        .error-message {
            background: #fef2f2;
            color: #ef4444;
            padding: 14px;
            border-radius: 10px;
            margin-bottom: 25px;
            font-size: 0.95rem;
            border: 1px solid #fee2e2;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .signup-prompt {
            margin-top: 35px;
            text-align: center;
            color: var(--text-muted);
            font-size: 1rem;
        }

        .signup-prompt a {
            color: var(--primary-light);
            text-decoration: none;
            font-weight: 700;
        }

        .signup-prompt a:hover {
            text-decoration: underline;
        }

        @media (max-width: 850px) {
            .auth-card {
                flex-direction: column;
                max-width: 450px;
            }

            .side-panel {
                width: 100%;
                padding: 40px 20px;
            }

            .form-panel {
                width: 100%;
                padding: 40px;
            }
        }
    </style>
</head>

<body>

    <div class="auth-card">
        <div class="side-panel">
            <img src="images/hardware_logo.png" alt="HMS Logo">
            <h2>Welcome Back</h2>
            <p>Access the Hardware Maintenance System portal to manage your technical assets and workflows.</p>
        </div>

        <div class="form-panel">
            <div class="form-header">
                <h1>Login</h1>
                <p>Enter your credentials to continue</p>
            </div>

            <?php if ($error): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <form action="loginprocess.php" method="post">
                <div class="input-group">
                    <input type="text" name="username" placeholder="Username" required>
                    <i class="fas fa-user-circle"></i>
                </div>

                <div class="input-group">
                    <input type="password" name="password" placeholder="Password" required>
                    <i class="fas fa-lock"></i>
                </div>

                <div class="forgot-link">
                    <a href="forgot_password.php">Forgot password?</a>
                </div>

                <button type="submit" name="login" class="btn-auth">Sign In</button>
            </form>

            <p class="signup-prompt">
                Don’t have an account?
                <a href="register.php">Sign up</a>
            </p>
        </div>
    </div>

</body>

</html>