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
                <h1 class="fw-semibold mb-4 h6 text-primary-light">Books List </h1>
                <div class="">
                    <a href="index.html" class="text-secondary-light hover-text-primary hover-underline">Dashboard </a>
                    <span class="text-secondary-light">/ Books List </span>
                </div>
            </div>
            <button type="button" class="my-sidebar-btn btn btn-primary-600 d-flex align-items-center gap-6">
                <span class="d-flex text-md">
                    <i class="ri-add-large-line"></i>
                </span>
                Add Book
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
                                    <th scope="col">Subject</th>
                                    <th scope="col">Book Name</th>
                                    <th scope="col">Publisher</th>
                                    <th scope="col">Author</th>
                                    <th scope="col">Number</th>
                                    <th scope="col">Rack No</th>
                                    <th scope="col">Qty</th>
                                    <th scope="col">Available</th>
                                    <th scope="col">Price</th>
                                    <th scope="col">Exam Date</th>
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
                                    <td>Art</td>
                                    <td>The Little Prince</td>
                                    <td>Devon Lane</td>
                                    <td>Darrell Steward</td>
                                    <td>101</td>
                                    <td>1234</td>
                                    <td>60</td>
                                    <td>20</td>
                                    <td>$250</td>
                                    <td>05 Jun 2015</td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl"
                                                data-bs-toggle="dropdown" data-bs-display="static"
                                                aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li>
                                                    <button type="button"
                                                        class="edit-sidebar-btn dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-edit-2-line"></i>
                                                        Edit
                                                    </button>
                                                </li>
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        type="button" data-bs-toggle="modal"
                                                        data-bs-target="#exampleModalDelete">
                                                        <i class="ri-delete-bin-6-line"></i>
                                                        Delete
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
                                    <td>Mathematics</td>
                                    <td>Advanced Algebra</td>
                                    <td>Penguin Books</td>
                                    <td>Jane Cooper</td>
                                    <td>102</td>
                                    <td>5678</td>
                                    <td>40</td>
                                    <td>18</td>
                                    <td>$300</td>
                                    <td>10 Jul 2016</td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl"
                                                data-bs-toggle="dropdown" data-bs-display="static"
                                                aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li><button type="button"
                                                        class="edit-sidebar-btn dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"><i
                                                            class="ri-edit-2-line"></i>Edit</button></li>
                                                <li><button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        type="button" data-bs-toggle="modal"
                                                        data-bs-target="#exampleModalDelete"><i
                                                            class="ri-delete-bin-6-line"></i>Delete</button></li>
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
                                    <td>Science</td>
                                    <td>Physics for Beginners</td>
                                    <td>HarperCollins</td>
                                    <td>Guy Hawkins</td>
                                    <td>103</td>
                                    <td>8790</td>
                                    <td>55</td>
                                    <td>30</td>
                                    <td>$280</td>
                                    <td>15 Mar 2017</td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl"
                                                data-bs-toggle="dropdown" data-bs-display="static"
                                                aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li><button type="button"
                                                        class="edit-sidebar-btn dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"><i
                                                            class="ri-edit-2-line"></i>Edit</button></li>
                                                <li><button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        type="button" data-bs-toggle="modal"
                                                        data-bs-target="#exampleModalDelete"><i
                                                            class="ri-delete-bin-6-line"></i>Delete</button></li>
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
                                    <td>History</td>
                                    <td>World Wars</td>
                                    <td>Oxford Press</td>
                                    <td>Leslie Alexander</td>
                                    <td>104</td>
                                    <td>3210</td>
                                    <td>35</td>
                                    <td>12</td>
                                    <td>$200</td>
                                    <td>21 Sep 2018</td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl"
                                                data-bs-toggle="dropdown" data-bs-display="static"
                                                aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li><button type="button"
                                                        class="edit-sidebar-btn dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"><i
                                                            class="ri-edit-2-line"></i>Edit</button></li>
                                                <li><button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        type="button" data-bs-toggle="modal"
                                                        data-bs-target="#exampleModalDelete"><i
                                                            class="ri-delete-bin-6-line"></i>Delete</button></li>
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
                                    <td>Geography</td>
                                    <td>Earth & Beyond</td>
                                    <td>Macmillan</td>
                                    <td>Robert Fox</td>
                                    <td>105</td>
                                    <td>4311</td>
                                    <td>45</td>
                                    <td>10</td>
                                    <td>$310</td>
                                    <td>08 Jan 2019</td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl"
                                                data-bs-toggle="dropdown" data-bs-display="static"
                                                aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li><button type="button"
                                                        class="edit-sidebar-btn dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"><i
                                                            class="ri-edit-2-line"></i>Edit</button></li>
                                                <li><button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        type="button" data-bs-toggle="modal"
                                                        data-bs-target="#exampleModalDelete"><i
                                                            class="ri-delete-bin-6-line"></i>Delete</button></li>
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
                                    <td>Biology</td>
                                    <td>Human Anatomy</td>
                                    <td>Cambridge House</td>
                                    <td>Annette Black</td>
                                    <td>106</td>
                                    <td>2915</td>
                                    <td>70</td>
                                    <td>35</td>
                                    <td>$400</td>
                                    <td>11 Dec 2020</td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl"
                                                data-bs-toggle="dropdown" data-bs-display="static"
                                                aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li><button type="button"
                                                        class="edit-sidebar-btn dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"><i
                                                            class="ri-edit-2-line"></i>Edit</button></li>
                                                <li><button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        type="button" data-bs-toggle="modal"
                                                        data-bs-target="#exampleModalDelete"><i
                                                            class="ri-delete-bin-6-line"></i>Delete</button></li>
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
                                    <td>Economics</td>
                                    <td>Money & Markets</td>
                                    <td>Random House</td>
                                    <td>Esther Howard</td>
                                    <td>107</td>
                                    <td>3425</td>
                                    <td>50</td>
                                    <td>28</td>
                                    <td>$270</td>
                                    <td>19 Mar 2021</td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl"
                                                data-bs-toggle="dropdown" data-bs-display="static"
                                                aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li><button type="button"
                                                        class="edit-sidebar-btn dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"><i
                                                            class="ri-edit-2-line"></i>Edit</button></li>
                                                <li><button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        type="button" data-bs-toggle="modal"
                                                        data-bs-target="#exampleModalDelete"><i
                                                            class="ri-delete-bin-6-line"></i>Delete</button></li>
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
                                    <td>Computer Science</td>
                                    <td>JavaScript Essentials</td>
                                    <td>TechWorld</td>
                                    <td>Kathryn Murphy</td>
                                    <td>108</td>
                                    <td>5320</td>
                                    <td>80</td>
                                    <td>60</td>
                                    <td>$500</td>
                                    <td>05 Apr 2022</td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl"
                                                data-bs-toggle="dropdown" data-bs-display="static"
                                                aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li><button type="button"
                                                        class="edit-sidebar-btn dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"><i
                                                            class="ri-edit-2-line"></i>Edit</button></li>
                                                <li><button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        type="button" data-bs-toggle="modal"
                                                        data-bs-target="#exampleModalDelete"><i
                                                            class="ri-delete-bin-6-line"></i>Delete</button></li>
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
                                    <td>English</td>
                                    <td>Shakespeare's Works</td>
                                    <td>Vintage Books</td>
                                    <td>Courtney Henry</td>
                                    <td>109</td>
                                    <td>1567</td>
                                    <td>65</td>
                                    <td>45</td>
                                    <td>$350</td>
                                    <td>12 May 2023</td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl"
                                                data-bs-toggle="dropdown" data-bs-display="static"
                                                aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li><button type="button"
                                                        class="edit-sidebar-btn dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"><i
                                                            class="ri-edit-2-line"></i>Edit</button></li>
                                                <li><button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        type="button" data-bs-toggle="modal"
                                                        data-bs-target="#exampleModalDelete"><i
                                                            class="ri-delete-bin-6-line"></i>Delete</button></li>
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
                                    <td>Chemistry</td>
                                    <td>Organic Compounds</td>
                                    <td>Scholastic</td>
                                    <td>Wade Warren</td>
                                    <td>110</td>
                                    <td>4879</td>
                                    <td>75</td>
                                    <td>55</td>
                                    <td>$420</td>
                                    <td>10 Feb 2024</td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl"
                                                data-bs-toggle="dropdown" data-bs-display="static"
                                                aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li><button type="button"
                                                        class="edit-sidebar-btn dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"><i
                                                            class="ri-edit-2-line"></i>Edit</button></li>
                                                <li><button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        type="button" data-bs-toggle="modal"
                                                        data-bs-target="#exampleModalDelete"><i
                                                            class="ri-delete-bin-6-line"></i>Delete</button></li>
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
    <form method="POST" action="" class="d-flex flex-column p-20">
        <input type="hidden" name="action" value="create_book">
        <div class="row g-3">
            <div class="col-sm-12">
                <div class="">
                    <label for="bookName" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Book Name
                    </label>
                    <input type="text" class="form-control" id="bookName" name="name" placeholder="Enter Book Name">
                </div>
            </div>
            <div class="col-sm-6">
                <div class="">
                    <label for="publisher" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Publisher
                    </label>
                    <input type="text" class="form-control" id="publisher" name="publisher" placeholder="Enter Publisher">
                </div>
            </div>
            <div class="col-sm-6">
                <div class="">
                    <label for="author" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Author
                    </label>
                    <input type="text" class="form-control" id="author" name="author" placeholder="Enter Author">
                </div>
            </div>
            <div class="col-sm-6">
                <div class="">
                    <label for="number" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">ISBN
                    </label>
                    <input type="text" class="form-control" id="number" name="isbn" placeholder="Enter ISBN">
                </div>
            </div>
            <div class="col-sm-6">
                <div class="">
                    <label for="subject" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Subject
                    </label>
                    <input type="text" class="form-control" id="subject" name="subject" placeholder="Enter Subject">
                </div>
            </div>
            <div class="col-sm-6">
                <div class="">
                    <label for="rackNo" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Rack No
                    </label>
                    <input type="text" class="form-control" id="rackNo" name="rack_no" placeholder="Enter Rack No">
                </div>
            </div>
            <div class="col-sm-6">
                <div class="">
                    <label for="enterQty" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Qty
                    </label>
                    <input type="text" class="form-control" id="enterQty" name="quantity" placeholder="Enter Qty">
                </div>
            </div>
            <div class="col-sm-6">
                <div class="">
                    <label for="available" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Available
                    </label>
                    <input type="text" class="form-control" id="available" name="available" placeholder="Enter Available">
                </div>
            </div>
            <div class="col-sm-6">
                <div class="">
                    <label for="price" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Price
                    </label>
                    <input type="text" class="form-control" id="price" name="price" placeholder="Enter Price">
                </div>
            </div>
            <div class="col-sm-6">
                <div class="">
                    <label for="postDate" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Post Date
                    </label>
                    <input type="date" class="form-control" id="postDate" name="post_date">
                </div>
            </div>
            <div class="col-sm-6">
                <div class="">
                    <label for="status" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Status
                    </label>
                    <select id="status" name="status" class="form-control form-select">
                        <option value="Select a Status" selected disabled>Select Status</option>
                        <option value="Active">Active</option>
                        <option value="Inactive">Inactive</option>
                    </select>
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
    <form method="POST" action="" class="d-flex flex-column p-20">
        <input type="hidden" name="action" value="update_book">
        <input type="hidden" name="id" id="edit_id" value="">
        <div class="row g-3">
            <div class="col-sm-12">
                <div class="">
                    <label for="bookNameEdit" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Book
                        Name
                    </label>
                    <input type="text" class="form-control" id="bookNameEdit" name="name" placeholder="Enter Book Name">
                </div>
            </div>
            <div class="col-sm-6">
                <div class="">
                    <label for="publisherEdit"
                        class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Publisher
                    </label>
                    <input type="text" class="form-control" id="publisherEdit" name="publisher" placeholder="Enter Publisher">
                </div>
            </div>
            <div class="col-sm-6">
                <div class="">
                    <label for="authorEdit" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Author
                    </label>
                    <input type="text" class="form-control" id="authorEdit" name="author" placeholder="Enter Author">
                </div>
            </div>
            <div class="col-sm-6">
                <div class="">
                    <label for="numberEdit" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">ISBN
                    </label>
                    <input type="text" class="form-control" id="numberEdit" name="isbn" placeholder="Enter ISBN">
                </div>
            </div>
            <div class="col-sm-6">
                <div class="">
                    <label for="subjectEdit" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Subject
                    </label>
                    <input type="text" class="form-control" id="subjectEdit" name="subject" placeholder="Enter Subject">
                </div>
            </div>
            <div class="col-sm-6">
                <div class="">
                    <label for="rackNoEdit" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Rack No
                    </label>
                    <input type="text" class="form-control" id="rackNoEdit" name="rack_no" placeholder="Enter Rack No">
                </div>
            </div>
            <div class="col-sm-6">
                <div class="">
                    <label for="enterQtyEdit" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Qty
                    </label>
                    <input type="text" class="form-control" id="enterQtyEdit" name="quantity" placeholder="Enter Qty">
                </div>
            </div>
            <div class="col-sm-6">
                <div class="">
                    <label for="availableEdit"
                        class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Available
                    </label>
                    <input type="text" class="form-control" id="availableEdit" name="available" placeholder="Enter Available">
                </div>
            </div>
            <div class="col-sm-6">
                <div class="">
                    <label for="priceEdit" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Price
                    </label>
                    <input type="text" class="form-control" id="priceEdit" name="price" placeholder="Enter Price">
                </div>
            </div>
            <div class="col-sm-6">
                <div class="">
                    <label for="postDateEdit" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Post
                        Date
                    </label>
                    <input type="date" class="form-control" id="postDateEdit" name="post_date">
                </div>
            </div>
            <div class="col-sm-6">
                <div class="">
                    <label for="statusEdit" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Status
                    </label>
                    <select id="statusEdit" name="status" class="form-control form-select">
                        <option value="Select a Status" selected disabled>Select Status</option>
                        <option value="Active">Active</option>
                        <option value="Inactive">Inactive</option>
                    </select>
                </div>
            </div>
            <div class="col-12">
                <div class="d-flex align-items-center justify-content-center gap-3 mt-8">
                    <button type="reset"
                        class="border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-50 py-11 radius-8">
                        Cancel
                    </button>
                    <button type="submit"
                        class="btn btn-primary-600 border border-primary-600 text-md px-28 py-12 radius-8">
                        Update
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