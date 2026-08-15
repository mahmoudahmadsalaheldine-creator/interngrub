<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('intern');

$pdo = getDB();
$error = '';
$success = '';

$stmt = $pdo->prepare("
    SELECT u.*, ip.position, ip.start_date, ip.end_date, ip.internship_status, d.department_name
    FROM user u
    LEFT JOIN intern_profile ip ON ip.user_id = u.user_id
    LEFT JOIN department d ON ip.department_id = d.department_id
    WHERE u.user_id = ?
");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (isset($_POST['update_profile'])) {
        $fullName = trim($_POST['full_name'] ?? '');
        $phone    = trim($_POST['phone'] ?? '');
        $error = vFirst([
            vRequired($fullName, 'Full name'), vMaxLen($fullName, 100, 'Full name'),
            vMaxLen($phone, 20, 'Phone'),
        ]);
        if ($error === '') {
            $pdo->prepare("UPDATE user SET full_name = ?, phone = ? WHERE user_id = ?")
                ->execute([$fullName, $phone, $_SESSION['user_id']]);
            $_SESSION['full_name'] = $fullName;
            $user['full_name'] = $fullName;
            $user['phone'] = $phone;
            $success = 'Profile updated.';
        }
    } elseif (isset($_POST['change_password'])) {
        $new = $_POST['new_password'] ?? '';
        $error = vFirst([vRequired($new, 'New password'), vMinLen($new, 8, 'New password')]);
        if ($error === '') {
            $pdo->prepare("UPDATE user SET password = ? WHERE user_id = ?")
                ->execute([password_hash($new, PASSWORD_DEFAULT), $_SESSION['user_id']]);
            $success = 'Password changed successfully.';
        }
    }
}

$pageTitle = 'My Profile';
$pageSubtitle = 'Your account and security settings';
require_once __DIR__ . '/../includes/layout_top.php';
?>

<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

<div class="profile-hero">
    <div class="avatar" style="<?= avatarStyle('#DB2777', 60, 22) ?>"><?= htmlspecialchars(getInitials($user['full_name'])) ?></div>
    <div style="flex:1;">
        <div class="profile-hero-name"><?= htmlspecialchars($user['full_name']) ?></div>
        <?php if (!empty($user['position']) || !empty($user['department_name'])): ?>
        <div class="profile-hero-meta"><?= htmlspecialchars($user['position'] ?? '') ?> &middot; <?= htmlspecialchars($user['department_name'] ?? '') ?></div>
        <?php endif; ?>
    </div>
    <?php if (!empty($user['internship_status'])): ?>
        <?= statusPillFor($internStat, $user['internship_status']) ?>
    <?php endif; ?>
</div>

<div class="two-col-grid">
    <div class="card" style="margin-bottom:0;">
        <div class="card-header"><div class="card-header-title">Edit profile</div></div>
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label" style="font-size:12.5px;">Full Name</label>
                    <input type="text" id="ip_fullname" name="full_name" class="form-control" value="<?= htmlspecialchars($user['full_name']) ?>" required>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label" style="font-size:12.5px;">Phone</label>
                    <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($user['phone'] ?? '') ?>">
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" name="update_profile" value="1" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>

    <div class="card" style="margin-bottom:0;">
        <div class="card-header"><div class="card-header-title">Change password</div></div>
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <div class="card-body">
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label" style="font-size:12.5px;display:flex;justify-content:space-between;align-items:center;">
                        <span>New Password</span>
                        <button type="button" onclick="suggestPwFromUsername('ip_fullname','ip_new','ip_strength')" style="font-size:11px;color:var(--purple);background:none;border:none;cursor:pointer;font-weight:600;">⚡ Suggest</button>
                    </label>
                    <div style="position:relative;">
                        <input type="password" id="ip_new" name="new_password" class="form-control" required style="padding-right:38px;" autocomplete="new-password" oninput="updatePwStrength(this.value,'ip_strength')">
                        <button type="button" onclick="toggleEye('ip_new',this)" tabindex="-1" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--muted-dark);padding:0;line-height:1;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                    <div id="ip_strength" style="height:4px;border-radius:4px;background:#E5E7EB;margin-top:6px;overflow:hidden;"><div style="height:100%;width:0;border-radius:4px;transition:width .2s,background .2s;"></div></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" name="change_password" value="1" class="btn btn-primary">Change Password</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_bottom.php'; ?>

