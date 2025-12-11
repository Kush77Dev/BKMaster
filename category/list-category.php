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
               <div class="container-xxl">

                    <div class="row">
                         <div class="col-md-6 col-xl-3">
                              <div class="card">
                                   <div class="card-body text-center">
                                        <div class="rounded bg-secondary-subtle d-flex align-items-center justify-content-center mx-auto">
                                             <img src="/../assets/images/product/p-1.png" alt="" class="avatar-xl">
                                        </div>
                                        <h4 class="mt-3 mb-0">Fashion Categories</h4>
                                   </div>
                              </div>
                         </div>
                         <div class="col-md-6 col-xl-3">
                              <div class="card">
                                   <div class="card-body text-center">
                                        <div class="rounded bg-primary-subtle d-flex align-items-center justify-content-center mx-auto">
                                             <img src="/../assets/images/product/p-6.png" alt="" class="avatar-xl">
                                        </div>
                                        <h4 class="mt-3 mb-0">Electronics Headphone</h4>
                                   </div>
                              </div>
                         </div>

                         <div class="col-md-6 col-xl-3">
                              <div class="card">
                                   <div class="card-body text-center">
                                        <div class="rounded bg-warning-subtle d-flex align-items-center justify-content-center mx-auto">
                                             <img src="/../assets/images/product/p-7.png" alt="" class="avatar-xl">
                                        </div>
                                        <h4 class="mt-3 mb-0">Foot Wares</h4>
                                   </div>
                              </div>
                         </div>

                         <div class="col-md-6 col-xl-3">
                              <div class="card">
                                   <div class="card-body text-center">
                                        <div class="rounded bg-info-subtle d-flex align-items-center justify-content-center mx-auto">
                                             <img src="/../assets/images/product/p-9.png" alt="" class="avatar-xl">
                                        </div>
                                        <h4 class="mt-3 mb-0">Eye Ware & Sunglass</h4>
                                   </div>
                              </div>
                         </div>
                    </div>

                    <div class="row">
                         <div class="col-xl-12">
                              <div class="card">
                                   <div class="card-header d-flex justify-content-between align-items-center gap-1">
                                        <h4 class="card-title flex-grow-1">All Categories List</h4>

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
                                                                 <div class="form-check">
                                                                      <input type="checkbox" class="form-check-input" id="customCheck1">
                                                                      <label class="form-check-label" for="customCheck1"></label>
                                                                 </div>
                                                            </th>
                                                            <th>Categories</th>
                                                            <th>Starting Price</th>
                                                            <th>Create by</th>
                                                            <th>ID</th>
                                                            <th>Product Stock</th>
                                                            <th>Action</th>
                                                       </tr>
                                                  </thead>
                                                  <tbody>
                                                       <tr>
                                                            <td>
                                                                 <div class="form-check">
                                                                      <input type="checkbox" class="form-check-input" id="customCheck2">
                                                                      <label class="form-check-label" for="customCheck2"></label>
                                                                 </div>
                                                            </td>
                                                            <td>
                                                                 <div class="d-flex align-items-center gap-2">
                                                                      <div class="rounded bg-light avatar-md d-flex align-items-center justify-content-center">
                                                                           <img src="/../assets/images/product/p-1.png" alt="" class="avatar-md">
                                                                      </div>
                                                                      <p class="text-dark fw-medium fs-15 mb-0">Fashion Men , Women & Kid's</p>
                                                                 </div>

                                                            </td>
                                                            <td>$80 to $400</td>
                                                            <td>Seller</td>
                                                            <td>FS16276</td>
                                                            <td>46233</td>
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
                                                                 <div class="form-check">
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
                                                                           <p class="text-dark fw-medium fs-15 mb-0">Women Hand Bag</p>
                                                                      </div>
                                                                 </div>

                                                            </td>
                                                            <td>$120 to $500</td>
                                                            <td>Admin</td>
                                                            <td>HB73029</td>
                                                            <td>2739</td>
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
                                                                 <div class="form-check">
                                                                      <input type="checkbox" class="form-check-input" id="customCheck2">
                                                                      <label class="form-check-label" for="customCheck2">&nbsp;</label>
                                                                 </div>
                                                            </td>
                                                            <td>
                                                                 <div class="d-flex align-items-center gap-2">
                                                                      <div class="rounded bg-light avatar-md d-flex align-items-center justify-content-center">
                                                                           <img src="/../assets/images/product/p-4.png" alt="" class="avatar-md">
                                                                      </div>
                                                                      <div>
                                                                           <p class="text-dark fw-medium fs-15 mb-0">Cap and Hat</p>
                                                                      </div>
                                                                 </div>

                                                            </td>
                                                            <td>$50 to $200</td>
                                                            <td>Admin</td>
                                                            <td>CH492-9</td>
                                                            <td>1829</td>
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