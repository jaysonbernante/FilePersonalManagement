<?php
include 'db.php';
if(!isset($_GET['id'])) die("ID not provided.");
$id = $_GET['id'];

// Delete file if exists
$res = $conn->query("SELECT * FROM resources WHERE id=$id");
$row = $res->fetch_assoc();
if($row['filename'] && file_exists(__DIR__."/uploads/".$row['filename'])){
    unlink(__DIR__."/uploads/".$row['filename']);
}

// Delete record
$conn->query("DELETE FROM resources WHERE id=$id");
header("Location: index.php");
exit;
?>
