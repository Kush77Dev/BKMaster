<?php
// list-users.php
// Shows users from DB in the table, supports delete via AJAX.

// ----- EDIT THESE DB CREDENTIALS IF NEEDED -----
$DB_HOST = 'localhost';
$DB_USER = 'root';
$DB_PASS = '';
$DB_NAME = 'bkmaster';
// ----------------------------------------------

$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($conn->connect_error) {
     die("DB connect error: " . $conn->connect_error);
}

$success = "";
$error = "";

// Helper: safe output
function e($str)
{
     return htmlspecialchars((string)$str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Helper: get initials from name
function getInitials($firstName, $lastName)
{
    $initials = '';
    if (!empty($firstName)) {
        $initials .= strtoupper(substr($firstName, 0, 1));
    }
    if (!empty($lastName)) {
        $initials .= strtoupper(substr($lastName, 0, 1));
    }
    if (empty($initials) && !empty($firstName)) {
        $initials = strtoupper(substr($firstName, 0, 1));
    }
    if (empty($initials) && !empty($lastName)) {
        $initials = strtoupper(substr($lastName, 0, 1));
    }
    if (empty($initials)) {
        $initials = 'U'; // Default for unknown
    }
    return $initials;
}

// Helper: get color based on name
function getColorFromName($name)
{
    $colors = [
        '#4A6FA5', '#166088', '#2D3047', '#419D78',
        '#E0A458', '#C04ABC', '#DB5461', '#686963',
        '#3D5A80', '#98C1D9', '#EE6C4D', '#293241',
        '#6B2737', '#E43F6F', '#1F2041', '#FFC857'
    ];
    
    $hash = 0;
    for ($i = 0; $i < strlen($name); $i++) {
        $hash = ord($name[$i]) + (($hash << 5) - $hash);
    }
    
    $index = abs($hash) % count($colors);
    return $colors[$index];
}

/* =========================
   Deletion logic
   - If POST && action=delete -> return JSON (AJAX)
   - Else if GET && action=delete -> perform delete and redirect (fallback)
   ========================= */

function delete_user_by_id($conn, $id, &$out_error = null)
{
     $out_error = null;
     $id = (int)$id;
     if ($id <= 0) {
          $out_error = "Invalid user id.";
          return false;
     }

     // Check if user exists
     $stmt = $conn->prepare("SELECT id FROM users WHERE id = ?");
     if (!$stmt) {
          $out_error = "Database error (prepare).";
          return false;
     }
     $stmt->bind_param('i', $id);
     $stmt->execute();
     $stmt->store_result();
     $found = $stmt->num_rows > 0;
     $stmt->close();

     if (!$found) {
          $out_error = "User not found.";
          return false;
     }

     // delete row
     $del = $conn->prepare("DELETE FROM users WHERE id = ?");
     if (!$del) {
          $out_error = "Database error (prepare delete).";
          return false;
     }
     $del->bind_param('i', $id);
     if (!$del->execute()) {
          $out_error = "Failed to delete user (database).";
          $del->close();
          return false;
     }
     $del->close();

     return true;
}

// Handle AJAX delete (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
     // Expect id in POST
     $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
     $out_err = null;
     $ok = delete_user_by_id($conn, $id, $out_err);
     header('Content-Type: application/json; charset=utf-8');
     if ($ok) {
          echo json_encode(['success' => true, 'message' => 'User deleted successfully.', 'id' => $id]);
          exit;
     } else {
          echo json_encode(['success' => false, 'error' => $out_err ?: 'Unknown error']);
          exit;
     }
}

// Handle fallback GET delete (graceful fallback)
if (isset($_GET['action']) && $_GET['action'] === 'delete' && $_SERVER['REQUEST_METHOD'] === 'GET') {
     $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
     $out_err = null;
     $ok = delete_user_by_id($conn, $id, $out_err);
     if ($ok) {
          // PRG: redirect with flag
          header("Location: " . strtok($_SERVER["REQUEST_URI"], '?') . "?deleted=1");
          exit;
     } else {
          $q = urlencode($out_err ?: 'Unknown error');
          header("Location: " . strtok($_SERVER["REQUEST_URI"], '?') . "?delete_error={$q}");
          exit;
     }
}

// New: JSON endpoint to fetch single user (used to refresh UI after edit if needed)
if (isset($_GET['action']) && $_GET['action'] === 'get_user_json' && isset($_GET['id'])) {
     $id = (int)$_GET['id'];
     $stmt = $conn->prepare("SELECT id, email, first_name, last_name, phone, created_at FROM users WHERE id = ?");
     if ($stmt) {
          $stmt->bind_param('i', $id);
          $stmt->execute();
          $res = $stmt->get_result();
          $row = $res ? $res->fetch_assoc() : null;
          $stmt->close();
          header('Content-Type: application/json; charset=utf-8');
          if ($row) {
               echo json_encode(['success' => true, 'user' => $row]);
          } else {
               echo json_encode(['success' => false, 'error' => 'User not found.']);
          }
          exit;
     } else {
          header('Content-Type: application/json; charset=utf-8');
          echo json_encode(['success' => false, 'error' => 'DB error']);
          exit;
     }
}

// AJAX endpoint for pagination
if (isset($_GET['action']) && $_GET['action'] === 'paginate' && isset($_GET['page'])) {
     $page = (int)$_GET['page'];
     $perPage = 10; // Number of items per page
     $offset = ($page - 1) * $perPage;
     
     // Get total count
     $countResult = $conn->query("SELECT COUNT(*) as total FROM users");
     $totalRows = $countResult->fetch_assoc()['total'];
     $totalPages = ceil($totalRows / $perPage);
     
     // Get paginated data
     $sql = "SELECT id, email, first_name, last_name, phone, created_at FROM users ORDER BY created_at DESC LIMIT $offset, $perPage";
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

/* =========================
   Normal page: fetch users
   ========================= */

// Pagination variables
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($currentPage < 1) $currentPage = 1;
$perPage = 10; // Items per page

// Get total count for pagination
$countResult = $conn->query("SELECT COUNT(*) as total FROM users");
$totalRows = $countResult->fetch_assoc()['total'];
$totalPages = ceil($totalRows / $perPage);

// Calculate offset
$offset = ($currentPage - 1) * $perPage;

// Fetch paginated users (for the table)
$sql = "SELECT id, email, first_name, last_name, phone, created_at FROM users ORDER BY created_at DESC LIMIT $offset, $perPage";
$result = $conn->query($sql);

// Build flash messages from GET params (set by fallback redirect)
$flashSuccess = "";
$flashError = "";
if (isset($_GET['deleted']) && $_GET['deleted'] == '1') {
     $flashSuccess = "User deleted successfully.";
}
if (isset($_GET['delete_error'])) {
     $flashError = htmlspecialchars(urldecode($_GET['delete_error']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">

<!-- Mirrored from techzaa.in/larkon/admin/customer-list.html by HTTrack Website Copier/3.x [XR&CO'2014], Sat, 29 Nov 2025 04:20:03 GMT -->
<head>
    <!-- Title Meta -->
    <meta charset="utf-8" />
    <title>Customer List | Larkon - Responsive Admin Dashboard Template</title>
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
        /* Pagination loading indicator */
        .pagination-loading {
            display: none;
            text-align: center;
            padding: 10px;
        }
        
        .pagination-loading.active {
            display: block;
        }
        
        /* Initials Avatar Styles */
        .initials-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 14px;
            text-transform: uppercase;
            margin-right: 8px;
        }
        
        .user-name-cell {
            display: flex;
            align-items: center;
        }
    </style>
</head>

<body>

    <!-- START Wrapper -->
    <div class="wrapper">

       <?php include __DIR__.'/../includes/header.php' ?>
       <?php include __DIR__.'/../includes/sidebar.php' ?>

        <!-- ==================================================== -->
        <!-- Start right Content here -->
        <!-- ==================================================== -->

        <div class="page-content">

            <!-- Start Container Fluid -->
            <div class="container-xxl">
                <div class="row">
                    <div class="col-xl-12">
                         <div class="card">
                              <div class="d-flex card-header justify-content-between align-items-center">
                                   <div>
                                        <h4 class="card-title">All Customers List</h4>
                                   </div>
                                   <div class="dropdown">
                                        <a href="#" class="dropdown-toggle btn btn-sm btn-outline-light rounded" data-bs-toggle="dropdown" aria-expanded="false">
                                             This Month
                                        </a>
                                        <div class="dropdown-menu dropdown-menu-end">
                                             <!-- item-->
                                             <a href="#!" class="dropdown-item">Download</a>
                                             <!-- item-->
                                             <a href="#!" class="dropdown-item">Export</a>
                                             <!-- item-->
                                             <a href="#!" class="dropdown-item">Import</a>
                                        </div>
                                   </div>
                              </div>
                              <div>
                                   <div class="table-responsive" id="usersTableContainer">
                                        <table class="table align-middle mb-0 table-hover table-centered">
                                             <thead class="bg-light-subtle">
                                                  <tr>
                                                       <th style="width: 20px;">
                                                            <div class="form-check">
                                                                 <input type="checkbox" class="form-check-input" id="customCheck1">
                                                                 <label class="form-check-label" for="customCheck1"></label>
                                                            </div>
                                                       </th>
                                                       <th>Customer Name</th>
                                                       <th>Email</th>
                                                       <th>Phone</th>
                                                       <th>Join Date</th>
                                                       <th>Status</th>
                                                       <th>Action</th>
                                                  </tr>
                                             </thead>
                                             <tbody id="usersTableBody">
                                                  <?php if ($result && $result->num_rows > 0): ?>
                                                       <?php while ($row = $result->fetch_assoc()): ?>
                                                            <?php
                                                            $fullName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                                                            if (empty($fullName)) {
                                                                 $fullName = 'Unknown User';
                                                            }
                                                            $email = $row['email'] ?? '-';
                                                            $phone = $row['phone'] ?? '-';
                                                            $joinDate = date('d M, Y', strtotime($row['created_at'] ?? ''));
                                                            $userId = $row['id'];
                                                            
                                                            // Get initials and color
                                                            $initials = getInitials($row['first_name'] ?? '', $row['last_name'] ?? '');
                                                            $color = getColorFromName($fullName);
                                                            ?>
                                                            <tr id="row-<?php echo e($userId); ?>">
                                                                 <td>
                                                                      <div class="form-check">
                                                                           <input type="checkbox" class="form-check-input" id="check_<?php echo e($userId); ?>">
                                                                           <label class="form-check-label" for="check_<?php echo e($userId); ?>"></label>
                                                                      </div>
                                                                 </td>
                                                                 <td>
                                                                      <div class="user-name-cell">
                                                                           <div class="initials-avatar" style="background-color: <?php echo e($color); ?>;">
                                                                                <?php echo e($initials); ?>
                                                                           </div>
                                                                           <?php echo e($fullName); ?>
                                                                      </div>
                                                                 </td>
                                                                 <td><?php echo e($email); ?></td>
                                                                 <td><?php echo e($phone); ?></td>
                                                                 <td><?php echo e($joinDate); ?></td>
                                                                 <td>
                                                                      <span class="badge bg-success-subtle text-success py-1 px-2">Active</span>
                                                                 </td>
                                                                 <td>
                                                                      <div class="d-flex gap-2">
                                                                           <a href="detail-customer.php?id=<?php echo e($userId); ?>" class="btn btn-light btn-sm"><iconify-icon icon="solar:eye-broken" class="align-middle fs-18"></iconify-icon></a>
                                                                           <a href="edit-user.php?id=<?php echo e($userId); ?>" class="btn btn-soft-primary btn-sm"><iconify-icon icon="solar:pen-2-broken" class="align-middle fs-18"></iconify-icon></a>
                                                                           <a href="?action=delete&id=<?php echo e($userId); ?>"
                                                                                class="btn btn-soft-danger btn-sm btn-delete"
                                                                                data-id="<?php echo e($userId); ?>">
                                                                                <iconify-icon icon="solar:trash-bin-minimalistic-2-broken" class="align-middle fs-18"></iconify-icon>
                                                                           </a>
                                                                      </div>
                                                                 </td>
                                                            </tr>
                                                       <?php endwhile; ?>
                                                  <?php else: ?>
                                                       <tr>
                                                            <td colspan="7" class="text-center text-muted py-4">No users found.</td>
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
                                        Loading users...
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
                                <h5 class="modal-title" id="deleteConfirmModalLabel">Delete User</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                           </div>
                           <div class="modal-body">
                                <p>Are you sure you want to delete this user? This action cannot be undone.</p>
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
            // small helper to show a toast (same look you used in add-category)
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

            // Helper: get initials from name
            function getInitials(firstName, lastName) {
                let initials = '';
                if (firstName && firstName.length > 0) {
                    initials += firstName.charAt(0).toUpperCase();
                }
                if (lastName && lastName.length > 0) {
                    initials += lastName.charAt(0).toUpperCase();
                }
                if (!initials && firstName && firstName.length > 0) {
                    initials = firstName.charAt(0).toUpperCase();
                }
                if (!initials && lastName && lastName.length > 0) {
                    initials = lastName.charAt(0).toUpperCase();
                }
                if (!initials) {
                    initials = 'U';
                }
                return initials;
            }

            // Helper: get color based on name (consistent with PHP)
            function getColorFromName(name) {
                const colors = [
                    '#4A6FA5', '#166088', '#2D3047', '#419D78',
                    '#E0A458', '#C04ABC', '#DB5461', '#686963',
                    '#3D5A80', '#98C1D9', '#EE6C4D', '#293241',
                    '#6B2737', '#E43F6F', '#1F2041', '#FFC857'
                ];
                
                let hash = 0;
                for (let i = 0; i < name.length; i++) {
                    hash = name.charCodeAt(i) + ((hash << 5) - hash);
                }
                
                const index = Math.abs(hash) % colors.length;
                return colors[index];
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
            var usersTableBody = document.getElementById('usersTableBody');
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
                           usersTableBody.innerHTML = '';
                           
                           if (data.rows.length > 0) {
                                data.rows.forEach(function(row) {
                                     var fullName = (row.first_name || '') + ' ' + (row.last_name || '');
                                     fullName = fullName.trim();
                                     if (!fullName) fullName = 'Unknown User';
                                     
                                     var email = row.email || '-';
                                     var phone = row.phone || '-';
                                     var joinDate = row.created_at ? new Date(row.created_at).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '-';
                                     var userId = row.id;
                                     
                                     // Get initials and color
                                     var initials = getInitials(row.first_name || '', row.last_name || '');
                                     var color = getColorFromName(fullName);
                                     
                                     var rowHtml = `
                                     <tr id="row-${row.id}">
                                          <td>
                                               <div class="form-check">
                                                    <input type="checkbox" class="form-check-input" id="check_${row.id}">
                                                    <label class="form-check-label" for="check_${row.id}"></label>
                                               </div>
                                          </td>
                                          <td>
                                               <div class="user-name-cell">
                                                    <div class="initials-avatar" style="background-color: ${color}">
                                                         ${initials}
                                                    </div>
                                                    ${fullName}
                                               </div>
                                          </td>
                                          <td>${email}</td>
                                          <td>${phone}</td>
                                          <td>${joinDate}</td>
                                          <td>
                                               <span class="badge bg-success-subtle text-success py-1 px-2">Active</span>
                                          </td>
                                          <td>
                                               <div class="d-flex gap-2">
                                                    <a href="view-user.php?id=${row.id}" class="btn btn-light btn-sm"><iconify-icon icon="solar:eye-broken" class="align-middle fs-18"></iconify-icon></a>
                                                    <a href="edit-user.php?id=${row.id}" class="btn btn-soft-primary btn-sm"><iconify-icon icon="solar:pen-2-broken" class="align-middle fs-18"></iconify-icon></a>
                                                    <a href="?action=delete&id=${row.id}" class="btn btn-soft-danger btn-sm btn-delete" data-id="${row.id}"><iconify-icon icon="solar:trash-bin-minimalistic-2-broken" class="align-middle fs-18"></iconify-icon></a>
                                               </div>
                                          </td>
                                     </tr>
                                     `;
                                     usersTableBody.innerHTML += rowHtml;
                                });
                           } else {
                                usersTableBody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">No users found.</td></tr>';
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
                           showToast(json.message || 'User deleted successfully.', 'success');
                           
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


<!-- Mirrored from techzaa.in/larkon/admin/customer-list.html by HTTrack Website Copier/3.x [XR&CO'2014], Sat, 29 Nov 2025 04:20:03 GMT -->
</html>
<?php
if (isset($result) && $result) $result->free();
$conn->close();
?>