<?php
$whatsappSettings = is_array($whatsappSettings ?? null) ? $whatsappSettings : WhatsAppService::defaultFeatureSettings(false);
$whatsappConfigured = (bool)($whatsappConfigured ?? false);
$whatsappConfigurationStatus = (string)($whatsappConfigurationStatus ?? 'WhatsApp configuration status unavailable.');

$whatsappOn = function (string $key) use ($whatsappSettings): bool {
    return in_array(strtolower(trim((string)($whatsappSettings[$key] ?? '0'))), ['1', 'true', 'yes', 'on', 'enabled'], true);
};

$featureCards = [
    [
        'key' => 'whatsapp_announcements_enabled',
        'title' => 'Announcements',
        'description' => 'Send school notice-board updates to parents, teachers, and selected audiences.',
        'icon' => 'ri-megaphone-line',
        'accent' => 'primary',
    ],
    [
        'key' => 'whatsapp_events_enabled',
        'title' => 'Events',
        'description' => 'Notify parents and teachers when school events are created, updated, or cancelled.',
        'icon' => 'ri-calendar-event-line',
        'accent' => 'info',
    ],
    [
        'key' => 'whatsapp_fees_enabled',
        'title' => 'Fees & Invoices',
        'description' => 'Send fee collection receipts, invoice notices, and payment reminders.',
        'icon' => 'ri-bank-card-line',
        'accent' => 'warning',
    ],
    [
        'key' => 'whatsapp_attendance_enabled',
        'title' => 'Attendance',
        'description' => 'Alert guardians when attendance is marked or changed for their child.',
        'icon' => 'ri-calendar-check-line',
        'accent' => 'success',
    ],
];
?>

<style>
    .whatsapp-settings-card {
        border: 1px solid #edf1f5;
        border-radius: 14px;
        background: #fff;
        box-shadow: 0 12px 30px rgba(15, 23, 42, 0.04);
    }
    .whatsapp-feature-card {
        border: 1px solid #edf1f5;
        border-radius: 14px;
        background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
        min-height: 168px;
        transition: border-color .2s ease, transform .2s ease;
    }
    .whatsapp-feature-card:hover {
        border-color: #cfe7e3;
        transform: translateY(-1px);
    }
    .whatsapp-switch {
        width: 52px;
        height: 28px;
    }
    .whatsapp-icon {
        width: 46px;
        height: 46px;
        border-radius: 13px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 22px;
    }
</style>

<div class="whatsapp-settings-card p-24">
    <div class="d-flex flex-wrap align-items-start justify-content-between gap-16 mb-24">
        <div>
            <span class="badge bg-success-focus text-success-main px-12 py-6 radius-8 mb-12">
                <i class="ri-whatsapp-line me-1"></i> WhatsApp Cloud API
            </span>
            <h4 class="mb-6">WhatsApp Notification Controls</h4>
            <p class="text-secondary-light mb-0">Choose which school actions are allowed to send WhatsApp alerts.</p>
        </div>
        <span class="badge <?php echo $whatsappConfigured ? 'bg-success-focus text-success-main' : 'bg-warning-focus text-warning-main'; ?> px-16 py-8 radius-8">
            <?php echo $whatsappConfigured ? 'Configured' : 'Needs API keys'; ?>
        </span>
    </div>

    <div class="alert <?php echo $whatsappConfigured ? 'alert-success' : 'alert-warning'; ?> radius-12 mb-24">
        <?php echo htmlspecialchars($whatsappConfigurationStatus, ENT_QUOTES, 'UTF-8'); ?>
    </div>

    <form id="whatsappSettingsForm" method="POST">
        <input type="hidden" name="action" value="update_whatsapp_settings">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

        <div class="d-flex align-items-center justify-content-between gap-16 p-20 bg-neutral-50 radius-12 mb-24">
            <div>
                <h6 class="mb-4">Master WhatsApp Switch</h6>
                <p class="text-secondary-light mb-0">Turn this off to stop all WhatsApp alerts from this school portal.</p>
            </div>
            <div class="form-switch switch-primary d-flex align-items-center">
                <input class="form-check-input whatsapp-switch" type="checkbox" name="whatsapp_enabled" id="whatsapp_enabled" <?php echo $whatsappOn('whatsapp_enabled') ? 'checked' : ''; ?>>
            </div>
        </div>

        <div class="row g-4">
            <?php foreach ($featureCards as $feature): ?>
                <div class="col-xxl-6">
                    <label class="whatsapp-feature-card d-block p-20 h-100" for="<?php echo $feature['key']; ?>">
                        <div class="d-flex align-items-start justify-content-between gap-16">
                            <span class="whatsapp-icon bg-<?php echo $feature['accent']; ?>-focus text-<?php echo $feature['accent']; ?>-main">
                                <i class="<?php echo $feature['icon']; ?>"></i>
                            </span>
                            <div class="form-switch switch-primary">
                                <input class="form-check-input whatsapp-switch" type="checkbox"
                                       name="<?php echo $feature['key']; ?>"
                                       id="<?php echo $feature['key']; ?>"
                                       <?php echo $whatsappOn($feature['key']) ? 'checked' : ''; ?>>
                            </div>
                        </div>
                        <h6 class="mt-18 mb-8"><?php echo htmlspecialchars($feature['title'], ENT_QUOTES, 'UTF-8'); ?></h6>
                        <p class="text-secondary-light mb-0"><?php echo htmlspecialchars($feature['description'], ENT_QUOTES, 'UTF-8'); ?></p>
                    </label>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="d-flex align-items-center justify-content-end gap-12 mt-24">
            <button type="submit" class="btn btn-primary-600 px-24">
                <i class="ri-save-3-line me-1"></i> Save WhatsApp Settings
            </button>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('whatsappSettingsForm');
    if (!form) return;

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        const button = form.querySelector('button[type="submit"]');
        const originalText = button ? button.innerHTML : '';
        if (button) {
            button.disabled = true;
            button.innerHTML = '<i class="ri-loader-4-line me-1"></i> Saving...';
        }

        fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: new FormData(form)
        })
            .then(response => response.json())
            .then(response => {
                if (typeof showToast === 'function') {
                    showToast(response.message || 'Settings saved.', response.success ? 'success' : 'error');
                } else {
                    alert(response.message || (response.success ? 'Settings saved.' : 'Unable to save settings.'));
                }
            })
            .catch(() => {
                if (typeof showToast === 'function') {
                    showToast('Request failed. Please try again.', 'error');
                } else {
                    alert('Request failed. Please try again.');
                }
            })
            .finally(() => {
                if (button) {
                    button.disabled = false;
                    button.innerHTML = originalText;
                }
            });
    });
});
</script>
