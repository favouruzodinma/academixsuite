<?php require_once __DIR__ . '/includes/handlers/library-handler.php'; ?>
<!-- meta tags and other links -->
<!DOCTYPE html>
<html lang="en" data-theme="light">

<head>
  <meta charset="UTF-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="description"
    content="Modern Education Admin Dashboard for schools, colleges, universities, and eLearning platforms. Includes student and course management, attendance, exams, payments, analytics, and a fully responsive clean UI—ideal for LMS, coaching centers, and academic admin systems.">
  <meta name="keywords"
    content="Education Admin Dashboard, School Admin Panel, College Dashboard, University Dashboard, LMS Dashboard, eLearning Admin Template, Student Management System, Course Management, Education Template, Study Dashboard, Online Learning Dashboard, Academic Admin Panel, Bootstrap Dashboard, React Education Dashboard, Next.js Education Template">
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
                <h1 class="fw-semibold mb-4 h6 text-primary-light">Members List </h1>
                <div class="">
                    <a href="index.html" class="text-secondary-light hover-text-primary hover-underline">Dashboard </a>
                    <span class="text-secondary-light">/ Members List </span>
                </div>
            </div>
            <button type="button" class="my-sidebar-btn btn btn-primary-600 d-flex align-items-center gap-6">
                <span class="d-flex text-md">
                    <i class="ri-add-large-line"></i>
                </span>
                Add Members
            </button>
        </div>

        <div class="mt-24">
            <div class="card h-100">
                <div class="card-body p-0 dataTable-wrapper">

                    <div
                        class="d-flex align-items-center justify-content-between flex-wrap gap-16 px-20 py-12 border-bottom border-neutral-200">
                        <div class="d-flex flex-wrap align-items-center gap-16">
                            <div class="dropdown">
                                <button type="button"
                                    class="px-12 py-5-px border border-neutral-300 radius-8 d-flex align-items-center gap-20 "
                                    data-bs-toggle="dropdown" aria-expanded="false">
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
                                        <button type="button"
                                            class="dropdown-item px-16 py-8 rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-10"
                                            data-bs-toggle="modal" data-bs-target="#exampleModalView">
                                            <i class="ri-file-3-line"></i>
                                            PDF
                                        </button>
                                    </li>
                                    <li>
                                        <button type="button"
                                            class="dropdown-item px-16 py-8 rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-10"
                                            data-bs-toggle="modal" data-bs-target="#exampleModalEdit">
                                            <i class="ri-file-excel-line"></i>
                                            Excel
                                        </button>
                                    </li>
                                </ul>
                            </div>
                            <form class="navbar-search dt-search m-0">
                                <input type="text" class="dt-input bg-transparent radius-4" aria-controls="dataTable"
                                    name="search" placeholder="Search...">
                                <iconify-icon icon="ion:search-outline" class="icon"></iconify-icon>
                            </form>
                        </div>
                        <div class="d-flex align-items-center gap-8 text-secondary-light">
                            <span class="">
                                Rows per page:
                            </span>
                            <div class="dt-length">
                                <select name="dataTable_length" aria-controls="dataTable"
                                    class="dt-input form-control form-select">
                                    <option value="5">5</option>
                                    <option value="10" selected>10</option>
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
                                    <th scope="col">Join Date</th>
                                    <th scope="col">Card No</th>
                                    <th scope="col">Student Name</th>
                                    <th scope="col">Class</th>
                                    <th scope="col">Phone Number</th>
                                    <th scope="col">Book Issue</th>
                                    <th scope="col">Issue Date</th>
                                    <th scope="col">Return Date</th>
                                    <th scope="col">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>
                                        <div class="form-check style-check d-flex align-items-center">
                                            <input class="form-check-input" type="checkbox">
                                            <label class="form-check-label">01</label>
                                        </div>
                                    </td>
                                    <td>05 Jun 2015</td>
                                    <td>12563</td>
                                    <td>Jon Dev</td>
                                    <td>Class 1 (A)</td>
                                    <td>(+33)6 55 56 56 33</td>
                                    <td>2</td>
                                    <td>01 Jun 2015</td>
                                    <td>01 Feb 2015</td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl"
                                                data-bs-toggle="dropdown" data-bs-display="static"
                                                aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li>
                                                    <a href="member-details.html"
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-eye-line"></i>View
                                                    </a>
                                                </li>
                                                <li>
                                                    <button type="button"
                                                        class="edit-sidebar-btn dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-edit-2-line"></i>Edit
                                                    </button>
                                                </li>
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        type="button">
                                                        <i class="ri-book-open-line"></i>Issue Book
                                                    </button>
                                                </li>
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        type="button" data-bs-toggle="modal"
                                                        data-bs-target="#exampleModalDelete">
                                                        <i class="ri-delete-bin-6-line"></i>Delete
                                                    </button>
                                                </li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>

                                <tr>
                                    <td>
                                        <div class="form-check style-check d-flex align-items-center">
                                            <input class="form-check-input" type="checkbox">
                                            <label class="form-check-label">02</label>
                                        </div>
                                    </td>
                                    <td>15 Jan 2016</td>
                                    <td>12890</td>
                                    <td>Emily Johnson</td>
                                    <td>Class 2 (B)</td>
                                    <td>(+1) 205 555 7821</td>
                                    <td>3</td>
                                    <td>12 Jan 2016</td>
                                    <td>20 Jan 2016</td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl"
                                                data-bs-toggle="dropdown" data-bs-display="static"
                                                aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li>
                                                    <a href="member-details.html"
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-eye-line"></i>View
                                                    </a>
                                                </li>
                                                <li>
                                                    <button type="button"
                                                        class="edit-sidebar-btn dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-edit-2-line"></i>Edit
                                                    </button>
                                                </li>
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        type="button">
                                                        <i class="ri-book-open-line"></i>Issue Book
                                                    </button>
                                                </li>
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        type="button" data-bs-toggle="modal"
                                                        data-bs-target="#exampleModalDelete">
                                                        <i class="ri-delete-bin-6-line"></i>Delete
                                                    </button>
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
                                    <td>10 Feb 2017</td>
                                    <td>14250</td>
                                    <td>Michael Brown</td>
                                    <td>Class 3 (C)</td>
                                    <td>(+44) 745 987 3210</td>
                                    <td>1</td>
                                    <td>05 Feb 2017</td>
                                    <td>15 Feb 2017</td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl"
                                                data-bs-toggle="dropdown" data-bs-display="static"
                                                aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li>
                                                    <a href="member-details.html"
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-eye-line"></i>View
                                                    </a>
                                                </li>
                                                <li>
                                                    <button type="button"
                                                        class="edit-sidebar-btn dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-edit-2-line"></i>Edit
                                                    </button>
                                                </li>
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        type="button">
                                                        <i class="ri-book-open-line"></i>Issue Book
                                                    </button>
                                                </li>
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        type="button" data-bs-toggle="modal"
                                                        data-bs-target="#exampleModalDelete">
                                                        <i class="ri-delete-bin-6-line"></i>Delete
                                                    </button>
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
                                    <td>22 Mar 2018</td>
                                    <td>15642</td>
                                    <td>Sarah Lee</td>
                                    <td>Class 4 (A)</td>
                                    <td>(+49) 178 556 9876</td>
                                    <td>4</td>
                                    <td>15 Mar 2018</td>
                                    <td>25 Mar 2018</td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl"
                                                data-bs-toggle="dropdown" data-bs-display="static"
                                                aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li>
                                                    <a href="member-details.html"
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-eye-line"></i>View
                                                    </a>
                                                </li>
                                                <li>
                                                    <button type="button"
                                                        class="edit-sidebar-btn dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-edit-2-line"></i>Edit
                                                    </button>
                                                </li>
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        type="button">
                                                        <i class="ri-book-open-line"></i>Issue Book
                                                    </button>
                                                </li>
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        type="button" data-bs-toggle="modal"
                                                        data-bs-target="#exampleModalDelete">
                                                        <i class="ri-delete-bin-6-line"></i>Delete
                                                    </button>
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
                                    <td>09 Apr 2019</td>
                                    <td>16580</td>
                                    <td>William Smith</td>
                                    <td>Class 5 (B)</td>
                                    <td>(+91) 98765 43210</td>
                                    <td>2</td>
                                    <td>05 Apr 2019</td>
                                    <td>10 Apr 2019</td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl"
                                                data-bs-toggle="dropdown" data-bs-display="static"
                                                aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li>
                                                    <a href="member-details.html"
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-eye-line"></i>View
                                                    </a>
                                                </li>
                                                <li>
                                                    <button type="button"
                                                        class="edit-sidebar-btn dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-edit-2-line"></i>Edit
                                                    </button>
                                                </li>
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        type="button">
                                                        <i class="ri-book-open-line"></i>Issue Book
                                                    </button>
                                                </li>
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        type="button" data-bs-toggle="modal"
                                                        data-bs-target="#exampleModalDelete">
                                                        <i class="ri-delete-bin-6-line"></i>Delete
                                                    </button>
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
                                    <td>20 May 2020</td>
                                    <td>17690</td>
                                    <td>Olivia White</td>
                                    <td>Class 6 (C)</td>
                                    <td>(+971) 55 432 7890</td>
                                    <td>3</td>
                                    <td>18 May 2020</td>
                                    <td>28 May 2020</td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl"
                                                data-bs-toggle="dropdown" data-bs-display="static"
                                                aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li>
                                                    <a href="member-details.html"
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-eye-line"></i>View
                                                    </a>
                                                </li>
                                                <li>
                                                    <button type="button"
                                                        class="edit-sidebar-btn dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-edit-2-line"></i>Edit
                                                    </button>
                                                </li>
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        type="button">
                                                        <i class="ri-book-open-line"></i>Issue Book
                                                    </button>
                                                </li>
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        type="button" data-bs-toggle="modal"
                                                        data-bs-target="#exampleModalDelete">
                                                        <i class="ri-delete-bin-6-line"></i>Delete
                                                    </button>
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
                                    <td>01 Jun 2021</td>
                                    <td>18950</td>
                                    <td>James Wilson</td>
                                    <td>Class 7 (A)</td>
                                    <td>(+92) 333 456 7890</td>
                                    <td>5</td>
                                    <td>25 May 2021</td>
                                    <td>05 Jun 2021</td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl"
                                                data-bs-toggle="dropdown" data-bs-display="static"
                                                aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li>
                                                    <a href="member-details.html"
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-eye-line"></i>View
                                                    </a>
                                                </li>
                                                <li>
                                                    <button type="button"
                                                        class="edit-sidebar-btn dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-edit-2-line"></i>Edit
                                                    </button>
                                                </li>
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        type="button">
                                                        <i class="ri-book-open-line"></i>Issue Book
                                                    </button>
                                                </li>
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        type="button" data-bs-toggle="modal"
                                                        data-bs-target="#exampleModalDelete">
                                                        <i class="ri-delete-bin-6-line"></i>Delete
                                                    </button>
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
                                    <td>17 Jul 2022</td>
                                    <td>19560</td>
                                    <td>Emma Garcia</td>
                                    <td>Class 8 (B)</td>
                                    <td>(+880) 1712 567 890</td>
                                    <td>1</td>
                                    <td>10 Jul 2022</td>
                                    <td>20 Jul 2022</td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl"
                                                data-bs-toggle="dropdown" data-bs-display="static"
                                                aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li>
                                                    <a href="member-details.html"
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-eye-line"></i>View
                                                    </a>
                                                </li>
                                                <li>
                                                    <button type="button"
                                                        class="edit-sidebar-btn dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-edit-2-line"></i>Edit
                                                    </button>
                                                </li>
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        type="button">
                                                        <i class="ri-book-open-line"></i>Issue Book
                                                    </button>
                                                </li>
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        type="button" data-bs-toggle="modal"
                                                        data-bs-target="#exampleModalDelete">
                                                        <i class="ri-delete-bin-6-line"></i>Delete
                                                    </button>
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
                                    <td>08 Aug 2023</td>
                                    <td>20540</td>
                                    <td>Liam Martinez</td>
                                    <td>Class 9 (A)</td>
                                    <td>(+880) 1785 112 223</td>
                                    <td>2</td>
                                    <td>01 Aug 2023</td>
                                    <td>12 Aug 2023</td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl"
                                                data-bs-toggle="dropdown" data-bs-display="static"
                                                aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li>
                                                    <a href="member-details.html"
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-eye-line"></i>View
                                                    </a>
                                                </li>
                                                <li>
                                                    <button type="button"
                                                        class="edit-sidebar-btn dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-edit-2-line"></i>Edit
                                                    </button>
                                                </li>
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        type="button">
                                                        <i class="ri-book-open-line"></i>Issue Book
                                                    </button>
                                                </li>
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        type="button" data-bs-toggle="modal"
                                                        data-bs-target="#exampleModalDelete">
                                                        <i class="ri-delete-bin-6-line"></i>Delete
                                                    </button>
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
                                    <td>02 Sep 2024</td>
                                    <td>21500</td>
                                    <td>Noah Anderson</td>
                                    <td>Class 10 (C)</td>
                                    <td>(+880) 1990 998 877</td>
                                    <td>6</td>
                                    <td>28 Aug 2024</td>
                                    <td>10 Sep 2024</td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl"
                                                data-bs-toggle="dropdown" data-bs-display="static"
                                                aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li>
                                                    <a href="member-details.html"
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-eye-line"></i>View
                                                    </a>
                                                </li>
                                                <li>
                                                    <button type="button"
                                                        class="edit-sidebar-btn dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-edit-2-line"></i>Edit
                                                    </button>
                                                </li>
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        type="button">
                                                        <i class="ri-book-open-line"></i>Issue Book
                                                    </button>
                                                </li>
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        type="button" data-bs-toggle="modal"
                                                        data-bs-target="#exampleModalDelete">
                                                        <i class="ri-delete-bin-6-line"></i>Delete
                                                    </button>
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

