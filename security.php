<?php

// Function to sanitize user input and prevent XSS attacks
function clean_input($data) {
    if (is_array($data)) {
        foreach ($data as $key => $value) {
            $data[$key] = clean_input($value);
        }
        return $data;
    }
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

// Generate CSRF Token
function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Verify CSRF Token
function verify_csrf_token($token) {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

// Output CSRF Field
function csrf_field() {
    $token = generate_csrf_token();
    echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

// Log System Activity
function log_system_activity($conn, $user_id, $action, $details = null) {
    if (!$conn) {
        return false;
    }
    $userIdVal = ($user_id === null) ? 0 : $user_id;
    if ($conn instanceof PDO) {
        try {
            $stmt = $conn->prepare("INSERT INTO system_logs (user_id, action, details) VALUES (?, ?, ?)");
            $stmt->execute([$userIdVal, $action, $details]);
            return true;
        } catch (PDOException $e) {
            return false;
        }
    } else {
        $sql = "INSERT INTO system_logs (user_id, action, details) VALUES (?, ?, ?)";
        $params = array($userIdVal, $action, $details);
        $stmt = sqlsrv_query($conn, $sql, $params);
        if ($stmt === false) {
            return false;
        }
        sqlsrv_free_stmt($stmt);
        return true;
    }
}
?>
