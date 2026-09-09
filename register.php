<?php
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
require_once __DIR__ . '/security.php';
session_start();
include "db.php";

$error = '';
$success = '';

if (isset($_POST['register'])) {

    $name = trim($_POST['name']);
    $phone = trim($_POST['phone']);
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    if (empty($name) || empty($phone) || empty($username) || empty($password) || empty($confirm_password)) {
        $error = "All fields are required!";
    } elseif (strlen($name) < 3) {
        $error = "Full Name must be at least 3 characters long!";
    } elseif (!preg_match('/^[A-Za-z\s]+$/', $name)) {
        $error = "Full Name must only contain letters and spaces!";
    } elseif (!preg_match('/^[0-9]{10}$/', $phone)) {
        $error = "Phone number must be exactly 10 digits!";
    } elseif (strlen($username) < 4) {
        $error = "Username must be at least 4 characters long!";
    } elseif (!preg_match('/^[A-Za-z0-9_\-]+$/', $username)) {
        $error = "Username must only contain letters, numbers, underscores, and dashes!";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match!";
    } elseif (strlen($password) < 8) {
        $error = "Password must be at least 8 characters long!";
    } elseif (!preg_match('/[A-Z]/', $password)) {
        $error = "Password must contain at least one uppercase letter!";
    } elseif (!preg_match('/[a-z]/', $password)) {
        $error = "Password must contain at least one lowercase letter!";
    } elseif (!preg_match('/[0-9]/', $password)) {
        $error = "Password must contain at least one number!";
    } elseif (!preg_match('/[!@#$%^&*(),.?":{}|<>_\-]/', $password)) {
        $error = "Password must contain at least one special character!";
    } else {

        /* ---------------- CHECK USER EXISTS ---------------- */
        $checkSql = "SELECT id FROM users WHERE username = ? OR phone = ?";
        $params = array($username, $phone);

        $checkStmt = sqlsrv_query($conn, $checkSql, $params);

        if ($checkStmt === false) {
            die(print_r(sqlsrv_errors(), true));
        }

        $resultRows = [];
        while ($row = sqlsrv_fetch_array($checkStmt, SQLSRV_FETCH_ASSOC)) {
            $resultRows[] = $row;
        }

        if (count($resultRows) > 0) {
            $error = "Username or Phone number already exists!";
        } else {

            /* ---------------- INSERT USER ---------------- */
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $role = isset($_POST['role']) ? $_POST['role'] : 'user';

            $insertSql = "INSERT INTO users (name, phone, username, password, role)
                          VALUES (?, ?, ?, ?, ?)";

            $insertParams = array($name, $phone, $username, $hashed_password, $role);

            $insertStmt = sqlsrv_query($conn, $insertSql, $insertParams);

            if ($insertStmt) {
                $success = "Registration successful! You can now log in.";
            } else {
                $error = "Registration failed. Please try again.";
                die(print_r(sqlsrv_errors(), true));
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
    <title>Register | Hardware Maintenance System</title>
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
            padding: 40px 50px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            max-height: 90vh;
            overflow-y: auto;
        }

        .form-panel::-webkit-scrollbar {
            width: 6px;
        }

        .form-panel::-webkit-scrollbar-thumb {
            background-color: #cbd5e1;
            border-radius: 4px;
        }

        .form-header {
            margin-bottom: 25px;
        }

        .form-header h1 {
            font-size: 2rem;
            color: var(--text-main);
            font-weight: 800;
            margin-bottom: 5px;
        }

        .form-header p {
            color: var(--text-muted);
            font-size: 0.95rem;
        }

        .input-group {
            margin-bottom: 18px;
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

        .input-group input, .input-group select {
            width: 100%;
            padding: 14px 16px 14px 50px;
            background: #f1f5f9;
            border: 2px solid transparent;
            border-radius: 14px;
            font-size: 0.95rem;
            color: var(--text-main);
            transition: var(--transition);
            appearance: none;
            -webkit-appearance: none;
        }

        .input-group select {
            cursor: pointer;
            padding-right: 40px;
        }

        /* Style select placeholder text when empty value is selected */
        .input-group select:invalid {
            color: var(--text-muted);
        }

        .input-group select option {
            color: var(--text-main);
            background: #ffffff;
        }

        .input-group select option:disabled {
            color: var(--text-muted);
        }

        .input-group input:focus, .input-group select:focus {
            outline: none;
            background: white;
            border-color: var(--primary-light);
            box-shadow: 0 0 0 4px rgba(0, 132, 209, 0.1);
        }

        .input-group input:focus+i, .input-group select:focus+i {
            color: var(--primary-light);
        }

        .input-group select:focus ~ .select-arrow {
            color: var(--primary-light);
        }

        .input-group .select-arrow {
            position: absolute;
            right: 18px;
            left: auto;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            pointer-events: none;
        }

        .btn-auth {
            width: 100%;
            padding: 14px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 14px;
            font-size: 1.05rem;
            font-weight: 700;
            cursor: pointer;
            transition: var(--transition);
            box-shadow: 0 10px 20px rgba(0, 65, 106, 0.15);
            margin-top: 10px;
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
            padding: 12px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 0.9rem;
            border: 1px solid #fee2e2;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .success-message {
            background: #f0fdf4;
            color: #15803d;
            padding: 12px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 0.9rem;
            border: 1px solid #dcfce7;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .password-requirements, .field-requirements {
            margin-top: -10px;
            margin-bottom: 18px;
            padding: 12px 16px;
            background: #f8fafc;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            display: none;
            flex-direction: column;
            gap: 8px;
            animation: fadeIn 0.3s ease;
        }

        .requirement {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.85rem;
            color: var(--text-muted);
            transition: var(--transition);
        }

        .requirement i {
            font-size: 0.9rem;
            color: var(--text-muted);
            transition: var(--transition);
        }

        .requirement.valid {
            color: #10b981;
        }

        .requirement.valid i {
            color: #10b981;
        }

        .requirement.invalid {
            color: #ef4444;
        }

        .requirement.invalid i {
            color: #ef4444;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-5px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .signup-prompt {
            margin-top: 25px;
            text-align: center;
            color: var(--text-muted);
            font-size: 0.95rem;
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
                max-height: none;
                overflow-y: visible;
            }
        }
    </style>
</head>

<body>

    <div class="auth-card">
        <div class="side-panel">
            <img src="images/hardware_logo.png" alt="HMS Logo">
            <h2>Join HMS</h2>
            <p>Create a secure account to access and utilize the Hardware Maintenance Portal.</p>
        </div>

        <div class="form-panel">
            <div class="form-header">
                <h1>Register</h1>
                <p>Please fill in the details below to register</p>
            </div>

            <?php if ($error): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="success-message">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <form action="register.php" method="post">
                <div class="input-group">
                    <input type="text" name="name" id="name" placeholder="Full Name" value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>" required>
                    <i class="fas fa-id-card"></i>
                </div>
                <!-- Name requirements checklist -->
                <div class="field-requirements" id="nameRequirements">
                    <div class="requirement" id="req-name-length">
                        <i class="far fa-circle"></i>
                        <span>Minimum 3 characters</span>
                    </div>
                    <div class="requirement" id="req-name-chars">
                        <i class="far fa-circle"></i>
                        <span>Letters and spaces only</span>
                    </div>
                </div>

                <div class="input-group">
                    <input type="text" name="phone" id="phone" placeholder="Phone Number" value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>" required>
                    <i class="fas fa-phone"></i>
                </div>
                <!-- Phone requirements checklist -->
                <div class="field-requirements" id="phoneRequirements">
                    <div class="requirement" id="req-phone-digits">
                        <i class="far fa-circle"></i>
                        <span>Digits only (no spaces or letters)</span>
                    </div>
                    <div class="requirement" id="req-phone-length">
                        <i class="far fa-circle"></i>
                        <span>Exactly 10 digits</span>
                    </div>
                </div>

                <div class="input-group">
                    <input type="text" name="username" id="username" placeholder="Username" value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>" required>
                    <i class="fas fa-user-circle"></i>
                </div>
                <!-- Username requirements checklist -->
                <div class="field-requirements" id="usernameRequirements">
                    <div class="requirement" id="req-user-length">
                        <i class="far fa-circle"></i>
                        <span>Minimum 4 characters</span>
                    </div>
                    <div class="requirement" id="req-user-chars">
                        <i class="far fa-circle"></i>
                        <span>Letters, numbers, and underscores/dashes only</span>
                    </div>
                </div>

                <div class="input-group">
                    <input type="password" name="password" id="password" placeholder="Password" required>
                    <i class="fas fa-lock"></i>
                </div>

                <!-- Password requirements checklist -->
                <div class="password-requirements" id="passwordRequirements">
                    <div class="requirement" id="req-length">
                        <i class="far fa-circle"></i>
                        <span>Minimum 8 characters</span>
                    </div>
                    <div class="requirement" id="req-uppercase">
                        <i class="far fa-circle"></i>
                        <span>At least one uppercase letter</span>
                    </div>
                    <div class="requirement" id="req-lowercase">
                        <i class="far fa-circle"></i>
                        <span>At least one lowercase letter</span>
                    </div>
                    <div class="requirement" id="req-number">
                        <i class="far fa-circle"></i>
                        <span>At least one number</span>
                    </div>
                    <div class="requirement" id="req-special">
                        <i class="far fa-circle"></i>
                        <span>At least one special character (!@#$%^&*(),.?":{}|<>_\-)</span>
                    </div>
                </div>

                <div class="input-group">
                    <input type="password" name="confirm_password" id="confirm_password" placeholder="Confirm Password" required>
                    <i class="fas fa-lock"></i>
                </div>
                <!-- Confirm Password requirements checklist -->
                <div class="field-requirements" id="confirmRequirements">
                    <div class="requirement" id="req-confirm-match">
                        <i class="far fa-circle"></i>
                        <span>Passwords must match</span>
                    </div>
                </div>

                <div class="input-group">
                    <select name="role" required>
                        <option value="" disabled selected>Select Role</option>
                        <option value="user" <?php echo (isset($_POST['role']) && $_POST['role'] === 'user') ? 'selected' : ''; ?>>User</option>
                        <option value="technician" <?php echo (isset($_POST['role']) && $_POST['role'] === 'technician') ? 'selected' : ''; ?>>Technician</option>
                        <option value="unithead" <?php echo (isset($_POST['role']) && $_POST['role'] === 'unithead') ? 'selected' : ''; ?>>Unit Head</option>
                        <option value="storekeeper" <?php echo (isset($_POST['role']) && $_POST['role'] === 'storekeeper') ? 'selected' : ''; ?>>Store Keeper</option>
                    </select>
                    <i class="fas fa-user-tag"></i>
                    <i class="fas fa-chevron-down select-arrow"></i>
                </div>

                <button type="submit" name="register" class="btn-auth">Sign Up</button>
            </form>

            <p class="signup-prompt">
                Already have an account?
                <a href="login.php">Sign in</a>
            </p>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const form = document.querySelector('form');

            // Helper to update requirement checklist items
            function updateRequirement(element, isValid) {
                const icon = element.querySelector('i');
                if (isValid) {
                    element.classList.add('valid');
                    icon.className = 'fas fa-check-circle';
                } else {
                    element.classList.remove('valid');
                    icon.className = 'far fa-circle';
                }
            }

            // --- FULL NAME VALIDATION ---
            const nameInput = document.getElementById('name');
            const nameRequirements = document.getElementById('nameRequirements');
            const reqNameLength = document.getElementById('req-name-length');
            const reqNameChars = document.getElementById('req-name-chars');

            nameInput.addEventListener('focus', () => {
                nameRequirements.style.display = 'flex';
            });
            nameInput.addEventListener('blur', () => {
                if (nameInput.value === '') nameRequirements.style.display = 'none';
            });
            nameInput.addEventListener('input', () => {
                const val = nameInput.value;
                reqNameLength.classList.remove('invalid');
                reqNameChars.classList.remove('invalid');
                updateRequirement(reqNameLength, val.length >= 3);
                updateRequirement(reqNameChars, val.length > 0 && /^[A-Za-z\s]+$/.test(val));
            });

            // --- PHONE NUMBER VALIDATION ---
            const phoneInput = document.getElementById('phone');
            const phoneRequirements = document.getElementById('phoneRequirements');
            const reqPhoneDigits = document.getElementById('req-phone-digits');
            const reqPhoneLength = document.getElementById('req-phone-length');

            phoneInput.addEventListener('focus', () => {
                phoneRequirements.style.display = 'flex';
            });
            phoneInput.addEventListener('blur', () => {
                if (phoneInput.value === '') phoneRequirements.style.display = 'none';
            });
            phoneInput.addEventListener('input', () => {
                const val = phoneInput.value;
                reqPhoneDigits.classList.remove('invalid');
                reqPhoneLength.classList.remove('invalid');
                updateRequirement(reqPhoneDigits, val.length > 0 && /^\d+$/.test(val));
                updateRequirement(reqPhoneLength, val.length === 10);
            });

            // --- USERNAME VALIDATION ---
            const usernameInput = document.getElementById('username');
            const usernameRequirements = document.getElementById('usernameRequirements');
            const reqUserLength = document.getElementById('req-user-length');
            const reqUserChars = document.getElementById('req-user-chars');

            usernameInput.addEventListener('focus', () => {
                usernameRequirements.style.display = 'flex';
            });
            usernameInput.addEventListener('blur', () => {
                if (usernameInput.value === '') usernameRequirements.style.display = 'none';
            });
            usernameInput.addEventListener('input', () => {
                const val = usernameInput.value;
                reqUserLength.classList.remove('invalid');
                reqUserChars.classList.remove('invalid');
                updateRequirement(reqUserLength, val.length >= 4);
                updateRequirement(reqUserChars, val.length > 0 && /^[A-Za-z0-9_\-]+$/.test(val));
            });

            // --- PASSWORD VALIDATION ---
            const passwordInput = document.getElementById('password');
            const passwordRequirements = document.getElementById('passwordRequirements');
            const reqLength = document.getElementById('req-length');
            const reqUppercase = document.getElementById('req-uppercase');
            const reqLowercase = document.getElementById('req-lowercase');
            const reqNumber = document.getElementById('req-number');
            const reqSpecial = document.getElementById('req-special');

            passwordInput.addEventListener('focus', () => {
                passwordRequirements.style.display = 'flex';
            });
            passwordInput.addEventListener('blur', () => {
                if (passwordInput.value === '') passwordRequirements.style.display = 'none';
            });
            passwordInput.addEventListener('input', () => {
                const val = passwordInput.value;
                reqLength.classList.remove('invalid');
                reqUppercase.classList.remove('invalid');
                reqLowercase.classList.remove('invalid');
                reqNumber.classList.remove('invalid');
                reqSpecial.classList.remove('invalid');

                updateRequirement(reqLength, val.length >= 8);
                updateRequirement(reqUppercase, /[A-Z]/.test(val));
                updateRequirement(reqLowercase, /[a-z]/.test(val));
                updateRequirement(reqNumber, /[0-9]/.test(val));
                updateRequirement(reqSpecial, /[!@#$%^&*(),.?":{}|<>_\-]/.test(val));
            });

            // --- CONFIRM PASSWORD VALIDATION ---
            const confirmInput = document.getElementById('confirm_password');
            const confirmRequirements = document.getElementById('confirmRequirements');
            const reqConfirmMatch = document.getElementById('req-confirm-match');

            confirmInput.addEventListener('focus', () => {
                confirmRequirements.style.display = 'flex';
            });
            confirmInput.addEventListener('blur', () => {
                if (confirmInput.value === '') confirmRequirements.style.display = 'none';
            });
            
            const validateConfirmPassword = () => {
                const val = confirmInput.value;
                reqConfirmMatch.classList.remove('invalid');
                updateRequirement(reqConfirmMatch, val.length > 0 && val === passwordInput.value);
            };
            
            confirmInput.addEventListener('input', validateConfirmPassword);
            passwordInput.addEventListener('input', () => {
                if (confirmInput.value !== '') {
                    validateConfirmPassword();
                }
            });

            // --- FORM SUBMIT INTERCEPTION ---
            form.addEventListener('submit', function (e) {
                let hasError = false;

                // Validate Name
                const nameVal = nameInput.value;
                const nameLenOk = nameVal.length >= 3;
                const nameCharsOk = /^[A-Za-z\s]+$/.test(nameVal);
                if (!nameLenOk || !nameCharsOk) {
                    nameRequirements.style.display = 'flex';
                    if (!nameLenOk) reqNameLength.classList.add('invalid');
                    if (!nameCharsOk) reqNameChars.classList.add('invalid');
                    hasError = true;
                }

                // Validate Phone
                const phoneVal = phoneInput.value;
                const phoneDigitsOk = /^\d+$/.test(phoneVal);
                const phoneLenOk = phoneVal.length === 10;
                if (!phoneDigitsOk || !phoneLenOk) {
                    phoneRequirements.style.display = 'flex';
                    if (!phoneDigitsOk) reqPhoneDigits.classList.add('invalid');
                    if (!phoneLenOk) reqPhoneLength.classList.add('invalid');
                    hasError = true;
                }

                // Validate Username
                const userVal = usernameInput.value;
                const userLenOk = userVal.length >= 4;
                const userCharsOk = /^[A-Za-z0-9_\-]+$/.test(userVal);
                if (!userLenOk || !userCharsOk) {
                    usernameRequirements.style.display = 'flex';
                    if (!userLenOk) reqUserLength.classList.add('invalid');
                    if (!userCharsOk) reqUserChars.classList.add('invalid');
                    hasError = true;
                }

                // Validate Password
                const passVal = passwordInput.value;
                const isLengthValid = passVal.length >= 8;
                const isUppercaseValid = /[A-Z]/.test(passVal);
                const isLowercaseValid = /[a-z]/.test(passVal);
                const isNumberValid = /[0-9]/.test(passVal);
                const isSpecialValid = /[!@#$%^&*(),.?":{}|<>_\-]/.test(passVal);
                if (!(isLengthValid && isUppercaseValid && isLowercaseValid && isNumberValid && isSpecialValid)) {
                    passwordRequirements.style.display = 'flex';
                    if (!isLengthValid) reqLength.classList.add('invalid');
                    if (!isUppercaseValid) reqUppercase.classList.add('invalid');
                    if (!isLowercaseValid) reqLowercase.classList.add('invalid');
                    if (!isNumberValid) reqNumber.classList.add('invalid');
                    if (!isSpecialValid) reqSpecial.classList.add('invalid');
                    hasError = true;
                }

                // Validate Confirm Password
                const confirmVal = confirmInput.value;
                const confirmOk = confirmVal.length > 0 && confirmVal === passVal;
                if (!confirmOk) {
                    confirmRequirements.style.display = 'flex';
                    reqConfirmMatch.classList.add('invalid');
                    hasError = true;
                }

                if (hasError) {
                    e.preventDefault();
                    
                    // Focus on the first invalid field
                    if (!nameLenOk || !nameCharsOk) {
                        nameInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        nameInput.focus();
                    } else if (!phoneDigitsOk || !phoneLenOk) {
                        phoneInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        phoneInput.focus();
                    } else if (!userLenOk || !userCharsOk) {
                        usernameInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        usernameInput.focus();
                    } else if (!(isLengthValid && isUppercaseValid && isLowercaseValid && isNumberValid && isSpecialValid)) {
                        passwordInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        passwordInput.focus();
                    } else if (!confirmOk) {
                        confirmInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        confirmInput.focus();
                    }
                }
            });
        });
    </script>
</body>

</html>