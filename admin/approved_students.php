<?php
require_once __DIR__ . '/../config/db.php';

$pdo = db();

/*
|--------------------------------------------------------------------------
| Fetch Approved Students
|--------------------------------------------------------------------------
*/

$sql = "
SELECT
    a.id,

    CONCAT(
        u.first_name,
        ' ',
        IF(
            u.middle_name IS NULL OR u.middle_name='',
            '',
            CONCAT(LEFT(u.middle_name,1),'. ')
        ),
        u.last_name
    ) AS student_name,
    p.program_name,
    a.institution AS institution_name,
    a.mobility_type,
    a.semester,
    a.school_year,
    a.approval_date,
    a.status

FROM applications a
LEFT JOIN users u
    ON a.applicant_id = u.id
LEFT JOIN programs p
    ON a.program_id = p.id
WHERE a.status='approved'
ORDER BY a.approval_date DESC
";

$students = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>

<link rel="stylesheet" href="../asset/css/approved_students.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

<div class="content">

    <div class="page-header">
        <h2>Approved Students</h2>
        <p>
            List of students approved for international mobility programs.
        </p>
    </div>

    <div class="toolbar">

        <div class="search-wrap">
            <i class="fa fa-search"></i>
            <input
                type="text"
                id="searchInput"
                placeholder="Search student..."
            >
        </div>

    </div>

    <div class="table-card">

        <table>

            <thead>

                <tr>

                    <th>Student</th>
                    <th>Program</th>
                    <th>Partner Institution</th>
                    <th>Mobility</th>
                    <th>Semester</th>
                    <th>School Year</th>
                    <th>Approved Date</th>
                    <th>Status</th>

                </tr>

            </thead>

            <tbody id="tableBody">

            <?php if($students): ?>

                <?php foreach($students as $row): ?>

                <tr
                    data-search="<?= strtolower(
                        $row['student_name'].
                        ' '.$row['program_name'].
                        ' '.$row['institution_name']
                    ) ?>"
                >

                    <td><?= h($row['student_name']) ?></td>

                    <td><?= h($row['program_name']) ?></td>

                    <td><?= h($row['institution_name']) ?></td>

                    <td><?= h($row['mobility_type']) ?></td>

                    <td><?= h($row['semester']) ?></td>

                    <td><?= h($row['school_year']) ?></td>

                    <td>
                        <?= !empty($row['approval_date'])
                            ? date("M d, Y", strtotime($row['approval_date']))
                            : '-' ?>
                    </td>

                    <td>
                        <span class="status-badge status-<?= strtolower($row['status']) ?>">
                            <?= ucfirst($row['status']) ?>
                        </span>
                    </td>

                </tr>

                <?php endforeach; ?>

            <?php else: ?>

                <tr>

                    <td colspan="8" class="empty-row">
                        No approved students found.
                    </td>

                </tr>

            <?php endif; ?>

            </tbody>

        </table>

        <div class="pagination">

            Showing <?= count($students) ?> approved student(s)

        </div>

    </div>

</div>

<script>

document.getElementById("searchInput").addEventListener("input",function(){

    const keyword=this.value.toLowerCase();

    document.querySelectorAll("#tableBody tr").forEach(function(row){

        const text=row.dataset.search || "";

        row.style.display=text.includes(keyword) ? "" : "none";

    });

});

</script>