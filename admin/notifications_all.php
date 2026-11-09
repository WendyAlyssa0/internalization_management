<?php
require_once __DIR__ . '/../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


/* =========================================================
   ADMIN AUTHORIZATION
========================================================= */

if (!isset($_SESSION['user_id'])) {
    header('Location: ../public/login.php');
    exit();
}

if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Access denied.');
}


$pdo = db();

$adminId = (int) $_SESSION['user_id'];


/* =========================================================
   FETCH ADMIN NOTIFICATIONS
========================================================= */

$stmt = $pdo->prepare("
    SELECT
        id,
        type,
        title,
        message,
        is_read,
        created_at
    FROM notifications
    WHERE user_id = ?
    ORDER BY created_at DESC
");

$stmt->execute([$adminId]);

$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);


/* =========================================================
   ESCAPE HELPER
========================================================= */

if (!function_exists('h')) {

    function h($value): string
    {
        return htmlspecialchars(
            (string) $value,
            ENT_QUOTES,
            'UTF-8'
        );
    }
}


/* =========================================================
   COUNTS
========================================================= */

$totalCount  = count($notifications);
$unreadCount = 0;

$typeCounts = [
    'application'  => 0,
    'approval'     => 0,
    'document'     => 0,
    'reminder'     => 0,
    'announcement' => 0
];


foreach ($notifications as $notification) {

    if (!(int) $notification['is_read']) {
        $unreadCount++;
    }

    $type = $notification['type'];

    if (isset($typeCounts[$type])) {
        $typeCounts[$type]++;
    }
}


/* =========================================================
   ADMIN NOTIFICATION TYPE MAP
========================================================= */

$typeMap = [

    'application' => [
        'icon'  => 'ti-file-description',
        'label' => 'Applications',
        'mod'   => ''
    ],

    'approval' => [
        'icon'  => 'ti-circle-check',
        'label' => 'Approvals',
        'mod'   => 'approval'
    ],

    'document' => [
        'icon'  => 'ti-file-alert',
        'label' => 'Documents',
        'mod'   => 'document'
    ],

    'reminder' => [
        'icon'  => 'ti-alarm',
        'label' => 'Reminders',
        'mod'   => 'reminder'
    ],

    'announcement' => [
        'icon'  => 'ti-speakerphone',
        'label' => 'Announcements',
        'mod'   => 'announcement'
    ],

    'default' => [
        'icon'  => 'ti-bell',
        'label' => 'Notification',
        'mod'   => ''
    ]
];

?>

<link rel="stylesheet" href="../asset/css/notifications.css">

<link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
>

<link
    rel="stylesheet"
    href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css"
>


