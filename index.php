<?php
session_start();
include 'db.php';

// Add deleted column if not exists
$conn->query("ALTER TABLE resources ADD COLUMN IF NOT EXISTS deleted TINYINT(1) DEFAULT 0");

/* =========================
   TOAST HELPER
========================= */
function setToast($type, $msg){
    $_SESSION['toast'] = ['type'=>$type, 'msg'=>$msg];
}

/* =========================
   UPLOADS DIR
========================= */
$baseDir = __DIR__ . "/uploads/";
if(!is_dir($baseDir)) mkdir($baseDir, 0777, true);

/* =========================
   CREATE FOLDER
========================= */
if(isset($_POST['createFolder'])){
    $folder = trim($_POST['folder_name']);
    if($folder){
        if(!is_dir($baseDir . $folder)){
            mkdir($baseDir . $folder, 0777, true);
            setToast('success', 'Folder created');
        } else {
            setToast('warning', 'Folder already exists');
        }
    }
    header("Location: index.php");
    exit;
}

/* =========================
   EDIT FOLDER
========================= */
if(isset($_POST['editFolder'])){
    $old = $_POST['old_folder'];
    $new = trim($_POST['new_folder']);
    if($new && $new != $old){
        $oldPath = $baseDir . $old;
        $newPath = $baseDir . $new;
        if(is_dir($oldPath) && !is_dir($newPath)){
            rename($oldPath, $newPath);
            $conn->query("UPDATE resources SET folder='" . mysqli_real_escape_string($conn, $new) . "' WHERE folder='" . mysqli_real_escape_string($conn, $old) . "'");
            setToast('success', 'Folder renamed');
            if($currentFolder == $old) $currentFolder = $new;
        } else {
            setToast('danger', 'Rename failed');
        }
    }
    header("Location: index.php?folder=" . urlencode($currentFolder));
    exit;
}

/* =========================
   DELETE FOLDER
========================= */
if(isset($_GET['delete_folder'])){
    $folder = $_GET['delete_folder'];
    $path = $baseDir . $folder;

    if(is_dir($path)){
        foreach(glob($path . '/*') as $f){
            if(is_file($f)) unlink($f);
        }
        rmdir($path);
        // Soft delete resources
        $stmt = $conn->prepare("UPDATE resources SET deleted=1 WHERE folder=?");
        $stmt->bind_param("s", $folder);
        $stmt->execute();
        setToast('success', 'Folder deleted, resources moved to history');
    }
    header("Location: index.php");
    exit;
}

/* =========================
   INSERT RESOURCE
========================= */
$currentFolder = $_GET['folder'] ?? 'Default';

