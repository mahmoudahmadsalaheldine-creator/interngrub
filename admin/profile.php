<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('admin', 'manager', 'hr');

$pdo = getDB();
$error = '';
$success = '';

$stmt = $pdo->prepare("SELECT * FROM user WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (isset($_POST['update_profile'])) {
        $fullName = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $phone    = trim($_POST['phone'] ?? '');
        $error = vFirst([
            vRequired($fullName, 'Full name'), vMaxLen($fullName, 100, 'Full name'),
            vUsername($username),
            vMaxLen($phone, 20, 'Phone'),
        ]);
        if ($error === '') {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM user WHERE username = ? AND user_id != ?");
            $chk->execute([$username, $_SESSION['user_id']]);
            if ((int)$chk->fetchColumn() > 0) {
                $error = 'Username already taken.';
            } else {
                $pdo->prepare("UPDATE user SET full_name = ?, username = ?, phone = ? WHERE user_id = ?")
                    ->execute([$fullName, $username, $phone, $_SESSION['user_id']]);
                $_SESSION['full_name'] = $fullName;
                $_SESSION['username']  = $username;
                $user['full_name'] = $fullName;
                $user['username']  = $username;
                $user['phone']     = $phone;
                $success = 'Profile updated.';
            }
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
    <?php $profileColor = $user['role'] === 'hr' ? '#0F766E' : ($user['role'] === 'manager' ? '#1D4ED8' : '#7B5EA7'); ?>
    <div class="avatar" style="<?= avatarStyle($profileColor, 60, 22) ?>"><?= htmlspecialchars(getInitials($user['full_name'])) ?></div>
    <div>
        <div class="profile-hero-name"><?= htmlspecialchars($user['full_name']) ?></div>
        <div class="profile-hero-meta"><?= htmlspecialchars($user['email'] ?? '') ?><?= !empty($user['email']) ? ' &middot; ' : '' ?><?= htmlspecialchars(ucfirst($user['role'])) ?></div>
    </div>
</div>

<div class="two-col-grid">
    <div class="card" style="margin-bottom:0;">
        <div class="card-header"><div class="card-header-title">Edit profile</div></div>
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label" style="font-size:12.5px;">Full Name</label>
                    <input type="text" id="ap_fullname" name="full_name" class="form-control" value="<?= htmlspecialchars($user['full_name']) ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label" style="font-size:12.5px;">Username</label>
                    <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($user['username'] ?? '') ?>" required autocomplete="off">
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
                        <button type="button" onclick="suggestPwFromUsername('ap_fullname','ap_new','ap_strength')" style="font-size:11px;color:var(--purple);background:none;border:none;cursor:pointer;font-weight:600;">⚡ Suggest password</button>
                    </label>
                    <div style="position:relative;">
                        <input type="password" id="ap_new" name="new_password" class="form-control" required style="padding-right:38px;" oninput="updatePwStrength(this.value,'ap_strength')" autocomplete="new-password">
                        <button type="button" onclick="toggleEye('ap_new',this)" tabindex="-1" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--muted-dark);padding:0;line-height:1;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                    <div id="ap_strength" style="height:4px;border-radius:4px;background:#E5E7EB;margin-top:6px;overflow:hidden;"><div style="height:100%;width:0;border-radius:4px;transition:width .2s,background .2s;"></div></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" name="change_password" value="1" class="btn btn-primary">Change Password</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_bottom.php'; ?>
