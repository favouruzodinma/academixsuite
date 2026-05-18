<?php
require_once __DIR__ . '/../../../includes/autoload.php';

$auth = new Auth();
$auth->requireLogin('super_admin');

$db = Database::getPlatformConnection();
$campaigns = [];
try {
    if ($db->query("SHOW TABLES LIKE 'bulk_email_campaigns'")->fetchColumn()) {
        $columnStmt = $db->query("SHOW COLUMNS FROM bulk_email_campaigns");
        $columns = array_column($columnStmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
        $dateColumn = in_array('sent_at', $columns, true)
            ? 'sent_at'
            : (in_array('completed_at', $columns, true) ? 'completed_at' : 'created_at');

        $campaigns = $db->query("SELECT * FROM bulk_email_campaigns ORDER BY `{$dateColumn}` DESC, id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
        $campaigns = array_map(function ($campaign) {
            $success = (int)($campaign['successful_sends'] ?? $campaign['sent_count'] ?? $campaign['emails_sent'] ?? 0);
            $failed = (int)($campaign['failed_sends'] ?? $campaign['failed_count'] ?? 0);
            $total = (int)($campaign['total_recipients'] ?? ($success + $failed));
            $sentAt = $campaign['sent_at'] ?? $campaign['completed_at'] ?? $campaign['created_at'] ?? '';

            return array_merge($campaign, [
                'total_recipients' => $total,
                'successful_sends' => $success,
                'failed_sends' => $failed,
                'sent_at' => $sentAt
            ]);
        }, $campaigns);
    }
} catch (Exception $e) {
    error_log("Campaign history load failed: " . $e->getMessage());
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Email Campaign History</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-900">
    <main class="max-w-6xl mx-auto p-6">
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-2xl font-black">Email Campaign History</h1>
            <a href="send-email.php" class="px-4 py-2 rounded-lg bg-slate-900 text-white text-sm font-bold">Back to Email</a>
        </div>
        <div class="bg-white border border-slate-200 rounded-xl overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-slate-100 text-left">
                    <tr>
                        <th class="p-3">Subject</th>
                        <th class="p-3">Total</th>
                        <th class="p-3">Successful</th>
                        <th class="p-3">Failed</th>
                        <th class="p-3">Sent At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$campaigns): ?>
                        <tr><td colspan="5" class="p-6 text-center text-slate-500">No campaign history found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($campaigns as $campaign): ?>
                        <tr class="border-t border-slate-100">
                            <td class="p-3"><?php echo htmlspecialchars($campaign['subject'] ?? 'Untitled'); ?></td>
                            <td class="p-3"><?php echo (int)($campaign['total_recipients'] ?? 0); ?></td>
                            <td class="p-3"><?php echo (int)($campaign['successful_sends'] ?? 0); ?></td>
                            <td class="p-3"><?php echo (int)($campaign['failed_sends'] ?? 0); ?></td>
                            <td class="p-3"><?php echo htmlspecialchars($campaign['sent_at'] ?? $campaign['created_at'] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>
</body>
</html>