if(isset($_POST['insertResource'])){
    $type = $_POST['type'];
    $folder = $_POST['folder'] ?: $currentFolder;
    $link = null;
    $filename = null;
    $title = null;

    if(!is_dir($baseDir . $folder)) mkdir($baseDir . $folder, 0777, true);

    if($type === 'link'){
        $title = $_POST['title'];
        $link  = $_POST['link'];
        if(!$title || !$link){
            setToast('danger', 'Link title and URL required');
            header("Location: index.php?folder=$folder");
            exit;
        }
    } else {
        if($_FILES['file']['error'] === 0){
            $orig     = $_FILES['file']['name'];
            $filename = time() . '_' . $orig;
            $title    = $orig;
            move_uploaded_file($_FILES['file']['tmp_name'], $baseDir . $folder . '/' . $filename);
        } else {
            setToast('danger', 'File upload failed');
            header("Location: index.php?folder=$folder");
            exit;
        }
    }

    $stmt = $conn->prepare("INSERT INTO resources (title, type, folder, link, filename) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("sssss", $title, $type, $folder, $link, $filename);
    $stmt->execute();

    setToast('success', 'Resource added');
    header("Location: index.php?folder=$folder");
    exit;
}

/* =========================
   DELETE RESOURCE
========================= */
if(isset($_GET['delete_resource'])){
    $id = (int)$_GET['delete_resource'];
    $conn->query("UPDATE resources SET deleted=1 WHERE id=$id");
    setToast('success', 'Resource moved to history');
    header("Location: index.php?folder=$currentFolder");
    exit;
}

/* =========================
   RESTORE RESOURCE
========================= */
if(isset($_GET['restore'])){
    $id = (int)$_GET['restore'];
    $conn->query("UPDATE resources SET deleted=0 WHERE id=$id");
    setToast('success', 'Resource restored');
    header("Location: index.php?history=1");
    exit;
}

/* =========================
   PERMANENT DELETE RESOURCE
========================= */
if(isset($_GET['perm_delete'])){
    $id = (int)$_GET['perm_delete'];
    $res = $conn->query("SELECT * FROM resources WHERE id=$id")->fetch_assoc();
    if($res && $res['filename']){
        $fp = $baseDir . $res['folder'] . '/' . $res['filename'];
        if(is_file($fp)) unlink($fp);
    }
    $conn->query("DELETE FROM resources WHERE id=$id");
    setToast('success', 'Permanently deleted');
    header("Location: index.php?history=1");
    exit;
}

/* =========================
   FETCH DATA
========================= */
$currentFolder = $_GET['folder'] ?? 'Default';
$filter = $_GET['filter'] ?? 'all';

$folders = array_filter(glob($baseDir . '*'), 'is_dir');
if(empty($folders)){
    mkdir($baseDir . 'Default', 0777, true);
    $folders = array_filter(glob($baseDir . '*'), 'is_dir');
}

if(isset($_GET['history'])){
    $sql = "SELECT * FROM resources WHERE deleted=1 ORDER BY uploaded_on DESC";
    $resources = $conn->query($sql);
} else {
    $sql = "SELECT * FROM resources WHERE folder='" . mysqli_real_escape_string($conn, $currentFolder) . "' AND deleted=0";
    if($filter == 'file') $sql .= " AND type='file'";
    elseif($filter == 'link') $sql .= " AND type='link'";
    $sql .= " ORDER BY uploaded_on DESC";
    $resources = $conn->query($sql);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Learning Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .sidebar { width: 280px; height: 100vh; background: #212529; color: #fff; position: fixed; padding: 20px; overflow-y: auto; }
        .content { margin-left: 280px; padding: 30px; }
        .folder-link { color: #adb5bd; text-decoration: none; display: block; padding: 8px; border-radius: 5px; }
        .folder-link:hover, .folder-link.active { background: #343a40; color: #fff; }
        .card { border: none; box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075); transition: transform 0.2s; }
        .card:hover { transform: translateY(-3px); }
        @media (max-width: 768px) {
            .sidebar { display: none; }
            .content { margin-left: 0; padding: 15px; }
            .card-body .d-flex { flex-direction: column; }
            .card-body .btn { margin-bottom: 5px; }
        }
    </style>
</head>
<body>

<div class="sidebar">
    <h4 class="mb-4">File Management</h4>
    <form method="post" class="mb-4">
        <div class="input-group">
            <input name="folder_name" class="form-control form-control-sm" placeholder="New Folder" required>
            <button class="btn btn-success btn-sm" name="createFolder">+</button>
        </div>
    </form>

    <?php foreach($folders as $f): $fname = basename($f); ?>
        <div class="d-flex justify-content-between align-items-center mb-1">
            <a href="?folder=<?=urlencode($fname)?>" class="folder-link flex-grow-1 <?= $currentFolder == $fname ? 'active' : '' ?>">
                <i class="bi bi-folder2-open me-2"></i><?=htmlspecialchars($fname)?>
            </a>
            <div>
                <button class="btn btn-sm btn-outline-warning me-1" onclick="editFolder('<?=htmlspecialchars($fname)?>')" title="Edit"><i class="bi bi-pencil"></i></button>
                <a href="?delete_folder=<?=urlencode($fname)?>" class="text-danger" onclick="return confirm('Delete folder and all files?')" title="Delete"><i class="bi bi-trash"></i></a>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><?php if(isset($_GET['history'])): ?>History<?php else: ?>Folder: <span class="text-primary"><?= htmlspecialchars($currentFolder) ?></span><?php endif; ?></h2>
        <div>
            <a href="?history=1" class="btn btn-secondary me-2">History</a>
            <?php if(!isset($_GET['history'])): ?>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addResourceModal">
                <i class="bi bi-plus-lg"></i> Add Resource
            </button>
            <?php endif; ?>
        </div>
    </div>

    <button id="toggleSidebar" class="btn btn-outline-secondary d-md-none mb-3">Show Folders</button>

    <?php if(isset($_GET['history'])): ?>
        <h3>Deleted Resources</h3>
    <?php else: ?>
        <div class="mb-3 d-flex align-items-center">
            <label class="me-2">Filter:</label>
            <select id="filter" class="form-select form-select-sm" style="width: auto;">
                <option value="all" <?= $filter=='all'?'selected':'' ?>>All</option>
                <option value="file" <?= $filter=='file'?'selected':'' ?>>Files</option>
                <option value="link" <?= $filter=='link'?'selected':'' ?>>Links</option>
            </select>
        </div>
    <?php endif; ?>

    <div class="row">
    <?php while($r = $resources->fetch_assoc()): 
        $ext = strtolower(pathinfo($r['filename'] ?? '', PATHINFO_EXTENSION));
        $cleanName = $r['filename'] ? preg_replace('/^\d+_/', '', $r['filename']) : $r['title'];
        
        // Dynamic Icon Logic
        $icon = 'bi-file-earmark';
        if($r['type'] == 'link') $icon = 'bi-link-45deg';
        elseif($ext == 'pdf') $icon = 'bi-file-earmark-pdf text-danger';
        elseif(in_array($ext, ['doc','docx'])) $icon = 'bi-file-earmark-word text-primary';
        elseif(in_array($ext, ['jpg','jpeg','png'])) $icon = 'bi-file-earmark-image text-success';

        // Action URL
        $actionUrl = ($r['type'] === 'link') ? $r['link'] : "uploads/" . urlencode($r['folder']) . "/" . urlencode($r['filename']);
        $actionAttr = ($r['type'] === 'file') ? 'download' : 'target="_blank"';
        $actionIcon = ($r['type'] === 'file') ? 'bi-download' : 'bi-eye';
        $actionText = ($r['type'] === 'file') ? 'Download' : 'View';
    ?>
        <div class="col-12 col-sm-6 col-md-4 col-lg-3">
            <div class="card mb-4">
                <div class="card-body">
                    <h6 class="card-title text-truncate">
                        <i class="bi <?=$icon?> me-2"></i> <?= htmlspecialchars($cleanName) ?>
                    </h6>
                    <p class="text-muted small mb-3">
                        <?php if(isset($_GET['history'])): ?>
                            Folder: <?= htmlspecialchars($r['folder']) ?> | 
                        <?php endif; ?>
                        Uploaded: <?= date("M j, Y", strtotime($r['uploaded_on'])) ?>
                    </p>
                    
                    <div class="d-flex gap-2">
                        <a href="<?= $actionUrl ?>" <?= $actionAttr ?> class="btn btn-sm btn-outline-primary flex-grow-1">
                            <i class="bi <?= $actionIcon ?>"></i> <?= $actionText ?>
                        </a>
                        <?php if(isset($_GET['history'])): ?>
                            <a href="?restore=<?= $r['id'] ?>" onclick="return confirm('Restore?')" class="btn btn-sm btn-outline-success">
                                <i class="bi bi-arrow-counterclockwise"></i> Restore
                            </a>
                            <a href="?perm_delete=<?= $r['id'] ?>" onclick="return confirm('Permanently delete?')" class="btn btn-sm btn-outline-danger">
                                <i class="bi bi-trash"></i> Delete
                            </a>
                        <?php else: ?>
                            <a href="?delete_resource=<?= $r['id'] ?>&folder=<?=urlencode($currentFolder)?>" onclick="return confirm('Delete?')" class="btn btn-sm btn-outline-danger">
                                <i class="bi bi-trash"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endwhile; ?>
    </div>
</div>

<?php if(!isset($_GET['history'])): ?>
<div class="modal fade" id="addResourceModal">
    <div class="modal-dialog">
        <form method="post" enctype="multipart/form-data" class="modal-content">
            <div class="modal-header"><h5>Add New Resource</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Type</label>
                    <select name="type" id="resType" class="form-select">
                        <option value="file">Local File</option>
                        <option value="link">External Link</option>
                    </select>
                </div>
                <div id="fileDiv" class="mb-3">
                    <label class="form-label">Select File</label>
                    <input type="file" name="file" class="form-control">
                </div>
                <div id="linkDiv" class="mb-3 d-none">
                    <label class="form-label">URL</label>
                    <input type="url" name="link" class="form-control" placeholder="https://...">
                    <label class="form-label mt-2">Display Title</label>
                    <input type="text" name="title" class="form-control">
                </div>
                <input type="hidden" name="folder" value="<?= htmlspecialchars($currentFolder) ?>">
            </div>
            <div class="modal-footer">
                <button class="btn btn-primary" name="insertResource">Upload</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="modal fade" id="editFolderModal">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <div class="modal-header"><h5>Edit Folder</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" name="old_folder" id="oldFolder">
                <label>Folder Name</label>
                <input type="text" name="new_folder" id="newFolder" class="form-control" required>
            </div>
            <div class="modal-footer">
                <button class="btn btn-primary" name="editFolder">Rename</button>
            </div>
        </form>
    </div>
</div>

<?php if(isset($_SESSION['toast'])): ?>
<div class="toast-container position-fixed bottom-0 end-0 p-3">
    <div class="toast show align-items-center text-white bg-<?=$_SESSION['toast']['type']?> border-0" role="alert">
        <div class="d-flex">
            <div class="toast-body"><?=$_SESSION['toast']['msg']?></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>
<?php unset($_SESSION['toast']); endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const typeSelect = document.getElementById('resType');
    const fileDiv = document.getElementById('fileDiv');
    const linkDiv = document.getElementById('linkDiv');
    
    if(typeSelect){
        typeSelect.addEventListener('change', () => {
            if(typeSelect.value === 'file'){
                fileDiv.classList.remove('d-none');
                linkDiv.classList.add('d-none');
            } else {
                fileDiv.classList.add('d-none');
                linkDiv.classList.remove('d-none');
            }
        });
    }

    function editFolder(name){
        document.getElementById('oldFolder').value = name;
        document.getElementById('newFolder').value = name;
        new bootstrap.Modal(document.getElementById('editFolderModal')).show();
    }

    const filterEl = document.getElementById('filter');
    if(filterEl){
        filterEl.addEventListener('change', function(){
            const url = new URL(window.location);
            url.searchParams.set('filter', this.value);
            window.location = url;
        });
    }

    const toggleBtn = document.getElementById('toggleSidebar');
    if(toggleBtn){
        toggleBtn.addEventListener('click', function(){
            const sidebar = document.querySelector('.sidebar');
            if(sidebar.style.display === 'none' || sidebar.style.display === ''){
                sidebar.style.display = 'block';
                this.textContent = 'Hide Folders';
            } else {
                sidebar.style.display = 'none';
                this.textContent = 'Show Folders';
            }
        });
    }
</script>
</body>
</html>