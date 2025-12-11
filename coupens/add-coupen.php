<!DOCTYPE html>
<html lang="en">


<!-- Mirrored from techzaa.in/larkon/admin/coupons-add.html by HTTrack Website Copier/3.x [XR&CO'2014], Sat, 29 Nov 2025 04:20:08 GMT -->
<head>
    <!-- Title Meta -->
    <meta charset="utf-8" />
    <title>Coupons Add | Larkon - Responsive Admin Dashboard Template</title>
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

    <!-- Theme Config js (Require in all Page) -->
    <script src="../assets/js/config.js"></script>
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
                <div class="col-lg-5">
                    <div class="card">
                        <div class="card-header">
                            <h4 class="card-title">Coupon Status</h4>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-lg-4">
                                    <div class="d-flex gap-2 align-items-center">
                                        <div class="form-check">
                                             <input class="form-check-input" type="radio" name="flexRadioDefault5" id="flexRadioDefault9" checked="">
                                             <label class="form-check-label" for="flexRadioDefault9">
                                                  Active
                                             </label>
                                        </div>
                                        
                                   </div>
                                </div>
                                <div class="col-lg-4">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="flexRadioDefault5" id="flexRadioDefault10">
                                        <label class="form-check-label" for="flexRadioDefault10">
                                             In Active
                                        </label>
                                   </div>
                                </div>
                                <div class="col-lg-4">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="flexRadioDefault5" id="flexRadioDefault11">
                                        <label class="form-check-label" for="flexRadioDefault11">
                                            Future Plan
                                        </label>
                                   </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-header">
                            <h4 class="card-title">Date Schedule</h4>
                        </div>
                        <div class="card-body">
                            <form>
                                <div class="mb-3">
                                     <label for="start-date" class="form-label text-dark">Start Date</label>
                                     <input type="text" id="start-date" class="form-control flatpickr-input active" placeholder="dd-mm-yyyy" readonly="readonly">
                                </div>
                           </form>
                           <form>
                                <div class="mb-3">
                                     <label for="end-date" class="form-label text-dark">End Date</label>
                                     <input type="text" id="end-date" class="form-control flatpickr-input active" placeholder="dd-mm-yyyy" readonly="readonly">
                                </div>
                           </form>
                        </div>
                    </div>
                </div>

                <div class="col-lg-7">
                    <div class="card">
                        <div class="card-header">
                            <h4 class="card-title">Coupon Information</h4>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-lg-6">
                                    <div class="mb-3">
                                        <label for="coupons-code" class="form-label">Coupons Code</label>
                                        <input type="text" id="coupons-code" name="coupons-code" class="form-control" placeholder="Code enter">
                                   </div>
                                </div>
                                <div class="col-lg-6">
                                    <form>
                                        <label for="product-categories" class="form-label">Discount Products</label>
                                        <select class="form-control" id="product-categories" data-choices data-choices-groups data-placeholder="Select Categories" name="choices-single-groups">
                                             <option value="">Choose a categories</option>
                                             <option value="Fashion">Fashion</option>
                                             <option value="Electronics">Electronics</option>
                                             <option value="Footwear">Footwear</option>
                                             <option value="Sportswear">Sportswear</option>
                                             <option value="Watches">Watches</option>
                                             <option value="Furniture">Furniture</option>
                                             <option value="Appliances">Appliances</option>
                                             <option value="Headphones">Headphones</option>
                                             <option value="Other Accessories">Other Accessories</option>
                                        </select>
                                   </form>
                                </div>
                                <div class="col-lg-6">
                                    <form>
                                        <label for="choices-country" class="form-label">Discount Country</label>
                                        <select class="form-control" id="choices-country" data-choices data-choices-groups data-placeholder="Select Country" name="choices-country">
                                             <option value="">Choose a country</option>
                                             <optgroup label="">
                                                  <option value="">United Kingdom</option>
                                                  <option value="Fran">France</option>
                                                  <option value="Netherlands">Netherlands</option>
                                                  <option value="U.S.A">U.S.A</option>
                                                  <option value="Denmark">Denmark</option>
                                                  <option value="Canada">Canada</option>
                                                  <option value="Australia">Australia</option>
                                                  <option value="India">India</option>
                                                  <option value="Germany">Germany</option>
                                                  <option value="Spain">Spain</option>
                                                  <option value="United Arab Emirates">United Arab Emirates</option>
                                             </optgroup>
                                        </select>
                                   </form>
                                </div>
                                <div class="col-lg-6">
                                    <div class="mb-3">
                                        <label for="coupons-limits" class="form-label">Coupons Limits</label>
                                        <input type="number" id="coupons-limits" name="coupons-limits" class="form-control" placeholder="limits nu">
                                   </div>
                                </div>
                            </div>
                            <h4 class="card-title mb-3 mt-2">Coupons Types</h4>
                            <div class="row mb-3">
                                <div class="col-lg-4">
                                    <div class="d-flex gap-2 align-items-center">
                                        <div class="form-check">
                                             <input class="form-check-input" type="radio" name="flexRadioDefault6" id="flexRadioDefault12" checked="">
                                             <label class="form-check-label" for="flexRadioDefault12">
                                                Free Shipping
                                             </label>
                                        </div>
                                        
                                   </div>
                                </div>
                                <div class="col-lg-4">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="flexRadioDefault6" id="flexRadioDefault13">
                                        <label class="form-check-label" for="flexRadioDefault13">
                                            Percentage
                                        </label>
                                   </div>
                                </div>
                                <div class="col-lg-4">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="flexRadioDefault6" id="flexRadioDefault14">
                                        <label class="form-check-label" for="flexRadioDefault14">
                                            Fixed Amount
                                        </label>
                                   </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-lg-12">
                                    <div class="">
                                        <label for="discount-value" class="form-label">Discount Value</label>
                                        <input type="text" id="discount-value" name="discount-value" class="form-control" placeholder="value enter">
                                   </div>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer border-top">
                            <a href="#!" class="btn btn-primary">Create Coupon</a>
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
    <script src="../assets/js/pages/coupons-add.js"></script>


</body>


<!-- Mirrored from techzaa.in/larkon/admin/coupons-add.html by HTTrack Website Copier/3.x [XR&CO'2014], Sat, 29 Nov 2025 04:20:08 GMT -->
</html>