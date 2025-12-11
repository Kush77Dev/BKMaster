<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>

     <!-- App favicon -->
     <link rel="shortcut icon" href="/../assets/images/favicon.ico">

     <!-- Vendor css (Require in all Page) -->
     <link href="/../assets/css/vendor.min.css" rel="stylesheet" type="text/css" />

     <!-- Icons css (Require in all Page) -->
     <link href="/../assets/css/icons.min.css" rel="stylesheet" type="text/css" />

     <!-- App css (Require in all Page) -->
     <link href="/../assets/css/app.min.css" rel="stylesheet" type="text/css" />

     <!-- Theme Config js (Require in all Page) -->
     <script src="/../assets/js/config.js"></script>
</head>
<body>

     <!-- START Wrapper -->
     <div class="wrapper">

          <!-- ========== Topbar Start ========== -->
          <?php include __DIR__.'/../includes/header.php' ?>

         

          <!-- ========== App Menu Start ========== -->
          <?php include __DIR__.'/../includes/sidebar.php' ?>
          <!-- ========== App Menu End ========== -->

          <!-- ==================================================== -->
          <!-- Start right Content here -->
          <!-- ==================================================== -->
         <div class="page-content">

               <!-- Start Container Fluid -->
               <div class="container-fluid">

                    <div class="row">
                         <div class="col-xl-12">
                              <div class="card">
                                   <div class="card-header d-flex justify-content-between align-items-center gap-1">
                                        <h4 class="card-title flex-grow-1">All Product List</h4>

                                        <a href="product-add.html" class="btn btn-sm btn-primary">
                                             Add Product
                                        </a>

                                        <div class="dropdown">
                                             <a href="#" class="dropdown-toggle btn btn-sm btn-outline-light" data-bs-toggle="dropdown" aria-expanded="false">
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
                                        <div class="table-responsive">
                                             <table class="table align-middle mb-0 table-hover table-centered">
                                                  <thead class="bg-light-subtle">
                                                       <tr>
                                                            <th style="width: 20px;">
                                                                 <div class="form-check ms-1">
                                                                      <input type="checkbox" class="form-check-input" id="customCheck1">
                                                                      <label class="form-check-label" for="customCheck1"></label>
                                                                 </div>
                                                            </th>
                                                            <th>Product Name & Size</th>
                                                            <th>Price</th>
                                                            <th>Stock</th>
                                                            <th>Category</th>
                                                            <th>Rating</th>
                                                            <th>Action</th>
                                                       </tr>
                                                  </thead>
                                                  <tbody>
                                                       <tr>
                                                            <td>
                                                                 <div class="form-check ms-1">
                                                                      <input type="checkbox" class="form-check-input" id="customCheck2">
                                                                      <label class="form-check-label" for="customCheck2">&nbsp;</label>
                                                                 </div>
                                                            </td>
                                                            <td>
                                                                 <div class="d-flex align-items-center gap-2">
                                                                      <div class="rounded bg-light avatar-md d-flex align-items-center justify-content-center">
                                                                           <img src="/../assets/images/product/p-1.png" alt="" class="avatar-md">
                                                                      </div>
                                                                      <div>
                                                                           <a href="#!" class="text-dark fw-medium fs-15">Black T-shirt</a>
                                                                           <p class="text-muted mb-0 mt-1 fs-13"><span>Size : </span>S , M , L , Xl </p>
                                                                      </div>
                                                                 </div>

                                                            </td>
                                                            <td>$80.00</td>
                                                            <td>
                                                                 <p class="mb-1 text-muted"><span class="text-dark fw-medium">486 Item</span> Left</p>
                                                                 <p class="mb-0 text-muted">155 Sold</p>
                                                            </td>
                                                            <td> Fashion</td>
                                                            <td> <span class="badge p-1 bg-light text-dark fs-12 me-1"><i class="bx bxs-star align-text-top fs-14 text-warning me-1"></i> 4.5</span> 55 Review</td>
                                                            <td>
                                                                 <div class="d-flex gap-2">
                                                                      <a href="#!" class="btn btn-light btn-sm"><iconify-icon icon="solar:eye-broken" class="align-middle fs-18"></iconify-icon></a>
                                                                      <a href="#!" class="btn btn-soft-primary btn-sm"><iconify-icon icon="solar:pen-2-broken" class="align-middle fs-18"></iconify-icon></a>
                                                                      <a href="#!" class="btn btn-soft-danger btn-sm"><iconify-icon icon="solar:trash-bin-minimalistic-2-broken" class="align-middle fs-18"></iconify-icon></a>
                                                                 </div>
                                                            </td>
                                                       </tr>

                                                       <tr>
                                                            <td>
                                                                 <div class="form-check ms-1">
                                                                      <input type="checkbox" class="form-check-input" id="customCheck2">
                                                                      <label class="form-check-label" for="customCheck2">&nbsp;</label>
                                                                 </div>
                                                            </td>
                                                            <td>
                                                                 <div class="d-flex align-items-center gap-2">
                                                                      <div class="rounded bg-light avatar-md d-flex align-items-center justify-content-center">
                                                                           <img src="/../assets/images/product/p-2.png" alt="" class="avatar-md">
                                                                      </div>
                                                                      <div>
                                                                           <a href="#!" class="text-dark fw-medium fs-15">Olive Green Leather Bag</a>
                                                                           <p class="text-muted mb-0 mt-1 fs-13"><span>Size : </span>S , M </p>
                                                                      </div>
                                                                 </div>

                                                            </td>
                                                            <td>$136.00</td>
                                                            <td>
                                                                 <p class="mb-1 text-muted"><span class="text-dark fw-medium">784 Item</span> Left</p>
                                                                 <p class="mb-0 text-muted">674 Sold</p>
                                                            </td>
                                                            <td> Hand Bag</td>
                                                            <td> <span class="badge p-1 bg-light text-dark fs-12 me-1"><i class="bx bxs-star align-text-top fs-14 text-warning me-1"></i> 4.1</span> 143 Review</td>
                                                            <td>
                                                                 <div class="d-flex gap-2">
                                                                      <a href="#!" class="btn btn-light btn-sm"><iconify-icon icon="solar:eye-broken" class="align-middle fs-18"></iconify-icon></a>
                                                                      <a href="#!" class="btn btn-soft-primary btn-sm"><iconify-icon icon="solar:pen-2-broken" class="align-middle fs-18"></iconify-icon></a>
                                                                      <a href="#!" class="btn btn-soft-danger btn-sm"><iconify-icon icon="solar:trash-bin-minimalistic-2-broken" class="align-middle fs-18"></iconify-icon></a>
                                                                 </div>
                                                            </td>
                                                       </tr>
                                                       <tr>
                                                            <td>
                                                                 <div class="form-check ms-1">
                                                                      <input type="checkbox" class="form-check-input" id="customCheck2">
                                                                      <label class="form-check-label" for="customCheck2">&nbsp;</label>
                                                                 </div>
                                                            </td>
                                                            <td>
                                                                 <div class="d-flex align-items-center gap-2">
                                                                      <div class="rounded bg-light avatar-md d-flex align-items-center justify-content-center">
                                                                           <img src="/../assets/images/product/p-3.png" alt="" class="avatar-md">
                                                                      </div>
                                                                      <div>
                                                                           <a href="#!" class="text-dark fw-medium fs-15">Women Golden Dress</a>
                                                                           <p class="text-muted mb-0 mt-1 fs-13"><span>Size : </span>S , M </p>
                                                                      </div>
                                                                 </div>

                                                            </td>
                                                            <td>$219.00</td>
                                                            <td>
                                                                 <p class="mb-1 text-muted"><span class="text-dark fw-medium">769 Item</span> Left</p>
                                                                 <p class="mb-0 text-muted">180 Sold</p>
                                                            </td>
                                                            <td> Fashion</td>
                                                            <td> <span class="badge p-1 bg-light text-dark fs-12 me-1"><i class="bx bxs-star align-text-top fs-14 text-warning me-1"></i> 4.4</span> 174 Review</td>
                                                            <td>
                                                                 <div class="d-flex gap-2">
                                                                      <a href="#!" class="btn btn-light btn-sm"><iconify-icon icon="solar:eye-broken" class="align-middle fs-18"></iconify-icon></a>
                                                                      <a href="#!" class="btn btn-soft-primary btn-sm"><iconify-icon icon="solar:pen-2-broken" class="align-middle fs-18"></iconify-icon></a>
                                                                      <a href="#!" class="btn btn-soft-danger btn-sm"><iconify-icon icon="solar:trash-bin-minimalistic-2-broken" class="align-middle fs-18"></iconify-icon></a>
                                                                 </div>
                                                            </td>
                                                       </tr>

                                                  


                                                  </tbody>
                                             </table>
                                        </div>
                                        <!-- end table-responsive -->
                                   </div>
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
                              </div>
                         </div>

                    </div>

               </div>
               <!-- End Container Fluid -->

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

</body>
</html>