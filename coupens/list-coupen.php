<?php
// coupons-list.php
// Database connection - use the same credentials from your add-coupon.php
$DB_HOST = 'localhost';
$DB_USER = 'root';
$DB_PASS = '';
$DB_NAME = 'bkmaster';

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
        echo json_encode(['success' => false, 'error' => 'Invalid coupon ID.']);
        exit;
    }
    
    // Delete the coupon
    $stmt = $conn->prepare("DELETE FROM coupon_codes WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param('i', $id);
        if ($stmt->execute()) {
            $stmt->close();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => true, 'message' => 'Coupon deleted successfully.', 'id' => $id]);
            exit;
        } else {
            $out_err = "Failed to delete coupon (database).";
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
        $stmt = $conn->prepare("DELETE FROM coupon_codes WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $id);
            if ($stmt->execute()) {
                $stmt->close();
                header("Location: " . strtok($_SERVER["REQUEST_URI"], '?') . "?deleted=1");
                exit;
            }
            $stmt->close();
        }
    }
    
    $q = urlencode('Failed to delete coupon');
    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?') . "?delete_error={$q}");
    exit;
}

// Get search parameters
$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

// Build query with filters
$query = "SELECT * FROM coupon_codes WHERE 1=1";
$conditions = [];

if (!empty($search)) {
    $conditions[] = "(code LIKE '%$search%')";
}

if ($status_filter === 'active') {
    $conditions[] = "is_active = 1 AND (expires_at IS NULL OR expires_at > NOW())";
} elseif ($status_filter === 'expired') {
    $conditions[] = "(expires_at IS NOT NULL AND expires_at < NOW())";
} elseif ($status_filter === 'inactive') {
    $conditions[] = "is_active = 0";
}

if (!empty($conditions)) {
    $query .= " AND " . implode(" AND ", $conditions);
}

$query .= " ORDER BY created_at DESC";

$result = $conn->query($query);
$total_coupons = $result ? $result->num_rows : 0;

// Get statistics
$stats_query = $conn->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN is_active = 1 AND (expires_at IS NULL OR expires_at > NOW()) THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive,
        SUM(CASE WHEN expires_at IS NOT NULL AND expires_at < NOW() THEN 1 ELSE 0 END) as expired
    FROM coupon_codes
");
$stats = $stats_query ? $stats_query->fetch_assoc() : ['total' => 0, 'active' => 0, 'inactive' => 0, 'expired' => 0];

