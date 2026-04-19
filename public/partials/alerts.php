<?php
$alerts = $alerts ?? [];
?>
<?php foreach ($alerts as $alert): ?>
    <?php if (!empty($alert['message'])): ?>
        <div class="alert alert-<?= htmlspecialchars($alert['type'] ?? 'info') ?>" role="alert">
            <?= htmlspecialchars($alert['message']) ?>
        </div>
    <?php endif; ?>
<?php endforeach; ?>