<?php require_once __DIR__ . '/includes/handlers/other-handler.php'; ?>
<!-- meta tags and other links -->
<!DOCTYPE html>
<html lang="en" data-theme="light">

<head>
  <meta charset="UTF-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="description" content="Modern Education Admin Dashboard for schools, colleges, universities, and eLearning platforms. Includes student and course management, attendance, exams, payments, analytics, and a fully responsive clean UI—ideal for LMS, coaching centers, and academic admin systems.">
  <meta name="keywords" content="Education Admin Dashboard, School Admin Panel, College Dashboard, University Dashboard, LMS Dashboard, eLearning Admin Template, Student Management System, Course Management, Education Template, Study Dashboard, Online Learning Dashboard, Academic Admin Panel, Bootstrap Dashboard, React Education Dashboard, Next.js Education Template">
  <meta name="robots" content="INDEX,FOLLOW">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <!-- Title -->
  <title> <?php echo htmlspecialchars($school['name']); ?> | <?php echo defined('APP_NAME') ? APP_NAME : 'School Management'; ?></title>
  <link rel="icon" type="image/png" href="https://academixsuite.com/tenant/assets/images/favicon.png" sizes="16x16">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/remixicon.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/bootstrap.min.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/apexcharts.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/dataTables.min.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/flatpickr.min.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/full-calendar.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/calendar.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/style.css">
</head>

<body>

  <!-- Theme Customization Structure Start -->




<!-- Theme Customization Structure End -->

  <div class="overlay bg-black bg-opacity-50 w-100 h-100 position-fixed z-9 visibility-hidden opacity-0 duration-300">
  </div>
<?php include_once __DIR__ . '/includes/sidebar.php'; ?>

<main class="dashboard-main">
    <?php require_once __DIR__ . '/includes/nav-header.php'; ?>

    <div class="dashboard-main-body">

        <div class="breadcrumb d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
  <div class="">
    <h1 class="fw-semibold mb-4 h6 text-primary-light">Suspend Student</h1>
    <div class="">
      <a href="index.html" class="text-secondary-light hover-text-primary hover-underline">Dashboard </a>
      <a href="javascript:void(0)" class="text-secondary-light hover-text-primary hover-underline d-none"> /
        Student</a>
      <span class="text-secondary-light">/ Suspend Student</span>
    </div>
  </div>
  <a href="add-new-student.html" class="btn btn-primary-600 d-flex align-items-center gap-6 ">
    <span class="d-flex text-md">
      <i class="ri-add-large-line"></i>
    </span>
    Add Student
  </a>
