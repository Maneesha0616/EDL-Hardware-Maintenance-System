<?php
require_once __DIR__ . '/security.php';
session_start();
include "db.php";

$error = "";
$success = "";
$step = 1;

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['send_otp'])) {
        $username = trim($_POST['username']);
        $phone = trim($_POST['phone']);

        if (empty($username) || empty($phone)) {
            $error = "Username and Phone Number are required!";
        } else {
            // Verify if user exists with this username and phone
            $check = "SELECT id FROM users WHERE username = ? AND phone = ?";
            $stmt = sqlsrv_prepare($conn, $check, array(&$username, &$phone));
            if ($stmt === false) {
                die("Prepare failed: " . print_r(sqlsrv_errors(), true));
            }
            if (!sqlsrv_execute($stmt)) {
                die("Execution failed: " . print_r(sqlsrv_errors(), true));
            }
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            if ($row) {
                $user = $row;

                // Generate a 6-digit OTP
                $otp = rand(100000, 999999);
                $_SESSION['reset_otp'] = $otp;
                $_SESSION['reset_user_id'] = $user['id'];
                $_SESSION['reset_phone'] = $phone;
                $_SESSION['otp_expiry'] = time() + 300; // OTP valid for 5 minutes

                // TODO: INTEGRATE SMS API HERE

                $sid = "";
                $token = "";
                $twilio_number = "";

                $url = "";
                $data = [
                    'To' => $phone,
                    'From' => $twilio_number,
                    'Body' => "Your HMS Password Reset OTP is: " . $otp
                ];

                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_USERPWD, "$sid:$token");
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));

                $response = curl_exec($ch);
                curl_close($ch);

                // Here you would call your SMS Provider API (Twilio, MSG91, Textlocal, etc.)
                // Example for Twilio:
                // $client->messages->create($phone, ['from' => $twilio_number, 'body' => "Your HMS OTP is: $otp"]);

                $step = 2;
                $success = "OTP sent to $phone!";
            } else {
                $error = "Invalid username or phone number. Verification failed.";
            }
        }
    } elseif (isset($_POST['reset_password'])) {
        $step = 2; // Stay on step 2 by default if there's an error
        $entered_otp = trim($_POST['otp']);
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];

        if (empty($entered_otp) || empty($new_password) || empty($confirm_password)) {
            $error = "All fields are required!";
        } elseif ($new_password !== $confirm_password) {
            $error = "Passwords do not match!";
        } elseif (!isset($_SESSION['reset_otp']) || !isset($_SESSION['reset_user_id'])) {
            $error = "Session expired. Please request a new OTP.";
            $step = 1;
        } elseif (time() > $_SESSION['otp_expiry']) {
            $error = "OTP has expired. Please request a new one.";
            $step = 1;
            unset($_SESSION['reset_otp'], $_SESSION['reset_user_id'], $_SESSION['reset_phone'], $_SESSION['otp_expiry']);
        } elseif ($entered_otp != $_SESSION['reset_otp']) {
            $error = "Invalid OTP entered!";
        } else {
            // Hash the new password
            $hashedPassword = password_hash($new_password, PASSWORD_DEFAULT);
            $user_id = $_SESSION['reset_user_id'];

            // Update the password in the database
            $update_sql = "UPDATE users SET password = ? WHERE id = ?";
            $update_stmt = sqlsrv_prepare($conn, $update_sql, array(&$hashedPassword, &$user_id));
            if ($update_stmt === false) {
                $error = "Prepare failed: " . print_r(sqlsrv_errors(), true);
            } else {
                if (sqlsrv_execute($update_stmt)) {
                    $success = "Password reset successfully! You can login now.";
                    $step = 3; // Success state
                    // Clear session variables after successful reset
                    unset($_SESSION['reset_otp'], $_SESSION['reset_user_id'], $_SESSION['reset_phone'], $_SESSION['otp_expiry']);
                } else {
                    $error = "Failed to update password. Please try again.";
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password | Hardware Maintenance System</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            --bg-color: #f4f7f9;
            --glass-bg: rgba(255, 255, 255, 0.85);
            --glass-border: rgba(255, 255, 255, 0.5);
            --text-main: #002b47;
            --text-muted: #546e7a;
            --primary: #00416a;
            --primary-glow: rgba(0, 65, 106, 0.3);
            --accent: #0084d1;
            --input-bg: rgba(255, 255, 255, 0.9);
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', sans-serif;
        }

        body {
            min-height: 100vh;
            background: linear-gradient(rgba(244, 247, 249, 0.85), rgba(244, 247, 249, 0.9)), url('images/hms_premium_hero_v2.png');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
            color: var(--text-main);
        }


        .orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(80px);
            opacity: 0.15;
            z-index: 0;
            animation: float 10s infinite ease-in-out alternate;
        }

        .orb-1 {
            width: 400px;
            height: 400px;
            background: var(--primary);
            top: -100px;
            left: -100px;
            animation-delay: 0s;
        }

        .orb-2 {
            width: 300px;
            height: 300px;
            background: var(--accent);
            bottom: -50px;
            right: -50px;
            animation-delay: -5s;
        }

        .orb-3 {
            width: 250px;
            height: 250px;
            background: var(--primary);
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            animation-duration: 15s;
            opacity: 0.1;
        }

        @keyframes float {
            0% {
                transform: translate(0, 0) scale(1);
            }

            100% {
                transform: translate(30px, 50px) scale(1.1);
            }
        }

        .auth-container {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 460px;
            padding: 20px;
        }

        .glass-card {
            background: var(--glass-bg);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border: 1px solid var(--glass-border);
            border-radius: 24px;
            padding: 50px 40px;
            box-shadow: 0 25px 50px -12px rgba(0, 65, 106, 0.15);
            text-align: center;
        }

        .icon-container {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 25px;
            box-shadow: 0 0 30px var(--primary-glow);
        }

        .icon-container i {
            font-size: 2rem;
            color: white;
        }

        h1 {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 10px;
            background: linear-gradient(to right, var(--primary), var(--accent));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .subtitle {
            color: var(--text-muted);
            font-size: 0.95rem;
            margin-bottom: 35px;
            line-height: 1.5;
        }

        .input-group {
            position: relative;
            margin-bottom: 20px;
            text-align: left;
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
            background: var(--input-bg);
            border: 1px solid var(--glass-border);
            border-radius: 14px;
            font-size: 1rem;
            color: var(--text-main);
            transition: var(--transition);
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.02);
        }

        .input-group input::placeholder {
            color: #8aa4b5;
        }

        .input-group input:focus {
            outline: none;
            border-color: var(--accent);
            background: #ffffff;
            box-shadow: 0 0 0 4px rgba(0, 132, 209, 0.15);
        }

        .input-group input:focus+i {
            color: var(--accent);
        }

        .btn-auth {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: white;
            border: none;
            border-radius: 14px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            margin-top: 15px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 10px 20px rgba(0, 65, 106, 0.2);
        }

        .btn-auth::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: all 0.5s ease;
        }

        .btn-auth:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 30px rgba(0, 65, 106, 0.3);
        }

        .btn-auth:hover::before {
            left: 100%;
        }

        .login-prompt {
            margin-top: 30px;
            color: var(--text-muted);
            font-size: 0.95rem;
        }

        .login-prompt a {
            color: var(--accent);
            text-decoration: none;
            font-weight: 600;
            transition: var(--transition);
        }

        .login-prompt a:hover {
            color: var(--primary);
            text-shadow: none;
            text-decoration: underline;
        }

        /* Modal / Popup Styles */
        .popup-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 43, 71, 0.6);
            backdrop-filter: blur(8px);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            padding: 20px;
        }

        .popup-box {
            background: #ffffff;
            border: 1px solid var(--glass-border);
            padding: 40px;
            border-radius: 24px;
            text-align: center;
            width: 100%;
            max-width: 400px;
            box-shadow: 0 25px 50px -12px rgba(0, 65, 106, 0.3);
            animation: popupIn 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }

        @keyframes popupIn {
            0% {
                transform: scale(0.8);
                opacity: 0;
            }

            100% {
                transform: scale(1);
                opacity: 1;
            }
        }

        .popup-box i {
            font-size: 4rem;
            margin-bottom: 20px;
        }

        .popup-box .fa-check-circle {
            color: #10b981;
        }

        .popup-box .fa-times-circle {
            color: #ef4444;
        }

        .popup-box p {
            font-size: 1.1rem;
            color: var(--text-main);
            margin-bottom: 30px;
            line-height: 1.5;
            font-weight: 500;
        }

        .popup-box button {
            background: var(--primary);
            color: white;
            border: none;
            padding: 12px 35px;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            transition: var(--transition);
        }

        .popup-box button:hover {
            background: var(--accent);
        }
    </style>
