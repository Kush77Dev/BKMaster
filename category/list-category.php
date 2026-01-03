<?php
// categories-list.php
// Shows categories from DB in the table, supports delete via AJAX and inline edit-in-modal (loads add-category.php form).
// Also initializes Dropzone inside the modal so the fallback file input won't break modal styling.

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

/**
 * Resolve image web URL for an image stored in category/uploads/
 */
function resolve_category_image_url($stored)
{
     if (!$stored) return null;
     $stored = trim($stored);

     if (preg_match('#^https?://#i', $stored)) {
          return $stored;
     }

     $candidates = [];
     $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
     $scriptDirWeb = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

     $push = function ($fs, $web) use (&$candidates) {
          $candidates[] = ['fs' => $fs, 'web' => $web];
     };

     if ($stored !== '' && $stored[0] === '/') {
          $push($docRoot . $stored, $stored);
          $push($docRoot . '/category' . $stored, '/category' . $stored);
          $push(__DIR__ . $stored, $scriptDirWeb . $stored);
          $push(__DIR__ . '/category' . $stored, $scriptDirWeb . '/category' . $stored);
     } else {
          $push($docRoot . '/uploads/' . $stored, '/uploads/' . $stored);
          $push($docRoot . '/category/uploads/' . $stored, '/category/uploads/' . $stored);
          $push(__DIR__ . '/uploads/' . $stored, $scriptDirWeb . '/uploads/' . $stored);
          $push(__DIR__ . '/category/uploads/' . $stored, $scriptDirWeb . '/category/uploads/' . $stored);
          $push(__DIR__ . '/' . $stored, $scriptDirWeb . '/' . $stored);
          $push(__DIR__ . '/category/' . $stored, $scriptDirWeb . '/category/' . $stored);
     }

     if (strpos($stored, 'uploads/') === 0) {
          $push($docRoot . '/' . $stored, '/' . $stored);
          $push(__DIR__ . '/' . $stored, $scriptDirWeb . '/' . $stored);
     } elseif (strpos($stored, 'category/uploads/') === 0) {
          $push($docRoot . '/' . $stored, '/' . $stored);
          $push(__DIR__ . '/' . $stored, $scriptDirWeb . '/' . $stored);
     }

     foreach ($candidates as $c) {
          if (isset($c['fs']) && file_exists($c['fs'])) {
               $web = $c['web'];
               if ($web === '') continue;
               if ($web[0] !== '/') $web = '/' . ltrim($web, '/');
               return $web;
          }
     }

     if ($stored !== '' && $stored[0] === '/' && (strpos($stored, '/uploads/') === 0 || strpos($stored, '/category/uploads/') === 0)) {
          return $stored;
     }

     return null;
}

/* =========================
   Deletion logic
   - If POST && action=delete -> return JSON (AJAX)
   - Else if GET && action=delete -> perform delete and redirect (fallback)
   ========================= */

function delete_category_by_id($conn, $id, &$out_error = null)
{
     $image_url = null;
     $out_error = null;
     $id = (int)$id;
     if ($id <= 0) {
          $out_error = "Invalid category id.";
          return false;
     }

     // fetch image_url
     $stmt = $conn->prepare("SELECT image_url FROM categories WHERE id = ?");
     if (!$stmt) {
          $out_error = "Database error (prepare).";
          return false;
     }
     $stmt->bind_param('i', $id);
     $stmt->execute();
     $stmt->bind_result($image_url);
     $found = $stmt->fetch();
     $stmt->close();

     if (!$found) {
          $out_error = "Category not found.";
          return false;
     }

     // delete row
     $del = $conn->prepare("DELETE FROM categories WHERE id = ?");
     if (!$del) {
          $out_error = "Database error (prepare delete).";
          return false;
     }
     $del->bind_param('i', $id);
     if (!$del->execute()) {
          $out_error = "Failed to delete category (database).";
          $del->close();
          return false;
     }
     $del->close();

     // attempt to delete the image file (non-fatal)
     if ($image_url && trim($image_url) !== '') {
          $stored = trim($image_url);
          $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
          $scriptDir = __DIR__;
          $candidates = [];
          $add = function ($p) use (&$candidates) {
               $candidates[] = $p;
          };

          if (strpos($stored, '/') === 0) {
               $add($docRoot . $stored);
               $add($scriptDir . $stored);
               $add($docRoot . '/category' . $stored);
               $add($scriptDir . '/category' . $stored);
          }

          $base = basename($stored);
          if ($base) {
               $add($scriptDir . '/uploads/' . $base);
               $add($scriptDir . '/category/uploads/' . $base);
               $add($docRoot . '/uploads/' . $base);
               $add($docRoot . '/category/uploads/' . $base);
               $add($scriptDir . '/' . $stored);
               $add($docRoot . '/' . $stored);
          }

          $candidates = array_unique($candidates);
          foreach ($candidates as $fs) {
               if (!$fs) continue;
               $fs = str_replace(['//', '\\\\'], '/', $fs);
               if (file_exists($fs) && is_file($fs)) {
                    @unlink($fs);
               }
          }
     }

     return true;
}

