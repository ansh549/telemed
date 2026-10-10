<?php
// includes/messages.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Success Notification
if (!empty($_SESSION['success'])): ?>
    <div class="bg-emerald-50 border border-emerald-300 text-emerald-800 px-4 py-3 rounded-xl relative mb-5 flex items-center justify-between shadow-sm">
        <div class="flex items-center gap-2">
            <span class="text-emerald-600 font-bold">✓</span>
            <span class="text-sm font-medium"><?= htmlspecialchars($_SESSION['success'], ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <button type="button" onclick="this.parentElement.remove()" class="text-emerald-600 hover:text-emerald-900 font-bold text-lg leading-none">&times;</button>
    </div>
    <?php unset($_SESSION['success']); ?>
<?php endif; ?>

<?php
// Error Notification
if (!empty($_SESSION['error'])): ?>
    <div class="bg-rose-50 border border-rose-300 text-rose-800 px-4 py-3 rounded-xl relative mb-5 flex items-center justify-between shadow-sm">
        <div class="flex items-center gap-2">
            <span class="text-rose-600 font-bold">⚠️</span>
            <span class="text-sm font-medium"><?= htmlspecialchars($_SESSION['error'], ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <button type="button" onclick="this.parentElement.remove()" class="text-rose-600 hover:text-rose-900 font-bold text-lg leading-none">&times;</button>
    </div>
    <?php unset($_SESSION['error']); ?>
<?php endif; ?>

<?php
// Informational Notification
if (!empty($_SESSION['info'])): ?>
    <div class="bg-blue-50 border border-blue-300 text-blue-800 px-4 py-3 rounded-xl relative mb-5 flex items-center justify-between shadow-sm">
        <div class="flex items-center gap-2">
            <span class="text-blue-600 font-bold">ℹ️</span>
            <span class="text-sm font-medium"><?= htmlspecialchars($_SESSION['info'], ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <button type="button" onclick="this.parentElement.remove()" class="text-blue-600 hover:text-blue-900 font-bold text-lg leading-none">&times;</button>
    </div>
    <?php unset($_SESSION['info']); ?>
<?php endif; ?>