</head>

<body>
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>
    <div class="orb orb-3"></div>

    <div class="auth-container">
        <div class="glass-card">
            <div class="icon-container">
                <i class="fa-solid fa-fingerprint"></i>
            </div>

            <h1>Recover Account</h1>
            <?php if ($step == 1): ?>
                <p class="subtitle">Please enter your verification details to receive an OTP</p>
            <?php elseif ($step == 2): ?>
                <p class="subtitle">Enter the OTP sent to <?= htmlspecialchars($_SESSION['reset_phone']) ?> and your new
                    password</p>
            <?php else: ?>
                <p class="subtitle">Password recovered successfully</p>
            <?php endif; ?>

            <?php if ($step == 1): ?>
                <form method="post">
                    <div class="input-group">
                        <input type="text" name="username" placeholder="Username" required>
                        <i class="fas fa-user"></i>
                    </div>

                    <div class="input-group">
                        <input type="tel" name="phone" placeholder="Registered Phone Number" required>
                        <i class="fas fa-phone"></i>
                    </div>

                    <button type="submit" name="send_otp" class="btn-auth">Send OTP</button>
                </form>
            <?php elseif ($step == 2): ?>
                <form method="post">
                    <div class="input-group">
                        <input type="text" name="otp" placeholder="Enter 6-digit OTP" required maxlength="6">
                        <i class="fas fa-key"></i>
                    </div>

                    <div class="input-group">
                        <input type="password" name="new_password" placeholder="New Password" required>
                        <i class="fas fa-lock"></i>
                    </div>

                    <div class="input-group">
                        <input type="password" name="confirm_password" placeholder="Confirm New Password" required>
                        <i class="fas fa-lock"></i>
                    </div>

                    <button type="submit" name="reset_password" class="btn-auth">Verify & Reset Password</button>
                </form>
            <?php endif; ?>

            <?php if ($step != 3): ?>
                <div class="login-prompt">
                    Remember your password? <a href="login.php">Back to Login</a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Popup Message -->
    <div id="popup" class="popup-overlay">
        <div class="popup-box">
            <i id="popup-icon" class="fas"></i>
            <p id="popup-message"></p>
            <button onclick="closePopup()">OK</button>
        </div>
    </div>

    <script>
        function showPopup(message, isSuccess, redirect = false) {
            document.getElementById("popup-message").innerText = message;
            const icon = document.getElementById("popup-icon");
            icon.className = isSuccess ? "fas fa-check-circle" : "fas fa-times-circle";
            document.getElementById("popup").style.display = "flex";

            if (redirect) {
                document.querySelector(".popup-box button").onclick = function () {
                    window.location.href = "login.php";
                };
            }
        }

        function closePopup() {
            document.getElementById("popup").style.display = "none";
        }

        <?php if ($success): ?>
            <?php if ($step == 3): ?>
                showPopup("<?= htmlspecialchars($success, ENT_QUOTES) ?>", true, true);
            <?php else: ?>
                showPopup("<?= htmlspecialchars($success, ENT_QUOTES) ?>", true, false);
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($error): ?>
            showPopup("<?= htmlspecialchars($error, ENT_QUOTES) ?>", false);
        <?php endif; ?>
    </script>

</body>

</html>