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
    <h1 class="fw-semibold mb-4 h6 text-primary-light">Member Details</h1>
    <div class="">
      <a href="index.html" class="text-secondary-light hover-text-primary hover-underline">Dashboard </a>
      <a href="books-list.html" class="text-secondary-light hover-text-primary hover-underline "> /
        Library</a>
      <span class="text-secondary-light">/ Member Details</span>
    </div>
  </div>
  <a href="add-new-teacher.html" class="btn btn-primary-600 d-flex align-items-center gap-6 d-none">
    <span class="d-flex text-md">
      <i class="ri-add-large-line"></i>
    </span>
    Add Teacher
  </a>
</div>

        <div class="mt-24">
            <div class="card h-100">
                <div class="card-body p-24">
                    <div class="d-flex gap-32 flex-md-row flex-column">
                        <div class="max-w-300-px w-100 text-center">
                            <figure class="mb-20 w-120-px h-120-px mx-auto rounded-circle overflow-hidden">
                                <img src="https://academixsuite.com/tenant/assets/images/thumbs/student-details-img.png" alt="Student Image"
                                    class="w-100 h-100 object-fit-cover">
                            </figure>
                            <h2 class="h6 text-primary-light mb-4 fw-semibold">Seth Hallam</h2>
                            <p class="mb-0">Admission No: <span class="text-primary-600 fw-semibold">AD1256589</span>
                            </p>
                            <div class="mt-32 d-flex gap-16 w-100">
                                <button type="button"
                                    class="btn border fw-medium border-danger-600 bg-hover-danger-200 text-danger-600 text-md d-flex justify-content-center align-items-center gap-8 flex-grow-1 px-12 py-8 radius-8"
                                    data-bs-toggle="modal" data-bs-target="#exampleModalDelete">
                                    <span class="d-flex text-lg">
                                        <i class="ri-delete-bin-2-line"></i>
                                    </span>
                                    Suspend
                                </button>
                                <a href="edit-student.html"
                                    class="btn btn-primary-600 border fw-medium border-primary-600 text-md d-flex justify-content-center align-items-center gap-8 flex-grow-1 px-12 py-8 radius-8">
                                    <span class="d-flex text-lg">
                                        <i class="ri-edit-line"></i>
                                    </span>
                                    Edit
                                </a>
                            </div>
                        </div>
                        <div class="">
                            <span class="h-100 w-1-px bg-neutral-200"></span>
                        </div>
                        <div class="flex-grow-1">
                            <div class="pb-16 border-bottom d-flex align-items-center justify-content-between gap-20">
                                <h3 class="h6 text-primary-light text-lg mb-0 fw-semibold">Personal Info</h3>
                            </div>
                            <div class="mt-16 d-flex flex-column gap-8">
                                <div class="d-flex gap-4">
                                    <span class="fw-semibold text-sm text-primary-light w-110-px">Class</span>
                                    <span class="fw-normal text-sm text-secondary-light">: Class 6 (2025-26)</span>
                                </div>
                                <div class="d-flex gap-4">
                                    <span class="fw-semibold text-sm text-primary-light w-110-px">Section</span>
                                    <span class="fw-normal text-sm text-secondary-light">: A</span>
                                </div>
                                <div class="d-flex gap-4">
                                    <span class="fw-semibold text-sm text-primary-light w-110-px">Join Date</span>
                                    <span class="fw-normal text-sm text-secondary-light">: 10</span>
                                </div>
                                <div class="d-flex gap-4">
                                    <span class="fw-semibold text-sm text-primary-light w-110-px">Gender</span>
                                    <span class="fw-normal text-sm text-secondary-light">: 10 Nov 2006</span>
                                </div>
                                <div class="d-flex gap-4">
                                    <span class="fw-semibold text-sm text-primary-light w-110-px">Phone Number</span>
                                    <span class="fw-normal text-sm text-primary-600">: 789678456</span>
                                </div>
                                <div class="d-flex gap-4">
                                    <span class="fw-semibold text-sm text-primary-light w-110-px">Email</span>
                                    <span class="fw-normal text-sm text-primary-600">: Set@example.com</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="my-16">
                <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                    <div class="card-header bg-base py-10 px-20 d-flex align-items-center justify-content-between">
                        <h6 class="text-lg fw-semibold mb-0">Book Issued </h6>
                        <button type="button"
                            class="my-sidebar-btn btn btn-primary-600 d-flex align-items-center gap-6 py-8 text-sm">
                            <span class="d-flex text-sm">
                                <i class="ri-add-large-line"></i>
                            </span>
                            Add Issue Book
                        </button>
                    </div>
                    <div class="card-body p-0 dataTable-wrapper">
                        <div class="d-flex flex-wrap align-items-center gap-24 justify-content-between px-20 py-12">
                            <div class="d-flex flex-wrap align-items-center gap-16">
                                <form class="navbar-search dt-search m-0">
                                    <input type="text" class="dt-input bg-transparent radius-4"
                                        aria-controls="dataTable" name="search" placeholder="Search...">
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

                        <table class="table bordered-table mb-0 table-heading-dark-mode w-100 data-table" id="dataTable"
                            data-page-length='10'>
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
                                    <th scope="col">Book Name</th>
                                    <th scope="col">Number</th>
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
                                    <td>The Little Prince</td>
                                    <td>101</td>
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
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        type="button" data-bs-toggle="modal"
                                                        data-bs-target="#exampleModalReturn">
                                                        <i class="ri-arrow-go-back-line"></i>Book Return
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
                                    <td>To Kill a Mockingbird</td>
                                    <td>102</td>
                                    <td>10 Jul 2016</td>
                                    <td>25 Jul 2016</td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl"
                                                data-bs-toggle="dropdown" data-bs-display="static"
                                                aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        type="button" data-bs-toggle="modal"
                                                        data-bs-target="#exampleModalReturn">
                                                        <i class="ri-arrow-go-back-line"></i>Book Return
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
                                    <td>1984</td>
                                    <td>103</td>
                                    <td>12 Mar 2017</td>
                                    <td>02 Apr 2017</td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl"
                                                data-bs-toggle="dropdown" data-bs-display="static"
                                                aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        type="button" data-bs-toggle="modal"
                                                        data-bs-target="#exampleModalReturn">
                                                        <i class="ri-arrow-go-back-line"></i>Book Return
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
                                    <td>Pride and Prejudice</td>
                                    <td>104</td>
                                    <td>05 Aug 2018</td>
                                    <td>28 Aug 2018</td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl"
                                                data-bs-toggle="dropdown" data-bs-display="static"
                                                aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        type="button" data-bs-toggle="modal"
                                                        data-bs-target="#exampleModalReturn">
                                                        <i class="ri-arrow-go-back-line"></i>Book Return
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
                                    <td>The Great Gatsby</td>
                                    <td>105</td>
                                    <td>11 Nov 2018</td>
                                    <td>05 Dec 2018</td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl"
                                                data-bs-toggle="dropdown" data-bs-display="static"
                                                aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        type="button" data-bs-toggle="modal"
                                                        data-bs-target="#exampleModalReturn">
                                                        <i class="ri-arrow-go-back-line"></i>Book Return
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
                                    <td>The Catcher in the Rye</td>
                                    <td>106</td>
                                    <td>14 Jan 2019</td>
                                    <td>02 Feb 2019</td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl"
                                                data-bs-toggle="dropdown" data-bs-display="static"
                                                aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        type="button" data-bs-toggle="modal"
                                                        data-bs-target="#exampleModalReturn">
                                                        <i class="ri-arrow-go-back-line"></i>Book Return
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
                                    <td>Jane Eyre</td>
                                    <td>107</td>
                                    <td>03 Apr 2020</td>
                                    <td>20 Apr 2020</td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl"
                                                data-bs-toggle="dropdown" data-bs-display="static"
                                                aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        type="button" data-bs-toggle="modal"
                                                        data-bs-target="#exampleModalReturn">
                                                        <i class="ri-arrow-go-back-line"></i>Book Return
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
                                    <td>The Hobbit</td>
                                    <td>108</td>
                                    <td>22 May 2021</td>
                                    <td>10 Jun 2021</td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl"
                                                data-bs-toggle="dropdown" data-bs-display="static"
                                                aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        type="button" data-bs-toggle="modal"
                                                        data-bs-target="#exampleModalReturn">
                                                        <i class="ri-arrow-go-back-line"></i>Book Return
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
                                    <td>The Alchemist</td>
                                    <td>109</td>
                                    <td>09 Oct 2022</td>
                                    <td>28 Oct 2022</td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl"
                                                data-bs-toggle="dropdown" data-bs-display="static"
                                                aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        type="button" data-bs-toggle="modal"
                                                        data-bs-target="#exampleModalReturn">
                                                        <i class="ri-arrow-go-back-line"></i>Book Return
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
                                    <td>Harry Potter and the Sorcerer’s Stone</td>
                                    <td>110</td>
                                    <td>15 Jan 2023</td>
                                    <td>10 Feb 2023</td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl"
                                                data-bs-toggle="dropdown" data-bs-display="static"
                                                aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        type="button" data-bs-toggle="modal"
                                                        data-bs-target="#exampleModalReturn">
                                                        <i class="ri-arrow-go-back-line"></i>Book Return
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
        <h5 class="text-lg mb-0">Add Book Issue </h5>
        <button type="button" class="close-my-sidebar text-danger-600 text-lg d-flex">
            <i class="ri-close-large-line"></i>
        </button>
    </div>
    <form action="#" class="d-flex flex-column p-20">
        <div class="row g-3">
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

