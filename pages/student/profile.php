<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
requireLogin();

$current = user();
$pdo = db();
$profileStmt = $pdo->prepare('SELECT registration_number, gender, date_of_birth, institution_id, programme_id, level_of_education_id, nationality_id FROM student_profiles WHERE user_id = ?');
$profileStmt->execute([$current['id']]);
$profile = $profileStmt->fetch() ?: [];
$institutions = $pdo->query('SELECT id, name FROM institutions WHERE is_active = TRUE ORDER BY name')->fetchAll();
$programmes = $pdo->query('SELECT id, name FROM programmes_of_study WHERE is_active = TRUE ORDER BY name')->fetchAll();
$levels = $pdo->query('SELECT id, name FROM levels_of_education ORDER BY name')->fetchAll();
$nationalities = $pdo->query('SELECT id, name FROM nationalities ORDER BY name')->fetchAll();
$error = $_GET['error'] ?? '';
$saved = isset($_GET['saved']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - FAMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../css/style.css">
</head>
<body>
    <nav class="navbar navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="dashboard.html"><i class="fas fa-graduation-cap me-2"></i>FAMS</a>
            <a class="btn btn-light btn-sm" href="../../api/logout.php">Logout</a>
        </div>
    </nav>
    <main class="container py-5">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div><h1 class="h2 mb-1">My Student Profile</h1><p class="text-muted mb-0">Update these details before submitting an application.</p></div>
                    <a href="dashboard.html" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-2"></i>Dashboard</a>
                </div>
                <?php if ($saved): ?><div class="alert alert-success">Your profile was saved successfully.</div><?php endif; ?>
                <?php if ($error): ?><div class="alert alert-danger">Please complete all profile fields and try again.</div><?php endif; ?>
                <div class="card"><div class="card-body p-4">
                    <p><strong>Name:</strong> <?= htmlspecialchars($current['full_name']) ?><br><strong>Email:</strong> <?= htmlspecialchars($current['email']) ?></p>
                    <form method="post" action="../../api/profile-save.php">
                        <div class="row">
                            <div class="col-md-6 mb-3"><label class="form-label">Registration Number</label><input class="form-control" name="registration_number" value="<?= htmlspecialchars($profile['registration_number'] ?? '') ?>" required></div>
                            <div class="col-md-6 mb-3"><label class="form-label">Gender</label><select class="form-select" name="gender" required><option value="">Select gender</option><option value="male" <?= ($profile['gender'] ?? '') === 'male' ? 'selected' : '' ?>>Male</option><option value="female" <?= ($profile['gender'] ?? '') === 'female' ? 'selected' : '' ?>>Female</option></select></div>
                            <div class="col-md-4 mb-3"><label class="form-label">Institution</label><select class="form-select" name="institution_id" required><option value="">Select institution</option><?php foreach ($institutions as $item): ?><option value="<?= $item['id'] ?>" <?= (int)($profile['institution_id'] ?? 0) === (int)$item['id'] ? 'selected' : '' ?>><?= htmlspecialchars($item['name']) ?></option><?php endforeach; ?></select></div>
                            <div class="col-md-4 mb-3"><label class="form-label">Programme</label><select class="form-select" name="programme_id" required><option value="">Select programme</option><?php foreach ($programmes as $item): ?><option value="<?= $item['id'] ?>" <?= (int)($profile['programme_id'] ?? 0) === (int)$item['id'] ? 'selected' : '' ?>><?= htmlspecialchars($item['name']) ?></option><?php endforeach; ?></select></div>
                            <div class="col-md-4 mb-3"><label class="form-label">Level of Study</label><select class="form-select" name="level_of_education_id" required><option value="">Select level</option><?php foreach ($levels as $item): ?><option value="<?= $item['id'] ?>" <?= (int)($profile['level_of_education_id'] ?? 0) === (int)$item['id'] ? 'selected' : '' ?>><?= htmlspecialchars($item['name']) ?></option><?php endforeach; ?></select></div>
                            <div class="col-md-6 mb-3"><label class="form-label">Nationality</label><select class="form-select" name="nationality_id" required><option value="">Select nationality</option><?php foreach ($nationalities as $item): ?><option value="<?= $item['id'] ?>" <?= (int)($profile['nationality_id'] ?? 0) === (int)$item['id'] ? 'selected' : '' ?>><?= htmlspecialchars($item['name']) ?></option><?php endforeach; ?></select></div>
                            <div class="col-md-6 mb-3"><label class="form-label" for="dateOfBirth">Date of Birth</label><input type="date" id="dateOfBirth" class="form-control" name="date_of_birth" value="<?= htmlspecialchars($profile['date_of_birth'] ?? '') ?>" required></div>
                        </div>
                        <button class="btn btn-primary" type="submit"><i class="fas fa-save me-2"></i>Save Profile</button>
                    </form>
                </div></div>
            </div>
        </div>
    </main>
</body>
</html>
