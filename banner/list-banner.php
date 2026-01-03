<?php
// banners-list.php
// Display banners from database with delete modal and edit functionality

// ----- EDIT THESE DB CREDENTIALS ----- 
$DB_HOST = 'localhost';
$DB_USER = 'root';
$DB_PASS = '';
$DB_NAME = 'bkmaster';
// -------------------------------------

$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($conn->connect_error) {
    die("DB connect error: " . $conn->connect_error);
}

// Handle AJAX delete (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $out_err = null;
    
    if ($id <= 0) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Invalid banner ID.']);
        exit;
    }
    
    // First get the image URL to delete the file
    $stmt = $conn->prepare("SELECT image_url FROM banners WHERE id = ?");
    $image_url = null;
    if ($stmt) {
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->bind_result($image_url);
        $stmt->fetch();
        $stmt->close();
    }
    
    // Delete the banner
    $stmt = $conn->prepare("DELETE FROM banners WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param('i', $id);
        if ($stmt->execute()) {
            $stmt->close();
            
            // Try to delete the image file if it exists locally
            if ($image_url && strpos($image_url, '/uploads/banners/') !== false) {
                $file_path = __DIR__ . '/..' . $image_url;
                if (file_exists($file_path)) {
                    @unlink($file_path);
                }
            }
            
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => true, 'message' => 'Banner deleted successfully.', 'id' => $id]);
            exit;
        } else {
            $out_err = "Failed to delete banner (database).";
        }
        $stmt->close();
    } else {
        $out_err = "Database error.";
    }
    
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => $out_err ?: 'Unknown error']);
    exit;
}

// Handle fallback GET delete (graceful fallback)
if (isset($_GET['action']) && $_GET['action'] === 'delete' && $_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['id'])) {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    
    if ($id > 0) {
        // Get image URL first
        $stmt = $conn->prepare("SELECT image_url FROM banners WHERE id = ?");
        $image_url = null;
        if ($stmt) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->bind_result($image_url);
            $stmt->fetch();
            $stmt->close();
        }
        
        // Delete banner
        $stmt = $conn->prepare("DELETE FROM banners WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $id);
            if ($stmt->execute()) {
                $stmt->close();
                
                // Delete image file if exists locally
                if ($image_url && strpos($image_url, '/uploads/banners/') !== false) {
                    $file_path = __DIR__ . '/..' . $image_url;
                    if (file_exists($file_path)) {
                        @unlink($file_path);
                    }
                }
                
                header("Location: " . strtok($_SERVER["REQUEST_URI"], '?') . "?deleted=1");
                exit;
            }
            $stmt->close();
        }
    }
    
    $q = urlencode('Failed to delete banner');
    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?') . "?delete_error={$q}");
    exit;
}

// AJAX endpoint for pagination
if (isset($_GET['action']) && $_GET['action'] === 'paginate' && isset($_GET['page'])) {
    $page = (int)$_GET['page'];
    $perPage = 10; // Number of items per page
    $offset = ($page - 1) * $perPage;
    
    // Get total count
    $countResult = $conn->query("SELECT COUNT(*) as total FROM banners");
    $totalRows = $countResult->fetch_assoc()['total'];
    $totalPages = ceil($totalRows / $perPage);
    
    // Get paginated data
    $sql = "SELECT id, title, subtitle, image_url, link_url, banner_type, position, is_active, created_at FROM banners ORDER BY position ASC, created_at DESC LIMIT $offset, $perPage";
    $result = $conn->query($sql);
    
    $rows = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'rows' => $rows,
        'currentPage' => $page,
        'totalPages' => $totalPages,
        'totalRows' => $totalRows
    ]);
    exit;
}

// Get total count for pagination
$countResult = $conn->query("SELECT COUNT(*) as total FROM banners");
$totalRows = $countResult->fetch_assoc()['total'];
$totalPages = ceil($totalRows / 10);

// Get current page
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($currentPage < 1) $currentPage = 1;

// Calculate offset
$offset = ($currentPage - 1) * 10;

// Fetch banners
$sql = "SELECT id, title, subtitle, image_url, link_url, banner_type, position, is_active, created_at FROM banners ORDER BY position ASC, created_at DESC LIMIT $offset, 10";
$result = $conn->query($sql);

