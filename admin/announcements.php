<?php
require_once __DIR__ . '/../config/db.php';

$pdo = db();

$announcements = $pdo->query("
SELECT
    id,
    title,
    announcement,
    publish_date,
    status
FROM announcements
ORDER BY publish_date DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>

<link rel="stylesheet" href="../asset/css/announcement.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

<div class="content">

    <div class="page-header">
        <h2>Announcement Management</h2>
        <p>Create and manage announcements for students and partner institutions.</p>
    </div>

    <div class="toolbar">

        <div class="search-box">
            <i class="fa fa-search"></i>
            <input
                type="text"
                id="searchAnnouncement"
                placeholder="Search announcement..."
            >
        </div>

        <button class="create-btn">
            <i class="fa fa-plus"></i>
            New Announcement
        </button>

    </div>

    <div class="table-card">

        <table>

            <thead>
                <tr>
                    <th>Title</th>
                    <th>Announcement</th>
                    <th>Publish Date</th>
                    <th>Status</th>
                    <th width="130">Actions</th>
                </tr>
            </thead>

            <tbody id="announcementTable">

            <?php if(count($announcements)): ?>

                <?php foreach($announcements as $row): ?>

                <tr data-search="<?= strtolower(htmlspecialchars($row['title'].' '.$row['announcement'])) ?>">

                    <td><?= htmlspecialchars($row['title']) ?></td>

                    <td>
                        <?= htmlspecialchars(substr($row['announcement'],0,100)) ?>
                        ...
                    </td>

                    <td>
                        <?= date('M d, Y',strtotime($row['publish_date'])) ?>
                    </td>

                    <td>

                        <span class="status-badge status-<?= strtolower($row['status']) ?>">
                            <?= htmlspecialchars($row['status']) ?>
                        </span>

                    </td>

                    <td>

                        <button class="action-btn edit-btn">
                            <i class="fa fa-pen"></i>
                        </button>

                        <button class="action-btn delete-btn">
                            <i class="fa fa-trash"></i>
                        </button>

                    </td>

                </tr>

                <?php endforeach; ?>

            <?php else: ?>

                <tr>
                    <td colspan="5" class="empty-row">
                        No announcements found.
                    </td>
                </tr>

            <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>

<script>
document.getElementById("searchAnnouncement").addEventListener("keyup",function(){

    let value=this.value.toLowerCase();

    document.querySelectorAll("#announcementTable tr").forEach(row=>{

        let text=row.dataset.search||"";

        row.style.display=text.includes(value)?"":"none";

    });

});
</script>