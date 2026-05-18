<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, viewport-fit=cover">
    <title>Edit School | NexusAdmin Executive</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap');
        
        :root {
            --brand-primary: #2563eb;
            --brand-primary-dark: #1d4ed8;
            --brand-secondary: #10b981;
            --brand-danger: #ef4444;
            --brand-warning: #f59e0b;
            --brand-surface: #ffffff;
            --brand-bg: #f8fafc;
            --brand-slate: #1e293b;
            --brand-slate-light: #64748b;
        }

        * {
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--brand-bg);
            color: var(--brand-slate);
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            line-height: 1.5;
            overflow-x: hidden;
        }

        /* Enhanced Scrollbar */
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        ::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 10px;
        }
        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 10px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        /* Glass Effect */
        .glass-header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(226, 232, 240, 0.8);
        }

        /* Card Design */
        .form-card {
            background: var(--brand-surface);
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.04);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .form-card:hover {
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.08);
            transform: translateY(-1px);
        }

        /* Tab System */
        .tab-nav {
            position: relative;
            background: white;
            border-bottom: 1px solid #e2e8f0;
            overflow-x: auto;
            scrollbar-width: none;
            -ms-overflow-style: none;
        }

        .tab-nav::-webkit-scrollbar {
            display: none;
        }

        .tab-button {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px 24px;
            font-size: 14px;
            font-weight: 600;
            color: var(--brand-slate-light);
            background: transparent;
            border: none;
            border-bottom: 3px solid transparent;
            white-space: nowrap;
            cursor: pointer;
            transition: all 0.2s ease;
            gap: 8px;
        }

        .tab-button:hover {
            color: var(--brand-primary);
            background: linear-gradient(to top, rgba(37, 99, 235, 0.04), transparent);
        }

        .tab-button.active {
            color: var(--brand-primary);
            border-bottom-color: var(--brand-primary);
            background: linear-gradient(to top, rgba(37, 99, 235, 0.08), transparent);
        }

        .tab-button.active::after {
            content: '';
            position: absolute;
            bottom: -1px;
            left: 50%;
            transform: translateX(-50%);
            width: 24px;
            height: 3px;
            background: var(--brand-primary);
            border-radius: 2px;
        }

        /* Form Elements */
        .form-group {
            position: relative;
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--brand-slate-light);
            margin-bottom: 8px;
        }

        .form-label.required::after {
            content: '*';
            color: var(--brand-danger);
            margin-left: 4px;
        }

        .form-input {
            width: 100%;
            padding: 12px 16px;
            font-size: 14px;
            font-weight: 500;
            color: var(--brand-slate);
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            transition: all 0.2s ease;
            outline: none;
        }

        .form-input:focus {
            border-color: var(--brand-primary);
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1);
            outline: none;
        }

        .form-input.error {
            border-color: var(--brand-danger);
        }

        .form-input.error:focus {
            box-shadow: 0 0 0 4px rgba(239, 68, 68, 0.1);
        }

        .form-help {
            display: block;
            font-size: 12px;
            color: var(--brand-slate-light);
            margin-top: 6px;
        }

        /* Status Badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 6px 12px;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-radius: 20px;
            gap: 6px;
        }

        .status-active {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .status-maintenance {
            background: #e0e7ff;
            color: #3730a3;
            border: 1px solid #c7d2fe;
        }

        .status-warning {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }

        .status-archived {
            background: #f3f4f6;
            color: #4b5563;
            border: 1px solid #d1d5db;
        }

        /* Toggle Switch */
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 52px;
            height: 28px;
        }

        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #cbd5e1;
            transition: .4s;
            border-radius: 34px;
        }

        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 20px;
            width: 20px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        input:checked + .toggle-slider {
            background-color: var(--brand-primary);
        }

        input:checked + .toggle-slider:before {
            transform: translateX(24px);
        }

        /* File Upload */
        .file-upload {
            position: relative;
            border: 2px dashed #cbd5e1;
            border-radius: 12px;
            padding: 32px 20px;
            text-align: center;
            background: #f8fafc;
            cursor: pointer;
            transition: all 0.3s ease;
            overflow: hidden;
        }

        .file-upload:hover {
            border-color: var(--brand-primary);
            background: #eff6ff;
        }

        .file-upload.dragover {
            border-color: var(--brand-primary);
            background: #eff6ff;
            transform: scale(1.01);
        }

        .file-upload input[type="file"] {
            position: absolute;
            width: 100%;
            height: 100%;
            top: 0;
            left: 0;
            opacity: 0;
            cursor: pointer;
        }

        /* Modal */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
            padding: 16px;
        }

        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .modal-content {
            background: white;
            border-radius: 20px;
            max-width: 480px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            transform: translateY(20px);
            transition: transform 0.3s ease;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
        }

        .modal-overlay.active .modal-content {
            transform: translateY(0);
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 12px 24px;
            font-size: 14px;
            font-weight: 600;
            border-radius: 12px;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            gap: 8px;
            outline: none;
            text-decoration: none;
            user-select: none;
            white-space: nowrap;
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--brand-primary), var(--brand-primary-dark));
            color: white;
            box-shadow: 0 2px 8px rgba(37, 99, 235, 0.2);
        }

        .btn-primary:hover:not(:disabled) {
            background: linear-gradient(135deg, var(--brand-primary-dark), #1e40af);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
        }

        .btn-secondary {
            background: white;
            color: var(--brand-slate);
            border: 2px solid #e2e8f0;
        }

        .btn-secondary:hover:not(:disabled) {
            background: #f8fafc;
            border-color: #cbd5e1;
            transform: translateY(-1px);
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--brand-danger), #dc2626);
            color: white;
            box-shadow: 0 2px 8px rgba(239, 68, 68, 0.2);
        }

        .btn-danger:hover:not(:disabled) {
            background: linear-gradient(135deg, #dc2626, #b91c1b);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--brand-warning), #d97706);
            color: white;
        }

        .btn-warning:hover:not(:disabled) {
            background: linear-gradient(135deg, #d97706, #b45309);
            transform: translateY(-1px);
        }

        /* Mobile Optimizations */
        @media (max-width: 640px) {
            .mobile-flex-col {
                flex-direction: column !important;
            }
            
            .mobile-w-full {
                width: 100% !important;
            }
            
            .mobile-text-center {
                text-align: center !important;
            }
            
            .mobile-p-4 {
                padding: 16px !important;
            }
            
            .mobile-space-y-4 > * + * {
                margin-top: 16px !important;
            }
            
            .tab-button {
                padding: 14px 16px;
                font-size: 13px;
                gap: 6px;
            }
            
            .btn {
                padding: 10px 16px;
                font-size: 13px;
                width: 100%;
            }
            
            .form-input {
                font-size: 16px; /* Prevents iOS zoom on focus */
                padding: 14px 16px;
            }
            
            /* Touch target sizing */
            .touch-target {
                min-height: 44px;
                min-width: 44px;
            }
        }

        @media (max-width: 768px) {
            .tablet-flex-col {
                flex-direction: column !important;
            }
            
            .tablet-w-full {
                width: 100% !important;
            }
            
            .tablet-text-center {
                text-align: center !important;
            }
            
            .tablet-p-6 {
                padding: 24px !important;
            }
        }

        /* Sidebar Mobile Optimization */
        .sidebar-mobile {
            position: fixed;
            inset: 0;
            left: -280px;
            width: 280px;
            background: white;
            z-index: 1000;
            transition: left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 20px 0 40px rgba(0, 0, 0, 0.1);
        }

        .sidebar-mobile.active {
            left: 0;
        }

        .sidebar-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .sidebar-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        /* Loading States */
        .loading {
            position: relative;
            overflow: hidden;
        }

        .loading::after {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.4), transparent);
            animation: loading 1.5s infinite;
        }

        @keyframes loading {
            0% { left: -100%; }
            100% { left: 100%; }
        }

        /* Notification System */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            left: 20px;
            padding: 16px;
            border-radius: 12px;
            background: white;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
            z-index: 1100;
            transform: translateX(120%);
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: center;
            gap: 12px;
            border-left: 4px solid var(--brand-primary);
        }

        .notification.active {
            transform: translateX(0);
        }

        .notification.success {
            border-left-color: var(--brand-secondary);
        }

        .notification.error {
            border-left-color: var(--brand-danger);
        }

        .notification.warning {
            border-left-color: var(--brand-warning);
        }

        @media (min-width: 640px) {
            .notification {
                left: auto;
                max-width: 400px;
            }
        }

        /* Tab Content Animation */
        .tab-content {
            display: none;
            animation: fadeInUp 0.3s ease-out;
        }

        .tab-content.active {
            display: block;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Safe Area Insets for Modern Mobile */
        @supports (padding: max(0px)) {
            .safe-area-top {
                padding-top: max(16px, env(safe-area-inset-top));
            }
            
            .safe-area-bottom {
                padding-bottom: max(16px, env(safe-area-inset-bottom));
            }
            
            .safe-area-left {
                padding-left: max(16px, env(safe-area-inset-left));
            }
            
            .safe-area-right {
                padding-right: max(16px, env(safe-area-inset-right));
            }
        }
    </style>
</head>
<body class="antialiased selection:bg-blue-100 safe-area-top safe-area-bottom safe-area-left safe-area-right">

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal-overlay">
        <div class="modal-content p-6">
            <div class="text-center">
                <div class="w-20 h-20 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-6">
                    <i class="fas fa-exclamation-triangle text-red-600 text-3xl"></i>
                </div>
                <h3 class="text-xl font-black text-slate-900 mb-2">Delete School?</h3>
                <p class="text-slate-600 mb-6">Are you sure you want to delete "Greenwood High Academy"? This action cannot be undone and will remove all associated data.</p>
                <div class="space-y-3 mobile-flex-col mobile-space-y-4">
                    <button onclick="confirmDelete()" class="btn btn-danger mobile-w-full">
                        Yes, Delete School
                    </button>
                    <button onclick="closeModal('deleteModal')" class="btn btn-secondary mobile-w-full">
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Sidebar Mobile Overlay -->
    <div id="sidebarOverlay" class="sidebar-overlay lg:hidden" onclick="mobileSidebarToggle()"></div>

    <!-- Mobile Sidebar -->
    <aside id="mobileSidebar" class="sidebar-mobile lg:hidden flex flex-col">
        <div class="h-16 flex items-center px-6 border-b border-slate-100 shrink-0">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 bg-blue-600 rounded-xl flex items-center justify-center">
                    <i class="fas fa-university text-white text-sm"></i>
                </div>
                <span class="text-xl font-extrabold tracking-tighter text-slate-900">NEXUS<span class="text-blue-600">OS</span></span>
            </div>
            <button onclick="mobileSidebarToggle()" class="ml-auto p-2">
                <i class="fas fa-times text-slate-400"></i>
            </button>
        </div>
        
        <div class="flex-1 overflow-y-auto py-4 px-3">
            <!-- Sidebar content here -->
        </div>
    </aside>

    <!-- Main Layout -->
    <div class="flex h-screen overflow-hidden">
        
        <!-- Desktop Sidebar -->
        <aside class="hidden lg:flex lg:w-64 bg-white border-r border-slate-200 flex-col">
            <div class="h-16 flex items-center px-6 border-b border-slate-100 shrink-0">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 bg-blue-600 rounded-xl flex items-center justify-center">
                        <i class="fas fa-university text-white text-sm"></i>
                    </div>
                    <span class="text-xl font-extrabold tracking-tighter text-slate-900">NEXUS<span class="text-blue-600">OS</span></span>
                </div>
            </div>
            
            <div class="flex-1 overflow-y-auto py-6 px-4">
                <!-- Desktop sidebar content -->
            </div>
        </aside>

        <!-- Main Content -->
        <main class="flex-1 flex flex-col min-w-0 overflow-hidden">
            
            <!-- Header -->
            <header class="h-16 glass-header flex items-center justify-between px-4 lg:px-8 shrink-0 safe-area-top">
                <div class="flex items-center gap-3">
                    <button onclick="mobileSidebarToggle()" class="lg:hidden p-2 text-slate-600 hover:bg-slate-100 rounded-lg transition">
                        <i class="fas fa-bars text-lg"></i>
                    </button>
                    <div class="flex items-center gap-2">
                        <h1 class="text-sm font-black text-slate-800 uppercase tracking-widest">Edit School</h1>
                        <span class="px-2 py-1 bg-amber-600 text-xs text-white font-bold rounded uppercase">Editing</span>
                    </div>
                </div>

                <div class="flex items-center gap-4">
                    <a href="view.html" class="hidden sm:flex items-center gap-2 px-4 py-2 text-slate-600 hover:text-blue-600 text-sm font-medium transition">
                        <i class="fas fa-arrow-left"></i>
                        <span>Back to List</span>
                    </a>
                    <div class="flex items-center gap-2 text-xs text-slate-500">
                        <i class="fas fa-clock"></i>
                        <span id="timestamp">--:--</span>
                    </div>
                </div>
            </header>

            <!-- Tab Navigation -->
            <div class="tab-nav">
                <div class="max-w-7xl mx-auto">
                    <div class="flex">
                        <button class="tab-button active" data-tab="basic">
                            <i class="fas fa-info-circle"></i>
                            <span class="hidden xs:inline">Basic Info</span>
                        </button>
                        <button class="tab-button" data-tab="contact">
                            <i class="fas fa-user-shield"></i>
                            <span class="hidden xs:inline">Contact & Admin</span>
                        </button>
                        <button class="tab-button" data-tab="subscription">
                            <i class="fas fa-credit-card"></i>
                            <span class="hidden xs:inline">Subscription</span>
                        </button>
                        <button class="tab-button" data-tab="advanced">
                            <i class="fas fa-cogs"></i>
                            <span class="hidden xs:inline">Advanced</span>
                        </button>
                        <button class="tab-button" data-tab="danger">
                            <i class="fas fa-exclamation-triangle"></i>
                            <span class="hidden xs:inline">Danger Zone</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Main Content Area -->
            <div class="flex-1 overflow-y-auto p-4 lg:p-8">
                <!-- School Header Card -->
                <div class="max-w-7xl mx-auto mb-6">
                    <div class="form-card p-6 mobile-p-4">
                        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-6 mb-6">
                            <div class="flex items-center gap-4">
                                <div class="relative">
                                    <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center">
                                        <i class="fas fa-university text-white text-2xl"></i>
                                    </div>
                                    <button onclick="changeLogo()" class="absolute -bottom-1 -right-1 w-8 h-8 bg-white border border-slate-200 rounded-full flex items-center justify-center hover:bg-slate-50 transition">
                                        <i class="fas fa-camera text-xs text-slate-600"></i>
                                    </button>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <h2 class="text-2xl font-black text-slate-900 mb-1 truncate">Greenwood High Academy</h2>
                                    <div class="flex flex-wrap items-center gap-3">
                                        <span class="status-badge status-active">
                                            <i class="fas fa-circle text-[8px]"></i> Active
                                        </span>
                                        <span class="text-sm text-slate-500 font-medium">
                                            <i class="fas fa-hashtag mr-1"></i>NX-NOD-0924A
                                        </span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="flex flex-wrap gap-3 tablet-flex-col mobile-w-full mobile-space-y-3">
                                <button onclick="previewChanges()" class="btn btn-secondary tablet-w-full">
                                    <i class="fas fa-eye"></i> Preview
                                </button>
                                <button onclick="saveChanges()" class="btn btn-primary tablet-w-full">
                                    <i class="fas fa-save"></i> Save Changes
                                </button>
                                <button onclick="discardChanges()" class="btn btn-secondary tablet-w-full">
                                    <i class="fas fa-times"></i> Discard
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab Contents -->
                <div class="max-w-7xl mx-auto space-y-6">
                    
                    <!-- Basic Info Tab -->
                    <div id="basicTab" class="tab-content active">
                        <div class="form-card p-6 mobile-p-4">
                            <h3 class="text-lg font-bold text-slate-900 mb-6">Institution Details</h3>
                            
                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                                <!-- Left Column -->
                                <div class="space-y-6">
                                    <div class="form-group">
                                        <label class="form-label required">Institution Name</label>
                                        <input type="text" 
                                               id="institutionName" 
                                               class="form-input" 
                                               value="Greenwood High Academy"
                                               placeholder="Enter institution name"
                                               required>
                                        <div id="nameError" class="error-message"></div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label required">Institution Type</label>
                                        <select id="institutionType" class="form-input" required>
                                            <option value="">Select type</option>
                                            <option value="university" selected>University / College</option>
                                            <option value="high_school">High School</option>
                                            <option value="middle_school">Middle School</option>
                                            <option value="elementary">Elementary School</option>
                                            <option value="vocational">Vocational Institute</option>
                                        </select>
                                        <div id="typeError" class="error-message"></div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label required">Status</label>
                                        <select id="status" class="form-input" required>
                                            <option value="active" selected>Active</option>
                                            <option value="maintenance">Maintenance</option>
                                            <option value="warning">Warning</option>
                                            <option value="archived">Archived</option>
                                        </select>
                                        <div id="statusError" class="error-message"></div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label required">Country & Region</label>
                                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                            <select id="country" class="form-input" required>
                                                <option value="">Select country</option>
                                                <option value="us">United States</option>
                                                <option value="uk" selected>United Kingdom</option>
                                                <option value="ca">Canada</option>
                                                <option value="au">Australia</option>
                                            </select>
                                            <input type="text" 
                                                   id="region" 
                                                   class="form-input" 
                                                   value="London"
                                                   placeholder="Region/State"
                                                   required>
                                        </div>
                                        <div id="locationError" class="error-message"></div>
                                    </div>
                                </div>

                                <!-- Right Column -->
                                <div class="space-y-6">
                                    <div class="form-group">
                                        <label class="form-label required">Email Domain</label>
                                        <div class="flex">
                                            <span class="bg-slate-100 px-4 py-3 border-2 border-r-0 border-slate-200 rounded-l-lg text-slate-500 flex items-center">@</span>
                                            <input type="text" 
                                                   id="emailDomain" 
                                                   class="form-input rounded-l-none" 
                                                   value="greenwood.edu"
                                                   placeholder="institution.edu"
                                                   required>
                                        </div>
                                        <span class="form-help">Used for all institutional email addresses</span>
                                        <div id="domainError" class="error-message"></div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label required">Phone Number</label>
                                        <input type="tel" 
                                               id="phone" 
                                               class="form-input" 
                                               value="+44 20 7123 4567"
                                               placeholder="+1 (555) 123-4567"
                                               required>
                                        <div id="phoneError" class="error-message"></div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Website URL</label>
                                        <input type="url" 
                                               id="website" 
                                               class="form-input" 
                                               value="https://www.greenwood.edu"
                                               placeholder="https://www.institution.edu">
                                        <div id="websiteError" class="error-message"></div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Institution Logo</label>
                                        <div id="logoUpload" class="file-upload">
                                            <i class="fas fa-cloud-upload-alt text-3xl text-slate-400 mb-3"></i>
                                            <p class="text-sm font-medium text-slate-700 mb-1">Click to upload or drag & drop</p>
                                            <p class="text-xs text-slate-500">PNG, JPG up to 5MB</p>
                                            <input type="file" 
                                                   id="logoFile" 
                                                   accept=".png,.jpg,.jpeg,.svg"
                                                   onchange="handleLogoUpload(event)">
                                        </div>
                                        <div id="logoPreview" class="mt-4">
                                            <div class="flex items-center gap-3">
                                                <img id="previewImage" 
                                                     src="https://ui-avatars.com/api/?name=Greenwood+High&background=2563eb&color=fff&size=128" 
                                                     class="w-16 h-16 rounded-lg object-cover border border-slate-200"
                                                     alt="School logo">
                                                <div>
                                                    <p id="fileName" class="text-sm font-medium text-slate-700">Current logo</p>
                                                    <button type="button" 
                                                            onclick="removeLogo()" 
                                                            class="text-xs text-red-500 hover:text-red-700 mt-1">
                                                        Remove
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-8 pt-8 border-t border-slate-100">
                                <div class="form-group">
                                    <label class="form-label required">Address</label>
                                    <textarea id="address" 
                                              class="form-input" 
                                              rows="3" 
                                              placeholder="Full physical address"
                                              required>123 Education Lane, London EC1A 1BB, United Kingdom</textarea>
                                    <div id="addressError" class="error-message"></div>
                                </div>
                            </div>
                        </div>

                        <!-- Capacity Settings -->
                        <div class="form-card p-6 mobile-p-4">
                            <h3 class="text-lg font-bold text-slate-900 mb-6">Capacity & Resources</h3>
                            
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 mb-6">
                                <div class="form-group">
                                    <label class="form-label">Total Students</label>
                                    <div class="relative">
                                        <input type="number" 
                                               id="studentCount" 
                                               class="form-input pr-16" 
                                               min="1" 
                                               max="100000" 
                                               value="1240">
                                        <span class="absolute right-4 top-1/2 transform -translate-y-1/2 text-slate-500">students</span>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Faculty Members</label>
                                    <div class="relative">
                                        <input type="number" 
                                               id="facultyCount" 
                                               class="form-input pr-16" 
                                               min="1" 
                                               max="5000" 
                                               value="85">
                                        <span class="absolute right-4 top-1/2 transform -translate-y-1/2 text-slate-500">staff</span>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Classrooms</label>
                                    <div class="relative">
                                        <input type="number" 
                                               id="classroomCount" 
                                               class="form-input pr-16" 
                                               min="1" 
                                               max="1000" 
                                               value="45">
                                        <span class="absolute right-4 top-1/2 transform -translate-y-1/2 text-slate-500">rooms</span>
                                    </div>
                                </div>
                            </div>

                            <div class="flex items-center justify-between p-4 border border-slate-200 rounded-xl">
                                <div>
                                    <p class="font-medium text-slate-900 mb-1">Auto-scale Resources</p>
                                    <p class="text-sm text-slate-500">Automatically adjust based on usage</p>
                                </div>
                                <label class="toggle-switch">
                                    <input type="checkbox" id="autoScale" checked>
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Contact & Admin Tab -->
                    <div id="contactTab" class="tab-content">
                        <div class="form-card p-6 mobile-p-4">
                            <h3 class="text-lg font-bold text-slate-900 mb-6">Primary Administrator</h3>
                            
                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                                <!-- Left Column -->
                                <div class="space-y-6">
                                    <div class="form-group">
                                        <label class="form-label required">Full Name</label>
                                        <input type="text" 
                                               id="adminName" 
                                               class="form-input" 
                                               value="Dr. Sarah Thompson"
                                               placeholder="Enter full name"
                                               required>
                                        <div id="adminNameError" class="error-message"></div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label required">Email Address</label>
                                        <input type="email" 
                                               id="adminEmail" 
                                               class="form-input" 
                                               value="sarah.thompson@greenwood.edu"
                                               placeholder="admin@institution.edu"
                                               required>
                                        <div id="adminEmailError" class="error-message"></div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label required">Position / Title</label>
                                        <input type="text" 
                                               id="adminTitle" 
                                               class="form-input" 
                                               value="Head Administrator"
                                               placeholder="Enter position/title"
                                               required>
                                        <div id="adminTitleError" class="error-message"></div>
                                    </div>
                                </div>

                                <!-- Right Column -->
                                <div class="space-y-6">
                                    <div class="form-group">
                                        <label class="form-label required">Phone Number</label>
                                        <input type="tel" 
                                               id="adminPhone" 
                                               class="form-input" 
                                               value="+44 20 7123 4567"
                                               placeholder="+1 (555) 123-4567"
                                               required>
                                        <div id="adminPhoneError" class="error-message"></div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label required">Access Level</label>
                                        <select id="accessLevel" class="form-input" required>
                                            <option value="">Select level</option>
                                            <option value="super_admin" selected>Super Administrator</option>
                                            <option value="admin">Administrator</option>
                                            <option value="manager">Manager</option>
                                            <option value="viewer">Viewer</option>
                                        </select>
                                        <div id="accessLevelError" class="error-message"></div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Reset Password</label>
                                        <div class="relative">
                                            <input type="password" 
                                                   id="adminPassword" 
                                                   class="form-input pr-12" 
                                                   placeholder="Leave blank to keep current">
                                            <button type="button" 
                                                    onclick="generatePassword()" 
                                                    class="absolute right-4 top-1/2 transform -translate-y-1/2 text-blue-600 hover:text-blue-700 bg-transparent border-none p-0">
                                                <i class="fas fa-redo text-sm"></i>
                                            </button>
                                        </div>
                                        <span class="form-help">If changed, password will be sent via secure email</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Secondary Contacts -->
                        <div class="form-card p-6 mobile-p-4">
                            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
                                <h3 class="text-lg font-bold text-slate-900">Secondary Contacts</h3>
                                <button onclick="addContact()" class="btn btn-primary sm:w-auto mobile-w-full">
                                    <i class="fas fa-user-plus"></i> Add Contact
                                </button>
                            </div>
                            
                            <div class="space-y-4">
                                <div class="flex flex-col sm:flex-row sm:items-center justify-between p-4 border border-slate-200 rounded-xl gap-4">
                                    <div class="flex items-center gap-3">
                                        <div class="w-12 h-12 rounded-xl bg-slate-100 flex items-center justify-center flex-shrink-0">
                                            <i class="fas fa-user text-slate-400"></i>
                                        </div>
                                        <div class="min-w-0">
                                            <p class="font-bold text-slate-900 truncate">John Miller</p>
                                            <p class="text-sm text-slate-500 truncate">IT Manager • john.miller@greenwood.edu</p>
                                        </div>
                                    </div>
                                    <div class="flex gap-2 sm:self-start">
                                        <button onclick="editContact(1)" class="w-10 h-10 rounded-lg border border-slate-200 text-slate-600 hover:text-blue-600 transition flex items-center justify-center">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button onclick="removeContact(1)" class="w-10 h-10 rounded-lg border border-slate-200 text-slate-600 hover:text-red-600 transition flex items-center justify-center">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                                
                                <div class="flex flex-col sm:flex-row sm:items-center justify-between p-4 border border-slate-200 rounded-xl gap-4">
                                    <div class="flex items-center gap-3">
                                        <div class="w-12 h-12 rounded-xl bg-slate-100 flex items-center justify-center flex-shrink-0">
                                            <i class="fas fa-user text-slate-400"></i>
                                        </div>
                                        <div class="min-w-0">
                                            <p class="font-bold text-slate-900 truncate">Lisa Park</p>
                                            <p class="text-sm text-slate-500 truncate">Finance Director • lisa.park@greenwood.edu</p>
                                        </div>
                                    </div>
                                    <div class="flex gap-2 sm:self-start">
                                        <button onclick="editContact(2)" class="w-10 h-10 rounded-lg border border-slate-200 text-slate-600 hover:text-blue-600 transition flex items-center justify-center">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button onclick="removeContact(2)" class="w-10 h-10 rounded-lg border border-slate-200 text-slate-600 hover:text-red-600 transition flex items-center justify-center">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Subscription Tab -->
                    <div id="subscriptionTab" class="tab-content">
                        <div class="form-card p-6 mobile-p-4">
                            <h3 class="text-lg font-bold text-slate-900 mb-6">Subscription Plan</h3>
                            
                            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                                <!-- Enterprise Plan -->
                                <div class="border-2 border-blue-500 rounded-2xl p-6 bg-blue-50 relative cursor-pointer transition-all hover:shadow-lg" onclick="selectPlan('enterprise')">
                                    <div class="absolute top-4 right-4 bg-blue-600 text-white text-xs font-bold px-3 py-1 rounded-full">
                                        CURRENT
                                    </div>
                                    <div class="flex justify-between items-start mb-4">
                                        <div>
                                            <h4 class="font-bold text-slate-900 text-lg">Enterprise</h4>
                                            <p class="text-slate-500 text-sm">For large institutions</p>
                                        </div>
                                        <div class="w-6 h-6 rounded-full border-2 border-blue-500 bg-blue-500 flex items-center justify-center">
                                            <i class="fas fa-check text-white text-xs"></i>
                                        </div>
                                    </div>
                                    <div class="mb-6">
                                        <div class="text-3xl font-black text-slate-900">$999<span class="text-sm text-slate-500 font-normal">/month</span></div>
                                        <p class="text-xs text-slate-400 mt-1">Billed annually at $11,988</p>
                                    </div>
                                    <ul class="space-y-3 mb-6">
                                        <li class="flex items-center gap-2 text-sm">
                                            <i class="fas fa-check text-emerald-500"></i>
                                            <span>Unlimited students</span>
                                        </li>
                                        <li class="flex items-center gap-2 text-sm">
                                            <i class="fas fa-check text-emerald-500"></i>
                                            <span>Premium analytics</span>
                                        </li>
                                        <li class="flex items-center gap-2 text-sm">
                                            <i class="fas fa-check text-emerald-500"></i>
                                            <span>24/7 dedicated support</span>
                                        </li>
                                        <li class="flex items-center gap-2 text-sm">
                                            <i class="fas fa-check text-emerald-500"></i>
                                            <span>API access</span>
                                        </li>
                                    </ul>
                                </div>

                                <!-- Pro Plan -->
                                <div class="border-2 border-slate-200 rounded-2xl p-6 hover:border-blue-500 transition-all cursor-pointer hover:shadow-lg" onclick="selectPlan('pro')">
                                    <div class="flex justify-between items-start mb-4">
                                        <div>
                                            <h4 class="font-bold text-slate-900 text-lg">Pro District</h4>
                                            <p class="text-slate-500 text-sm">For medium institutions</p>
                                        </div>
                                        <div class="w-6 h-6 rounded-full border-2 border-slate-300"></div>
                                    </div>
                                    <div class="mb-6">
                                        <div class="text-3xl font-black text-slate-900">$499<span class="text-sm text-slate-500 font-normal">/month</span></div>
                                        <p class="text-xs text-slate-400 mt-1">Billed annually at $5,988</p>
                                    </div>
                                    <ul class="space-y-3 mb-6">
                                        <li class="flex items-center gap-2 text-sm">
                                            <i class="fas fa-check text-emerald-500"></i>
                                            <span>Up to 2,000 students</span>
                                        </li>
                                        <li class="flex items-center gap-2 text-sm">
                                            <i class="fas fa-check text-emerald-500"></i>
                                            <span>Advanced analytics</span>
                                        </li>
                                        <li class="flex items-center gap-2 text-sm">
                                            <i class="fas fa-check text-emerald-500"></i>
                                            <span>Priority support</span>
                                        </li>
                                        <li class="flex items-center gap-2 text-sm">
                                            <i class="fas fa-check text-emerald-500"></i>
                                            <span>Custom reports</span>
                                        </li>
                                    </ul>
                                </div>

                                <!-- Basic Plan -->
                                <div class="border-2 border-slate-200 rounded-2xl p-6 hover:border-blue-500 transition-all cursor-pointer hover:shadow-lg" onclick="selectPlan('basic')">
                                    <div class="flex justify-between items-start mb-4">
                                        <div>
                                            <h4 class="font-bold text-slate-900 text-lg">Basic</h4>
                                            <p class="text-slate-500 text-sm">Essential features</p>
                                        </div>
                                        <div class="w-6 h-6 rounded-full border-2 border-slate-300"></div>
                                    </div>
                                    <div class="mb-6">
                                        <div class="text-3xl font-black text-slate-900">$199<span class="text-sm text-slate-500 font-normal">/month</span></div>
                                        <p class="text-xs text-slate-400 mt-1">Billed annually at $2,388</p>
                                    </div>
                                    <ul class="space-y-3 mb-6">
                                        <li class="flex items-center gap-2 text-sm">
                                            <i class="fas fa-check text-emerald-500"></i>
                                            <span>Up to 500 students</span>
                                        </li>
                                        <li class="flex items-center gap-2 text-sm">
                                            <i class="fas fa-check text-emerald-500"></i>
                                            <span>Basic analytics</span>
                                        </li>
                                        <li class="flex items-center gap-2 text-sm">
                                            <i class="fas fa-check text-emerald-500"></i>
                                            <span>Email support</span>
                                        </li>
                                        <li class="flex items-center gap-2 text-sm">
                                            <i class="fas fa-times text-slate-300"></i>
                                            <span class="text-slate-400">Advanced reporting</span>
                                        </li>
                                    </ul>
                                </div>
                            </div>

                            <div class="border-t border-slate-100 pt-6">
                                <h4 class="font-bold text-slate-900 mb-4">Billing Information</h4>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div class="form-group">
                                        <label class="form-label">Billing Cycle</label>
                                        <div class="flex flex-wrap gap-4">
                                            <label class="flex items-center gap-2 cursor-pointer">
                                                <input type="radio" name="billingCycle" value="monthly" class="text-blue-600">
                                                <span class="text-sm">Monthly</span>
                                            </label>
                                            <label class="flex items-center gap-2 cursor-pointer">
                                                <input type="radio" name="billingCycle" value="annual" class="text-blue-600" checked>
                                                <span class="text-sm">Annual (Save 15%)</span>
                                            </label>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Payment Method</label>
                                        <select id="paymentMethod" class="form-input">
                                            <option value="credit_card">Credit Card</option>
                                            <option value="bank_transfer" selected>Bank Transfer</option>
                                            <option value="invoice">Send Invoice</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-6 pt-6 border-t border-slate-100">
                                <label class="flex items-start gap-3 cursor-pointer p-2 -m-2 rounded-lg hover:bg-slate-50 transition">
                                    <input type="checkbox" id="autoRenew" class="mt-1" checked>
                                    <div>
                                        <span class="text-sm text-slate-700">
                                            Auto-renew subscription
                                        </span>
                                        <p class="text-xs text-slate-500 mt-1">Automatically renew at the end of each billing cycle</p>
                                    </div>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Advanced Tab -->
                    <div id="advancedTab" class="tab-content">
                        <div class="form-card p-6 mobile-p-4">
                            <h3 class="text-lg font-bold text-slate-900 mb-6">System Configuration</h3>
                            
                            <div class="space-y-8">
                                <!-- API Settings -->
                                <div>
                                    <h4 class="font-bold text-slate-900 mb-4">API Settings</h4>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                                        <div class="form-group">
                                            <label class="form-label">API Rate Limit</label>
                                            <select class="form-input">
                                                <option>100 requests/min</option>
                                                <option selected>500 requests/min</option>
                                                <option>1000 requests/min</option>
                                            </select>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label class="form-label">Data Retention</label>
                                            <select class="form-input">
                                                <option>30 days</option>
                                                <option selected>90 days</option>
                                                <option>365 days</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Security Settings -->
                                <div>
                                    <h4 class="font-bold text-slate-900 mb-4">Security Settings</h4>
                                    <div class="space-y-4">
                                        <label class="flex items-center justify-between p-4 border border-slate-200 rounded-xl hover:bg-slate-50 transition cursor-pointer">
                                            <div>
                                                <p class="font-medium text-slate-900">Two-Factor Authentication</p>
                                                <p class="text-sm text-slate-500">Require 2FA for all admin accounts</p>
                                            </div>
                                            <label class="toggle-switch">
                                                <input type="checkbox" checked>
                                                <span class="toggle-slider"></span>
                                            </label>
                                        </label>
                                        
                                        <label class="flex items-center justify-between p-4 border border-slate-200 rounded-xl hover:bg-slate-50 transition cursor-pointer">
                                            <div>
                                                <p class="font-medium text-slate-900">IP Whitelisting</p>
                                                <p class="text-sm text-slate-500">Restrict access to specific IP ranges</p>
                                            </div>
                                            <label class="toggle-switch">
                                                <input type="checkbox">
                                                <span class="toggle-slider"></span>
                                            </label>
                                        </label>
                                        
                                        <label class="flex items-center justify-between p-4 border border-slate-200 rounded-xl hover:bg-slate-50 transition cursor-pointer">
                                            <div>
                                                <p class="font-medium text-slate-900">Session Timeout</p>
                                                <p class="text-sm text-slate-500">Auto-logout after 30 minutes</p>
                                            </div>
                                            <label class="toggle-switch">
                                                <input type="checkbox" checked>
                                                <span class="toggle-slider"></span>
                                            </label>
                                        </label>
                                    </div>
                                </div>
                                
                                <!-- Integration Settings -->
                                <div>
                                    <h4 class="font-bold text-slate-900 mb-4">Integration Settings</h4>
                                    <div class="space-y-6">
                                        <div class="form-group">
                                            <label class="form-label">Custom Domain</label>
                                            <input type="text" class="form-input" placeholder="school.yourdomain.com">
                                        </div>
                                        
                                        <div class="form-group">
                                            <label class="form-label">Webhook URL</label>
                                            <input type="url" class="form-input" placeholder="https://your-server.com/webhook">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Backup & Restore -->
                        <div class="form-card p-6 mobile-p-4">
                            <h3 class="text-lg font-bold text-slate-900 mb-6">Backup & Restore</h3>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div class="text-center p-6 border border-slate-200 rounded-xl hover:shadow-lg transition">
                                    <div class="w-12 h-12 rounded-xl bg-blue-100 flex items-center justify-center mx-auto mb-4">
                                        <i class="fas fa-database text-blue-600"></i>
                                    </div>
                                    <h4 class="font-bold text-slate-900 mb-2">Create Backup</h4>
                                    <p class="text-sm text-slate-600 mb-4">Generate a complete system backup</p>
                                    <button onclick="createBackup()" class="btn btn-primary">
                                        Backup Now
                                    </button>
                                </div>
                                
                                <div class="text-center p-6 border border-slate-200 rounded-xl hover:shadow-lg transition">
                                    <div class="w-12 h-12 rounded-xl bg-emerald-100 flex items-center justify-center mx-auto mb-4">
                                        <i class="fas fa-history text-emerald-600"></i>
                                    </div>
                                    <h4 class="font-bold text-slate-900 mb-2">Restore Point</h4>
                                    <p class="text-sm text-slate-600 mb-4">Restore from previous backup</p>
                                    <button onclick="restoreBackup()" class="btn btn-secondary">
                                        Restore
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Danger Zone Tab -->
                    <div id="dangerTab" class="tab-content">
                        <div class="bg-red-50 border border-red-200 rounded-xl p-6">
                            <div class="flex items-start gap-4">
                                <div class="w-12 h-12 rounded-xl bg-red-100 flex items-center justify-center flex-shrink-0">
                                    <i class="fas fa-exclamation-triangle text-red-600 text-xl"></i>
                                </div>
                                <div>
                                    <h3 class="text-lg font-bold text-red-900 mb-2">Danger Zone</h3>
                                    <p class="text-red-700">These actions are irreversible. Please proceed with caution.</p>
                                </div>
                            </div>
                        </div>

                        <div class="form-card p-6 mobile-p-4">
                            <div class="space-y-6">
                                <!-- Archive School -->
                                <div class="flex flex-col sm:flex-row sm:items-center justify-between p-6 border border-slate-200 rounded-xl gap-4">
                                    <div>
                                        <h4 class="font-bold text-slate-900 mb-1">Archive School</h4>
                                        <p class="text-sm text-slate-600">Move to archived directory. Data preserved, access restricted.</p>
                                    </div>
                                    <button onclick="archiveSchool()" class="btn btn-warning sm:w-auto mobile-w-full">
                                        Archive
                                    </button>
                                </div>
                                
                                <!-- Reset System -->
                                <div class="flex flex-col sm:flex-row sm:items-center justify-between p-6 border border-slate-200 rounded-xl gap-4">
                                    <div>
                                        <h4 class="font-bold text-slate-900 mb-1">Reset System</h4>
                                        <p class="text-sm text-slate-600">Reset all configurations to default settings.</p>
                                    </div>
                                    <button onclick="resetSystem()" class="btn btn-secondary sm:w-auto mobile-w-full">
                                        Reset
                                    </button>
                                </div>
                                
                                <!-- Delete School -->
                                <div class="flex flex-col sm:flex-row sm:items-center justify-between p-6 border border-red-200 rounded-xl bg-red-50 gap-4">
                                    <div>
                                        <h4 class="font-bold text-red-900 mb-1">Delete School</h4>
                                        <p class="text-sm text-red-700">Permanently delete this school and all associated data.</p>
                                    </div>
                                    <button onclick="openModal('deleteModal')" class="btn btn-danger sm:w-auto mobile-w-full">
                                        Delete School
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Notification Container -->
    <div id="notificationContainer"></div>

    <script>
        // Current tab state
        let currentTab = 'basic';
        let hasChanges = false;
        let initialFormState = {};

        // Initialize timestamp
        function updateTimestamp() {
            const now = new Date();
            const options = { 
                hour: '2-digit',
                minute: '2-digit',
                hour12: true
            };
            document.getElementById('timestamp').textContent = now.toLocaleTimeString('en-US', options);
        }
        
        updateTimestamp();
        setInterval(updateTimestamp, 60000);

        // Tab switching
        function switchTab(tabName) {
            currentTab = tabName;
            
            // Update tab buttons
            document.querySelectorAll('.tab-button').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Add active class to clicked button
            const clickedButton = document.querySelector(`[data-tab="${tabName}"]`);
            if (clickedButton) {
                clickedButton.classList.add('active');
            }
            
            // Hide all tab content
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            
            // Show selected tab content
            const tabContent = document.getElementById(`${tabName}Tab`);
            if (tabContent) {
                tabContent.classList.add('active');
                // Scroll to top of tab content
                tabContent.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }

        // Initialize tab system
        document.addEventListener('DOMContentLoaded', function() {
            // Set up tab click handlers
            document.querySelectorAll('.tab-button').forEach(button => {
                button.addEventListener('click', function() {
                    const tabName = this.getAttribute('data-tab');
                    switchTab(tabName);
                });
            });
            
            // Set initial tab
            switchTab('basic');
            
            // Track form changes
            trackFormChanges();
            
            // Warn before leaving if there are unsaved changes
            window.addEventListener('beforeunload', function(e) {
                if (hasChanges) {
                    e.preventDefault();
                    e.returnValue = '';
                }
            });
        });

        // Track form changes
        function trackFormChanges() {
            const formElements = document.querySelectorAll('input, select, textarea');
            formElements.forEach(element => {
                initialFormState[element.id] = element.value;
                
                element.addEventListener('input', function() {
                    hasChanges = true;
                });
                
                element.addEventListener('change', function() {
                    hasChanges = true;
                });
            });
        }

        // File upload handling
        function handleLogoUpload(event) {
            const file = event.target.files[0];
            if (!file) return;
            
            // Validate file size (5MB)
            if (file.size > 5 * 1024 * 1024) {
                showNotification('File size must be less than 5MB', 'error');
                return;
            }
            
            // Validate file type
            const validTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/svg+xml'];
            if (!validTypes.includes(file.type)) {
                showNotification('Please upload a valid image file (JPEG, PNG, SVG)', 'error');
                return;
            }
            
            const reader = new FileReader();
            reader.onload = function(e) {
                const previewImage = document.getElementById('previewImage');
                const fileName = document.getElementById('fileName');
                
                if (previewImage) {
                    previewImage.src = e.target.result;
                    previewImage.classList.add('success-pulse');
                }
                
                if (fileName) {
                    fileName.textContent = file.name;
                }
                
                showNotification('Logo uploaded successfully', 'success');
                
                // Remove animation class after animation completes
                setTimeout(() => {
                    if (previewImage) {
                        previewImage.classList.remove('success-pulse');
                    }
                }, 600);
            };
            reader.readAsDataURL(file);
        }

        function removeLogo() {
            const previewImage = document.getElementById('previewImage');
            const fileName = document.getElementById('fileName');
            const logoFile = document.getElementById('logoFile');
            
            if (previewImage) {
                previewImage.src = 'https://ui-avatars.com/api/?name=Greenwood+High&background=2563eb&color=fff&size=128';
            }
            
            if (fileName) {
                fileName.textContent = 'Current logo';
            }
            
            if (logoFile) {
                logoFile.value = '';
            }
            
            showNotification('Logo removed', 'info');
        }

        function changeLogo() {
            const logoFile = document.getElementById('logoFile');
            if (logoFile) {
                logoFile.click();
            }
        }

        // Plan selection
        function selectPlan(plan) {
            // Remove selected state from all plans
            document.querySelectorAll('[onclick^="selectPlan"]').forEach(card => {
                card.classList.remove('border-blue-500', 'bg-blue-50');
                const indicator = card.querySelector('.rounded-full');
                if (indicator) {
                    indicator.classList.remove('border-blue-500', 'bg-blue-500');
                    indicator.classList.add('border-slate-300');
                }
                
                // Remove "CURRENT" badge
                const badge = card.querySelector('.bg-blue-600');
                if (badge && badge.textContent === 'CURRENT') {
                    badge.remove();
                }
            });
            
            // Add selected state to chosen plan
            const selectedCard = document.querySelector(`[onclick="selectPlan('${plan}')"]`);
            if (selectedCard) {
                selectedCard.classList.add('border-blue-500', 'bg-blue-50');
                const indicator = selectedCard.querySelector('.rounded-full');
                if (indicator) {
                    indicator.classList.add('border-blue-500', 'bg-blue-500');
                    indicator.classList.remove('border-slate-300');
                }
                
                // Add check icon to selected plan
                if (indicator) {
                    indicator.innerHTML = '<i class="fas fa-check text-white text-xs"></i>';
                }
                
                // Add "CURRENT" badge to enterprise if selected
                if (plan === 'enterprise') {
                    const badge = document.createElement('div');
                    badge.className = 'absolute top-4 right-4 bg-blue-600 text-white text-xs font-bold px-3 py-1 rounded-full';
                    badge.textContent = 'CURRENT';
                    selectedCard.appendChild(badge);
                }
            }
            
            showNotification(`Selected ${plan} plan`, 'success');
        }

        // Password generation
        function generatePassword() {
            const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*';
            let password = '';
            for (let i = 0; i < 12; i++) {
                password += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            
            const adminPassword = document.getElementById('adminPassword');
            if (adminPassword) {
                adminPassword.type = 'text';
                adminPassword.value = password;
                
                // Show copy button
                showNotification('Password generated. Click to copy.', 'info', {
                    action: () => {
                        navigator.clipboard.writeText(password);
                        showNotification('Password copied to clipboard', 'success');
                    }
                });
            }
        }

        // Contact management
        function addContact() {
            showNotification('Add contact feature coming soon', 'info');
        }

        function editContact(id) {
            showNotification(`Editing contact ${id}...`, 'info');
        }

        function removeContact(id) {
            if (confirm('Are you sure you want to remove this contact?')) {
                showNotification('Contact removed', 'success');
            }
        }

        // Form validation
        function validateCurrentTab() {
            let isValid = true;
            
            // Clear previous errors
            document.querySelectorAll('.error-message').forEach(el => {
                el.textContent = '';
            });
            document.querySelectorAll('.form-input').forEach(el => {
                el.classList.remove('error');
            });

            if (currentTab === 'basic') {
                // Validate institution name
                const name = document.getElementById('institutionName');
                if (name && !name.value.trim()) {
                    showError('institutionName', 'Institution name is required');
                    isValid = false;
                }

                // Validate institution type
                const type = document.getElementById('institutionType');
                if (type && !type.value) {
                    showError('institutionType', 'Please select an institution type');
                    isValid = false;
                }

                // Validate email domain
                const domain = document.getElementById('emailDomain');
                if (domain && !domain.value.trim()) {
                    showError('emailDomain', 'Email domain is required');
                    isValid = false;
                } else if (domain && !/^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/.test(domain.value)) {
                    showError('emailDomain', 'Please enter a valid domain');
                    isValid = false;
                }

                // Validate address
                const address = document.getElementById('address');
                if (address && !address.value.trim()) {
                    showError('address', 'Address is required');
                    isValid = false;
                }

            } else if (currentTab === 'contact') {
                // Validate administrator name
                const adminName = document.getElementById('adminName');
                if (adminName && !adminName.value.trim()) {
                    showError('adminName', 'Administrator name is required');
                    isValid = false;
                }

                // Validate administrator email
                const adminEmail = document.getElementById('adminEmail');
                if (adminEmail) {
                    const emailValue = adminEmail.value.trim();
                    if (!emailValue) {
                        showError('adminEmail', 'Administrator email is required');
                        isValid = false;
                    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailValue)) {
                        showError('adminEmail', 'Please enter a valid email address');
                        isValid = false;
                    }
                }

                // Validate access level
                const accessLevel = document.getElementById('accessLevel');
                if (accessLevel && !accessLevel.value) {
                    showError('accessLevel', 'Please select an access level');
                    isValid = false;
                }
            }

            if (!isValid) {
                showNotification('Please fix validation errors', 'error');
                // Scroll to first error
                const firstError = document.querySelector('.form-input.error');
                if (firstError) {
                    firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    firstError.focus();
                }
            }

            return isValid;
        }

        function showError(fieldId, message) {
            const errorElement = document.getElementById(fieldId + 'Error');
            const inputElement = document.getElementById(fieldId);
            
            if (errorElement && inputElement) {
                errorElement.textContent = message;
                inputElement.classList.add('error');
            }
        }

        // Save changes
        function saveChanges() {
            if (!validateCurrentTab()) {
                return;
            }
            
            // Show loading state
            const saveButton = document.querySelector('[onclick="saveChanges()"]');
            if (saveButton) {
                const originalText = saveButton.innerHTML;
                saveButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
                saveButton.disabled = true;
            }
            
            showNotification('Saving changes...', 'info');
            
            // Simulate API call
            setTimeout(() => {
                // Reset loading state
                if (saveButton) {
                    saveButton.innerHTML = '<i class="fas fa-save"></i> Save Changes';
                    saveButton.disabled = false;
                }
                
                showNotification('Changes saved successfully', 'success');
                hasChanges = false;
                
                // Update status badge if status changed
                updateStatusBadge();
            }, 1500);
        }

        function updateStatusBadge() {
            const statusSelect = document.getElementById('status');
            if (!statusSelect) return;
            
            const status = statusSelect.value;
            const badge = document.querySelector('.status-badge');
            
            if (badge) {
                // Remove all status classes
                badge.classList.remove('status-active', 'status-maintenance', 'status-warning', 'status-archived');
                // Add new status class
                badge.classList.add(`status-${status}`);
                // Update text
                const statusText = status.charAt(0).toUpperCase() + status.slice(1);
                badge.innerHTML = `<i class="fas fa-circle text-[8px]"></i> ${statusText}`;
            }
        }

        // Preview changes
        function previewChanges() {
            if (validateCurrentTab()) {
                showNotification('Preview generated successfully', 'success');
            }
        }

        // Discard changes
        function discardChanges() {
            if (hasChanges) {
                if (confirm('Are you sure you want to discard all changes?')) {
                    // Reload form with original data
                    location.reload();
                }
            } else {
                showNotification('No changes to discard', 'info');
            }
        }

        // Backup functions
        function createBackup() {
            showNotification('Creating backup...', 'info');
            
            setTimeout(() => {
                showNotification('Backup created successfully', 'success');
            }, 2000);
        }

        function restoreBackup() {
            showNotification('Restore feature coming soon', 'info');
        }

        // Danger zone functions
        function archiveSchool() {
            if (confirm('Are you sure you want to archive this school? It will be moved to the archived directory.')) {
                showNotification('Archiving school...', 'warning');
                
                setTimeout(() => {
                    showNotification('School archived successfully', 'success');
                    // In real app, redirect to archived page
                }, 2000);
            }
        }

        function resetSystem() {
            if (confirm('Are you sure you want to reset all system configurations? User data will be preserved.')) {
                showNotification('Resetting system...', 'warning');
                
                setTimeout(() => {
                    showNotification('System reset completed', 'success');
                }, 2000);
            }
        }

        function confirmDelete() {
            showNotification('Deleting school...', 'warning');
            
            setTimeout(() => {
                showNotification('School deleted successfully', 'success');
                closeModal('deleteModal');
                
                // Redirect to schools list
                setTimeout(() => {
                    window.location.href = 'index.html';
                }, 1000);
            }, 2000);
        }

        // Modal functions
        function openModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.add('active');
                document.body.style.overflow = 'hidden';
            }
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.remove('active');
                document.body.style.overflow = 'auto';
            }
        }

        // Sidebar functionality
        function mobileSidebarToggle() {
            const sidebar = document.getElementById('mobileSidebar');
            const overlay = document.getElementById('sidebarOverlay');
            
            if (sidebar && overlay) {
                sidebar.classList.toggle('active');
                overlay.classList.toggle('active');
            }
        }

        // Notification system
        function showNotification(message, type = 'info', options = {}) {
            const container = document.getElementById('notificationContainer');
            if (!container) return;
            
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            
            let icon = 'info-circle';
            if (type === 'success') icon = 'check-circle';
            if (type === 'error') icon = 'exclamation-circle';
            if (type === 'warning') icon = 'exclamation-triangle';
            
            notification.innerHTML = `
                <i class="fas fa-${icon} text-${type === 'success' ? 'emerald' : type === 'error' ? 'red' : type === 'warning' ? 'amber' : 'blue'}-500"></i>
                <span class="flex-1 text-sm font-medium">${message}</span>
                ${options.action ? '<button onclick="this.parentElement.remove()" class="text-slate-400 hover:text-slate-600"><i class="fas fa-times"></i></button>' : ''}
            `;
            
            if (options.action) {
                notification.style.cursor = 'pointer';
                notification.onclick = options.action;
            }
            
            container.appendChild(notification);
            
            // Show notification
            setTimeout(() => notification.classList.add('active'), 10);
            
            // Auto-remove after 5 seconds
            setTimeout(() => {
                notification.classList.remove('active');
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.parentNode.removeChild(notification);
                    }
                }, 300);
            }, 5000);
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            // Save with Ctrl/Cmd + S
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                saveChanges();
            }
            
            // Close modals and sidebar with Escape
            if (e.key === 'Escape') {
                const activeModal = document.querySelector('.modal-overlay.active');
                if (activeModal) {
                    closeModal(activeModal.id);
                }
                
                const sidebar = document.getElementById('mobileSidebar');
                const overlay = document.getElementById('sidebarOverlay');
                if (sidebar && overlay && sidebar.classList.contains('active')) {
                    mobileSidebarToggle();
                }
            }
        });

        // Initialize plan selection
        selectPlan('enterprise');
        
        // Add success pulse animation
        const style = document.createElement('style');
        style.textContent = `
            .success-pulse {
                animation: successPulse 0.6s ease-in-out;
            }
            
            @keyframes successPulse {
                0% { transform: scale(1); }
                50% { transform: scale(1.05); }
                100% { transform: scale(1); }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>