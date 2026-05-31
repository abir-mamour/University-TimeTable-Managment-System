<?php
// ─── This file is included BY all pages ───────
// ─── $user, $unreadCount must be set before ───
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?? 'Timetable System' ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap"
          rel="stylesheet">
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="/TimeTable/assets/css/global.css?v=<?= time() ?>">
    <link rel="stylesheet" href="/TimeTable/assets/css/dashboard.css?v=<?= time() ?>">
    <?php if (isset($extraCss)): ?>
        <link rel="stylesheet"
              href="/TimeTable/assets/css/<?= $extraCss ?>">
    <?php endif; ?>

    <style>
        /* ─── Topbar Dropdown ─────────────────── */
        .user-dropdown-wrapper {
            position: relative;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            padding: 8px 12px;
            border-radius: var(--radius-md);
            transition: var(--transition);
            user-select: none;
        }

        .user-info:hover {
            background: var(--color-sage);
        }

        .user-dropdown {
            display: none;
            position: absolute;
            top: calc(100% + 8px);
            right: 0;
            background: var(--color-white);
            border: 1px solid var(--color-border);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-md);
            min-width: 200px;
            z-index: 200;
            overflow: hidden;
        }

        .user-dropdown.open {
            display: block;
        }

        .dropdown-header {
            padding: 14px 16px;
            border-bottom: 1px solid var(--color-border);
            background: var(--color-cream);
        }

        .dropdown-header .d-name {
            font-size: 14px;
            font-weight: 700;
            color: var(--color-text-dark);
            margin-bottom: 2px;
        }

        .dropdown-header .d-role {
            font-size: 12px;
            color: var(--color-text-light);
        }

        .dropdown-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            font-size: 14px;
            font-weight: 500;
            color: var(--color-text-dark);
            text-decoration: none;
            transition: var(--transition);
            cursor: pointer;
            border: none;
            background: none;
            width: 100%;
            font-family: var(--font-main);
        }

        .dropdown-item:hover {
            background: var(--color-sage);
            color: var(--color-text-dark);
        }

        .dropdown-item i {
            font-size: 14px;
            width: 18px;
            text-align: center;
            color: var(--color-text-light);
        }

        .dropdown-item.logout {
            color: var(--color-rose-dark);
            border-top: 1px solid var(--color-border);
        }

        .dropdown-item.logout i {
            color: var(--color-rose-dark);
        }

        .dropdown-item.logout:hover {
            background: var(--color-rose-light);
        }

        .chevron-icon {
            font-size: 12px;
            color: var(--color-text-light);
            transition: transform 0.25s ease;
        }

        .chevron-icon.rotated {
            transform: rotate(180deg);
        }
    </style>
