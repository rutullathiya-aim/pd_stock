<?php
require_once __DIR__ . '/helpers/Auth.php';

// If already logged in, redirect to dashboard
if (Auth::check()) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf($_POST['csrf_token'] ?? '')) {
        die("Invalid security token. Please refresh and try again.");
    }

    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (Auth::login($username, $password)) {
        header('Location: index.php');
        exit;
    } else {
        $error = 'Access Denied. Invalid username or password.';
    }
}

$csrfToken = Auth::csrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Sync Command Center</title>
    <link rel="stylesheet" href="assets/css/global.css">
</head>
<body class="centered-layout bg-gradient">

    <div class="login-card">
        <div class="header">
            <img src="assets/images/Shoptrophies_Canadian_Logo.png" alt="ShopTrophies Logo" style="max-width: 80%; max-height: 50px; display: block; margin: 0 auto; margin-bottom: 20px;">
            <p>Protected Administration Access</p>
        </div>

        <?php if ($error): ?>
            <div class="error-msg"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            
            <div class="form-group">
                <label for="username">Username</label>
                <div class="input-wrapper">
                    <input type="text" id="username" name="username" required autofocus placeholder="Admin identity">
                </div>
            </div>

            <div class="form-group" style="margin-bottom: 32px;">
                <label for="password">Password</label>
                <div class="input-wrapper">
                    <input type="password" id="password" name="password" required placeholder="••••••••">
                </div>
            </div>

            <button type="submit" class="login-btn">Authenticate Access</button>
        </form>
    </div>

</body>
</html>
