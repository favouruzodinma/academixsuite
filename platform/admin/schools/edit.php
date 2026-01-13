<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Edit School | NexusAdmin Executive</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
        
        :root {
            --brand-primary: #2563eb;
            --brand-surface: #ffffff;
            --brand-bg: #f8fafc;
        }

        body { 
            font-family: 'Inter', sans-serif; 
            background-color: var(--brand-bg); 
            color: #1e293b; 
            -webkit-tap-highlight-color: transparent;
        }

        /* Mobile-optimized scrollbar */
        ::-webkit-scrollbar { 
            width: 4px; 
            height: 4px; 
        }
        ::-webkit-scrollbar-track { 
            background: #f1f5f9; 
        }
        ::-webkit-scrollbar-thumb { 
            background: #cbd5e1; 
            border-radius: 10px; 
        }

        .sidebar-link { 
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); 
            border-left: 3px solid transparent; 
        }
        .sidebar-link:hover { 
            background: #f1f5f9; 
            color: var(--brand-primary); 
        }
        .active-link { 
            background: #eff6ff; 
            color: var(--brand-primary); 
            border-left-color: var(--brand-primary); 
            font-weight: 600; 
        }
        
        .dropdown-content { 
            max-height: 0; 
            overflow: hidden; 
            transition: max-height 0.4s cubic-bezier(0.4, 0, 0.2, 1); 
        }
        .dropdown-open .dropdown-content { 
            max-height: 500px; 
        }
        .dropdown-open .chevron { 
            transform: rotate(180deg); 
        }

        /* Mobile-first responsive design */
        @media (max-width: 640px) {
            .mobile-stack { flex-direction: column; }
            .mobile-full { width: 100%; }
            .mobile-text-center { text-align: center; }
            .mobile-p-4 { padding: 1rem; }
            .mobile-space-y-4 > * + * { margin-top: 1rem; }
        }

        @media (max-width: 768px) {
            .tablet-hide { display: none; }
            .tablet-full { width: 100%; }
        }

        /* Touch-friendly sizes */
        .touch-target {
            min-height: 44px;
            min-width: 44px;
        }

        .glass-header { 
            background: rgba(255, 255, 255, 0.95); 
            backdrop-filter: blur(12px); 
            -webkit-backdrop-filter: blur(12px);
        }
        
        .form-card { 
            border: 1px solid #e2e8f0; 
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05); 
            border-radius: 20px;
        }

        /* Tabs */
        .tab-button {
            padding: 12px 24px;
            font-size: 14px;
            font-weight: 600;
            color: #64748b;
            background: transparent;
            border: none;
            border-bottom: 3px solid transparent;
            cursor: pointer;
            transition: all 0.2s ease;
            white-space: nowrap;
        }
        
        .tab-button:hover {
            color: #2563eb;
        }
        
        .tab-button.active {
            color: #2563eb;
            border-bottom-color: #2563eb;
            background: linear-gradient(to top, rgba(37, 99, 235, 0.05), transparent);
        }

        /* Tab Content */
        .tab-content {
            display: none;
            animation: fadeIn 0.3s ease-in-out;
        }
        
        .tab-content.active {
            display: block;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Form styling */
        .form-group {
            position: relative;
        }
        
        .form-label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #475569;
            margin-bottom: 6px;
        }
        
        .form-input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 500;
            color: #1e293b;
            background: white;
            transition: all 0.2s ease;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        
        .form-input.error {
            border-color: #ef4444;
        }
        
        .error-message {
            display: none;
            font-size: 12px;
            color: #ef4444;
            margin-top: 4px;
            font-weight: 500;
        }
        
        .error-message.show {
            display: block;
        }

        /* Status badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .status-active {
            background-color: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }
        
        .status-maintenance {
            background-color: #e0e7ff;
            color: #3730a3;
            border: 1px solid #c7d2fe;
        }
        
        .status-warning {
            background-color: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }
        
        .status-archived {
            background-color: #f3f4f6;
            color: #4b5563;
            border: 1px solid #d1d5db;
        }

        /* Toggle switch */
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
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
            height: 16px;
            width: 16px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        
        input:checked + .toggle-slider {
            background-color: #2563eb;
        }
        
        input:checked + .toggle-slider:before {
            transform: translateX(26px);
        }

        /* File upload */
        .file-upload {
            border: 2px dashed #cbd5e1;
            border-radius: 12px;
            padding: 40px 20px;
            text-align: center;
            background: #f8fafc;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .file-upload:hover {
            border-color: #2563eb;
            background: #eff6ff;
        }
        
        .file-upload.dragover {
            border-color: #2563eb;
            background: #eff6ff;
            transform: scale(1.02);
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
        }
        
        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        
        .modal-content {
            background: white;
            border-radius: 20px;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            transform: translateY(20px);
            transition: transform 0.3s ease;
        }
        
        .modal-overlay.active .modal-content {
            transform: translateY(0);
        }

        /* Success animation */
        @keyframes successPulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }
        
        .success-pulse {
            animation: successPulse 0.6s ease-in-out;
        }

        /* Mobile menu overlay */
        .sidebar-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 99;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }
        
        .sidebar-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        
        /* Notification animation */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateX(100px); }
            to { opacity: 1; transform: translateX(0); }
        }
        
        .animate-fadeIn {
            animation: fadeIn 0.3s ease-in-out;
        }
    </style>
