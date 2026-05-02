<?php
include 'db.php';
$message = "";

if(!isset($_GET['id'])) die("ID not provided.");
$id = $_GET['id'];

$res = $conn->query("SELECT * FROM resources WHERE id=$id");
if($res->num_rows==0) die("Resource not found.");
$row = $res->fetch_assoc();

$uploadDir = __DIR__ . "/uploads/";

if(isset($_POST['update'])){
    $title = $_POST['title'];
    $type = $_POST['type'];

    if($type=='link'){
        $link = $_POST['link'];
        $filename = NULL;
    } else {
        $link = NULL;
        $filename = $row['filename'];
        if(isset($_FILES['file']) && $_FILES['file']['error']==0){
            // Delete old file
            if($row['filename'] && file_exists($uploadDir.$row['filename'])){
                unlink($uploadDir.$row['filename']);
            }
            $file = $_FILES['file'];
            $filename = time().'_'.$file['name'];
            move_uploaded_file($file['tmp_name'], $uploadDir.$filename);
        }
    }

    $stmt = $conn->prepare("UPDATE resources SET title=?, type=?, link=?, filename=? WHERE id=?");
    $stmt->bind_param("ssssi",$title,$type,$link,$filename,$id);
    $stmt->execute();
    $message = "Resource updated successfully!";
    $res = $conn->query("SELECT * FROM resources WHERE id=$id");
    $row = $res->fetch_assoc();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Edit Resource</title>
</head>
<body>
<h2>Edit Resource</h2>
<?php if($message) echo "<p>$message</p>"; ?>

<form action="" method="post" enctype="multipart/form-data">
    <input type="text" name="title" value="<?php echo $row['title']; ?>" required><br>
    <select name="type" id="type" required onchange="toggleInput(this.value)">
        <option value="">Select Type</option>
        <option value="file" <?php if($row['type']=='file') echo 'selected'; ?>>File</option>
        <option value="image" <?php if($row['type']=='image') echo 'selected'; ?>>Image</option>
        <option value="link" <?php if($row['type']=='link') echo 'selected'; ?>>Link</option>
    </select><br>

    <div id="fileInput" style="display:none;">
        <input type="file" name="file">
    </div>
    <div id="linkInput" style="display:none;">
        <input type="text" name="link" value="<?php echo $row['link']; ?>">
    </div><br>

    <button type="submit" name="update">Update Resource</button>
</form>

<a href="index.php">Back to Home</a>

<script>
function toggleInput(value){
    document.getElementById('fileInput').style.display = (value=='file'||value=='image') ? 'block':'none';
    document.getElementById('linkInput').style.display = (value=='link') ? 'block':'none';
}
// Initial toggle
toggleInput('<?php echo $row['type']; ?>');
</script>

</body>
</html>
