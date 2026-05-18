<header class="h-20 glass-header px-6 lg:px-8 flex items-center justify-between shrink-0 z-40">
    <div class="flex items-center gap-3">
        <button onclick="mobileSidebarToggle()" class="lg:hidden text-slate-600 p-2 hover:bg-slate-100 rounded-lg transition">
            <i class="fas fa-bars-staggered"></i>
        </button>
        <div class="flex items-center gap-3">
            <h1 class="text-lg font-bold text-slate-900 tracking-tight"><?php echo $pageTitle ?? 'Dashboard'; ?></h1>
        </div>
    </div>
    <div class="flex items-center gap-4" id="headerActions">
        <?php echo $headerActions ?? ''; ?>
    </div>
</header>
