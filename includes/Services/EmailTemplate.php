<?php

class EmailTemplate
{
    private string $brandName;
    private string $supportEmail;
    private string $appUrl;

    public function __construct()
    {
        $this->brandName = defined('APP_NAME') ? APP_NAME : 'AcademixSuite';
        $this->supportEmail = defined('SUPPORT_EMAIL') ? SUPPORT_EMAIL : 'support@academixsuite.com';
        $this->appUrl = defined('APP_URL') ? rtrim(APP_URL, '/') : 'http://localhost';
    }

    public function getTemplate($templateName, $data)
    {
        switch ($templateName) {
            case 'welcome':
                return $this->getWelcomeEmailTemplate(
                    $data['admin_name'] ?? '',
                    $data['school_name'] ?? '',
                    $data['login_url'] ?? '',
                    $data['credentials'] ?? [],
                    $data['trial_info'] ?? []
                );
            case 'provisioning_notification':
                return $this->getProvisioningNotificationTemplate(
                    $data['school_name'] ?? '',
                    $data['admin_email'] ?? '',
                    $data['school_id'] ?? '',
                    $data['database_info'] ?? []
                );
            case 'invoice':
                return $this->getInvoiceEmailTemplate($data);
            case 'trial_expired':
                return $this->getTrialExpiredEmailTemplate($data);
            case 'notification':
                return $this->getNotificationTemplate($data);
            case 'announcement':
            default:
                return $this->getAnnouncementTemplate($data);
        }
    }

    public function getWelcomeEmailTemplate($adminName, $schoolName, $loginUrl, $credentials, $trialInfo)
    {
        $trialDays = (int)($trialInfo['trial_days'] ?? 7);
        $trialEndsAt = $trialInfo['trial_ends_at'] ?? date('Y-m-d H:i:s', strtotime('+7 days'));
        $trialEndDate = date('F j, Y', strtotime($trialEndsAt));
        $planName = $trialInfo['plan'] ?? 'Starter';

        return $this->renderLayout([
            'preheader' => 'Your school portal is ready on ' . $this->brandName . '.',
            'eyebrow' => 'School onboarding complete',
            'title' => 'Welcome to ' . $this->brandName,
            'intro' => 'Hello ' . $this->escape($adminName ?: 'Administrator') . ', your school workspace has been provisioned and is ready to use.',
            'body' => [
                $this->paragraph('Your school, <strong>' . $this->escape($schoolName) . '</strong>, is active on the <strong>' . $this->escape($planName) . '</strong> plan with a ' . $trialDays . '-day trial.'),
                $this->detailsTable([
                    'School' => $schoolName,
                    'Admin email' => $credentials['email'] ?? '',
                    'Temporary password' => $credentials['password'] ?? '',
                    'Trial ends' => $trialEndDate,
                    'Login URL' => $loginUrl
                ]),
                $this->notice('For security, change this temporary password after your first login and invite other administrators only after reviewing their permissions.')
            ],
            'button_text' => 'Open School Portal',
            'button_url' => $loginUrl,
            'footer_note' => 'This onboarding email contains account access details. Keep it private.'
        ]);
    }

    public function getProvisioningNotificationTemplate($schoolName, $adminEmail, $schoolId, $databaseInfo)
    {
        return $this->renderLayout([
            'preheader' => 'A new school has been provisioned.',
            'eyebrow' => 'Platform notification',
            'title' => 'New School Provisioned',
            'intro' => 'A school onboarding process has completed successfully.',
            'body' => [
                $this->detailsTable([
                    'School' => $schoolName,
                    'School ID' => $schoolId,
                    'Admin email' => $adminEmail,
                    'Database' => $databaseInfo['database_name'] ?? 'Not available',
                    'Database host' => $databaseInfo['database_host'] ?? 'localhost',
                    'Tables created' => $databaseInfo['tables_created'] ?? 'Unknown',
                    'Provisioned at' => date('F j, Y g:i A')
                ]),
                $this->notice('The school record, tenant database, initial admin account, trial subscription, and onboarding emails were processed by the platform.')
            ],
            'button_text' => 'Open Platform',
            'button_url' => $this->appUrl . '/platform/admin/schools/manage.php?id=' . urlencode((string)$schoolId)
        ]);
    }

    public function getAnnouncementTemplate($data)
    {
        $subject = $data['subject'] ?? 'Important Announcement';
        $message = isset($data['message_html'])
            ? $this->sanitizeEmailHtml($data['message_html'])
            : nl2br($this->escape($data['message'] ?? ''));
        $schoolName = $data['school_name'] ?? 'Your School';
        $adminName = $data['admin_name'] ?? 'Administrator';
        $portalUrl = $data['portal_url'] ?? ($this->appUrl . '/tenant/login.php');

        return $this->renderLayout([
            'preheader' => $subject,
            'eyebrow' => 'Announcement',
            'title' => $subject,
            'intro' => 'Hello ' . $this->escape($adminName) . ', this update is for ' . $this->escape($schoolName) . '.',
            'body' => [
                '<div style="font-size:15px;line-height:24px;color:#334155;">' . $message . '</div>',
                $this->detailsTable([
                    'School' => $schoolName,
                    'Date' => $data['date'] ?? date('F j, Y')
                ])
            ],
            'button_text' => 'Open Portal',
            'button_url' => $portalUrl
        ]);
    }