// Handle AJAX delete (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
     // Expect id in POST
     $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
     $out_err = null;
     $ok = delete_category_by_id($conn, $id, $out_err);
     header('Content-Type: application/json; charset=utf-8');
     if ($ok) {
          echo json_encode(['success' => true, 'message' => 'Category deleted successfully.', 'id' => $id]);
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
     $ok = delete_category_by_id($conn, $id, $out_err);
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

// New: JSON endpoint to fetch single category (used to refresh UI after edit)
if (isset($_GET['action']) && $_GET['action'] === 'get_category_json' && isset($_GET['id'])) {
     $id = (int)$_GET['id'];
     $stmt = $conn->prepare("SELECT id, name, image_url, position FROM categories WHERE id = ?");
     if ($stmt) {
          $stmt->bind_param('i', $id);
          $stmt->execute();
          $res = $stmt->get_result();
          $row = $res ? $res->fetch_assoc() : null;
          $stmt->close();
          header('Content-Type: application/json; charset=utf-8');
          if ($row) {
               echo json_encode(['success' => true, 'category' => $row]);
          } else {
               echo json_encode(['success' => false, 'error' => 'Category not found.']);
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
     $countResult = $conn->query("SELECT COUNT(*) as total FROM categories");
     $totalRows = $countResult->fetch_assoc()['total'];
     $totalPages = ceil($totalRows / $perPage);
     
     // Get paginated data
     $sql = "SELECT id, name, image_url, position FROM categories ORDER BY position ASC, id DESC LIMIT $offset, $perPage";
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
   Normal page: fetch categories & random 4 for cards
   ========================= */

// Pagination variables
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($currentPage < 1) $currentPage = 1;
$perPage = 10; // Items per page

// Get total count for pagination
$countResult = $conn->query("SELECT COUNT(*) as total FROM categories");
$totalRows = $countResult->fetch_assoc()['total'];
$totalPages = ceil($totalRows / $perPage);

// Calculate offset
$offset = ($currentPage - 1) * $perPage;

// Fetch paginated categories (for the table)
$sql = "SELECT id, name, image_url, position FROM categories ORDER BY position ASC, id DESC LIMIT $offset, $perPage";
$result = $conn->query($sql);

// ALSO: fetch up to 4 random categories for the top cards
$randomCats = [];
$randSql = "SELECT id, name, image_url FROM categories ORDER BY RAND() LIMIT 4";
if ($randRes = $conn->query($randSql)) {
     while ($r = $randRes->fetch_assoc()) {
          $randomCats[] = $r;
     }
     $randRes->free();
}

// Build flash messages from GET params (set by fallback redirect)
$flashSuccess = "";
$flashError = "";
if (isset($_GET['deleted']) && $_GET['deleted'] == '1') {
     $flashSuccess = "Category deleted successfully.";
}
if (isset($_GET['delete_error'])) {
     $flashError = htmlspecialchars(urldecode($_GET['delete_error']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
     <meta charset="utf-8" />
     <meta name="viewport" content="width=device-width,initial-scale=1" />
     <title>Categories List</title>

     <!-- App favicon -->
     <link rel="shortcut icon" href="/assets/images/favicon.ico">

     <!-- Vendor css (Require in all Page) -->
     <link href="/assets/css/vendor.min.css" rel="stylesheet" type="text/css" />

     <!-- Icons css (Require in all Page) -->
     <link href="/assets/css/icons.min.css" rel="stylesheet" type="text/css" />

     <!-- App css (Require in all Page) -->
     <link href="/assets/css/app.min.css" rel="stylesheet" type="text/css" />

     <!-- Toastify CSS (for toasts) -->
     <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">

     <!-- Theme Config js (Require in all Page) -->
     <script src="/assets/js/config.js"></script>

     <style>
          /* avatar sizing and empty-avatar style */
          .avatar-md {
               width: 56px;
               height: 56px;
               object-fit: cover;
               border-radius: 6px;
          }

          .empty-avatar {
               width: 56px;
               height: 56px;
               border-radius: 6px;
               background: #f5f6f8;
               display: inline-block;
          }

          .category-name {
               margin-bottom: 4px;
          }

          /* only non-invasive small transition for smooth swap - does not change original styling */
          .avatar-xl,
          .card .card-body h4 {
               transition: opacity 0.4s ease;
          }

          /* optional small tweak so toast doesn't overlap with any fixed header */
          .toastify {
               z-index: 20000;
          }

          /* ONLY affects images inside the edit modal */
          #editCategoryModal .avatar-xxl,
          #editCategoryModal .avatar-xl,
          #editCategoryModal .avatar-md,
          #editCategoryModal .card-body img,
          #editCategoryModal .dropzone .dz-image img {
               width: 72px !important;
               /* desired width */
               height: 72px !important;
               /* desired height */
               max-width: 72px !important;
               max-height: 72px !important;
               object-fit: cover !important;
               border-radius: 6px !important;
          }
          
          /* Pagination loading indicator */
          .pagination-loading {
               display: none;
               text-align: center;
               padding: 10px;
          }
          
          .pagination-loading.active {
               display: block;
          }
     </style>
</head>

<body>

     <div class="wrapper">
          <?php include __DIR__ . '/../includes/header.php' ?>
          <?php include __DIR__ . '/../includes/sidebar.php' ?>

          <div class="page-content">
               <div class="container-xxl">

                    <div class="row">
                         <?php
                         // Default fallback images
                         $defaultImages = [
                              '/assets/images/product/p-1.png',
                              '/assets/images/product/p-6.png',
                              '/assets/images/product/p-7.png',
                              '/assets/images/product/p-9.png'
                         ];
                         $fallbackTitles = ['Fashion Categories', 'Electronics Headphone', 'Foot Wares', 'Eye Ware & Sunglass'];

                         // Render up to 4 cards: use randomCats if available; otherwise show fallback static cards
                         for ($i = 0; $i < 4; $i++):
                              $cat = isset($randomCats[$i]) ? $randomCats[$i] : null;
                              if ($cat) {
                                   $img = resolve_category_image_url($cat['image_url']);
                                   if (!$img) $img = $defaultImages[$i % count($defaultImages)];
                                   $title = $cat['name'] ?: $fallbackTitles[$i] ?? 'Category';
                              } else {
                                   $img = $defaultImages[$i % count($defaultImages)];
                                   $title = $fallbackTitles[$i] ?? 'Category';
                              }
                         ?>
                              <div class="col-md-6 col-xl-3">
                                   <div class="card">
                                        <div class="card-body text-center">
                                             <div class="rounded bg-secondary-subtle d-flex align-items-center justify-content-center mx-auto" style="width:100px;height:100px;">
                                                  <img id="card-img-<?php echo $i; ?>" src="<?php echo e($img); ?>" alt="<?php echo e($title); ?>" class="avatar-xl" onerror="this.src='<?php echo e($defaultImages[$i % count($defaultImages)]); ?>'">
                                             </div>
                                             <h4 id="card-title-<?php echo $i; ?>" class="mt-3 mb-0"><?php echo e($title); ?></h4>
                                        </div>
                                   </div>
                              </div>
                         <?php endfor; ?>
                    </div>

                    <div class="row">
                         <div class="col-xl-12">
                              <div class="card">
                                   <div class="card-header d-flex justify-content-between align-items-center gap-1">
                                        <h4 class="card-title flex-grow-1">All Categories List</h4>

                                        <a href="add-category.php" class="btn btn-sm btn-primary">
                                             Add Category
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
                                        <div class="table-responsive" id="categoriesTableContainer">
                                             <table class="table align-middle mb-0 table-hover table-centered">
                                                  <thead class="bg-light-subtle">
                                                       <tr>
                                                            <th style="width: 20px;">
                                                                 <div class="form-check">
                                                                      <input type="checkbox" class="form-check-input" id="customCheck1">
                                                                      <label class="form-check-label" for="customCheck1"></label>
                                                                 </div>
                                                            </th>
                                                            <th>Category Image</th>
                                                            <th>Category Name</th>
                                                            <th>Position</th>
                                                            <th>ID</th>
                                                            <th>Action</th>
                                                       </tr>
                                                  </thead>
                                                  <tbody id="categoriesTableBody">
                                                       <?php if ($result && $result->num_rows > 0): ?>
                                                            <?php while ($row = $result->fetch_assoc()): ?>
                                                                 <?php
                                                                 $imgUrl = resolve_category_image_url($row['image_url']);
                                                                 $displayId = $row['id'];
                                                                 $catName = $row['name'] ?: '-';
                                                                 $position = isset($row['position']) ? $row['position'] : '-';
                                                                 ?>
                                                                 <tr id="row-<?php echo e($row['id']); ?>">
                                                                      <td>
                                                                           <div class="form-check">
                                                                                <input type="checkbox" class="form-check-input" id="check_<?php echo e($row['id']); ?>">
                                                                                <label class="form-check-label" for="check_<?php echo e($row['id']); ?>"></label>
                                                                           </div>
                                                                      </td>

                                                                      <td>
                                                                           <div class="d-flex align-items-start gap-2">
                                                                                <div class="rounded bg-light d-flex align-items-center justify-content-center" style="width:56px;height:56px;border-radius:6px;">
                                                                                     <?php if ($imgUrl): ?>
                                                                                          <img id="img-<?php echo e($row['id']); ?>" src="<?php echo e($imgUrl); ?>" alt="<?php echo e($catName); ?>" class="avatar-md" onerror="this.style.display='none'">
                                                                                     <?php else: ?>
                                                                                          <span class="empty-avatar" aria-hidden="true"></span>
                                                                                     <?php endif; ?>
                                                                                </div>
                                                                           </div>
                                                                      </td>

                                                                      <td>
                                                                           <p id="name-<?php echo e($row['id']); ?>" class="text-dark fw-medium fs-15 mb-1 category-name"><?php echo e($catName); ?></p>
                                                                      </td>
                                                                      <td>
                                                                           <p id="pos-<?php echo e($row['id']); ?>" class="text-muted mb-0"><strong><?php echo e($position); ?></strong></p>
                                                                      </td>
                                                                      <td><?php echo e($displayId); ?></td>

                                                                      <td>
                                                                           <div class="d-flex gap-2">
                                                                                <a href="view-category.php?id=<?php echo e($row['id']); ?>" class="btn btn-light btn-sm" title="View"><iconify-icon icon="solar:eye-broken" class="align-middle fs-18"></iconify-icon></a>
                                                                                <!-- Open add-category.php in modal for editing -->
                                                                                <a href="add-category.php?id=<?php echo e($row['id']); ?>" class="btn btn-soft-primary btn-sm btn-edit" data-id="<?php echo e($row['id']); ?>" title="Edit"><iconify-icon icon="solar:pen-2-broken" class="align-middle fs-18"></iconify-icon></a>

                                                                                <!-- Delete (modal) -->
                                                                                <a href="?action=delete&id=<?php echo e($row['id']); ?>"
                                                                                     class="btn btn-soft-danger btn-sm btn-delete"
                                                                                     data-id="<?php echo e($row['id']); ?>"
                                                                                     title="Delete">
                                                                                     <iconify-icon icon="solar:trash-bin-minimalistic-2-broken" class="align-middle fs-18"></iconify-icon>
                                                                                </a>
                                                                           </div>
                                                                      </td>
                                                                 </tr>
                                                            <?php endwhile; ?>
                                                       <?php else: ?>
                                                            <tr>
                                                                 <td colspan="6" class="text-center text-muted py-4">No categories found.</td>
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
                                             Loading categories...
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

               </div> <!-- container -->
          </div> <!-- page-content -->

          <!-- Edit Modal (content loaded via AJAX from add-category.php and inserted) -->
          <div class="modal fade" id="editCategoryModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="editCategoryModalLabel" aria-hidden="true">
               <div class="modal-dialog modal-lg modal-dialog-scrollable">
                    <div class="modal-content">
                         <div class="modal-header">
                              <h5 class="modal-title" id="editCategoryModalLabel">Edit Category</h5>
                              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                         </div>
                         <div class="modal-body" id="editModalBody">
                              <!-- AJAX-loaded form will be inserted here -->
                              <div class="text-center py-4" id="edit-loading-placeholder">Loading...</div>
                         </div>
                    </div>
               </div>
          </div>

          <!-- Delete Confirmation Modal -->
          <div class="modal fade" id="deleteConfirmModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="deleteConfirmModalLabel" aria-hidden="true">
               <div class="modal-dialog">
                    <div class="modal-content">
                         <div class="modal-header">
                              <h5 class="modal-title" id="deleteConfirmModalLabel">Delete category</h5>
                              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                         </div>
                         <div class="modal-body">
                              <p>Are you sure you want to delete this category? This action cannot be undone.</p>
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

          <!-- Footer -->
          <footer class="footer">
               <div class="container-fluid">
                    <div class="row">
                         <div class="col-12 text-center">
                              <script>
                                   document.write(new Date().getFullYear())
                              </script> &copy; Larkon. Crafted by <iconify-icon icon="iconamoon:heart-duotone" class="fs-18 align-middle text-danger"></iconify-icon> <a href="https://1.envato.market/techzaa" class="fw-bold footer-text" target="_blank">Techzaa</a>
                         </div>
                    </div>
               </div>
          </footer>

     </div> <!-- wrapper -->

     <!-- Vendor Javascript (Require in all Page) -->
     <script src="/assets/js/vendor.js"></script>

     <!-- App Javascript (Require in all Page) -->
     <script src="/assets/js/app.js"></script>

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
               var categoriesTableBody = document.getElementById('categoriesTableBody');
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
                              categoriesTableBody.innerHTML = '';
                              
                              if (data.rows.length > 0) {
                                   data.rows.forEach(function(row) {
                                        var imgUrl = row.image_url ? row.image_url : '';
                                        var catName = row.name || '-';
                                        var position = row.position || '-';
                                        var displayId = row.id;
                                        
                                        var rowHtml = `
                                        <tr id="row-${row.id}">
                                             <td>
                                                  <div class="form-check">
                                                       <input type="checkbox" class="form-check-input" id="check_${row.id}">
                                                       <label class="form-check-label" for="check_${row.id}"></label>
                                                  </div>
                                             </td>
                                             <td>
                                                  <div class="d-flex align-items-start gap-2">
                                                       <div class="rounded bg-light d-flex align-items-center justify-content-center" style="width:56px;height:56px;border-radius:6px;">
                                                            ${imgUrl ? `<img id="img-${row.id}" src="${imgUrl}" alt="${catName}" class="avatar-md" onerror="this.style.display='none'">` : '<span class="empty-avatar" aria-hidden="true"></span>'}
                                                       </div>
                                                  </div>
                                             </td>
                                             <td>
                                                  <p id="name-${row.id}" class="text-dark fw-medium fs-15 mb-1 category-name">${catName}</p>
                                             </td>
                                             <td>
                                                  <p id="pos-${row.id}" class="text-muted mb-0"><strong>${position}</strong></p>
                                             </td>
                                             <td>${displayId}</td>
                                             <td>
                                                  <div class="d-flex gap-2">
                                                       <a href="view-category.php?id=${row.id}" class="btn btn-light btn-sm" title="View"><iconify-icon icon="solar:eye-broken" class="align-middle fs-18"></iconify-icon></a>
                                                       <a href="add-category.php?id=${row.id}" class="btn btn-soft-primary btn-sm btn-edit" data-id="${row.id}" title="Edit"><iconify-icon icon="solar:pen-2-broken" class="align-middle fs-18"></iconify-icon></a>
                                                       <a href="?action=delete&id=${row.id}" class="btn btn-soft-danger btn-sm btn-delete" data-id="${row.id}" title="Delete"><iconify-icon icon="solar:trash-bin-minimalistic-2-broken" class="align-middle fs-18"></iconify-icon></a>
                                                  </div>
                                             </td>
                                        </tr>
                                        `;
                                        categoriesTableBody.innerHTML += rowHtml;
                                   });
                              } else {
                                   categoriesTableBody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">No categories found.</td></tr>';
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
                              showToast(json.message || 'Category deleted successfully.', 'success');
                              
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

               /* -----------------------------
                  Edit in modal (AJAX load + submit)
                  ----------------------------- */

               var editModalEl = document.getElementById('editCategoryModal');
               var bsEditModal = bootstrap.Modal.getOrCreateInstance(editModalEl);
               var editModalBody = document.getElementById('editModalBody');

               // Function to init Dropzone inside modalForm (self-contained)
               function initModalDropzone(modalForm) {
                    if (!modalForm) return;

                    var _showToast = (typeof showToast === 'function') ? showToast : function(msg, type) {
                         alert((type === 'error' ? 'Error: ' : '') + msg);
                    };

                    // If Dropzone not loaded, hide fallback input to avoid ugly default file input
                    if (typeof Dropzone === 'undefined') {
                         var fallbackNoDZ = modalForm.querySelector('.fallback');
                         if (fallbackNoDZ) fallbackNoDZ.style.display = 'none';
                         return;
                    }

                    Dropzone.autoDiscover = false;

                    // Find the dropzone element inside the modal form
                    var dzEl = modalForm.querySelector('.dropzone');
                    if (!dzEl) {
                         // no dropzone markup present — nothing to init
                         return;
                    }

                    // Destroy any existing Dropzone attached to this element (defensive)
                    if (dzEl.dropzone && typeof dzEl.dropzone.destroy === 'function') {
                         try {
                              dzEl.dropzone.destroy();
                         } catch (e) {
                              /* ignore */ }
                    }

                    // Create Dropzone instance (keep options same as your add-category page)
                    var modalDropzone = new Dropzone(dzEl, {
                         url: modalForm.getAttribute('action') || window.location.pathname,
                         autoProcessQueue: false,
                         uploadMultiple: false,
                         maxFiles: 1,
                         paramName: "category_image",
                         addRemoveLinks: true,
                         acceptedFiles: "image/*"
                    });

                    // Hide fallback input so it doesn't show ugly default file control
                    var fallback = dzEl.querySelector('.fallback');
                    if (fallback) fallback.style.display = 'none';

                    // When Dropzone sends the file, append all non-file form fields
                    modalDropzone.on("sending", function(file, xhr, formData) {
                         var elements = modalForm.querySelectorAll("input[name], select[name], textarea[name]");
                         elements.forEach(function(el) {
                              if (!el.name) return;
                              if (el.type === 'file') return;
                              if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) return;
                              formData.append(el.name, el.value);
                         });
                         try {
                              xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                         } catch (e) {}
                    });

                    // Success handler: expect JSON (string or parsed)
                    modalDropzone.on("success", function(file, resp) {
                         var data = resp;
                         if (typeof resp === 'string') {
                              try {
                                   data = JSON.parse(resp);
                              } catch (e) {
                                   data = {
                                        success: resp
                                   };
                              }
                         }
                         if (data && data.success) {
                              try {
                                   var bs = bootstrap.Modal.getInstance(document.getElementById('editCategoryModal'));
                                   if (bs) bs.hide();
                              } catch (e) {}
                              _showToast(data.success, 'success');

                              var catId = data.id || (modalForm.querySelector('input[name="id"]') && modalForm.querySelector('input[name="id"]').value);
                              if (catId) {
                                   fetch(window.location.pathname + '?action=get_category_json&id=' + encodeURIComponent(catId), {
                                             credentials: 'same-origin'
                                        })
                                        .then(function(r) {
                                             return r.json();
                                        })
                                        .then(function(j) {
                                             if (j && j.success && j.category) {
                                                  var cat = j.category;
                                                  var nameEl = document.getElementById('name-' + cat.id);
                                                  if (nameEl) nameEl.textContent = cat.name || '-';
                                                  var posEl = document.getElementById('pos-' + cat.id);
                                                  if (posEl) posEl.innerHTML = '<strong>' + (cat.position !== null ? cat.position : '-') + '</strong>';
                                                  var imgEl = document.getElementById('img-' + cat.id);
                                                  if (imgEl) {
                                                       if (cat.image_url) {
                                                            imgEl.src = cat.image_url;
                                                            imgEl.style.display = '';
                                                       } else imgEl.style.display = 'none';
                                                  }
                                             }
                                        }).catch(function() {});
                              }

                         } else {
                              _showToast((data && data.error) ? data.error : 'Upload complete', 'error');
                         }
                    });

                    // Error handler
                    modalDropzone.on("error", function(file, resp) {
                         var msg = 'Upload failed';
                         if (!resp && file.xhr && file.xhr.response) resp = file.xhr.response;
                         if (typeof resp === 'string') {
                              try {
                                   var parsed = JSON.parse(resp);
                                   if (parsed && parsed.error) msg = parsed.error;
                                   else if (parsed && parsed.success) msg = parsed.success;
                                   else msg = resp;
                              } catch (e) {
                                   msg = resp;
                              }
                         } else if (typeof resp === 'object' && resp !== null) {
                              if (resp.error) msg = resp.error;
                              else if (resp.message) msg = resp.message;
                         }
                         _showToast(msg, 'error');
                    });

                    // Helper: submit form via fetch without file (used when no file queued)
                    function submitFormViaFetch() {
                         var fd = new FormData(modalForm);
                         fetch(modalForm.getAttribute('action') || 'add-category.php', {
                                   method: 'POST',
                                   headers: {
                                        'X-Requested-With': 'XMLHttpRequest'
                                   },
                                   body: fd,
                                   credentials: 'same-origin'
                              }).then(function(resp) {
                                   return resp.json();
                              })
                              .then(function(json) {
                                   if (json && json.success) {
                                        try {
                                             var bs2 = bootstrap.Modal.getInstance(document.getElementById('editCategoryModal'));
                                             if (bs2) bs2.hide();
                                        } catch (e) {}
                                        _showToast(json.success, 'success');

                                        var catId = json.id || fd.get('id');
                                        if (catId) {
                                             fetch(window.location.pathname + '?action=get_category_json&id=' + encodeURIComponent(catId), {
                                                       credentials: 'same-origin'
                                                  })
                                                  .then(function(r) {
                                                       return r.json();
                                                  }).then(function(j) {
                                                       if (j && j.success && j.category) {
                                                            var cat = j.category;
                                                            var nameEl = document.getElementById('name-' + cat.id);
                                                            if (nameEl) nameEl.textContent = cat.name || '-';
                                                            var posEl = document.getElementById('pos-' + cat.id);
                                                            if (posEl) posEl.innerHTML = '<strong>' + (cat.position !== null ? cat.position : '-') + '</strong>';
                                                            var imgEl = document.getElementById('img-' + cat.id);
                                                            if (imgEl) {
                                                                 if (cat.image_url) {
                                                                      imgEl.src = cat.image_url;
                                                                      imgEl.style.display = '';
                                                                 } else imgEl.style.display = 'none';
                                                            }
                                                       }
                                                  }).catch(function() {});
                                        }
                                   } else {
                                        _showToast((json && json.error) ? json.error : 'Save failed', 'error');
                                   }
                              }).catch(function(err) {
                                   _showToast('Request failed: ' + (err && err.message ? err.message : 'network error'), 'error');
                              });
                    }

                    // Wire modal Save button (your add-category form uses #saveBtn)
                    var modalSaveBtn = modalForm.querySelector('#saveBtn') || modalForm.querySelector('button[type="submit"], button:not([type])');
                    if (modalSaveBtn) {
                         var newBtn = modalSaveBtn.cloneNode(true);
                         modalSaveBtn.parentNode.replaceChild(newBtn, modalSaveBtn);
                         modalSaveBtn = newBtn;

                         modalSaveBtn.addEventListener('click', function(ev) {
                              ev.preventDefault();

                              // validate name field
                              var nameInput = modalForm.querySelector('input[name="name"]');
                              if (!nameInput || nameInput.value.trim() === '') {
                                   alert('Category Title is required.');
                                   if (nameInput) nameInput.focus();
                                   return;
                              }

                              // If Dropzone has a queued file, process it; otherwise submit via fetch
                              if (modalDropzone.getQueuedFiles().length > 0) {
                                   modalDropzone.processQueue();
                              } else {
                                   submitFormViaFetch();
                              }
                         });
                    }
               } // end initModalDropzone

               // Use event delegation for edit buttons since they're dynamically loaded
               document.addEventListener('click', function(e) {
                    var editBtn = e.target.closest('.btn-edit');
                    if (!editBtn) return;
                    e.preventDefault();

                    var href = editBtn.getAttribute('href') || editBtn.dataset.href;
                    if (!href) return;
                    editModalBody.innerHTML = '<div class="text-center py-4">Loading...</div>';
                    bsEditModal.show();

                    fetch(href, {
                         credentials: 'same-origin'
                    }).then(function(resp) {
                         return resp.text();
                    }).then(function(html) {
                         var parser = new DOMParser();
                         var doc = parser.parseFromString(html, 'text/html');
                         var form = doc.querySelector('#categoryForm');
                         if (!form) {
                              editModalBody.innerHTML = '<div class="p-3">Could not load edit form. Try opening in a new tab.<br><a class="btn btn-sm btn-primary mt-2" href="' + href + '" target="_blank">Open</a></div>';
                              return;
                         }
                         // Insert form HTML into modal
                         editModalBody.innerHTML = form.outerHTML;

                         // Now wire the form in modal
                         var modalForm = editModalBody.querySelector('#categoryForm');
                         if (!modalForm) return;

                         // Initialize Dropzone inside modal to hide fallback input and enable drag-drop
                         initModalDropzone(modalForm);

                         // Attach save handling for cases where Dropzone isn't used or for extra safety (the Dropzone init also wires the button)
                         var modalSaveBtn = modalForm.querySelector('#saveBtn') || modalForm.querySelector('button[type="submit"], button:not([type])');

                         function handleSaveClickFallback(e) {
                              e.preventDefault();
                              var nameInp = modalForm.querySelector('input[name="name"]');
                              if (!nameInp || nameInp.value.trim() === '') {
                                   alert('Category Title is required.');
                                   nameInp && nameInp.focus();
                                   return;
                              }
                              // Submit as FormData (no file or fallback)
                              var fd = new FormData(modalForm);
                              fetch(modalForm.getAttribute('action') || 'add-category.php', {
                                   method: 'POST',
                                   headers: {
                                        'X-Requested-With': 'XMLHttpRequest'
                                   },
                                   body: fd,
                                   credentials: 'same-origin'
                              }).then(function(r) {
                                   return r.json();
                              }).then(function(json) {
                                   if (json && json.success) {
                                        bsEditModal.hide();
                                        showToast(json.success, 'success');
                                        var catId = json.id || fd.get('id');
                                        if (catId) {
                                             fetch(window.location.pathname + '?action=get_category_json&id=' + encodeURIComponent(catId), {
                                                       credentials: 'same-origin'
                                                  })
                                                  .then(function(r) {
                                                       return r.json();
                                                  })
                                                  .then(function(j) {
                                                       if (j && j.success && j.category) {
                                                            var cat = j.category;
                                                            var nameEl = document.getElementById('name-' + cat.id);
                                                            if (nameEl) nameEl.textContent = cat.name || '-';
                                                            var posEl = document.getElementById('pos-' + cat.id);
                                                            if (posEl) posEl.innerHTML = '<strong>' + (cat.position !== null ? cat.position : '-') + '</strong>';
                                                            var imgEl = document.getElementById('img-' + cat.id);
                                                            if (imgEl) {
                                                                 if (cat.image_url) {
                                                                      imgEl.src = cat.image_url;
                                                                      imgEl.style.display = '';
                                                                 } else imgEl.style.display = 'none';
                                                            }
                                                       }
                                                  }).catch(function() {});
                                        }
                                   } else {
                                        showToast((json && json.error) ? json.error : 'Save failed', 'error');
                                   }
                              }).catch(function(err) {
                                   showToast('Request failed: ' + (err && err.message ? err.message : 'network error'), 'error');
                              });
                         }

                         if (modalSaveBtn) {
                              // avoid duplicate listeners: replace
                              modalSaveBtn.replaceWith(modalSaveBtn.cloneNode(true));
                              modalSaveBtn = modalForm.querySelector('#saveBtn') || modalForm.querySelector('button[type="submit"], button:not([type])');
                              modalSaveBtn.addEventListener('click', handleSaveClickFallback);
                         } else {
                              modalForm.addEventListener('submit', function(ev) {
                                   ev.preventDefault();
                                   handleSaveClickFallback(ev);
                              });
                         }

                    }).catch(function(err) {
                         editModalBody.innerHTML = '<div class="p-3 text-danger">Failed to load form: ' + (err && err.message ? err.message : 'network error') + '</div>';
                    });
               });

               // When modal hidden, clear body to free DOM and avoid duplicate IDs
               editModalEl.addEventListener('hidden.bs.modal', function() {
                    editModalBody.innerHTML = '<div class="text-center py-4">Loading...</div>';
               });

          })();
     </script>

</body>

</html>
<?php
if (isset($result) && $result) $result->free();
$conn->close();
?>