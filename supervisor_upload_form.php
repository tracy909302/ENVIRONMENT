<?php
session_start();
include 'config.php';

// Check if the supervisor is logged in
if (!isset($_SESSION['sup_id'])) {
    header("Location: student_login.php");
    exit();
}

// Check if document ID is set
if (!isset($_GET['document_id']) || !isset($_GET['action'])) {
    echo "Document Uploaded successful.";
    exit();
}

$document_id = $_GET['document_id'];
$action = $_GET['action'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload New Document</title>
    <link rel="stylesheet" href="../css/status.css">
</head>
<body>
    <div>
        <h2>Upload New Document</h2>
        <form method="post" action="supervisor_upload_document.php" enctype="multipart/form-data">
            <input type="hidden" name="document_id" value="<?php echo htmlspecialchars($document_id); ?>">
            <input type="hidden" name="action" value="<?php echo htmlspecialchars($action); ?>">
            <label for="new_document">Select document to upload:</label>
            <input type="file" name="new_document" id="new_document">
            <button type="submit">Upload</button>
        </form>
    </div>
</body>
</html>
