<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
requireLogin();
$current = user();
$stmt = db()->prepare('SELECT full_name, email, phone_number, profile_photo FROM users WHERE id = ?');
$stmt->execute([$current['id']]);
$profile = $stmt->fetch();
$dashboard = roleDashboard($current['role']);
$role = strtolower($current['role']);
$sidebar = match ($role) {
    'fams officer' => [
        ['dashboard.html', 'fas fa-tachometer-alt', 'Dashboard'],
        ['all-applications.html', 'fas fa-file-alt', 'All Applications'],
        ['placements.html', 'fas fa-briefcase', 'Placements'],
        ['notifications.html', 'fas fa-bell', 'Notifications'],
        ['../profile.php', 'fas fa-user-circle', 'My Profile'],
    ],
    'department officer' => [
        ['dashboard.html', 'fas fa-tachometer-alt', 'Dashboard'],
        ['pending-reviews.html', 'fas fa-file-alt', 'Pending Reviews'],
        ['history.html', 'fas fa-history', 'Review History'],
        ['notifications.html', 'fas fa-bell', 'Notifications'],
        ['../profile.php', 'fas fa-user-circle', 'My Profile'],
    ],
    default => [],
};
$roleFolder = $role === 'fams officer' ? 'secretary' : ($role === 'department officer' ? 'hod' : '');
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>My Profile - FAMS</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"><link rel="stylesheet" href="../css/style.css"></head><body><nav class="navbar navbar-dark"><div class="container"><a class="navbar-brand" href="<?= htmlspecialchars($dashboard) ?>"><i class="fas fa-graduation-cap me-2"></i>FAMS</a><a class="btn btn-light btn-sm" href="../api/logout.php">Logout</a></div></nav><?php if ($sidebar): ?><div class="container-fluid"><div class="row"><nav class="col-md-3 col-lg-2 d-md-block sidebar"><div class="position-sticky"><ul class="nav flex-column"><?php foreach ($sidebar as [$page, $icon, $label]): ?><li class="nav-item"><a class="nav-link<?= $label === 'My Profile' ? ' active' : '' ?>" href="<?= htmlspecialchars($roleFolder . '/' . $page) ?>"><i class="<?= htmlspecialchars($icon) ?>"></i> <?= htmlspecialchars($label) ?></a></li><?php endforeach; ?><li class="nav-item mt-3"><a class="nav-link text-danger" href="../api/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li></ul></div></nav><main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4"><?php else: ?><main class="container py-5"><?php endif; ?><div class="row justify-content-center"><div class="col-md-7"><div class="card"><div class="card-body p-4"><h1 class="h3">My Profile</h1><?php if (isset($_GET['photo_saved'])): ?><div class="alert alert-success">Profile photo updated.</div><?php endif; ?><?php if (isset($_GET['photo_error'])): ?><div class="alert alert-danger">Use a JPG, PNG, or WebP image no larger than 2 MB.</div><?php endif; ?><div class="d-flex align-items-center gap-3 my-4"><?php if (!empty($profile['profile_photo'])): ?><img class="profile-photo" src="../<?= htmlspecialchars($profile['profile_photo']) ?>" alt="Profile photo"><?php else: ?><i class="fas fa-user-circle profile-photo-placeholder"></i><?php endif; ?><div><strong><?= htmlspecialchars($profile['full_name']) ?></strong><br><span class="text-muted"><?= htmlspecialchars($current['role']) ?></span></div></div><p><strong>Email:</strong> <?= htmlspecialchars($profile['email']) ?><br><strong>Phone:</strong> <?= htmlspecialchars($profile['phone_number'] ?: 'Not provided') ?></p><form action="../api/profile-photo.php" method="post" enctype="multipart/form-data"><label class="form-label">Profile picture</label><input class="form-control mb-3" name="profile_photo" type="file" accept="image/jpeg,image/png,image/webp" required><button class="btn btn-primary">Upload picture</button> <a class="btn btn-outline-secondary" href="<?= htmlspecialchars($dashboard) ?>">Back to dashboard</a></form></div></div></div></div></main><?php if ($sidebar): ?></div></div><?php endif; ?></body></html>
