<?php
session_start();
require_once "../includes/db.php";
require_once "../includes/auth.php";
checkRole('admin');

$doctors = $pdo->query("SELECT * FROM users WHERE role='doctor'")->fetchAll(PDO::FETCH_ASSOC);
?>
<?php include "../includes/header.php"; ?>
<div class="max-w-4xl mx-auto mt-10">
    <h2 class="text-2xl font-bold mb-4">All Doctors</h2>
    <table class="min-w-full bg-white shadow-md rounded-lg">
        <thead>
            <tr class="bg-gray-200 text-left">
                <th class="px-4 py-2">Name</th>
                <th class="px-4 py-2">Email</th>
                <th class="px-4 py-2">Specialty</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($doctors as $doc): ?>
            <tr class="border-b">
                <td class="px-4 py-2"><?= htmlspecialchars($doc['name']) ?></td>
                <td class="px-4 py-2"><?= htmlspecialchars($doc['email']) ?></td>
                <td class="px-4 py-2"><?= htmlspecialchars($doc['specialty']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php include "../includes/footer.php"; ?>