<div class="content">

    <div class="notification-card">


        <!-- =================================================
             HEADER
        ================================================== -->

        <div class="notification-header">

            <div class="notification-header-left">

                <div class="notification-header-icon">
                    <i class="ti ti-bell"></i>
                </div>

                <div>

                    <h3>
                        Admin Notifications
                    </h3>

                    <p>
                        Review applications, documents,
                        approvals, reminders, and system updates.
                    </p>

                </div>

            </div>


            <button
                class="btn-mark-all"
                id="markAllBtn"
                onclick="markAllRead()"
                <?= $unreadCount === 0 ? 'disabled' : '' ?>
            >

                <i class="ti ti-checks"></i>

                Mark all as read

            </button>

        </div>


        <!-- =================================================
             FILTERS
        ================================================== -->

        <div class="notification-filters">


            <!-- ALL -->

            <button
                class="active"
                data-filter="all"
                onclick="filterNotifications('all', this)"
            >

                <i class="ti ti-layout-grid"></i>

                All

                <span class="filter-count">
                    <?= $totalCount ?>
                </span>

            </button>


            <!-- UNREAD -->

            <button
                data-filter="unread"
                onclick="filterNotifications('unread', this)"
            >

                <i class="ti ti-mail"></i>

                Unread

                <span class="filter-count">
                    <?= $unreadCount ?>
                </span>

            </button>


            <!-- APPLICATIONS -->

            <button
                data-filter="application"
                onclick="filterNotifications('application', this)"
            >

                <i class="ti ti-file-description"></i>

                Applications

                <span class="filter-count">
                    <?= $typeCounts['application'] ?>
                </span>

            </button>


            <!-- APPROVALS -->

            <button
                data-filter="approval"
                onclick="filterNotifications('approval', this)"
            >

                <i class="ti ti-circle-check"></i>

                Approvals

                <span class="filter-count">
                    <?= $typeCounts['approval'] ?>
                </span>

            </button>


            <!-- DOCUMENTS -->

            <button
                data-filter="document"
                onclick="filterNotifications('document', this)"
            >

                <i class="ti ti-file-alert"></i>

                Documents

                <span class="filter-count">
                    <?= $typeCounts['document'] ?>
                </span>

            </button>


            <!-- REMINDERS -->

            <button
                data-filter="reminder"
                onclick="filterNotifications('reminder', this)"
            >

                <i class="ti ti-alarm"></i>

                Reminders

                <span class="filter-count">
                    <?= $typeCounts['reminder'] ?>
                </span>

            </button>


            <!-- ANNOUNCEMENTS -->

            <button
                data-filter="announcement"
                onclick="filterNotifications('announcement', this)"
            >

                <i class="ti ti-speakerphone"></i>

                Announcements

                <span class="filter-count">
                    <?= $typeCounts['announcement'] ?>
                </span>

            </button>

        </div>


        <!-- =================================================
             TOOLBAR
        ================================================== -->

        <div class="notification-toolbar">


            <button
                class="notification-sort"
                id="sortBtn"
                onclick="toggleSort()"
            >

                <i class="ti ti-arrows-sort"></i>

                <span id="sortLabel">
                    Latest first
                </span>

            </button>


            <div class="notification-search">

                <i class="ti ti-search"></i>

                <input
                    type="text"
                    id="notificationSearch"
                    placeholder="Search admin notifications..."
                    oninput="searchNotifications()"
                >

            </div>

        </div>


        <!-- =================================================
             NOTIFICATION LIST
        ================================================== -->

        <div
            class="notification-list"
            id="notificationList"
        >


            <?php if (!empty($notifications)): ?>


                <?php foreach ($notifications as $notification): ?>

                    <?php

                    $type =
                        $notification['type'];

                    $map =
                        $typeMap[$type]
                        ?? $typeMap['default'];

                    $icon =
                        $map['icon'];

                    $mod =
                        $map['mod'];

                    $tagLabel =
                        $map['label'];

                    $isUnread =
                        !(int) $notification['is_read'];

                    $timestamp =
                        strtotime(
                            $notification['created_at']
                        );

                    ?>


                    <div
                        class="
                            notification-item
                            <?= $isUnread ? 'unread' : '' ?>
                        "

                        data-id="<?= (int) $notification['id'] ?>"

                        data-type="<?= h($type) ?>"

                        data-read="<?= $isUnread ? 'unread' : 'read' ?>"

                        data-time="<?= $timestamp ?>"

                        data-search="<?= h(
                            strtolower(
                                $notification['title']
                                . ' '
                                . $notification['message']
                                . ' '
                                . $tagLabel
                            )
                        ) ?>"

                        onclick="markRead(this)"
                    >


                        <!-- ICON -->

                        <div
                            class="
                                notification-icon
                                <?= $mod
                                    ? 'icon-' . h($mod)
                                    : ''
                                ?>
                            "
                        >

                            <i class="ti <?= h($icon) ?>"></i>

                        </div>


                        <!-- CONTENT -->

                        <div class="notification-content">


                            <div class="notification-title">

                                <?= h(
                                    $notification['title']
                                ) ?>

                            </div>


                            <div class="notification-message">

                                <?= h(
                                    $notification['message']
                                ) ?>

                            </div>


                            <div
                                class="
                                    notification-tag
                                    <?= $mod
                                        ? 'tag-' . h($mod)
                                        : ''
                                    ?>
                                "
                            >

                                <?= h($tagLabel) ?>

                            </div>

                        </div>


                        <!-- META -->

                        <div class="notification-meta">

                            <span class="notification-time">

                                <?= date(
                                    'M d, Y g:i A',
                                    $timestamp
                                ) ?>

                            </span>


                            <span
                                class="notification-status-dot"
                            ></span>

                        </div>


                    </div>


                <?php endforeach; ?>


            <?php else: ?>


                <div class="notification-empty">

                    <div class="notification-empty-icon">

                        <i class="ti ti-bell-off"></i>

                    </div>


                    <h3>
                        No admin notifications
                    </h3>


                    <p>
                        New applications, documents,
                        approvals, and system alerts
                        will appear here.
                    </p>

                </div>


            <?php endif; ?>


        </div>


        <!-- =================================================
             NO SEARCH RESULTS
        ================================================== -->

        <div
            class="notification-empty"
            id="noSearchResults"
            style="display:none;"
        >

            <div class="notification-empty-icon">

                <i class="ti ti-search-off"></i>

            </div>


            <h3>
                No notifications found
            </h3>


            <p>
                Try a different search term or filter.
            </p>

        </div>


        <!-- =================================================
             FOOTER
        ================================================== -->

        <div class="notification-footer">

            <span>

                Showing

                <strong id="visibleCount">
                    <?= $totalCount ?>
                </strong>

                notifications

            </span>

        </div>


    </div>

</div>
    <script src="../asset/js/notifications_all.js"></script>