<!-- Modal Delete Event start -->
<div class="modal fade" id="exampleModalDelete" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog modal-dialog-centered max-w-340-px">
        <div class="modal-content radius-16 bg-base">
            <div class="modal-body pt-32 px-36 pb-24 text-center">
                <span class="mb-16 fs-1 line-height-1 text-danger">
                    <iconify-icon icon="fluent:delete-24-regular" class="menu-icon"></iconify-icon>
                </span>
                <h6 class="text-lg fw-semibold text-primary-light mb-0">Are your sure you want to Suspend this Student
                </h6>
                <div class="d-flex align-items-center justify-content-center gap-3 mt-24">
                    <button type="reset"
                        class="flex-grow-1 border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-24 py-11 radius-8">
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

<!-- Modal Return Event start -->
<div class="modal fade" id="exampleModalReturn" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog modal-dialog-centered max-w-340-px">
        <div class="modal-content radius-16 bg-base">
            <div class="modal-body pt-32 px-36 pb-24 text-center">
                <span class="mb-16 fs-1 line-height-1 text-primary-600">
                    <i class="ri-book-open-line"></i>
                </span>
                <h6 class="text-lg fw-semibold text-primary-light mb-0">Are your sure you want to Return this book!
                </h6>
                <div class="d-flex align-items-center justify-content-center gap-3 mt-24">
                    <button type="reset"
                        class="flex-grow-1 border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-24 py-11 radius-8">
                        No
                    </button>
                    <button type="button"
                        class="flex-grow-1 btn btn-primary-600 border border-primary-600 text-md px-16 py-12 radius-8">
                        Yes Return
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
<!-- Modal Return Event end -->

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
    // Data Table start
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
    // Sidebar js end

</script>

</body>

</html>