<?php
$db = new PDO('sqlite:../database/telemed.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents("php://input"), true);
    $stmt = $db->prepare("INSERT INTO signals (room, sender, data) VALUES (?, ?, ?)");
    $stmt->execute([$input['room'], $_SERVER['REMOTE_ADDR'], $input['data']]);
    exit(json_encode(["status" => "ok"]));
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['room'])) {
    $stmt = $db->prepare("SELECT sender, data FROM signals WHERE room = ? ORDER BY id ASC");
    $stmt->execute([$_GET['room']]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}
?>