</head>
<body>
<div class="layout">

    <!-- ─── SIDEBAR ──────────────────────────── -->
    <aside class="sidebar">

        <div class="sidebar-header">
            <div class="sidebar-logo">
                <i class="fa-solid fa-calendar-check"></i>
                <span>Timetable System</span>
            </div>
        </div>

        <nav class="sidebar-nav">

            <?php if ($user['role'] === 'student'): ?>
            <!-- ─── STUDENT MENU ─────────────── -->

                <a href="/TimeTable/pages/student/dashboard.php"
                   class="nav-item <?= ($activePage==='dashboard') ? 'active':'' ?>">
                    <i class="fa-solid fa-house"></i>
                    <span>Dashboard</span>
                </a>

                <a href="/TimeTable/pages/student/timetable.php"
                   class="nav-item <?= ($activePage==='timetable') ? 'active':'' ?>">
                    <i class="fa-solid fa-calendar-days"></i>
                    <span>My Timetable</span>
                </a>

                <a href="/TimeTable/pages/student/notifications.php"
                   class="nav-item <?= ($activePage==='notifications') ? 'active':'' ?>">
                    <i class="fa-solid fa-bell"></i>
                    <span>Notifications</span>
                    <?php if (!empty($unreadCount) && $unreadCount > 0): ?>
                        <span class="nav-badge"><?= $unreadCount ?></span>
                    <?php endif; ?>
                </a>

                <a href="/TimeTable/pages/student/group_switch.php"
                    class="nav-item <?= ($activePage==='group_switch') ? 'active':'' ?>">
                    <i class="fa-solid fa-right-left"></i>
                    <span>Switch Group</span>
                </a>

            <?php elseif ($user['role'] === 'professor'): ?>
            <!-- ─── PROFESSOR MENU ───────────── -->

                <a href="/TimeTable/pages/professor/dashboard.php"
                   class="nav-item <?= ($activePage==='dashboard') ? 'active':'' ?>">
                    <i class="fa-solid fa-house"></i>
                    <span>Dashboard</span>
                </a>

                <a href="/TimeTable/pages/professor/schedule.php"
                   class="nav-item <?= ($activePage==='schedule') ? 'active':'' ?>">
                    <i class="fa-solid fa-calendar-days"></i>
                    <span>My Schedule</span>
                </a>

                <a href="/TimeTable/pages/professor/availability.php"
                   class="nav-item <?= ($activePage==='availability') ? 'active':'' ?>">
                    <i class="fa-solid fa-calendar-check"></i>
                    <span>Set Availability</span>
                </a>

                <a href="/TimeTable/pages/professor/requests.php"
                   class="nav-item <?= ($activePage==='requests') ? 'active':'' ?>">
                    <i class="fa-solid fa-paper-plane"></i>
                    <span>Requests</span>
                    <?php if (!empty($pendingRequests) && $pendingRequests > 0): ?>
                        <span class="nav-badge"><?= $pendingRequests ?></span>
                    <?php endif; ?>
                </a>

                <a href="/TimeTable/pages/professor/notifications.php"
                   class="nav-item <?= ($activePage==='notifications') ? 'active':'' ?>">
                    <i class="fa-solid fa-bell"></i>
                    <span>Notifications</span>
                    <?php if (!empty($unreadCount) && $unreadCount > 0): ?>
                        <span class="nav-badge"><?= $unreadCount ?></span>
                    <?php endif; ?>
                </a>

                <a href="/TimeTable/pages/professor/groups.php"
                   class="nav-item <?= ($activePage==='groups') ? 'active':'' ?>">
                    <i class="fa-solid fa-users"></i>
                    <span>Student Groups</span>
                </a>

            <?php elseif ($user['role'] === 'admin'): ?>
            <!-- ─── ADMIN MENU ───────────────── -->

                <a href="/TimeTable/pages/admin/dashboard.php"
                   class="nav-item <?= ($activePage==='dashboard') ? 'active':'' ?>">
                    <i class="fa-solid fa-house"></i>
                    <span>Dashboard</span>
                </a>

                <a href="/TimeTable/pages/admin/timetable.php"
                   class="nav-item <?= ($activePage==='timetable') ? 'active':'' ?>">
                    <i class="fa-solid fa-calendar-days"></i>
                    <span>Timetable</span>
                </a>

                <a href="/TimeTable/pages/admin/requests.php"
                   class="nav-item <?= ($activePage==='requests') ? 'active':'' ?>">
                    <i class="fa-solid fa-inbox"></i>
                    <span>Requests</span>
                    <?php if (!empty($pendingRequests) && $pendingRequests > 0): ?>
                        <span class="nav-badge"><?= $pendingRequests ?></span>
                    <?php endif; ?>
                </a>

                <a href="/TimeTable/pages/admin/users.php"
                   class="nav-item <?= ($activePage==='users') ? 'active':'' ?>">
                    <i class="fa-solid fa-users"></i>
                    <span>Users</span>
                </a>

                <a href="/TimeTable/pages/admin/groups.php"
                   class="nav-item <?= ($activePage==='groups') ? 'active':'' ?>">
                    <i class="fa-solid fa-layer-group"></i>
                    <span>Groups</span>
                </a>

                <a href="/TimeTable/pages/admin/availability.php"
                   class="nav-item <?= ($activePage==='availability') ? 'active':'' ?>">
                    <i class="fa-solid fa-calendar-check"></i>
                    <span>Availability</span>
                </a>

                <a href="/TimeTable/pages/admin/rooms.php"
                   class="nav-item <?= ($activePage==='rooms') ? 'active':'' ?>">
                    <i class="fa-solid fa-door-open"></i>
                    <span>Rooms</span>
                </a>

                <a href="/TimeTable/pages/admin/subjects.php"
                   class="nav-item <?= ($activePage==='subjects') ? 'active':'' ?>">
                    <i class="fa-solid fa-book-open"></i>
                    <span>Subjects</span>
                </a>

                <a href="/TimeTable/pages/admin/departments.php"
                   class="nav-item <?= ($activePage==='departments') ? 'active':'' ?>">
                    <i class="fa-solid fa-building-columns"></i>
                    <span>Departments</span>
                </a>

                <a href="/TimeTable/pages/admin/notifications.php"
                   class="nav-item <?= ($activePage==='notifications') ? 'active':'' ?>">
                    <i class="fa-solid fa-bell"></i>
                    <span>Notifications</span>
                    <?php if (!empty($unreadCount) && $unreadCount > 0): ?>
                        <span class="nav-badge"><?= $unreadCount ?></span>
                    <?php endif; ?>
                </a>

            <?php endif; ?>

        </nav>

        <div class="sidebar-help">
            <div class="help-icon">
                <i class="fa-solid fa-circle-question"></i>
            </div>
            <div class="help-text">
                <strong>Need help?</strong>
                <p>Contact your administrator for support.</p>
            </div>
        </div>

    </aside>

    <!-- ─── MAIN WRAPPER ─────────────────────── -->
    <div class="main-wrapper">

        <!-- ─── TOPBAR ────────────────────────── -->
        <header class="topbar">
            <div class="topbar-left">
                <h1 class="page-heading">
                    <?= $pageTitle ?? 'Dashboard' ?>
                </h1>
                <p class="page-subtitle">
                    <?= $pageSubtitle ?? '' ?>
                </p>
            </div>
            <div class="topbar-right">

                <!-- ─── User Dropdown ─────────── -->
                <div class="user-dropdown-wrapper">

                    <div class="user-info" onclick="toggleDropdown()">
                        <div class="user-avatar">
                            <?php if ($user['role'] === 'student'): ?>
                                <i class="fa-solid fa-user"></i>
                            <?php elseif ($user['role'] === 'professor'): ?>
                                <i class="fa-solid fa-chalkboard-user"></i>
                            <?php else: ?>
                                <i class="fa-solid fa-user-shield"></i>
                            <?php endif; ?>
                        </div>
                        <div class="user-details">
                            <span class="user-name">
                                <?= htmlspecialchars($user['name'] ?? '') ?>
                            </span>
                            <span class="user-role">
                                <?php if ($user['role'] === 'student'): ?>
                                    <?= htmlspecialchars($studentGroup['level']      ?? '') ?>
                                    <?= htmlspecialchars($studentGroup['department'] ?? 'Student') ?>
                                <?php elseif ($user['role'] === 'professor'): ?>
                                    <?= htmlspecialchars($profInfo['department'] ?? 'Professor') ?>
                                <?php else: ?>
                                    Administrator
                                <?php endif; ?>
                            </span>
                        </div>
                        <i class="fa-solid fa-chevron-down chevron-icon" id="dropdownChevron"></i>
                    </div>

                    <!-- Dropdown Menu -->
                    <div class="user-dropdown" id="userDropdown">

                        <!-- Header -->
                        <div class="dropdown-header">
                            <div class="d-name">
                                <?= htmlspecialchars($user['name'] ?? '') ?>
                            </div>
                            <div class="d-role">
                                <?php if ($user['role'] === 'student'): ?>
                                    Student
                                <?php elseif ($user['role'] === 'professor'): ?>
                                    Professor
                                <?php else: ?>
                                    Administrator
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Profile Link -->
                        <?php
                        $profileUrl = match($user['role']) {
                            'student'   => '/TimeTable/pages/student/profile.php',
                            'professor' => '/TimeTable/pages/professor/profile.php',
                            default     => '/TimeTable/pages/admin/profile.php',
                        };
                        ?>
                        <a href="<?= $profileUrl ?>" class="dropdown-item">
                            <i class="fa-solid fa-user"></i>
                            My Profile
                        </a>

                        <!-- Logout -->
                        <a href="/TimeTable/api/auth/logout.php"
                           class="dropdown-item logout">
                            <i class="fa-solid fa-right-from-bracket"></i>
                            Logout
                        </a>

                    </div>
                </div>

            </div>
        </header>

        <!-- ─── PAGE CONTENT ──────────────────── -->
        <main class="main-content">