</head>
<body class="antialiased overflow-hidden selection:bg-blue-100">

    <!-- Mobile Sidebar Overlay -->
    <div id="sidebarOverlay" class="sidebar-overlay lg:hidden" onclick="mobileSidebarToggle()"></div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal-overlay">
        <div class="modal-content p-6">
            <div class="text-center">
                <div class="w-20 h-20 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-6">
                    <i class="fas fa-exclamation-triangle text-red-600 text-3xl"></i>
                </div>
                <h3 class="text-xl font-black text-slate-900 mb-2">Delete School?</h3>
                <p class="text-slate-600 mb-6">Are you sure you want to delete "Greenwood High Academy"? This action cannot be undone and will remove all associated data.</p>
                <div class="space-y-3">
                    <button onclick="confirmDelete()" class="w-full py-3 bg-red-600 text-white font-bold rounded-xl hover:bg-red-700 transition touch-target">
                        Yes, Delete School
                    </button>
                    <button onclick="closeModal('deleteModal')" class="w-full py-3 border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition touch-target">
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="flex h-screen overflow-hidden">
        
        <aside id="sidebar" class="fixed inset-y-0 left-0 w-64 bg-white border-r border-slate-200 z-[100] lg:relative lg:translate-x-0 -translate-x-full transition-transform duration-300 flex flex-col shadow-xl lg:shadow-none">
            
            <div class="h-16 flex items-center px-6 border-b border-slate-100 shrink-0">
                <div class="flex items-center gap-2.5">
                    <div class="w-9 h-9 bg-blue-600 rounded-xl flex items-center justify-center shadow-lg shadow-blue-100">
                        <i class="fas fa-university text-white text-sm"></i>
                    </div>
                    <span class="text-xl font-extrabold tracking-tighter text-slate-900 italic">NEXUS<span class="text-blue-600 font-normal">OS</span></span>
                </div>
            </div>

            <div class="flex-1 overflow-y-auto py-5 space-y-7 px-2">
                <div>
                    <nav class="space-y-1">
                        <a href="dashboard.html" class="sidebar-link flex items-center gap-3 px-4 py-3 text-sm font-medium text-slate-600 rounded-lg mx-2 touch-target">
                            <i class="fas fa-grid-2 w-4"></i> Dashboard
                        </a>
                    </nav>
                </div>

                <div>
                    <p class="px-4 text-[11px] font-bold text-slate-400 uppercase tracking-widest mb-3">Institutional Ops</p>
                    <nav class="space-y-1">
                        <div class="dropdown-group" id="schools-drop">
                            <button onclick="toggleDropdown('schools-drop')" class="w-full flex items-center justify-between px-4 py-3 sidebar-link text-sm rounded-lg mx-2 touch-target">
                                <span class="flex items-center gap-3"><i class="fas fa-school-flag w-4"></i> Schools Registry</span>
                                <i class="fas fa-chevron-down text-xs chevron transition-transform opacity-50"></i>
                            </button>
                            <div class="dropdown-content bg-slate-50/50 ml-4">
                                <a href="index.html" class="block pl-10 py-2.5 text-xs font-medium text-slate-500 hover:text-blue-600 transition touch-target">Active Directory</a>
                                <a href="add.html" class="block pl-10 py-2.5 text-xs font-medium text-slate-500 hover:text-blue-600 transition touch-target">Provision New Node</a>
                                <a href="view.html" class="block pl-10 py-2.5 text-xs font-medium text-slate-500 hover:text-blue-600 transition touch-target">Performance Audit</a>
                            </div>
                        </div>
                        <a href="subscriptions/index.html" class="sidebar-link flex items-center gap-3 px-4 py-3 text-sm font-medium text-slate-600 rounded-lg mx-2 touch-target">
                            <i class="fas fa-credit-card-front w-4"></i> Subscription Tiers
                        </a>
                    </nav>
                </div>

                <div>
                    <p class="px-4 text-[11px] font-bold text-slate-400 uppercase tracking-widest mb-3">Platform Health</p>
                    <nav class="space-y-1">
                        <a href="logs/activity.html" class="sidebar-link flex items-center gap-3 px-4 py-3 text-sm font-medium text-slate-600 rounded-lg mx-2 touch-target">
                            <i class="fas fa-microchip w-4"></i> System Logs
                        </a>
                        <a href="settings/general.html" class="sidebar-link flex items-center gap-3 px-4 py-3 text-sm font-medium text-slate-600 rounded-lg mx-2 touch-target">
                            <i class="fas fa-sliders-h w-4"></i> Global Config
                        </a>
                    </nav>
                </div>
            </div>

            <div class="p-4 border-t border-slate-100 bg-slate-50/30 shrink-0">
                <div class="flex items-center gap-3 p-2 group cursor-pointer">
                    <div class="relative">
                        <img src="https://ui-avatars.com/api/?name=Admin+Master&background=1e293b&color=fff&bold=true" class="w-9 h-9 rounded-lg shadow-sm">
                        <span class="absolute -bottom-0.5 -right-0.5 w-3 h-3 bg-green-500 border-2 border-white rounded-full"></span>
                    </div>
                    <div class="overflow-hidden">
                        <p class="text-[12px] font-bold text-slate-900 truncate">Alexander Pierce</p>
                        <p class="text-[10px] text-blue-600 font-black uppercase">Super Admin</p>
                    </div>
                </div>
            </div>
        </aside>

        <main class="flex-1 flex flex-col min-w-0 overflow-hidden">
            
            <header class="h-16 glass-header border-b border-slate-200 px-4 lg:px-8 flex items-center justify-between shrink-0 z-40">
                <div class="flex items-center gap-3">
                    <button onclick="mobileSidebarToggle()" class="lg:hidden text-slate-500 p-2 hover:bg-slate-100 rounded-lg transition touch-target">
                        <i class="fas fa-bars-staggered"></i>
                    </button>
                    <div class="flex items-center gap-2">
                        <h1 class="text-sm font-black text-slate-800 uppercase tracking-widest">Edit School</h1>
                        <span class="px-2 py-0.5 bg-amber-600 text-[10px] text-white font-black rounded uppercase">Editing</span>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <a href="view.html" class="hidden sm:flex items-center gap-2 px-4 py-2 text-slate-600 hover:text-blue-600 text-sm font-medium transition">
                        <i class="fas fa-arrow-left"></i>
                        <span>Back to View</span>
                    </a>
                    <div class="flex items-center gap-2 text-xs text-slate-500">
                        <i class="fas fa-clock"></i>
                        <span id="timestamp">Loading...</span>
                    </div>
                </div>
            </header>

            <!-- Tabs Navigation -->
            <div class="border-b border-slate-200 bg-white">
                <div class="max-w-7xl mx-auto px-4 lg:px-8">
                    <div class="flex overflow-x-auto">
                        <button class="tab-button active" data-tab="basic">
                            <i class="fas fa-info-circle mr-2"></i>Basic Info
                        </button>
                        <button class="tab-button" data-tab="contact">
                            <i class="fas fa-user-shield mr-2"></i>Contact & Admin
                        </button>
                        <button class="tab-button" data-tab="subscription">
                            <i class="fas fa-credit-card mr-2"></i>Subscription
                        </button>
                        <button class="tab-button" data-tab="advanced">
                            <i class="fas fa-cogs mr-2"></i>Advanced
                        </button>
                        <button class="tab-button" data-tab="danger">
                            <i class="fas fa-exclamation-triangle mr-2"></i>Danger Zone
                        </button>
                    </div>
                </div>
            </div>

            <div class="flex-1 overflow-y-auto p-4 lg:p-8">
                <!-- School Header -->
                <div class="max-w-7xl mx-auto mb-8">
                    <div class="bg-white form-card p-6">
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
                                <div>
                                    <h2 class="text-2xl font-black text-slate-900 mb-1">Greenwood High Academy</h2>
                                    <div class="flex items-center gap-3">
                                        <span class="status-badge status-active">
                                            <i class="fas fa-circle text-[8px] mr-1"></i> Operational
                                        </span>
                                        <span class="text-sm text-slate-500 font-medium">
                                            <i class="fas fa-hashtag mr-1"></i>NX-NOD-0924A
                                        </span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="flex flex-wrap gap-3">
                                <button onclick="previewChanges()" class="px-5 py-2.5 bg-white border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition flex items-center gap-2 touch-target">
                                    <i class="fas fa-eye"></i> Preview
                                </button>
                                <button onclick="saveChanges()" class="px-5 py-2.5 bg-blue-600 text-white font-bold rounded-xl hover:bg-blue-700 transition flex items-center gap-2 touch-target">
                                    <i class="fas fa-save"></i> Save Changes
                                </button>
                                <button onclick="discardChanges()" class="px-5 py-2.5 bg-slate-100 text-slate-700 font-bold rounded-xl hover:bg-slate-200 transition flex items-center gap-2 touch-target">
                                    <i class="fas fa-times"></i> Discard
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab Content: Basic Info -->
                <div id="basicTab" class="max-w-7xl mx-auto space-y-6 tab-content active">
                    <div class="bg-white form-card p-6">
                        <h3 class="text-lg font-bold text-slate-900 mb-6">Institution Details</h3>
                        
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                            <!-- Left Column -->
                            <div class="space-y-6">
                                <div class="form-group">
                                    <label class="form-label">
                                        Institution Name <span class="text-red-500">*</span>
                                    </label>
                                    <input type="text" 
                                           id="institutionName" 
                                           class="form-input" 
                                           value="Greenwood High Academy"
                                           required>
                                    <div id="nameError" class="error-message"></div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">
                                        Institution Type <span class="text-red-500">*</span>
                                    </label>
                                    <select id="institutionType" class="form-input" required>
                                        <option value="">Select institution type</option>
                                        <option value="university" selected>University / College</option>
                                        <option value="high_school">High School</option>
                                        <option value="middle_school">Middle School</option>
                                        <option value="elementary">Elementary School</option>
                                        <option value="vocational">Vocational Institute</option>
                                        <option value="training">Training Center</option>
                                        <option value="online">Online Academy</option>
                                    </select>
                                    <div id="typeError" class="error-message"></div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">
                                        Status <span class="text-red-500">*</span>
                                    </label>
                                    <select id="status" class="form-input" required>
                                        <option value="active" selected>Active</option>
                                        <option value="maintenance">Maintenance</option>
                                        <option value="warning">Warning</option>
                                        <option value="archived">Archived</option>
                                    </select>
                                    <div id="statusError" class="error-message"></div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">
                                        Country & Region <span class="text-red-500">*</span>
                                    </label>
                                    <div class="grid grid-cols-2 gap-4">
                                        <select id="country" class="form-input" required>
                                            <option value="">Select country</option>
                                            <option value="us">United States</option>
                                            <option value="uk" selected>United Kingdom</option>
                                            <option value="ca">Canada</option>
                                            <option value="au">Australia</option>
                                            <option value="de">Germany</option>
                                            <option value="fr">France</option>
                                            <option value="jp">Japan</option>
                                            <option value="sg">Singapore</option>
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
                                    <label class="form-label">
                                        Official Email Domain <span class="text-red-500">*</span>
                                    </label>
                                    <div class="flex items-center">
                                        <span class="bg-slate-100 px-4 py-3 border-2 border-r-0 border-slate-200 rounded-l-lg text-slate-500">@</span>
                                        <input type="text" 
                                               id="emailDomain" 
                                               class="form-input rounded-l-none" 
                                               value="greenwood.edu"
                                               placeholder="institution.edu"
                                               required>
                                    </div>
                                    <div class="text-xs text-slate-400 mt-2">This will be used for all institutional email addresses</div>
                                    <div id="domainError" class="error-message"></div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">
                                        Phone Number <span class="text-red-500">*</span>
                                    </label>
                                    <input type="tel" 
                                           id="phone" 
                                           class="form-input" 
                                           value="+44 20 7123 4567"
                                           placeholder="+1 (555) 123-4567"
                                           required>
                                    <div id="phoneError" class="error-message"></div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">
                                        Website URL
                                    </label>
                                    <input type="url" 
                                           id="website" 
                                           class="form-input" 
                                           value="https://www.greenwood.edu"
                                           placeholder="https://www.institution.edu">
                                    <div id="websiteError" class="error-message"></div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">
                                        Institution Logo
                                    </label>
                                    <div id="logoUpload" class="file-upload">
                                        <i class="fas fa-cloud-upload-alt text-3xl text-slate-400 mb-3"></i>
                                        <p class="text-sm font-medium text-slate-700 mb-1">Click to upload new logo</p>
                                        <p class="text-xs text-slate-500">PNG, JPG up to 5MB</p>
                                        <input type="file" 
                                               id="logoFile" 
                                               class="hidden" 
                                               accept=".png,.jpg,.jpeg">
                                    </div>
                                    <div id="logoPreview" class="mt-4">
                                        <div class="flex items-center gap-3">
                                            <img id="previewImage" src="https://ui-avatars.com/api/?name=Greenwood+High&background=2563eb&color=fff" class="w-16 h-16 rounded-lg object-cover border border-slate-200">
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

                        <!-- Address -->
                        <div class="mt-8 pt-8 border-t border-slate-100">
                            <div class="form-group">
                                <label class="form-label">
                                    Address <span class="text-red-500">*</span>
                                </label>
                                <textarea id="address" 
                                          class="form-input" 
                                          rows="3" 
                                          placeholder="Full physical address of the institution"
                                          required>123 Education Lane, London EC1A 1BB, United Kingdom</textarea>
                                <div id="addressError" class="error-message"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Capacity Settings -->
                    <div class="bg-white form-card p-6">
                        <h3 class="text-lg font-bold text-slate-900 mb-6">Capacity & Resources</h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div class="form-group">
                                <label class="form-label">Total Students</label>
                                <div class="relative">
                                    <input type="number" 
                                           id="studentCount" 
                                           class="form-input pr-12" 
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
                                           class="form-input pr-12" 
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
                                           class="form-input pr-12" 
                                           min="1" 
                                           max="1000" 
                                           value="45">
                                    <span class="absolute right-4 top-1/2 transform -translate-y-1/2 text-slate-500">rooms</span>
                                </div>
                            </div>
                        </div>

                        <div class="mt-6">
                            <label class="flex items-center justify-between p-4 border border-slate-200 rounded-xl cursor-pointer">
                                <div>
                                    <p class="font-medium text-slate-900">Auto-scale Resources</p>
                                    <p class="text-sm text-slate-500">Automatically adjust resources based on usage</p>
                                </div>
                                <label class="toggle-switch">
                                    <input type="checkbox" id="autoScale" checked>
                                    <span class="toggle-slider"></span>
                                </label>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Tab Content: Contact & Admin -->
                <div id="contactTab" class="max-w-7xl mx-auto space-y-6 tab-content">
                    <div class="bg-white form-card p-6">
                        <h3 class="text-lg font-bold text-slate-900 mb-6">Primary Administrator</h3>
                        
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                            <div class="space-y-6">
                                <div class="form-group">
                                    <label class="form-label">
                                        Full Name <span class="text-red-500">*</span>
                                    </label>
                                    <input type="text" 
                                           id="adminName" 
                                           class="form-input" 
                                           value="Dr. Sarah Thompson"
                                           placeholder="Dr. Sarah Thompson"
                                           required>
                                    <div id="adminNameError" class="error-message"></div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">
                                        Email Address <span class="text-red-500">*</span>
                                    </label>
                                    <input type="email" 
                                           id="adminEmail" 
                                           class="form-input" 
                                           value="sarah.thompson@greenwood.edu"
                                           placeholder="sarah.thompson@institution.edu"
                                           required>
                                    <div id="adminEmailError" class="error-message"></div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">
                                        Position / Title <span class="text-red-500">*</span>
                                    </label>
                                    <input type="text" 
                                           id="adminTitle" 
                                           class="form-input" 
                                           value="Head Administrator"
                                           placeholder="Head Administrator"
                                           required>
                                    <div id="adminTitleError" class="error-message"></div>
                                </div>
                            </div>

                            <div class="space-y-6">
                                <div class="form-group">
                                    <label class="form-label">
                                        Phone Number <span class="text-red-500">*</span>
                                    </label>
                                    <input type="tel" 
                                           id="adminPhone" 
                                           class="form-input" 
                                           value="+44 20 7123 4567"
                                           placeholder="+1 (555) 123-4567"
                                           required>
                                    <div id="adminPhoneError" class="error-message"></div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">
                                        Access Level <span class="text-red-500">*</span>
                                    </label>
                                    <select id="accessLevel" class="form-input" required>
                                        <option value="">Select access level</option>
                                        <option value="super_admin" selected>Super Administrator</option>
                                        <option value="admin">Administrator</option>
                                        <option value="manager">Manager</option>
                                        <option value="viewer">Viewer</option>
                                    </select>
                                    <div id="accessLevelError" class="error-message"></div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">
                                        Reset Password
                                    </label>
                                    <div class="relative">
                                        <input type="password" 
                                               id="adminPassword" 
                                               class="form-input pr-12" 
                                               placeholder="Leave blank to keep current">
                                        <button type="button" 
                                                onclick="generatePassword()" 
                                                class="absolute right-4 top-1/2 transform -translate-y-1/2 text-blue-600 hover:text-blue-700">
                                            <i class="fas fa-redo"></i>
                                        </button>
                                    </div>
                                    <div class="text-xs text-slate-400 mt-2">If changed, password will be sent via secure email</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Secondary Contacts -->
                    <div class="bg-white form-card p-6">
                        <div class="flex justify-between items-center mb-6">
                            <h3 class="text-lg font-bold text-slate-900">Secondary Contacts</h3>
                            <button onclick="addContact()" class="px-4 py-2 bg-blue-600 text-white font-bold rounded-xl hover:bg-blue-700 transition touch-target">
                                <i class="fas fa-user-plus mr-2"></i>Add Contact
                            </button>
                        </div>
                        
                        <div class="space-y-4">
                            <div class="flex items-center justify-between p-4 border border-slate-200 rounded-xl">
                                <div class="flex items-center gap-3">
                                    <div class="w-12 h-12 rounded-xl bg-slate-100 flex items-center justify-center">
                                        <i class="fas fa-user text-slate-400"></i>
                                    </div>
                                    <div>
                                        <p class="font-bold text-slate-900">John Miller</p>
                                        <p class="text-sm text-slate-500">IT Manager • john.miller@greenwood.edu</p>
                                    </div>
                                </div>
                                <div class="flex gap-2">
                                    <button onclick="editContact(1)" class="w-10 h-10 rounded-lg border border-slate-200 text-slate-600 hover:text-blue-600 transition touch-target">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button onclick="removeContact(1)" class="w-10 h-10 rounded-lg border border-slate-200 text-slate-600 hover:text-red-600 transition touch-target">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <div class="flex items-center justify-between p-4 border border-slate-200 rounded-xl">
                                <div class="flex items-center gap-3">
                                    <div class="w-12 h-12 rounded-xl bg-slate-100 flex items-center justify-center">
                                        <i class="fas fa-user text-slate-400"></i>
                                    </div>
                                    <div>
                                        <p class="font-bold text-slate-900">Lisa Park</p>
                                        <p class="text-sm text-slate-500">Finance Director • lisa.park@greenwood.edu</p>
                                    </div>
                                </div>
                                <div class="flex gap-2">
                                    <button onclick="editContact(2)" class="w-10 h-10 rounded-lg border border-slate-200 text-slate-600 hover:text-blue-600 transition touch-target">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button onclick="removeContact(2)" class="w-10 h-10 rounded-lg border border-slate-200 text-slate-600 hover:text-red-600 transition touch-target">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab Content: Subscription -->
                <div id="subscriptionTab" class="max-w-7xl mx-auto space-y-6 tab-content">
                    <div class="bg-white form-card p-6">
                        <h3 class="text-lg font-bold text-slate-900 mb-6">Subscription Plan</h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                            <div class="border-2 border-blue-500 rounded-2xl p-6 bg-blue-50 relative cursor-pointer" onclick="selectPlan('enterprise')">
                                <div class="absolute top-4 right-4 bg-blue-600 text-white text-xs font-bold px-3 py-1 rounded-full">
                                    CURRENT
                                </div>
                                <div class="flex justify-between items-start mb-4">
                                    <div>
                                        <h4 class="font-bold text-slate-900 text-lg">Enterprise</h4>
                                        <p class="text-slate-500 text-sm">For large institutions & districts</p>
                                    </div>
                                    <div class="w-6 h-6 rounded-full border-2 border-blue-500 bg-blue-500"></div>
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

                            <div class="border-2 border-slate-200 rounded-2xl p-6 hover:border-blue-500 transition-colors cursor-pointer" onclick="selectPlan('pro')">
                                <div class="flex justify-between items-start mb-4">
                                    <div>
                                        <h4 class="font-bold text-slate-900 text-lg">Pro District</h4>
                                        <p class="text-slate-500 text-sm">For medium-sized institutions</p>
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

                            <div class="border-2 border-slate-200 rounded-2xl p-6 hover:border-blue-500 transition-colors cursor-pointer" onclick="selectPlan('basic')">
                                <div class="flex justify-between items-start mb-4">
                                    <div>
                                        <h4 class="font-bold text-slate-900 text-lg">Basic</h4>
                                        <p class="text-slate-500 text-sm">Essential features for small institutions</p>
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
                                    <div class="flex gap-4">
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
                            <label class="flex items-start gap-3 cursor-pointer">
                                <input type="checkbox" id="autoRenew" class="mt-1" checked>
                                <div>
                                    <span class="text-sm text-slate-700">
                                        Auto-renew subscription
                                    </span>
                                    <p class="text-xs text-slate-500 mt-1">Automatically renew subscription at the end of each billing cycle</p>
                                </div>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Tab Content: Advanced -->
                <div id="advancedTab" class="max-w-7xl mx-auto space-y-6 tab-content">
                    <div class="bg-white form-card p-6">
                        <h3 class="text-lg font-bold text-slate-900 mb-6">System Configuration</h3>
                        
                        <div class="space-y-6">
                            <div>
                                <h4 class="font-bold text-slate-900 mb-4">API Settings</h4>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
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
                            
                            <div>
                                <h4 class="font-bold text-slate-900 mb-4">Security Settings</h4>
                                <div class="space-y-4">
                                    <label class="flex items-center justify-between p-4 border border-slate-200 rounded-xl">
                                        <div>
                                            <p class="font-medium text-slate-900">Two-Factor Authentication</p>
                                            <p class="text-sm text-slate-500">Require 2FA for all admin accounts</p>
                                        </div>
                                        <label class="toggle-switch">
                                            <input type="checkbox" checked>
                                            <span class="toggle-slider"></span>
                                        </label>
                                    </label>
                                    
                                    <label class="flex items-center justify-between p-4 border border-slate-200 rounded-xl">
                                        <div>
                                            <p class="font-medium text-slate-900">IP Whitelisting</p>
                                            <p class="text-sm text-slate-500">Restrict access to specific IP ranges</p>
                                        </div>
                                        <label class="toggle-switch">
                                            <input type="checkbox">
                                            <span class="toggle-slider"></span>
                                        </label>
                                    </label>
                                    
                                    <label class="flex items-center justify-between p-4 border border-slate-200 rounded-xl">
                                        <div>
                                            <p class="font-medium text-slate-900">Session Timeout</p>
                                            <p class="text-sm text-slate-500">Auto-logout after 30 minutes of inactivity</p>
                                        </div>
                                        <label class="toggle-switch">
                                            <input type="checkbox" checked>
                                            <span class="toggle-slider"></span>
                                        </label>
                                    </label>
                                </div>
                            </div>
                            
                            <div>
                                <h4 class="font-bold text-slate-900 mb-4">Integration Settings</h4>
                                <div class="space-y-4">
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
                    <div class="bg-white form-card p-6">
                        <h3 class="text-lg font-bold text-slate-900 mb-6">Backup & Restore</h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="text-center p-6 border border-slate-200 rounded-xl">
                                <div class="w-12 h-12 rounded-xl bg-blue-100 flex items-center justify-center mx-auto mb-4">
                                    <i class="fas fa-database text-blue-600"></i>
                                </div>
                                <h4 class="font-bold text-slate-900 mb-2">Create Backup</h4>
                                <p class="text-sm text-slate-600 mb-4">Generate a complete system backup</p>
                                <button onclick="createBackup()" class="px-4 py-2 bg-blue-600 text-white font-bold rounded-xl hover:bg-blue-700 transition touch-target">
                                    Backup Now
                                </button>
                            </div>
                            
                            <div class="text-center p-6 border border-slate-200 rounded-xl">
                                <div class="w-12 h-12 rounded-xl bg-emerald-100 flex items-center justify-center mx-auto mb-4">
                                    <i class="fas fa-history text-emerald-600"></i>
                                </div>
                                <h4 class="font-bold text-slate-900 mb-2">Restore Point</h4>
                                <p class="text-sm text-slate-600 mb-4">Restore from previous backup</p>
                                <button onclick="restoreBackup()" class="px-4 py-2 border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition touch-target">
                                    Restore
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab Content: Danger Zone -->
                <div id="dangerTab" class="max-w-7xl mx-auto space-y-6 tab-content">
                    <div class="bg-red-50 border border-red-200 rounded-xl p-6">
                        <div class="flex items-start gap-4">
                            <div class="w-12 h-12 rounded-xl bg-red-100 flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-exclamation-triangle text-red-600 text-xl"></i>
                            </div>
                            <div>
                                <h3 class="text-lg font-bold text-red-900 mb-2">Danger Zone</h3>
                                <p class="text-red-700 mb-4">These actions are irreversible. Please proceed with caution.</p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white form-card p-6">
                        <div class="space-y-6">
                            <!-- Archive School -->
                            <div class="flex items-center justify-between p-6 border border-slate-200 rounded-xl">
                                <div>
                                    <h4 class="font-bold text-slate-900 mb-1">Archive School</h4>
                                    <p class="text-sm text-slate-600">Move this school to the archived directory. Data will be preserved but access will be restricted.</p>
                                </div>
                                <button onclick="archiveSchool()" class="px-6 py-3 border border-amber-300 text-amber-700 font-bold rounded-xl hover:bg-amber-50 transition touch-target">
                                    Archive
                                </button>
                            </div>
                            
                            <!-- Reset System -->
                            <div class="flex items-center justify-between p-6 border border-slate-200 rounded-xl">
                                <div>
                                    <h4 class="font-bold text-slate-900 mb-1">Reset System</h4>
                                    <p class="text-sm text-slate-600">Reset all configurations to default settings. User data will be preserved.</p>
                                </div>
                                <button onclick="resetSystem()" class="px-6 py-3 border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition touch-target">
                                    Reset
                                </button>
                            </div>
                            
                            <!-- Delete School -->
                            <div class="flex items-center justify-between p-6 border border-red-200 rounded-xl bg-red-50">
                                <div>
                                    <h4 class="font-bold text-red-900 mb-1">Delete School</h4>
                                    <p class="text-sm text-red-700">Permanently delete this school and all associated data. This action cannot be undone.</p>
                                </div>
                                <button onclick="openModal('deleteModal')" class="px-6 py-3 bg-red-600 text-white font-bold rounded-xl hover:bg-red-700 transition touch-target">
                                    Delete School
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Initialize timestamp
        function updateTimestamp() {
            const now = new Date();
            const options = { 
                weekday: 'short', 
                year: 'numeric', 
                month: 'short', 
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            };
            document.getElementById('timestamp').textContent = now.toLocaleDateString('en-US', options);
        }
        
        updateTimestamp();
        setInterval(updateTimestamp, 1000);

        // Tab switching
        let currentTab = 'basic';

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
            }
        }

        // Initialize tab click events
        document.addEventListener('DOMContentLoaded', function() {
            const tabButtons = document.querySelectorAll('.tab-button');
            tabButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const tabName = this.getAttribute('data-tab');
                    switchTab(tabName);
                });
            });
            
            // Set initial tab as active
            switchTab('basic');
        });

        // File upload handling
        const logoUpload = document.getElementById('logoUpload');
        const logoFile = document.getElementById('logoFile');

        if (logoUpload && logoFile) {
            logoUpload.addEventListener('click', () => logoFile.click());
            
            logoUpload.addEventListener('dragover', (e) => {
                e.preventDefault();
                logoUpload.classList.add('dragover');
            });
            
            logoUpload.addEventListener('dragleave', () => {
                logoUpload.classList.remove('dragover');
            });
            
            logoFile.addEventListener('change', (e) => {
                const file = e.target.files[0];
                if (file) {
                    handleFile(file);
                }
            });
        }

        function handleFile(file) {
            if (file.size > 5 * 1024 * 1024) {
                showNotification('File size must be less than 5MB', 'error');
                return;
            }
            
            const reader = new FileReader();
            reader.onload = function(e) {
                const previewImage = document.getElementById('previewImage');
                const fileName = document.getElementById('fileName');
                
                if (previewImage && fileName) {
                    previewImage.src = e.target.result;
                    fileName.textContent = file.name;
                    showNotification('Logo uploaded successfully', 'success');
                }
            };
            reader.readAsDataURL(file);
        }

        function removeLogo() {
            const previewImage = document.getElementById('previewImage');
            const fileName = document.getElementById('fileName');
            
            if (previewImage && fileName) {
                previewImage.src = 'https://ui-avatars.com/api/?name=Greenwood+High&background=2563eb&color=fff';
                fileName.textContent = 'Current logo';
                if (logoFile) logoFile.value = '';
                showNotification('Logo removed', 'info');
            }
        }

        function changeLogo() {
            if (logoFile) {
                logoFile.click();
            }
        }

        // Plan selection
        function selectPlan(plan) {
            // Update UI
            document.querySelectorAll('[onclick^="selectPlan"]').forEach(card => {
                if (card) {
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
                }
            });
            
            const selectedCard = document.querySelector(`[onclick="selectPlan('${plan}')"]`);
            if (selectedCard) {
                selectedCard.classList.add('border-blue-500', 'bg-blue-50');
                const indicator = selectedCard.querySelector('.rounded-full');
                if (indicator) {
                    indicator.classList.add('border-blue-500', 'bg-blue-500');
                    indicator.classList.remove('border-slate-300');
                }
                
                // Add "CURRENT" badge to enterprise if selected
                if (plan === 'enterprise') {
                    const badge = document.createElement('div');
                    badge.className = 'absolute top-4 right-4 bg-blue-600 text-white text-xs font-bold px-3 py-1 rounded-full';
                    badge.textContent = 'CURRENT';
                    selectedCard.appendChild(badge);
                }
            }
        }

        // Generate password
        function generatePassword() {
            const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*';
            let password = '';
            for (let i = 0; i < 12; i++) {
                password += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            const adminPassword = document.getElementById('adminPassword');
            if (adminPassword) {
                adminPassword.value = password;
                showNotification('Password generated', 'success');
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

        // Save changes
        function saveChanges() {
            if (!validateCurrentTab()) {
                return;
            }
            
            // Show loading
            showNotification('Saving changes...', 'info');
            
            // Simulate API call
            setTimeout(() => {
                showNotification('Changes saved successfully', 'success');
                
                // In a real app, you would update the UI with saved data
                updateStatusBadge();
            }, 1500);
        }

        function validateCurrentTab() {
            let isValid = true;
            
            // Clear previous errors
            document.querySelectorAll('.error-message').forEach(el => {
                el.classList.remove('show');
                el.textContent = '';
            });
            document.querySelectorAll('.form-input').forEach(el => {
                el.classList.remove('error');
            });

            if (currentTab === 'basic') {
                // Validate basic info
                const name = document.getElementById('institutionName');
                if (name && !name.value.trim()) {
                    showError('institutionName', 'Institution name is required');
                    isValid = false;
                }

                const type = document.getElementById('institutionType');
                if (type && !type.value) {
                    showError('institutionType', 'Please select an institution type');
                    isValid = false;
                }

                const domain = document.getElementById('emailDomain');
                if (domain && !domain.value.trim()) {
                    showError('emailDomain', 'Email domain is required');
                    isValid = false;
                }

            } else if (currentTab === 'contact') {
                // Validate contact info
                const adminName = document.getElementById('adminName');
                if (adminName && !adminName.value.trim()) {
                    showError('adminName', 'Administrator name is required');
                    isValid = false;
                }

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

                const accessLevel = document.getElementById('accessLevel');
                if (accessLevel && !accessLevel.value) {
                    showError('accessLevel', 'Please select an access level');
                    isValid = false;
                }
            }

            if (!isValid) {
                showNotification('Please fix validation errors', 'error');
            }

            return isValid;
        }

        function showError(fieldId, message) {
            const errorElement = document.getElementById(fieldId + 'Error');
            const inputElement = document.getElementById(fieldId);
            
            if (errorElement && inputElement) {
                errorElement.textContent = message;
                errorElement.classList.add('show');
                inputElement.classList.add('error');
                
                // Scroll to first error
                const firstError = document.querySelector('.form-input.error');
                if (firstError) {
                    firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }
        }

        function updateStatusBadge() {
            const statusSelect = document.getElementById('status');
            if (!statusSelect) return;
            
            const status = statusSelect.value;
            const badge = document.querySelector('.status-badge');
            
            if (badge) {
                badge.className = `status-badge status-${status}`;
                badge.innerHTML = `<i class="fas fa-circle text-[8px] mr-1"></i> ${status.charAt(0).toUpperCase() + status.slice(1)}`;
            }
        }

        // Preview changes
        function previewChanges() {
            showNotification('Preview feature coming soon', 'info');
        }

        // Discard changes
        function discardChanges() {
            if (confirm('Are you sure you want to discard all changes?')) {
                // Reload form with original data
                location.reload();
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
            if (confirm('Are you sure you want to archive this school? It will be moved to archived directory.')) {
                showNotification('Archiving school...', 'info');
                
                setTimeout(() => {
                    showNotification('School archived successfully', 'success');
                    // In real app, redirect to archived page
                }, 2000);
            }
        }

        function resetSystem() {
            if (confirm('Are you sure you want to reset all system configurations? User data will be preserved.')) {
                showNotification('Resetting system...', 'info');
                
                setTimeout(() => {
                    showNotification('System reset completed', 'success');
                }, 2000);
            }
        }

        function confirmDelete() {
            showNotification('Deleting school...', 'info');
            
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

        // Notification system
        function showNotification(message, type) {
            const notification = document.createElement('div');
            notification.className = `fixed top-4 right-4 px-6 py-3 rounded-xl shadow-lg z-[1001] animate-fadeIn ${
                type === 'success' ? 'bg-emerald-500 text-white' :
                type === 'error' ? 'bg-red-500 text-white' :
                'bg-blue-500 text-white'
            }`;
            notification.innerHTML = `
                <div class="flex items-center gap-3">
                    <i class="fas fa-${type === 'success' ? 'check' : type === 'error' ? 'exclamation' : 'info'}"></i>
                    <span>${message}</span>
                </div>
            `;
            document.body.appendChild(notification);
            
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.parentNode.removeChild(notification);
                }
            }, 3000);
        }

        // Sidebar functionality
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            if (sidebar && overlay) {
                sidebar.classList.toggle('-translate-x-full');
                overlay.classList.toggle('active');
            }
        }

        function toggleDropdown(id) {
            const dropdown = document.getElementById(id);
            if (dropdown) {
                dropdown.classList.toggle('dropdown-open');
            }
        }

        function mobileSidebarToggle() {
            toggleSidebar();
        }

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', (e) => {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            if (window.innerWidth < 1024 && 
                sidebar && 
                overlay && 
                !sidebar.contains(e.target) && 
                !e.target.closest('[onclick*="mobileSidebarToggle"]')) {
                sidebar.classList.add('-translate-x-full');
                overlay.classList.remove('active');
            }
        });

        // Handle escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                const sidebar = document.getElementById('sidebar');
                const overlay = document.getElementById('sidebarOverlay');
                if (sidebar && overlay) {
                    sidebar.classList.add('-translate-x-full');
                    overlay.classList.remove('active');
                }
                
                // Close modals
                document.querySelectorAll('.modal-overlay.active').forEach(modal => {
                    modal.classList.remove('active');
                });
                document.body.style.overflow = 'auto';
            }
        });

        // Initialize plan selection
        selectPlan('enterprise');
    </script>
</body>
</html>