// Build flash messages
$flashSuccess = "";
$flashError = "";
if (isset($_GET['deleted']) && $_GET['deleted'] == '1') {
    $flashSuccess = "Banner deleted successfully.";
}
if (isset($_GET['delete_error'])) {
    $flashError = htmlspecialchars(urldecode($_GET['delete_error']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Banners List</title>

    <!-- App favicon -->
    <link rel="shortcut icon" href="../assets/images/favicon.ico">

    <!-- Vendor css (Require in all Page) -->
    <link href="../assets/css/vendor.min.css" rel="stylesheet" type="text/css" />

    <!-- Icons css (Require in all Page) -->
    <link href="../assets/css/icons.min.css" rel="stylesheet" type="text/css" />

    <!-- App css (Require in all Page) -->
    <link href="../assets/css/app.min.css" rel="stylesheet" type="text/css" />

    <!-- Toastify CSS (for toasts) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">

    <!-- Theme Config js (Require in all Page) -->
    <script src="../assets/js/config.js"></script>
    
    <style>
        .toastify {
            z-index: 20000;
        }
        .banner-thumb {
            width: 80px;
            height: 60px;
            object-fit: cover;
            border-radius: 6px;
        }
        .empty-thumb {
            width: 80px;
            height: 60px;
            border-radius: 6px;
            background: #f5f6f8;
            display: inline-block;
        }
        .status-badge {
            font-size: 12px;
            padding: 4px 8px;
        }
        .pagination-loading {
            display: none;
            text-align: center;
            padding: 10px;
        }
        .pagination-loading.active {
            display: block;
        }
        .type-badge {
            font-size: 11px;
            padding: 3px 6px;
        }
        .title-truncate {
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
    </style>
</head>
<body>

    <!-- START Wrapper -->
    <div class="wrapper">

        <!-- ========== Topbar Start ========== -->
        <?php include __DIR__.'/../includes/header.php' ?>
        <!-- ========== App Menu End ========== -->

        <!-- ========== App Menu Start ========== -->
        <?php include __DIR__.'/../includes/sidebar.php' ?>
        <!-- ========== App Menu End ========== -->

        <!-- ==================================================== -->
        <!-- Start right Content here -->
        <!-- ==================================================== -->
        <div class="page-content">

            <!-- Start Container Fluid -->
            <div class="container-xxl">

                <!-- Flash Messages -->
                <?php if ($flashSuccess): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php echo $flashSuccess; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php endif; ?>
                
                <?php if ($flashError): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php echo $flashError; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-xl-12">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center gap-1">
                                <h4 class="card-title flex-grow-1">All Banners List</h4>

                                <a href="add-banner.php" class="btn btn-sm btn-primary">
                                    Add Banner
                                </a>

                                <div class="dropdown">
                                    <a href="#" class="dropdown-toggle btn btn-sm btn-outline-light" data-bs-toggle="dropdown" aria-expanded="false">
                                        This Month
                                    </a>
                                    <div class="dropdown-menu dropdown-menu-end">
                                        <a href="#!" class="dropdown-item">Download</a>
                                        <a href="#!" class="dropdown-item">Export</a>
                                        <a href="#!" class="dropdown-item">Import</a>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <div class="table-responsive" id="bannersTableContainer">
                                    <table class="table align-middle mb-0 table-hover table-centered">
                                        <thead class="bg-light-subtle">
                                            <tr>
                                                <th style="width: 20px;">
                                                    <div class="form-check">
                                                        <input type="checkbox" class="form-check-input" id="customCheck1">
                                                        <label class="form-check-label" for="customCheck1"></label>
                                                    </div>
                                                </th>
                                                <th>Banner Image</th>
                                                <th>Title</th>
                                                <th>Subtitle</th>
                                                <th>Link Url</th>
                                                <th>Type</th>
                                                <th>Position</th>
                                                <th>Status</th>
                                                
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody id="bannersTableBody">
                                            <?php if ($result && $result->num_rows > 0): ?>
                                                <?php while ($row = $result->fetch_assoc()): ?>
                                                    <?php
                                                    $status_class = $row['is_active'] ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger';
                                                    $status_text = $row['is_active'] ? 'Active' : 'Inactive';
                                                    $type_class = '';
                                                    switch($row['banner_type']) {
                                                        case 'homepage': $type_class = 'bg-primary-subtle text-primary'; break;
                                                        case 'category': $type_class = 'bg-info-subtle text-info'; break;
                                                        case 'product': $type_class = 'bg-warning-subtle text-warning'; break;
                                                        default: $type_class = 'bg-secondary-subtle text-secondary';
                                                    }
                                                    $created_date = date('d M Y', strtotime($row['created_at']));
                                                    ?>
                                                    <tr id="row-<?php echo $row['id']; ?>">
                                                        <td>
                                                            <div class="form-check">
                                                                <input type="checkbox" class="form-check-input" id="check_<?php echo $row['id']; ?>">
                                                                <label class="form-check-label" for="check_<?php echo $row['id']; ?>"></label>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <div class="rounded bg-light d-flex align-items-center justify-content-center" style="width:80px;height:60px;">
                                                                <?php if ($row['image_url'] && filter_var($row['image_url'], FILTER_VALIDATE_URL)): ?>
                                                                    <img src="<?php echo htmlspecialchars($row['image_url']); ?>" alt="<?php echo htmlspecialchars($row['title']); ?>" class="banner-thumb" onerror="this.style.display='none'; this.parentElement.innerHTML='<span class=\"empty-thumb\"></span>
                                                                <?php elseif ($row['image_url'] && file_exists(__DIR__ . '/..' . $row['image_url'])): ?>
                                                                    <img src="<?php echo htmlspecialchars($row['image_url']); ?>" alt="<?php echo htmlspecialchars($row['title']); ?>" class="banner-thumb" onerror="this.style.display='none'; this.parentElement.innerHTML='<span class=\"empty-thumb\"></span>
                                                                <?php else: ?>
                                                                    <span class="empty-thumb" aria-hidden="true"></span>
                                                                <?php endif; ?>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <p class="text-dark fw-medium fs-15 mb-1 title-truncate"><?php echo htmlspecialchars($row['title']); ?></p>
                                                        </td>
                                                        <td>
                                                             <?php if ($row['subtitle']): ?>
                                                                <p class="text-muted mb-0 fs-13"><?php echo htmlspecialchars($row['subtitle']); ?></p>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php if ($row['link_url']): ?>
                                                                <small class="text-primary"><a href="<?php echo htmlspecialchars($row['link_url']); ?>" target="_blank" class="text-decoration-underline"><?php echo $row['link_url'] ?></a></small>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <span class="badge type-badge <?php echo $type_class; ?>">
                                                                <?php echo ucfirst($row['banner_type']); ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <span class="fw-bold"><?php echo $row['position']; ?></span>
                                                        </td>
                                                        <td>
                                                            <span class="badge status-badge <?php echo $status_class; ?>">
                                                                <?php echo $status_text; ?>
                                                            </span>
                                                        </td>
                                                        
                                                        <td>
                                                            <div class="d-flex gap-2">
                                                                <a href="add-banner.php?id=<?php echo $row['id']; ?>" 
                                                                   class="btn btn-soft-primary btn-sm"
                                                                   title="Edit">
                                                                    <iconify-icon icon="solar:pen-2-broken" class="align-middle fs-18"></iconify-icon>
                                                                </a>
                                                                <a href="?action=delete&id=<?php echo $row['id']; ?>" 
                                                                   class="btn btn-soft-danger btn-sm btn-delete"
                                                                   data-id="<?php echo $row['id']; ?>"
                                                                   title="Delete">
                                                                    <iconify-icon icon="solar:trash-bin-minimalistic-2-broken" class="align-middle fs-18"></iconify-icon>
                                                                </a>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endwhile; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="8" class="text-center text-muted py-4">No banners found. <a href="add-banner.php">Add your first banner</a></td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <!-- end table-responsive -->
                            </div>
                            <div class="card-footer border-top">
                                <div class="pagination-loading" id="paginationLoading">
                                    <div class="spinner-border spinner-border-sm text-primary me-2" role="status">
                                        <span class="visually-hidden">Loading...</span>
                                    </div>
                                    Loading banners...
                                </div>
                                <nav aria-label="Page navigation example">
                                    <ul class="pagination justify-content-end mb-0" id="pagination">
                                        <?php if ($totalPages > 1): ?>
                                            <!-- Previous Button -->
                                            <li class="page-item <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>">
                                                <a class="page-link" href="?page=<?php echo $currentPage - 1; ?>" data-page="<?php echo $currentPage - 1; ?>" <?php echo $currentPage <= 1 ? 'tabindex="-1" aria-disabled="true"' : ''; ?>>
                                                    Previous
                                                </a>
                                            </li>
                                            
                                            <?php 
                                            // Calculate page range to show
                                            $startPage = max(1, $currentPage - 2);
                                            $endPage = min($totalPages, $startPage + 4);
                                            
                                            // Adjust start page if we're near the end
                                            if ($endPage - $startPage < 4 && $startPage > 1) {
                                                $startPage = max(1, $endPage - 4);
                                            }
                                            
                                            // Show page numbers
                                            for ($i = $startPage; $i <= $endPage; $i++): 
                                                $activeClass = $i == $currentPage ? 'active' : '';
                                            ?>
                                                <li class="page-item <?php echo $activeClass; ?>">
                                                    <a class="page-link" href="?page=<?php echo $i; ?>" data-page="<?php echo $i; ?>">
                                                        <?php echo $i; ?>
                                                    </a>
                                                </li>
                                            <?php endfor; ?>
                                            
                                            <!-- Next Button -->
                                            <li class="page-item <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>">
                                                <a class="page-link" href="?page=<?php echo $currentPage + 1; ?>" data-page="<?php echo $currentPage + 1; ?>" <?php echo $currentPage >= $totalPages ? 'tabindex="-1" aria-disabled="true"' : ''; ?>>
                                                    Next
                                                </a>
                                            </li>
                                        <?php else: ?>
                                            <!-- Single page fallback -->
                                            <li class="page-item active"><a class="page-link" href="javascript:void(0);">1</a></li>
                                        <?php endif; ?>
                                    </ul>
                                </nav>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
            <!-- End Container Fluid -->

            <!-- Delete Confirmation Modal -->
            <div class="modal fade" id="deleteConfirmModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="deleteConfirmModalLabel" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="deleteConfirmModalLabel">Delete Banner</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <p>Are you sure you want to delete this banner? This action cannot be undone.</p>
                            <p class="text-danger"><small>The banner image will also be deleted from the server.</small></p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            <!-- Confirm button triggers AJAX deletion -->
                            <button type="button" id="confirmDeleteBtn" class="btn btn-danger">
                                Delete
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ========== Footer Start ========== -->
            <footer class="footer">
                <div class="container-fluid">
                    <div class="row">
                        <div class="col-12 text-center">
                            <script>document.write(new Date().getFullYear())</script> &copy; Larkon. Crafted by <iconify-icon icon="iconamoon:heart-duotone" class="fs-18 align-middle text-danger"></iconify-icon> <a
                                href="https://1.envato.market/techzaa" class="fw-bold footer-text" target="_blank">Techzaa</a>
                        </div>
                    </div>
                </div>
            </footer>
            <!-- ========== Footer End ========== -->

        </div>
        <!-- ==================================================== -->
        <!-- End Page Content -->
        <!-- ==================================================== -->
    </div>
    <!-- END Wrapper -->

    <!-- Vendor Javascript (Require in all Page) -->
    <script src="../assets/js/vendor.js"></script>

    <!-- App Javascript (Require in all Page) -->
    <script src="../assets/js/app.js"></script>

    <!-- Toastify JS -->
    <script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>

    <script>
        (function() {
            // Helper to show toast
            function showToast(msg, type) {
                Toastify({
                    text: msg,
                    duration: type === 'error' ? 6000 : 4000,
                    close: true,
                    gravity: "top",
                    position: "right",
                    stopOnFocus: true,
                    style: {
                        background: type === 'error' ?
                            "linear-gradient(to right, #dc3545, #b02a37)" :
                            "linear-gradient(to right, #4CAF7C, #4CAF7C)",
                        color: "#fff"
                    }
                }).showToast();
            }

            // Show server-side messages (from fallback redirect params)
            var serverSuccess = <?php echo json_encode($flashSuccess ?? '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
            var serverError = <?php echo json_encode($flashError ?? '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
            if (serverSuccess && serverSuccess.length) {
                setTimeout(function() {
                    showToast(serverSuccess, 'success');
                }, 50);
            }
            if (serverError && serverError.length) {
                setTimeout(function() {
                    showToast(serverError, 'error');
                }, 50);
            }

            /* -------------------
               Dynamic Pagination with AJAX
               ------------------- */
            var currentPage = <?php echo $currentPage; ?>;
            var totalPages = <?php echo $totalPages; ?>;
            var paginationLoading = document.getElementById('paginationLoading');
            var bannersTableBody = document.getElementById('bannersTableBody');
            var pagination = document.getElementById('pagination');

            function loadPage(page) {
                if (page < 1 || page > totalPages || page === currentPage) return;
                
                // Show loading indicator
                if (paginationLoading) paginationLoading.classList.add('active');
                
                // Update current page
                currentPage = page;
                
                // Update URL without page reload
                var url = new URL(window.location);
                url.searchParams.set('page', page);
                window.history.pushState({}, '', url);
                
                // Fetch data via AJAX
                fetch('?action=paginate&page=' + page, {
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(function(response) {
                    return response.json();
                })
                .then(function(data) {
                    if (data.success && data.rows) {
                        // Update table body
                        bannersTableBody.innerHTML = '';
                        
                        if (data.rows.length > 0) {
                            data.rows.forEach(function(row) {
                                // Determine status
                                var status_class = row.is_active ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger';
                                var status_text = row.is_active ? 'Active' : 'Inactive';
                                
                                // Determine type
                                var type_class = '';
                                switch(row.banner_type) {
                                    case 'homepage': type_class = 'bg-primary-subtle text-primary'; break;
                                    case 'category': type_class = 'bg-info-subtle text-info'; break;
                                    case 'product': type_class = 'bg-warning-subtle text-warning'; break;
                                    default: type_class = 'bg-secondary-subtle text-secondary';
                                }
                                
                                // Format date
                                var created_date = new Date(row.created_at).toLocaleDateString('en-GB', { 
                                    day: '2-digit', 
                                    month: 'short', 
                                    year: 'numeric' 
                                });
                                
                                var rowHtml = `
                                <tr id="row-${row.id}">
                                    <td>
                                        <div class="form-check">
                                            <input type="checkbox" class="form-check-input" id="check_${row.id}">
                                            <label class="form-check-label" for="check_${row.id}"></label>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="rounded bg-light d-flex align-items-center justify-content-center" style="width:80px;height:60px;">
                                            ${row.image_url ? `<img src="${row.image_url}" alt="${row.title}" class="banner-thumb" onerror="this.style.display='none'; this.parentElement.innerHTML='<span class=\"empty-thumb\"></span>';">` : '<span class="empty-thumb" aria-hidden="true"></span>'}
                                        </div>
                                    </td>
                                    <td>
                                        <p class="text-dark fw-medium fs-15 mb-1 title-truncate">${row.title || ''}</p>
                                        ${row.subtitle ? `<p class="text-muted mb-0 fs-13">${row.subtitle}</p>` : ''}
                                        ${row.link_url ? `<small class="text-primary"><a href="${row.link_url}" target="_blank" class="text-decoration-underline">View Link</a></small>` : ''}
                                    </td>
                                    <td>
                                        <span class="badge type-badge ${type_class}">
                                            ${row.banner_type ? row.banner_type.charAt(0).toUpperCase() + row.banner_type.slice(1) : ''}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="fw-bold">${row.position || 0}</span>
                                    </td>
                                    <td>
                                        <span class="badge status-badge ${status_class}">
                                            ${status_text}
                                        </span>
                                    </td>
                                    <td>
                                        ${created_date}
                                    </td>
                                    <td>
                                        <div class="d-flex gap-2">
                                            <a href="add-banner.php?id=${row.id}" 
                                               class="btn btn-soft-primary btn-sm"
                                               title="Edit">
                                                <iconify-icon icon="solar:pen-2-broken" class="align-middle fs-18"></iconify-icon>
                                            </a>
                                            <a href="?action=delete&id=${row.id}" 
                                               class="btn btn-soft-danger btn-sm btn-delete"
                                               data-id="${row.id}"
                                               title="Delete">
                                                <iconify-icon icon="solar:trash-bin-minimalistic-2-broken" class="align-middle fs-18"></iconify-icon>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                `;
                                bannersTableBody.innerHTML += rowHtml;
                            });
                        } else {
                            bannersTableBody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4">No banners found. <a href="add-banner.php">Add your first banner</a></td></tr>';
                        }
                        
                        // Update pagination
                        updatePagination(data.currentPage, data.totalPages);
                    }
                })
                .catch(function(error) {
                    console.error('Error loading page:', error);
                    showToast('Error loading page. Please try again.', 'error');
                })
                .finally(function() {
                    // Hide loading indicator
                    if (paginationLoading) paginationLoading.classList.remove('active');
                });
            }

            function updatePagination(currentPage, totalPages) {
                if (!pagination || totalPages <= 1) return;
                
                var html = '';
                
                // Previous button
                html += `<li class="page-item ${currentPage <= 1 ? 'disabled' : ''}">
                    <a class="page-link" href="?page=${currentPage - 1}" data-page="${currentPage - 1}" ${currentPage <= 1 ? 'tabindex="-1" aria-disabled="true"' : ''}>
                        Previous
                    </a>
                </li>`;
                
                // Calculate page range
                var startPage = Math.max(1, currentPage - 2);
                var endPage = Math.min(totalPages, startPage + 4);
                
                if (endPage - startPage < 4 && startPage > 1) {
                    startPage = Math.max(1, endPage - 4);
                }
                
                // Page numbers
                for (var i = startPage; i <= endPage; i++) {
                    var activeClass = i == currentPage ? 'active' : '';
                    html += `<li class="page-item ${activeClass}">
                        <a class="page-link" href="?page=${i}" data-page="${i}">
                            ${i}
                        </a>
                    </li>`;
                }
                
                // Next button
                html += `<li class="page-item ${currentPage >= totalPages ? 'disabled' : ''}">
                    <a class="page-link" href="?page=${currentPage + 1}" data-page="${currentPage + 1}" ${currentPage >= totalPages ? 'tabindex="-1" aria-disabled="true"' : ''}>
                        Next
                    </a>
                </li>`;
                
                pagination.innerHTML = html;
            }

            // Handle pagination clicks
            if (pagination) {
                pagination.addEventListener('click', function(e) {
                    var link = e.target.closest('.page-link');
                    if (!link) return;
                    
                    e.preventDefault();
                    
                    var page = link.getAttribute('data-page');
                    if (!page) {
                        page = parseInt(new URL(link.href).searchParams.get('page')) || 1;
                    }
                    
                    loadPage(parseInt(page));
                });
            }

            // Handle browser back/forward buttons
            window.addEventListener('popstate', function() {
                var urlParams = new URLSearchParams(window.location.search);
                var page = parseInt(urlParams.get('page')) || 1;
                if (page !== currentPage) {
                    loadPage(page);
                }
            });

            /* -------------------
               Delete modal + AJAX
               ------------------- */
            var deleteModalEl = document.getElementById('deleteConfirmModal');
            var bsDeleteModal = null;
            
            // Use event delegation for delete buttons since they're dynamically loaded
            document.addEventListener('click', function(e) {
                var delBtn = e.target.closest('.btn-delete');
                if (!delBtn) return;
                e.preventDefault();
                var deleteId = delBtn.getAttribute('data-id');
                if (!deleteId) return;
                
                deleteModalEl.dataset.deleteId = deleteId;
                bsDeleteModal = bootstrap.Modal.getOrCreateInstance(deleteModalEl);
                bsDeleteModal.show();
            });

            var confirmBtn = document.getElementById('confirmDeleteBtn');
            var origConfirmHtml = confirmBtn ? confirmBtn.innerHTML : 'Delete';
            confirmBtn && confirmBtn.addEventListener('click', function() {
                var id = deleteModalEl.dataset.deleteId;
                if (!id) {
                    if (bsDeleteModal) bsDeleteModal.hide();
                    return;
                }
                confirmBtn.disabled = true;
                confirmBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Deleting...';

                var fd = new FormData();
                fd.append('action', 'delete');
                fd.append('id', id);

                fetch(window.location.pathname, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: fd,
                    credentials: 'same-origin'
                }).then(r => r.json()).then(function(json) {
                    confirmBtn.disabled = false;
                    confirmBtn.innerHTML = origConfirmHtml;
                    if (bsDeleteModal) bsDeleteModal.hide();
                    if (json && json.success) {
                        var row = document.getElementById('row-' + id);
                        if (row) {
                            row.style.transition = 'opacity 0.3s';
                            row.style.opacity = '0';
                            setTimeout(function() {
                                row.remove();
                            }, 300);
                        }
                        showToast(json.message || 'Banner deleted successfully.', 'success');
                        
                        // Reload current page to update pagination if needed
                        loadPage(currentPage);
                    } else {
                        showToast((json && json.error) ? json.error : 'Delete failed', 'error');
                    }
                }).catch(function(err) {
                    confirmBtn.disabled = false;
                    confirmBtn.innerHTML = origConfirmHtml;
                    if (bsDeleteModal) bsDeleteModal.hide();
                    showToast('Request failed: ' + (err && err.message ? err.message : 'network error'), 'error');
                });
            });

        })();
    </script>

</body>
</html>
<?php
// Close database connection
if ($result) $result->free();
$conn->close();
?>