    public function getNotificationTemplate($data)
    {
        $title = $data['title'] ?? ($data['subject'] ?? 'Platform Notification');
        $message = nl2br($this->escape($data['message'] ?? ''));

        return $this->renderLayout([
            'preheader' => $title,
            'eyebrow' => $data['eyebrow'] ?? 'Notification',
            'title' => $title,
            'intro' => $data['intro'] ?? 'There is a new update from ' . $this->brandName . '.',
            'body' => [
                '<div style="font-size:15px;line-height:24px;color:#334155;">' . $message . '</div>',
                !empty($data['details']) && is_array($data['details']) ? $this->detailsTable($data['details']) : ''
            ],
            'button_text' => $data['button_text'] ?? null,
            'button_url' => $data['button_url'] ?? null
        ]);
    }

    public function getTrialExpiredEmailTemplate($data)
    {
        $schoolName = $data['school_name'] ?? 'Your School';
        $adminName = $data['admin_name'] ?? 'Administrator';
        $trialEndsAt = $data['trial_ends_at'] ?? date('Y-m-d H:i:s');
        $trialEndDate = date('F j, Y', strtotime($trialEndsAt));
        $planName = $data['plan_name'] ?? 'Starter';
        $portalUrl = $data['portal_url'] ?? ($this->appUrl . '/tenant/login.php');
        $billingUrl = $data['billing_url'] ?? $portalUrl;

        return $this->renderLayout([
            'preheader' => 'Your ' . $this->brandName . ' school trial has ended.',
            'eyebrow' => 'Trial ended',
            'title' => 'Your school trial has ended',
            'intro' => 'Hello ' . $this->escape($adminName) . ', the free trial for <strong>' . $this->escape($schoolName) . '</strong> has ended.',
            'body' => [
                $this->paragraph('Your school portal has been moved to a suspended billing state until an active subscription is selected and payment is completed. Your school data remains preserved.'),
                $this->detailsTable([
                    'School' => $schoolName,
                    'Plan' => $planName,
                    'Trial ended' => $trialEndDate,
                    'Portal URL' => $portalUrl
                ]),
                $this->notice('To restore access, open billing from your school portal and activate a subscription. If payment has already been made, contact support with your school name and invoice reference.')
            ],
            'button_text' => 'Reactivate Subscription',
            'button_url' => $billingUrl,
            'footer_note' => 'This automated notice was sent because the trial period for your school workspace has ended.'
        ]);
    }

    public function getInvoiceEmailTemplate($data)
    {
        $invoiceNumber = $data['invoice_number'] ?? 'N/A';
        $status = strtoupper((string)($data['status'] ?? 'PENDING'));
        $currency = $data['currency'] ?? 'NGN';
        $amount = $data['amount'] ?? '0.00';

        return $this->renderLayout([
            'preheader' => 'Invoice ' . $invoiceNumber . ' from ' . $this->brandName . '.',
            'eyebrow' => 'Billing',
            'title' => 'Invoice ' . $invoiceNumber,
            'intro' => 'Hello ' . $this->escape($data['school_name'] ?? 'there') . ', here is your subscription invoice summary.',
            'body' => [
                $this->statusPill($status),
                $this->detailsTable([
                    'Invoice number' => $invoiceNumber,
                    'Description' => $data['description'] ?? 'School Management Subscription',
                    'Amount due' => $currency . ' ' . $amount,
                    'Due date' => $data['due_date'] ?? date('F j, Y', strtotime('+30 days')),
                    'Status' => $status
                ]),
                $this->notice($status === 'TRIAL' ? 'This invoice is attached to your free trial period.' : 'Please keep this invoice for your records.')
            ],
            'button_text' => 'View Billing',
            'button_url' => $this->appUrl . '/platform/admin/billings/'
        ]);
    }

