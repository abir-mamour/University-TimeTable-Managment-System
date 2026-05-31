<?php
require_once 'config/app.php';
require_once 'includes/auth.php';

if (isLoggedIn()) {
    header("Location: /TimeTable/pages/" . $_SESSION['role'] . "/dashboard.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Timetable Management System</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="/TimeTable/assets/css/global.css">
    <link rel="stylesheet" href="/TimeTable/assets/css/login.css">
</head>
<body>

<div class="login-wrapper">

    <!-- ─── LEFT PANEL ─────────────────────── -->
    <div class="left-panel">
        <div class="left-content">
            <div class="brand-icon">
                <div class="icon-calendar">
                    <i class="fa-solid fa-calendar-days"></i>
                    <div class="icon-clock-badge">
                        <i class="fa-solid fa-clock"></i>
                    </div>
                </div>
            </div>
            <h1 class="brand-title">
                Timetable<br>Management System
            </h1>
            <p class="brand-subtitle">
                Organize. Schedule. Succeed.<br>
                Smart timetabling for everyone.
            </p>
        </div>
        <div class="building-illustration">
            <i class="fa-solid fa-building-columns building-icon"></i>
        </div>
    </div>

    <!-- ─── RIGHT PANEL ────────────────────── -->
    <div class="right-panel">
        <div class="form-container">
            <h2 class="form-title">Login</h2>

            <div class="alert alert-error" id="errorAlert" style="display:none;">
                <i class="fa-solid fa-circle-exclamation"></i>
                <span id="errorText"></span>
            </div>

            <form id="loginForm" novalidate>
                <div class="form-group">
                    <label class="form-label">Registration Number</label>
                    <div class="input-wrapper">
                        <i class="fa-solid fa-id-card input-icon"></i>
                        <input
                            type="text"
                            id="reg_number"
                            name="reg_number"
                            class="form-input"
                            placeholder="Enter registration number"
                            autocomplete="off"
                            required
                        />
                    </div>
                    <span class="field-error" id="regError"></span>
                </div>

                <div class="form-group">
                    <label class="form-label">Password</label>
                    <div class="input-wrapper">
                        <i class="fa-solid fa-lock input-icon"></i>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="form-input"
                            placeholder="Enter password"
                            required
                        />
                        <button
                            type="button"
                            class="toggle-password"
                            id="togglePassword"
                        >
                            <i class="fa-regular fa-eye" id="eyeIcon"></i>
                        </button>
                    </div>
                    <span class="field-error" id="passError"></span>
                </div>

                <button type="submit" class="btn-login" id="loginBtn">
                    <span id="btnText">Login</span>
                    <span id="btnLoader" style="display:none;">
                        <i class="fa-solid fa-spinner fa-spin"></i>
                        Logging in...
                    </span>
                </button>
            </form>

            <p class="contact-text">
                Don't have an account?
                <a href="mailto:admin@school.dz" class="contact-link">
                    Contact administrator
                </a>
            </p>
        </div>
    </div>
</div>

<script src="/TimeTable/assets/js/api.js"></script>
<script src="/TimeTable/assets/js/main.js"></script>
</body>
</html>