<!-- Add sidebar start -->
<div
    class="my-sidebar bg-white position-fixed end-0 top-0 h-100vh overflow-y-auto z-99 max-w-700-px w-100 translate-x-full duration-300 active-translate-0">
    <div class="px-20 py-12 border-bottom d-flex align-items-center justify-content-between gap-20">
        <h5 class="text-lg mb-0">Add Book </h5>
        <button type="button" class="close-my-sidebar text-danger-600 text-lg d-flex">
            <i class="ri-close-large-line"></i>
        </button>
    </div>
    <form action="#" class="d-flex flex-column p-20">
        <div class="row g-3">
            <div class="col-sm-6">
                <div class="">
                    <label for="libraryCardNo"
                        class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Library Card No
                    </label>
                    <input type="text" class="form-control" id="libraryCardNo" placeholder="Enter Library Card No">
                </div>
            </div>
            <div class="col-sm-6">
                <div class="">
                    <label for="memberClass" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Class
                    </label>
                    <input type="text" class="form-control" id="memberClass" placeholder="Enter Class">
                </div>
            </div>
            <div class="col-sm-6">
                <div class="">
                    <label for="memberSection"
                        class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Section
                    </label>
                    <input type="text" class="form-control" id="memberSection" placeholder="Enter Section">
                </div>
            </div>
            <div class="col-sm-6">
                <div class="">
                    <label for="memberStudent"
                        class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Student
                    </label>
                    <select id="memberStudent" class="form-control form-select">
                        <option value="Select a Student" selected disabled>Select Student</option>
                        <option value="Regular">Regular</option>
                        <option value="Irregular">Irregular</option>
                    </select>
                </div>
            </div>
            <div class="col-sm-6">
                <div class="">
                    <label for="memberEmail" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Email
                    </label>
                    <input type="email" class="form-control" id="memberEmail" placeholder="Enter Email">
                </div>
            </div>
            <div class="col-sm-6">
                <div class="">
                    <label for="phoneNumber" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Phone
                        Number
                    </label>
                    <input type="tel" class="form-control" id="phoneNumber" placeholder="Enter Phone Number">
                </div>
            </div>
            <div class="col-sm-12">
                <div class="">
                    <label for="joinDate" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Join Date
                    </label>
                    <input type="date" class="form-control" id="joinDate">
                </div>
            </div>
            <div class="col-sm-12">
                <div class="">
                    <h6 class="text-lg mt-16">Book Issue</h6>
                </div>
            </div>
            <div class="col-sm-6">
                <div class="">
                    <label for="selectSubject" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Select
                        Subject
                    </label>
                    <select id="selectSubject" class="form-control form-select">
                        <option value="Select a Student" selected disabled>Select a Subject</option>
                        <option value="Regular">Regular</option>
                        <option value="Irregular">Irregular</option>
                    </select>
                </div>
            </div>
            <div class="col-sm-6">
                <div class="">
                    <label for="selectBook" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Book
                    </label>
                    <select id="selectBook" class="form-control form-select">
                        <option value="Select a Student" selected disabled>Select a book</option>
                        <option value="English">English</option>
                        <option value="Mathematics">Mathematics</option>
                    </select>
                </div>
            </div>
            <div class="col-sm-6">
                <div class="">
                    <label for="issueDate" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Issue Date
                    </label>
                    <input type="date" class="form-control" id="issueDate">
                </div>
            </div>
            <div class="col-sm-6">
                <div class="">
                    <label for="returnDate" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Return
                        Date
                    </label>
                    <input type="date" class="form-control" id="returnDate">
                </div>
            </div>
            <div class="col-12">
                <div class="d-flex align-items-center justify-content-center gap-3 mt-8">
                    <button type="reset"
                        class="border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-50 py-11 radius-8">
                        Cancel
                    </button>
                    <button type="submit"
                        class="btn btn-primary-600 border border-primary-600 text-md px-28 py-12 radius-8 max-w-156-px w-100">
                        Save
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>
<!-- Add sidebar end -->