    private function renderLayout(array $data): string
    {
        $preheader = $this->escape($data['preheader'] ?? '');
        $eyebrow = $this->escape($data['eyebrow'] ?? 'Notification');
        $title = $this->escape($data['title'] ?? $this->brandName);
        $intro = $data['intro'] ?? '';
        $body = implode('', array_filter($data['body'] ?? []));
        $button = '';

        if (!empty($data['button_text']) && !empty($data['button_url'])) {
            $button = '<tr><td align="left" style="padding:8px 0 24px 0;">
                <a href="' . $this->escape($data['button_url']) . '" style="display:inline-block;background:#0f766e;color:#ffffff;text-decoration:none;font-weight:700;font-size:14px;line-height:20px;padding:13px 20px;border-radius:6px;">' . $this->escape($data['button_text']) . '</a>
            </td></tr>';
        }

        $footerNote = $this->escape($data['footer_note'] ?? 'You are receiving this email because of activity on your ' . $this->brandName . ' account.');

        return '<!doctype html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>' . $title . '</title>
</head>
<body style="margin:0;padding:0;background:#eef2f7;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">' . $preheader . '</div>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef2f7;margin:0;padding:32px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;background:#ffffff;border:1px solid #dbe3ef;border-radius:10px;overflow:hidden;">
                    <tr>
                        <td style="background:#0f172a;padding:28px 32px;">
                            <div style="font-size:20px;line-height:28px;font-weight:800;color:#ffffff;">' . $this->escape($this->brandName) . '</div>
                            <div style="font-size:13px;line-height:20px;color:#cbd5e1;margin-top:4px;">School operations, billing, and communication platform</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:34px 32px 12px 32px;">
                            <div style="font-size:12px;line-height:18px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#0f766e;margin-bottom:10px;">' . $eyebrow . '</div>
                            <h1 style="margin:0 0 12px 0;font-size:26px;line-height:34px;color:#0f172a;font-weight:800;">' . $title . '</h1>
                            <div style="font-size:15px;line-height:24px;color:#334155;margin-bottom:22px;">' . $intro . '</div>
                            ' . $body . '
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $button . '</table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:22px 32px;background:#f8fafc;border-top:1px solid #e2e8f0;">
                            <div style="font-size:12px;line-height:19px;color:#64748b;">' . $footerNote . '</div>
                            <div style="font-size:12px;line-height:19px;color:#64748b;margin-top:10px;">Need help? Contact <a href="mailto:' . $this->escape($this->supportEmail) . '" style="color:#0f766e;text-decoration:none;">' . $this->escape($this->supportEmail) . '</a>.</div>
                            <div style="font-size:11px;line-height:17px;color:#94a3b8;margin-top:12px;">Copyright ' . date('Y') . ' ' . $this->escape($this->brandName) . '. All rights reserved.</div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
    }

    private function detailsTable(array $rows): string
    {
        $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0;border-radius:8px;margin:20px 0 24px 0;overflow:hidden;">';
        foreach ($rows as $label => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $html .= '<tr>
                <td style="width:38%;padding:12px 14px;background:#f8fafc;border-bottom:1px solid #e2e8f0;font-size:13px;line-height:19px;color:#64748b;font-weight:700;">' . $this->escape((string)$label) . '</td>
                <td style="padding:12px 14px;border-bottom:1px solid #e2e8f0;font-size:13px;line-height:19px;color:#0f172a;">' . $this->escape((string)$value) . '</td>
            </tr>';
        }
        return $html . '</table>';
    }

    private function paragraph(string $html): string
    {
        return '<p style="margin:0 0 16px 0;font-size:15px;line-height:24px;color:#334155;">' . $html . '</p>';
    }

    private function notice(string $text): string
    {
        return '<div style="background:#ecfdf5;border:1px solid #bbf7d0;border-radius:8px;padding:14px 16px;margin:20px 0 24px 0;font-size:13px;line-height:21px;color:#14532d;">' . $this->escape($text) . '</div>';
    }

    private function statusPill(string $status): string
    {
        $background = '#fef3c7';
        $color = '#92400e';
        if ($status === 'PAID' || $status === 'ACTIVE') {
            $background = '#dcfce7';
            $color = '#166534';
        } elseif ($status === 'OVERDUE' || $status === 'FAILED') {
            $background = '#fee2e2';
            $color = '#991b1b';
        }

        return '<div style="display:inline-block;background:' . $background . ';color:' . $color . ';font-size:12px;line-height:18px;font-weight:800;border-radius:999px;padding:5px 11px;margin-bottom:16px;">' . $this->escape($status) . '</div>';
    }

    private function escape($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }

    private function sanitizeEmailHtml($value): string
    {
        $html = strip_tags((string)$value, '<p><br><strong><b><em><i><u><ul><ol><li><a><span><div>');
        $html = preg_replace('/\s+on[a-z]+\s*=\s*"[^"]*"/i', '', $html);
        $html = preg_replace("/\s+on[a-z]+\s*=\s*'[^']*'/i", '', $html);
        $html = preg_replace('/\s+on[a-z]+\s*=\s*[^\s>]+/i', '', $html);
        $html = preg_replace('/href\s*=\s*"javascript:[^"]*"/i', 'href="#"', $html);
        $html = preg_replace("/href\s*=\s*'javascript:[^']*'/i", "href=\"#\"", $html);
        $html = preg_replace('/href\s*=\s*javascript:[^\s>]+/i', 'href="#"', $html);

        return $html;
    }
}