</div>

        <div class="mt-24">
            <div class="card h-100">
                <div class="card-body p-0 dataTable-wrapper">

                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-16 px-20 py-12 border-bottom border-neutral-200">
                        <div class="d-flex flex-wrap align-items-center gap-16">
                            <div class="dropdown">
                                <button type="button" class="px-12 py-5-px border border-neutral-300 radius-8 d-flex align-items-center gap-20 " data-bs-toggle="dropdown" aria-expanded="false">
                                    <span class="d-flex align-items-center gap-1 text-secondary-light text-sm">
                                        <i class="ri-file-upload-line text-md line-height-1"></i>
                                        Export
                                    </span>
                                    <span class="">
                                        <i class="ri-arrow-down-s-line"></i>
                                    </span>
                                </button>
                                <ul class="dropdown-menu p-12 border bg-base shadow">
                                    <li>
                                        <button type="button" class="dropdown-item px-16 py-8 rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-10" data-bs-toggle="modal" data-bs-target="#exampleModalView">
                                            <i class="ri-file-3-line"></i>
                                            PDF
                                        </button>
                                    </li>
                                    <li>
                                        <button type="button" class="dropdown-item px-16 py-8 rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-10" data-bs-toggle="modal" data-bs-target="#exampleModalEdit">
                                            <i class="ri-file-excel-line"></i>
                                            Excel
                                        </button>
                                    </li>
                                </ul>
                            </div>
                            <form class="navbar-search dt-search m-0">
                                <input type="text" class="dt-input bg-transparent radius-4" aria-controls="dataTable" name="search" placeholder="Search...">
                                <iconify-icon icon="ion:search-outline" class="icon"></iconify-icon>
                            </form>
                            <div class="dropdown">
                                <button type="button" class="px-12 py-5-px border border-neutral-300 radius-8 d-flex align-items-center gap-20" data-bs-toggle="dropdown" aria-expanded="false">
                                    <span class="d-flex align-items-center gap-1 text-secondary-light text-sm">
                                        Filter
                                    </span>
                                    <span class="">
                                        <i class="ri-arrow-down-s-line"></i>
                                    </span>
                                </button>
                                <div class="dropdown-menu border bg-base shadow dropdown-menu-lg p-0">
                                    <div class="d-flex align-items-center justify-content-between border-bottom py-8 px-16">
                                        <span class="fw-semibold text-lg text-primary-light">Filter</span>
                                        <button type="button">
                                            <i class="ri-close-large-line"></i>
                                        </button>
                                    </div>

                                    <form action="#" class="p-16 d-grid grid-cols-2 gap-16">
                                        <div class="">
                                            <label for="class" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Class</label>
                                            <select id="class" class="form-control form-select">
                                                <option value="Select" disabled="">Select Class</option>
                                                <option value="Primary">Primary</option>
                                                <option value="SSC">SSC</option>
                                                <option value="HSC">HSC</option>
                                                <option value="Hons">Hons</option>
                                                <option value="Masters">Masters</option>
                                            </select>
                                        </div>
                                        <div class="">
                                            <label for="section" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Section</label>
                                            <select id="section" class="form-control form-select">
                                                <option value="Select">Select Section</option>
                                                <option value="Arts">Arts</option>
                                                <option value="Science">Science</option>
                                                <option value="Commerce">Commerce</option>
                                            </select>
                                        </div>
                                        <div class="">
                                            <label for="gender" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Gender</label>
                                            <select id="gender" class="form-control form-select">
                                                <option value="Select">Select Gender</option>
                                                <option value="Male">Male</option>
                                                <option value="Female">Female</option>
                                            </select>
                                        </div>
                                        <div class="">
                                            <label for="status" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Status</label>
                                            <select id="status" class="form-control form-select">
                                                <option value="Select">Select Status</option>
                                                <option value="Active">Active</option>
                                                <option value="Inactive">Inactive</option>
                                            </select>
                                        </div>
                                        <div class="">
                                            <button type="reset" class="btn btn-danger-200 text-danger-600 w-100">Reset</button>
                                        </div>
                                        <div class="">
                                            <button type="submit" class="btn btn-primary-600 w-100">Apply</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-8 text-secondary-light">
                            <span class="">
                                Rows per page:
                            </span>
                            <div class="dt-length">
                                <select name="dataTable_length" aria-controls="dataTable" class="dt-input form-control form-select">
                                    <option value="5">5</option>
                                    <option value="10" selected="">10</option>
                                    <option value="25">25</option>
                                    <option value="50">50</option>
                                    <option value="100">100</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="p-0">
                        <table class="table bordered-table mb-0 data-table" id="dataTable" data-page-length='10'>
                            <thead>
                                <tr>
                                    <th scope="col">
                                        <div class="form-check style-check d-flex align-items-center">
                                            <input class="form-check-input" type="checkbox">
                                            <label class="form-check-label">
                                                S.L
                                            </label>
                                        </div>
                                    </th>
                                    <th scope="col">Admission No</th>
                                    <th scope="col">Name</th>
                                    <th scope="col">Class</th>
                                    <th scope="col">Date of Birth</th>
                                    <th scope="col">Gender</th>
                                    <th scope="col">Mobile Number</th>
                                    <th scope="col">Category</th>
                                    <th scope="col">Status</th>
                                    <th scope="col">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>
                                        <div class="form-check style-check d-flex align-items-center">
                                            <input class="form-check-input" type="checkbox">
                                            <label class="form-check-label">
                                                01
                                            </label>
                                        </div>
                                    </td>
                                    <td><span class="text-primary-600">AD52365</span></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/avatar-img1.png" alt="Image" class="flex-shrink-0 me-12 radius-8">
                                            <div class="">
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1">Kathryn Murphy</h6>
                                                <span class="">Roll No: <span class="fw-semibold">12</span> </span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>Class 1 (A)</td>
                                    <td>05 May 2012</td>
                                    <td>Male</td>
                                    <td>209.555.0104</td>
                                    <td>General</td>
                                    <td> <span class="bg-danger-100 text-danger-600 px-24 py-4 radius-4 fw-medium text-sm">Suspend</span>
                                    </td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li>
                                                    <button class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6" type="button">
                                                        <i class="ri-user-3-line"></i>
                                                        View Teacher
                                                    </button>
                                                </li>
                                                <li>
                                                    <a href="edit-teacher.html" class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-edit-2-line"></i>
                                                        Edit
                                                    </a>
                                                </li>
                                                <li>
                                                    <button class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6" type="button">
                                                        <i class="ri-money-dollar-box-line"></i>
                                                        Collect Fees
                                                    </button>
                                                </li>
                                                <li>
                                                    <button class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6" type="button">
                                                        <i class="ri-error-warning-line"></i>
                                                        Inactive
                                                    </button>
                                                </li>
                                                <li>
                                                    <button class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6" data-bs-toggle="modal" data-bs-target="#exampleModalDelete"><i class="ri-delete-bin-6-line"></i>Delete</button>
                                                </li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <div class="form-check style-check d-flex align-items-center">
                                            <input class="form-check-input" type="checkbox">
                                            <label class="form-check-label">
                                                02
                                            </label>
                                        </div>
                                    </td>
                                    <td><span class="text-primary-600">AD52365</span></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/avatar-img2.png" alt="Image" class="flex-shrink-0 me-12 radius-8">
                                            <div class="">
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1">Floyd Miles</h6>
                                                <span class="">Roll No: <span class="fw-semibold">1</span> </span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>Class 2 (B)</td>
                                    <td>05 May 2012</td>
                                    <td>Female</td>
                                    <td>209.555.0104</td>
                                    <td>Special</td>
                                    <td> <span class="bg-danger-100 text-danger-600 px-24 py-4 radius-4 fw-medium text-sm">Suspend</span>
                                    </td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li>
                                                    <button class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6" type="button">
                                                        <i class="ri-user-3-line"></i>
                                                        View Teacher
                                                    </button>
                                                </li>
                                                <li>
                                                    <a href="edit-teacher.html" class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-edit-2-line"></i>
                                                        Edit
                                                    </a>
                                                </li>
                                                <li>
                                                    <button class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6" type="button">
                                                        <i class="ri-money-dollar-box-line"></i>
                                                        Collect Fees
                                                    </button>
                                                </li>
                                                <li>
                                                    <button class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6" type="button">
                                                        <i class="ri-error-warning-line"></i>
                                                        Inactive
                                                    </button>
                                                </li>
                                                <li>
                                                    <button class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6" data-bs-toggle="modal" data-bs-target="#exampleModalDelete"><i class="ri-delete-bin-6-line"></i>Delete</button>
                                                </li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <div class="form-check style-check d-flex align-items-center">
                                            <input class="form-check-input" type="checkbox">
                                            <label class="form-check-label">03</label>
                                        </div>
                                    </td>
                                    <td><span class="text-primary-600">AD52367</span></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/avatar-img3.png" alt="Image" class="flex-shrink-0 me-12 radius-8">
                                            <div>
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1">Cody Fisher</h6>
                                                <span>Roll No: <span class="fw-semibold">7</span></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>Class 3 (A)</td>
                                    <td>12 Feb 2013</td>
                                    <td>Male</td>
                                    <td>207.445.9821</td>
                                    <td>OBC</td>
                                    <td>
                                        <span class="bg-danger-100 text-danger-600 px-24 py-4 radius-4 fw-medium text-sm">Suspend</span>
                                    </td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li><button class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"><i class="ri-user-3-line"></i>View Teacher</button></li>
                                                <li><a href="edit-teacher.html" class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"><i class="ri-edit-2-line"></i>Edit</a></li>
                                                <li><button class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"><i class="ri-money-dollar-box-line"></i>Collect Fees</button>
                                                </li>
                                                <li><button class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"><i class="ri-error-warning-line"></i>Inactive</button></li>
                                                <li>
                                                    <button class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6" data-bs-toggle="modal" data-bs-target="#exampleModalDelete"><i class="ri-delete-bin-6-line"></i>Delete</button>
                                                </li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>

                                <tr>
                                    <td>
                                        <div class="form-check style-check d-flex align-items-center">
                                            <input class="form-check-input" type="checkbox">
                                            <label class="form-check-label">04</label>
                                        </div>
                                    </td>
                                    <td><span class="text-primary-600">AD52368</span></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/avatar-img4.png" alt="Image" class="flex-shrink-0 me-12 radius-8">
                                            <div>
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1">Jane Cooper</h6>
                                                <span>Roll No: <span class="fw-semibold">8</span></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>Class 4 (C)</td>
                                    <td>17 Mar 2014</td>
                                    <td>Female</td>
                                    <td>204.658.4421</td>
                                    <td>Special</td>
                                    <td>
                                        <span class="bg-danger-100 text-danger-600 px-24 py-4 radius-4 fw-medium text-sm">Suspend</span>
                                    </td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li>
                                                    <button class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6" type="button">
                                                        <i class="ri-user-3-line"></i>
                                                        View Teacher
                                                    </button>
                                                </li>
                                                <li>
                                                    <a href="edit-teacher.html" class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-edit-2-line"></i>
                                                        Edit
                                                    </a>
                                                </li>
                                                <li>
                                                    <button class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6" type="button">
                                                        <i class="ri-money-dollar-box-line"></i>
                                                        Collect Fees
                                                    </button>
                                                </li>
                                                <li>
                                                    <button class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6" type="button">
                                                        <i class="ri-error-warning-line"></i>
                                                        Inactive
                                                    </button>
                                                </li>
                                                <li>
                                                    <button class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6" data-bs-toggle="modal" data-bs-target="#exampleModalDelete"><i class="ri-delete-bin-6-line"></i>Delete</button>
                                                </li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>

                                <tr>
                                    <td>
                                        <div class="form-check style-check d-flex align-items-center">
                                            <input class="form-check-input" type="checkbox">
                                            <label class="form-check-label">05</label>
                                        </div>
                                    </td>
                                    <td><span class="text-primary-600">AD52369</span></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/avatar-img5.png" alt="Image" class="flex-shrink-0 me-12 radius-8">
                                            <div>
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1">Esther Howard</h6>
                                                <span>Roll No: <span class="fw-semibold">15</span></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>Class 5 (B)</td>
                                    <td>25 Jul 2013</td>
                                    <td>Female</td>
                                    <td>209.875.9987</td>
                                    <td>General</td>
                                    <td>
                                        <span class="bg-danger-100 text-danger-600 px-24 py-4 radius-4 fw-medium text-sm">Suspend</span>
                                    </td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li>
                                                    <button class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6" type="button">
                                                        <i class="ri-user-3-line"></i>
                                                        View Teacher
                                                    </button>
                                                </li>
                                                <li>
                                                    <a href="edit-teacher.html" class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-edit-2-line"></i>
                                                        Edit
                                                    </a>
                                                </li>
                                                <li>
                                                    <button class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6" type="button">
                                                        <i class="ri-money-dollar-box-line"></i>
                                                        Collect Fees
                                                    </button>
                                                </li>
                                                <li>
                                                    <button class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6" type="button">
                                                        <i class="ri-error-warning-line"></i>
                                                        Inactive
                                                    </button>
                                                </li>
                                                <li>
                                                    <button class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6" data-bs-toggle="modal" data-bs-target="#exampleModalDelete"><i class="ri-delete-bin-6-line"></i>Delete</button>
                                                </li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>

                                <tr>
                                    <td>
                                        <div class="form-check style-check d-flex align-items-center">
                                            <input class="form-check-input" type="checkbox">
                                            <label class="form-check-label">06</label>
                                        </div>
                                    </td>
                                    <td><span class="text-primary-600">AD52370</span></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/avatar-img6.png" alt="Image" class="flex-shrink-0 me-12 radius-8">
                                            <div>
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1">Albert Flores</h6>
                                                <span>Roll No: <span class="fw-semibold">3</span></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>Class 6 (A)</td>
                                    <td>08 Dec 2011</td>
                                    <td>Male</td>
                                    <td>208.324.1110</td>
                                    <td>OBC</td>
                                    <td>
                                        <span class="bg-danger-100 text-danger-600 px-24 py-4 radius-4 fw-medium text-sm">Suspend</span>
                                    </td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li>
                                                    <button class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6" type="button">
                                                        <i class="ri-user-3-line"></i>
                                                        View Teacher
                                                    </button>
                                                </li>
                                                <li>
                                                    <a href="edit-teacher.html" class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-edit-2-line"></i>
                                                        Edit
                                                    </a>
                                                </li>
                                                <li>
                                                    <button class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6" type="button">
                                                        <i class="ri-money-dollar-box-line"></i>
                                                        Collect Fees
                                                    </button>
                                                </li>
                                                <li>
                                                    <button class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6" type="button">
                                                        <i class="ri-error-warning-line"></i>
                                                        Inactive
                                                    </button>
                                                </li>
                                                <li>
                                                    <button class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6" data-bs-toggle="modal" data-bs-target="#exampleModalDelete"><i class="ri-delete-bin-6-line"></i>Delete</button>
                                                </li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>

                                <tr>
                                    <td>
                                        <div class="form-check style-check d-flex align-items-center">
                                            <input class="form-check-input" type="checkbox">
                                            <label class="form-check-label">07</label>
                                        </div>
                                    </td>
                                    <td><span class="text-primary-600">AD52371</span></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/avatar-img7.png" alt="Image" class="flex-shrink-0 me-12 radius-8">
                                            <div>
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1">Jenny Wilson</h6>
                                                <span>Roll No: <span class="fw-semibold">9</span></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>Class 7 (C)</td>
                                    <td>19 Sep 2010</td>
                                    <td>Female</td>
                                    <td>206.211.4567</td>
                                    <td>General</td>
                                    <td>
                                        <span class="bg-danger-100 text-danger-600 px-24 py-4 radius-4 fw-medium text-sm">Suspend</span>
                                    </td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li>
                                                    <button class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6" type="button">
                                                        <i class="ri-user-3-line"></i>
                                                        View Teacher
                                                    </button>
                                                </li>
                                                <li>
                                                    <a href="edit-teacher.html" class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-edit-2-line"></i>
                                                        Edit
                                                    </a>
                                                </li>
                                                <li>
                                                    <button class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6" type="button">
                                                        <i class="ri-money-dollar-box-line"></i>
                                                        Collect Fees
                                                    </button>
                                                </li>
                                                <li>
                                                    <button class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6" type="button">
                                                        <i class="ri-error-warning-line"></i>
                                                        Inactive
                                                    </button>
                                                </li>
                                                <li>
                                                    <button class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6" data-bs-toggle="modal" data-bs-target="#exampleModalDelete"><i class="ri-delete-bin-6-line"></i>Delete</button>
                                                </li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <div class="form-check style-check d-flex align-items-center">
                                            <input class="form-check-input" type="checkbox">
                                            <label class="form-check-label">08</label>
                                        </div>
                                    </td>
                                    <td><span class="text-primary-600">AD52367</span></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/avatar-img3.png" alt="Image" class="flex-shrink-0 me-12 radius-8">
                                            <div>
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1">Jane Cooper</h6>
                                                <span>Roll No: <span class="fw-semibold">5</span></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>Class 3 (A)</td>
                                    <td>12 Jan 2013</td>
                                    <td>Female</td>
                                    <td>202.444.0089</td>
                                    <td>OBC</td>
                                    <td><span class="bg-danger-100 text-danger-600 px-24 py-4 radius-4 fw-medium text-sm">Suspend</span>
                                    </td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li><button class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"><i class="ri-user-3-line"></i>View Teacher</button></li>
                                                <li><a href="edit-teacher.html" class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"><i class="ri-edit-2-line"></i>Edit</a></li>
                                                <li><button class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"><i class="ri-money-dollar-box-line"></i>Collect Fees</button>
                                                </li>
                                                <li><button class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"><i class="ri-error-warning-line"></i>Inactive</button></li>
                                                <li>
                                                    <button class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6" data-bs-toggle="modal" data-bs-target="#exampleModalDelete"><i class="ri-delete-bin-6-line"></i>Delete</button>
                                                </li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>

                                <tr>
                                    <td>
                                        <div class="form-check style-check d-flex align-items-center">
                                            <input class="form-check-input" type="checkbox">
                                            <label class="form-check-label">09</label>
                                        </div>
                                    </td>
                                    <td><span class="text-primary-600">AD52368</span></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/avatar-img4.png" alt="Image" class="flex-shrink-0 me-12 radius-8">
                                            <div>
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1">Cameron Williamson</h6>
                                                <span>Roll No: <span class="fw-semibold">23</span></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>Class 4 (C)</td>
                                    <td>08 Jul 2011</td>
                                    <td>Male</td>
                                    <td>203.111.0456</td>
                                    <td>SC</td>
                                    <td><span class="bg-danger-100 text-danger-600 px-24 py-4 radius-4 fw-medium text-sm">Suspend</span>
                                    </td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li><button class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"><i class="ri-user-3-line"></i>View Teacher</button></li>
                                                <li><a href="edit-teacher.html" class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"><i class="ri-edit-2-line"></i>Edit</a></li>
                                                <li><button class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"><i class="ri-money-dollar-box-line"></i>Collect Fees</button>
                                                </li>
                                                <li><button class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"><i class="ri-error-warning-line"></i>Inactive</button></li>
                                                <li>
                                                    <button class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6" data-bs-toggle="modal" data-bs-target="#exampleModalDelete"><i class="ri-delete-bin-6-line"></i>Delete</button>
                                                </li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>

                                <tr>
                                    <td>
                                        <div class="form-check style-check d-flex align-items-center">
                                            <input class="form-check-input" type="checkbox">
                                            <label class="form-check-label">10</label>
                                        </div>
                                    </td>
                                    <td><span class="text-primary-600">AD52369</span></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/avatar-img5.png" alt="Image" class="flex-shrink-0 me-12 radius-8">
                                            <div>
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1">Theresa Webb</h6>
                                                <span>Roll No: <span class="fw-semibold">10</span></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>Class 5 (A)</td>
                                    <td>18 Nov 2010</td>
                                    <td>Female</td>
                                    <td>205.777.0190</td>
                                    <td>General</td>
                                    <td><span class="bg-danger-100 text-danger-600 px-24 py-4 radius-4 fw-medium text-sm">Suspend</span>
                                    </td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li><button class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"><i class="ri-user-3-line"></i>View Teacher</button></li>
                                                <li><a href="edit-teacher.html" class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"><i class="ri-edit-2-line"></i>Edit</a></li>
                                                <li><button class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"><i class="ri-money-dollar-box-line"></i>Collect Fees</button>
                                                </li>
                                                <li><button class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"><i class="ri-error-warning-line"></i>Inactive</button></li>
                                                <li>
                                                    <button class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6" data-bs-toggle="modal" data-bs-target="#exampleModalDelete"><i class="ri-delete-bin-6-line"></i>Delete</button>
                                                </li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>

                                <tr>
                                    <td>
                                        <div class="form-check style-check d-flex align-items-center">
                                            <input class="form-check-input" type="checkbox">
                                            <label class="form-check-label">11</label>
                                        </div>
                                    </td>
                                    <td><span class="text-primary-600">AD52370</span></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/avatar-img6.png" alt="Image" class="flex-shrink-0 me-12 radius-8">
                                            <div>
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1">Marvin McKinney</h6>
                                                <span>Roll No: <span class="fw-semibold">7</span></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>Class 6 (B)</td>
                                    <td>21 Mar 2011</td>
                                    <td>Male</td>
                                    <td>209.660.0912</td>
                                    <td>General</td>
                                    <td><span class="bg-danger-100 text-danger-600 px-24 py-4 radius-4 fw-medium text-sm">Suspend</span>
                                    </td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li><button class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"><i class="ri-user-3-line"></i>View Teacher</button></li>
                                                <li><a href="edit-teacher.html" class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"><i class="ri-edit-2-line"></i>Edit</a></li>
                                                <li><button class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"><i class="ri-money-dollar-box-line"></i>Collect Fees</button>
                                                </li>
                                                <li><button class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"><i class="ri-error-warning-line"></i>Inactive</button></li>
                                                <li>
                                                    <button class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6" data-bs-toggle="modal" data-bs-target="#exampleModalDelete"><i class="ri-delete-bin-6-line"></i>Delete</button>
                                                </li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>

                                <tr>
                                    <td>
                                        <div class="form-check style-check d-flex align-items-center">
                                            <input class="form-check-input" type="checkbox">
                                            <label class="form-check-label">12</label>
                                        </div>
                                    </td>
                                    <td><span class="text-primary-600">AD52371</span></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/avatar-img7.png" alt="Image" class="flex-shrink-0 me-12 radius-8">
                                            <div>
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1">Courtney Henry</h6>
                                                <span>Roll No: <span class="fw-semibold">15</span></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>Class 7 (A)</td>
                                    <td>10 Feb 2009</td>
                                    <td>Female</td>
                                    <td>204.120.0023</td>
                                    <td>OBC</td>
                                    <td><span class="bg-danger-100 text-danger-600 px-24 py-4 radius-4 fw-medium text-sm">Suspend</span>
                                    </td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li><button class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"><i class="ri-user-3-line"></i>View Teacher</button></li>
                                                <li><a href="edit-teacher.html" class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"><i class="ri-edit-2-line"></i>Edit</a></li>
                                                <li><button class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"><i class="ri-money-dollar-box-line"></i>Collect Fees</button>
                                                </li>
                                                <li><button class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"><i class="ri-error-warning-line"></i>Inactive</button></li>
                                                <li>
                                                    <button class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6" data-bs-toggle="modal" data-bs-target="#exampleModalDelete"><i class="ri-delete-bin-6-line"></i>Delete</button>
                                                </li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>

                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php require_once __DIR__ . '/includes/footer.php'; ?>
