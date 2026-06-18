<?php
require_once __DIR__ . '/../php/auth/session.php';
require_once __DIR__ . '/../php/conn/db.php';
require_once __DIR__ . '/../php/util/notification_helper.php';

$pdoHeader = db();

$headerUserId = (int)($_SESSION['user_id'] ?? 0);
$headerRole = strtolower(trim(currentUserRole()));

$notifications = getUserNotifications($pdoHeader, $headerUserId, $headerRole);
$notificationCount = count($notifications);
$appVersion = 'v2.7.7';
$appBuild = '20260618.004';
?>

<nav>
    <div class="titleNav">
        <button type="button" class="menu-toggle" id="Humberger" aria-label="Open navigation" aria-expanded="false">
            <i class="fa-solid fa-bars"></i>
        </button>

        <a href="index.php" class="tilteLink">
            <span class="title">Kiss Web</span>
        </a>
    </div>

    <div class="divpageName">
        <span id="pageName">
            <?= htmlspecialchars($title ?? 'KISS-Web', ENT_QUOTES, 'UTF-8') ?>
        </span>

        <div class="notification-wrapper">
            <button type="button" id="notifBtn" class="notif-btn">
                <i class="fa-solid fa-bell"></i>

                <span
                    class="notif-count"
                    id="notifCount"
                    style="<?= ($notificationCount ?? 0) > 0 ? '' : 'display:none;' ?>"
                >
                    <?= (int)$notificationCount ?>
                </span>
            </button>

            <div id="notifDropdown" class="notif-dropdown">
                <h4>Notifications</h4>

                <?php if (!empty($notifications)): ?>
                    <?php foreach ($notifications as $notif): ?>
                        <a
                            href="<?= htmlspecialchars($notif['link'], ENT_QUOTES, 'UTF-8') ?>"
                            class="notif-item <?= htmlspecialchars($notif['type'], ENT_QUOTES, 'UTF-8') ?>"
                            data-notif-id="<?= (int)$notif['id'] ?>"
                        >
                            <strong>
                                <?= htmlspecialchars($notif['title'], ENT_QUOTES, 'UTF-8') ?>
                            </strong>

                            <span>
                                <?= htmlspecialchars($notif['message'], ENT_QUOTES, 'UTF-8') ?>
                            </span>

                            <small>
                                <?= htmlspecialchars($notif['created_at'], ENT_QUOTES, 'UTF-8') ?>
                            </small>
                        </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="notif-empty">No notifications</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>

<!-- Side nav / user / version -->
<div class="navLinksContainer">

    <div class="user">
        <span class="userGreeting">Hi!</span>

        <span class="userName">
            <?= htmlspecialchars($_SESSION['name'] ?? 'User', ENT_QUOTES, 'UTF-8') ?>
        </span>
    </div>

    <?php
    $role = strtolower(trim(currentUserRole()));
    ?>

    <div class="navLinks">

        <a class="nav-link <?= ($activePage ?? '') === 'dashboard' || ($title ?? '') === 'Dashboard' ? 'active' : '' ?>" href="index.php">
            Dashboard
        </a>

        <a class="nav-link <?= ($activePage ?? '') === 'location' || ($title ?? '') === 'Product Locations' ? 'active' : '' ?>" href="Location.php">
            Location
        </a>

        <a class="nav-link <?= ($activePage ?? '') === 'Products' || ($title ?? '') === 'Products' ? 'active' : '' ?>" href="Products.php">
           SKU Summary
        </a>

        <?php if (in_array($role, ['admin', 'outwards'])): ?>
            <a class="nav-link <?= in_array(($activePage ?? ''), ['orders', 'orders_list'], true) ? 'active' : '' ?>" href="orders_list.php">
                Orders
            </a>

            <!--<a class="nav-link" href="#">Reports</a>-->
        <?php endif; ?>

    </div>

    <div class="utilities">
        <span class="verNumber">
            <?= htmlspecialchars($appVersion . ' | Build ' . $appBuild, ENT_QUOTES, 'UTF-8') ?>
        </span>

        <a href="logout.php" class="signOut" aria-label="Sign out" title="Sign out">
            <i class="fa fa-sign-out" aria-hidden="true"></i>
        </a>
    </div>
</div>

<div class="overlay"></div>