<!-- Edit sidebar start -->
<div
    class="edit-sidebar bg-white position-fixed end-0 top-0 h-100vh overflow-y-auto z-99 max-w-700-px w-100 translate-x-full duration-300 active-translate-0">
    <div class="px-20 py-12 border-bottom d-flex align-items-center justify-content-between gap-20">
        <h5 class="text-lg mb-0">Edit Fees Type</h5>
        <button type="button" class="close-edit-sidebar text-danger-600 text-lg d-flex">
            <i class="ri-close-large-line"></i>
        </button>
    </div>
    <form action="#" class="d-flex flex-column p-20">
        <div class="row g-3">
            <div class="col-sm-6">
                <div class="">
                    <label for="libraryCardNoEdit"
                        class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Library Card No
                    </label>
                    <input type="text" class="form-control" id="libraryCardNoEdit" placeholder="Enter Library Card No">
                </div>
            </div>
            <div class="col-sm-6">
                <div class="">
                    <label for="memberClassEdit"
                        class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Class
                    </label>
                    <input type="text" class="form-control" id="memberClassEdit" placeholder="Enter Class">
                </div>
            </div>
            <div class="col-sm-6">
                <div class="">
                    <label for="memberSectionEdit"
                        class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Section
                    </label>
                    <input type="text" class="form-control" id="memberSectionEdit" placeholder="Enter Section">
                </div>
            </div>
            <div class="col-sm-6">
                <div class="">
                    <label for="memberStudentEdit"
                        class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Student
                    </label>
                    <select id="memberStudentEdit" class="form-control form-select">
                        <option value="Select a Student" selected disabled>Select Student</option>
                        <option value="Regular">Regular</option>
                        <option value="Irregular">Irregular</option>
                    </select>
                </div>
            </div>
            <div class="col-sm-6">
                <div class="">
                    <label for="memberEmailEdit"
                        class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Email
                    </label>
                    <input type="email" class="form-control" id="memberEmailEdit" placeholder="Enter Email">
                </div>
            </div>
            <div class="col-sm-6">
                <div class="">
                    <label for="phoneNumberEdit"
                        class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Phone
                        Number
                    </label>
                    <input type="tel" class="form-control" id="phoneNumberEdit" placeholder="Enter Phone Number">
                </div>
            </div>
            <div class="col-sm-12">
                <div class="">
                    <label for="joinDateEdit" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Join
                        Date
                    </label>
                    <input type="date" class="form-control" id="joinDateEdit">
                </div>
            </div>
            <div class="col-12">
                <div class="d-flex align-items-center justify-content-center gap-3 mt-8">
                    <button type="reset"
                        class="border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-50 py-11 radius-8">
                        Cancel
                    </button>
                    <button type="submit"
                        class="btn btn-primary-600 border border-primary-600 text-md px-28 py-12 radius-8 max-w-156-px w-100">
                        Save
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>
<!-- Edit sidebar end -->

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
                    <button type="reset"
                        class="flex-grow-1 border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-24 py-11 radius-8" data-bs-dismiss="modal">
                        Cancel
                    </button>
                    <button type="button"
                        class="flex-grow-1 btn btn-primary-600 border border-primary-600 text-md px-16 py-12 radius-8">
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

    // Sidebar js start
    $('.my-sidebar-btn').on('click', function () {
        $('.my-sidebar').addClass('active');
        $('.overlay').addClass('active');
    });
    $('.close-my-sidebar, .overlay').on('click', function () {
        $('.my-sidebar').removeClass('active');
        $('.overlay').removeClass('active');
    });

    $('.edit-sidebar-btn').on('click', function () {
        $('.edit-sidebar').addClass('active');
        $('.overlay').addClass('active');
    });
    $('.close-edit-sidebar, .overlay').on('click', function () {
        $('.edit-sidebar').removeClass('active');
        $('.overlay').removeClass('active');
    });
    // Sidebar js end
</script>

</body>

</html>