</main>

<!-- Modal Delete Event start -->
<div class="modal fade" id="exampleModalDelete" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog modal-dialog-centered max-w-340-px">
        <div class="modal-content radius-16 bg-base">
            <div class="modal-body pt-32 px-36 pb-24 text-center">
                <span class="mb-16 fs-1 line-height-1 text-danger">
                    <iconify-icon icon="fluent:delete-24-regular" class="menu-icon"></iconify-icon>
                </span>
                <h6 class="text-lg fw-semibold text-primary-light mb-0">Are your sure you want to Suspend this teacher
                </h6>
                <div class="d-flex align-items-center justify-content-center gap-3 mt-24">
                    <button type="reset" class="flex-grow-1 border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-24 py-11 radius-8">
                        Cancel
                    </button>
                    <button type="button" class="flex-grow-1 btn btn-primary-600 border border-primary-600 text-md px-16 py-12 radius-8">
                        Yes, Suspend
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
<!-- Modal Delete Event end -->

  <!-- jQuery library js -->
  <script src="assets/js/lib/jquery-3.7.1.min.js"></script>
  <!-- Bootstrap js -->
  <script src="assets/js/lib/bootstrap.bundle.min.js"></script>
  <!-- Apex Chart js -->
  <script src="assets/js/lib/apexcharts.min.js"></script>
  <!-- Iconify Font js -->
  <script src="assets/js/lib/iconify-icon.min.js"></script>
  <!-- Data Table js -->
  <script src="assets/js/lib/dataTables.min.js"></script>
  
  <!-- jQuery UI js -->
  <script src="assets/js/lib/jquery-ui.min.js"></script>
  
  <!-- main js -->
  <script src="assets/js/app.js"></script>

<script>
    let table = new DataTable('#dataTable');

    // ✅ Data Table start
    $('.data-table').each(function () {
        const $table = $(this);
        const tableInstance = new DataTable(this);

        // Handle search input (inside same wrapper)
        $table.closest('.dataTable-wrapper').find('.dt-search .dt-input').on('keyup', function () {
            tableInstance.search(this.value).draw();
        });

        // Handle page length change (inside same wrapper)
        $table.closest('.dataTable-wrapper').find('.dt-length .dt-input').on('change', function () {
            const value = $(this).val();
            tableInstance.page.len(value).draw();
        });
    });
    // ✅ Data Table end
</script>

</body>

</html>