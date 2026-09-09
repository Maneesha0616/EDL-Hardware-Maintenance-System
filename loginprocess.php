<?php
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
require_once __DIR__ . '/security.php';
session_start();
include "db.php";

if (isset($_POST['login'])) {

    $username = trim($_POST['username']);
    $password = $_POST['password'];


    $sql = "SELECT * FROM users WHERE username = ?";
    $params = [$username];
    // Use sqlsrv_query for prepared statements with parameters
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        $error = sqlsrv_errors();
        error_log("Login query error: " . $error[0]['message']);
        die("Database error.");
    }
    $user = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);



    if ($user) {

        $validPassword = false;

        if (password_verify($password, $user['password'])) {
            $validPassword = true;

        } elseif ($password === $user['password']) {
            $validPassword = true;

            $newHash = password_hash($password, PASSWORD_DEFAULT);

            $updateSql = "UPDATE users SET password = ? WHERE id = ?";
            $updateParams = array($newHash, $user['id']);
            // Use sqlsrv_query for the update
            $stmt = sqlsrv_query($conn, $updateSql, $updateParams);
            if ($stmt === false) {
                $error = sqlsrv_errors();
                error_log("Password update error: " . $error[0]['message']);
                // Continue without stopping the login flow
            }
        }

        if ($validPassword) {

            session_regenerate_id(true);

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = $user['role'];

            log_system_activity($conn, $user['id'], 'User Login', 'User logged in as ' . $user['role']);

            switch ($user['role']) {
                case 'admin':
                    header("Location: Admin/Admindash.php");
                    break;
                case 'unithead':
                    header("Location: unithead/unitheaddash.php");
                    break;
                case 'technician':
                    header("Location: technician/techniciandash.php");
                    break;
                case 'storekeeper':
                    header("Location: storekeeper/storekeeperdash.php");
                    break;
                default:
                    header("Location: user/userdash.php");
            }
            exit;

        } else {
            $_SESSION['error'] = "Invalid password!";
            log_system_activity($conn, $user['id'], 'Failed Login', 'Wrong password');
        }

    } else {
        $_SESSION['error'] = "User not found!";
    }

    header("Location: login.php");
    exit;
}

// session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
// require_once __DIR__ . '/security.php';

// session_start();
// include "db.php";

// if (isset($_POST['login'])) {

//     $username = trim($_POST['username']);
//     $password = $_POST['password'];

//     $sql = "SELECT * FROM users WHERE username = ?";
//     $stmt = $conn->prepare($sql);
//     $stmt->bind_param("s", $username);
//     $stmt->execute();
//     $result = $stmt->get_result();

//     if ($user = $result->fetch_assoc()) {

//         //verify password
//         $validPassword = false;
//         if (password_verify($password, $user['password'])) {
//             $validPassword = true;
//         } elseif ($password === $user['password']) {
//             // Legacy plain-text match. Upgrade to hash.
//             $validPassword = true;
//             $newHash = password_hash($password, PASSWORD_DEFAULT);
//             $updateStmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
//             $updateStmt->bind_param("si", $newHash, $user['id']);
//             $updateStmt->execute();
//         }

//         if ($validPassword) {
//             session_regenerate_id(true);

//             $_SESSION['user_id'] = $user['id'];
//             $_SESSION['role'] = $user['role'];

//             // Log successful login
//             log_system_activity($conn, $user['id'], 'User Login', 'User successfully logged in as ' . $user['role']);

//             switch ($user['role']) {
//                 case 'admin':
//                     header("Location: Admin/Admindash.php");
//                     break;
//                 case 'unithead':
//                     header("Location: unithead/unitheaddash.php");
//                     break;
//                 case 'technician':
//                     header("Location: technician/techniciandash.php");
//                     break;
//                 case 'storekeeper':
//                     header("Location: storekeeper/storekeeperdash.php");
//                     break;
//                 default:
//                     header("Location: user/userdash.php");
//             }
//             exit;

//         } else {
//             $_SESSION['error'] = "Invalid password!";
//             // Log failed login (invalid password)
//             log_system_activity($conn, $user['id'], 'Failed Login Attempt', 'Invalid password entered');
//         }

//     } else {
//         $_SESSION['error'] = "User not found!";
//     }

//     header("Location: login.php");
//     exit;
// }
?>