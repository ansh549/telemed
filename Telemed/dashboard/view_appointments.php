<?php
session_start();
require_once "../includes/db.php";
require_once "../includes/auth.php";
checkRole('admin');

$query = "SELECT a.id, u1.name AS patient, u2.name AS doctor, a.date, a.time, a.status
          FROM appointments a
          JOIN users u1 ON a.patient_id = u1.id
          JOIN users u2 ON a.doctor_id = u2.id
          ORDER BY a.date DESC";
$appointments = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
?>
<?php include "../includes/header.php"; ?>
<div class="max-w-5xl mx-auto mt-10">
    <h2 class="text-2xl font-bold mb-4">All Appointments</h2>
    <table class="min-w-full bg-white shadow-md rounded-lg">
        <thead>
            <tr class="bg-gray-200 text-left">
                <th class="px-4 py-2">Patient</th>
                <th class="px-4 py-2">Doctor</th>
                <th class="px-4 py-2">Date</th>
                <th class="px-4 py-2">Time</th>
                <th class="px-4 py-2">Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($appointments as $app): ?>
            <tr class="border-b">
                <td class="px-4 py-2"><?= htmlspecialchars($app['patient']) ?></td>
                <td class="px-4 py-2"><?= htmlspecialchars($app['doctor']) ?></td>
                <td class="px-4 py-2"><?= htmlspecialchars($app['date']) ?></td>
                <td class="px-4 py-2"><?= htmlspecialchars($app['time']) ?></td>
                <td class="px-4 py-2"><?= htmlspecialchars($app['status']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php include "../includes/footer.php"; ?>