// Close result for reuse
if ($result) {
    $result->data_seek(0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <title>Coupons List | Larkon - Responsive Admin Dashboard Template</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="A fully responsive premium admin dashboard template" />
    <meta name="author" content="Techzaa" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />

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
    </style>
</head>

<body>
    <div class="wrapper">
        <?php include __DIR__.'/../includes/header.php' ?>
        <?php include __DIR__.'/../includes/sidebar.php' ?>

        <div class="page-content">
            <div class="container-xxl">
                <?php if (isset($_GET['deleted']) && $_GET['deleted'] == '1'): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    Coupon deleted successfully.
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php endif; ?>
                
                <?php if (isset($_GET['delete_error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars(urldecode($_GET['delete_error'])); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php endif; ?>

                <!-- Stats Cards -->
                <div class="row">
                    <div class="col-md-6 col-xl-3">
                        <div class="card bg-primary-subtle">
                            <div class="card-body">
                                <h4 class="mb-1"><?php echo $stats['total']; ?> Coupons</h4>
                                <p>Total coupons in system</p>
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h3 class="text-primary fw-semibold">Total</h3>
                                        <p class="mb-0">All Time</p>
                                    </div>
                                    <div>
                                        <span class="fs-48 text-primary">📊</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="card bg-success-subtle">
                            <div class="card-body">
                                <h4 class="mb-1"><?php echo $stats['active']; ?> Active</h4>
                                <p>Currently valid coupons</p>
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h3 class="text-success fw-semibold">Active</h3>
                                        <p class="mb-0">Ready to use</p>
                                    </div>
                                    <div>
                                        <span class="fs-48 text-success">✓</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="card bg-warning-subtle">
                            <div class="card-body">
                                <h4 class="mb-1"><?php echo $stats['expired']; ?> Expired</h4>
                                <p>Past expiry date</p>
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h3 class="text-warning fw-semibold">Expired</h3>
                                        <p class="mb-0">No longer valid</p>
                                    </div>
                                    <div>
                                        <span class="fs-48 text-warning">⏰</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="card bg-info-subtle">
                            <div class="card-body">
                                <h4 class="mb-1"><?php echo $stats['inactive']; ?> Inactive</h4>
                                <p>Manually disabled</p>
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h3 class="text-info fw-semibold">Inactive</h3>
                                        <p class="mb-0">Disabled by admin</p>
                                    </div>
                                    <div>
                                        <span class="fs-48 text-info">⏸️</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Coupons Table -->
                <div class="row">
                    <div class="col-xl-12">
                        <div class="card">
                            <div class="d-flex card-header justify-content-between align-items-center">
                                <div>
                                    <h4 class="card-title">All Coupons List</h4>
                                    <p class="text-muted mb-0">Showing <?php echo $total_coupons; ?> coupon(s)</p>
                                </div>
                                <div class="dropdown">
                                    <a href="#" class="dropdown-toggle btn btn-sm btn-outline-light rounded" data-bs-toggle="dropdown" aria-expanded="false">
                                        Export
                                    </a>
                                    <div class="dropdown-menu dropdown-menu-end">
                                        <a href="?export=csv" class="dropdown-item">CSV</a>
                                        <a href="?export=excel" class="dropdown-item">Excel</a>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <div class="table-responsive">
                                    <table class="table align-middle mb-0 table-hover table-centered">
                                        <thead class="bg-light-subtle">
                                            <tr>
                                                <th>ID</th>
                                                <th>Coupon Code</th>
                                                <th>Discount Type & Value</th>
                                                <th>Min. Order</th>
                                                <th>Usage</th>
                                                <th>Start Date</th>
                                                <th>End Date</th>
                                                <th>Status</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody id="couponsTableBody">
                                            <?php if ($result && $result->num_rows > 0): ?>
                                                <?php while ($row = $result->fetch_assoc()): ?>
                                                <?php
                                                    // Determine status
                                                    $is_expired = $row['expires_at'] && strtotime($row['expires_at']) < time();
                                                    $is_active = $row['is_active'] == 1 && !$is_expired;
                                                    
                                                    // Format dates
                                                    $starts_at = $row['starts_at'] ? date('d M Y', strtotime($row['starts_at'])) : '-';
                                                    $expires_at = $row['expires_at'] ? date('d M Y', strtotime($row['expires_at'])) : 'No expiry';
                                                    
                                                    // Usage text
                                                    $usage_text = $row['usage_limit'] ? 
                                                        $row['used_count'] . '/' . $row['usage_limit'] : 
                                                        $row['used_count'] . ' (Unlimited)';
                                                ?>
                                                <tr id="row-<?php echo $row['id']; ?>">
                                                    <td>#<?php echo $row['id']; ?></td>
                                                    <td>
                                                        <div class="d-flex align-items-center gap-2">
                                                            
                                                            <div>
                                                                <strong><?php echo htmlspecialchars($row['code']); ?></strong>
                                                                <p class="text-muted mb-0 mt-1 fs-13">
                                                                    Created: <?php echo date('d M Y', strtotime($row['created_at'])); ?>
                                                                </p>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-<?php echo $row['discount_type'] === 'percent' ? 'info' : 'primary'; ?>">
                                                            <?php echo $row['discount_type'] === 'percent' ? 'Percentage' : 'Fixed'; ?>
                                                        </span>
                                                        <div class="mt-1">
                                                            <strong><?php echo $row['discount_value']; ?><?php echo $row['discount_type'] === 'percent' ? '%' : '₹'; ?></strong>
                                                        </div>
                                                    </td>
                                                    <td>₹<?php echo number_format($row['min_order_value'], 2); ?></td>
                                                    <td>
                                                        <div class="progress" style="height: 6px;">
                                                            <?php if ($row['usage_limit']): ?>
                                                                <?php $percent = ($row['used_count'] / $row['usage_limit']) * 100; ?>
                                                                <div class="progress-bar bg-<?php echo $percent >= 100 ? 'danger' : ($percent >= 80 ? 'warning' : 'success'); ?>" 
                                                                     style="width: <?php echo min($percent, 100); ?>%"></div>
                                                            <?php else: ?>
                                                                <div class="progress-bar bg-success" style="width: <?php echo min($row['used_count'] * 10, 100); ?>%"></div>
                                                            <?php endif; ?>
                                                        </div>
                                                        <small class="text-muted"><?php echo $usage_text; ?></small>
                                                    </td>
                                                    <td><?php echo $starts_at; ?></td>
                                                    <td><?php echo $expires_at; ?></td>
                                                    <td>
                                                        <?php if ($is_expired): ?>
                                                            <span class="badge text-danger bg-danger-subtle fs-12">
                                                                <i class="bx bx-x"></i> Expired
                                                            </span>
                                                        <?php elseif (!$row['is_active']): ?>
                                                            <span class="badge text-secondary bg-secondary-subtle fs-12">
                                                                <i class="bx bx-pause"></i> Inactive
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="badge text-success bg-success-subtle fs-12">
                                                                <i class="bx bx-check-double"></i> Active
                                                            </span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <div class="d-flex gap-2">
                                                            <a href="add-coupen.php?id=<?php echo $row['id']; ?>" 
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
                                                    <td colspan="9" class="text-center py-4">
                                                        <div class="text-muted">
                                                            <iconify-icon icon="solar:coupon-broken" class="fs-48"></iconify-icon>
                                                            <h5 class="mt-2">No coupons found</h5>
                                                            <p>Create your first coupon by clicking "Add New Coupon"</p>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <?php if ($total_coupons > 10): ?>
                            <div class="card-footer border-top">
                                <nav aria-label="Page navigation example">
                                    <ul class="pagination justify-content-end mb-0">
                                        <li class="page-item"><a class="page-link" href="javascript:void(0);">Previous</a></li>
                                        <li class="page-item active"><a class="page-link" href="javascript:void(0);">1</a></li>
                                        <li class="page-item"><a class="page-link" href="javascript:void(0);">2</a></li>
                                        <li class="page-item"><a class="page-link" href="javascript:void(0);">3</a></li>
                                        <li class="page-item"><a class="page-link" href="javascript:void(0);">Next</a></li>
                                    </ul>
                                </nav>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Delete Confirmation Modal -->
            <div class="modal fade" id="deleteConfirmModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="deleteConfirmModalLabel" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="deleteConfirmModalLabel">Delete Coupon</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <p>Are you sure you want to delete this coupon? This action cannot be undone.</p>
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

            <footer class="footer">
                <div class="container-fluid">
                    <div class="row">
                        <div class="col-12 text-center">
                            <script>document.write(new Date().getFullYear())</script> &copy; Larkon. Crafted by 
                            <iconify-icon icon="iconamoon:heart-duotone" class="fs-18 align-middle text-danger"></iconify-icon> 
                            <a href="https://1.envato.market/techzaa" class="fw-bold footer-text" target="_blank">Techzaa</a>
                        </div>
                    </div>
                </div>
            </footer>
        </div>
    </div>

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

            /* -------------------
               Delete modal + AJAX
               ------------------- */
            var deleteModalEl = document.getElementById('deleteConfirmModal');
            var bsDeleteModal = null;
            
            // Use event delegation for delete buttons
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
                        showToast(json.message || 'Coupon deleted successfully.', 'success');
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

            // Auto-submit search on Enter
            var searchInput = document.querySelector('input[name="search"]');
            if (searchInput) {
                searchInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        this.form.submit();
                    }
                });
            }
        })();
    </script>
</body>
</html>
<?php
// Close database connection
if ($result) $result->close();
$conn->close();
?>